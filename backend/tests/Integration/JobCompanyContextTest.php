<?php

declare(strict_types=1);

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Jobs\Middleware\WithCompanyContext;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\RefreshDatabaseAsOwner;
use Tests\Support\ProbeJobRecord;
use Tests\Support\ReadProbeLabelsJob;
use Tests\Support\ReadProbeLabelsThenFailJob;
use Tests\Support\ReadProbeLabelsWithoutMiddlewareJob;

// Company and user context in jobs: company_id and user_id travel in the payload, the WithCompanyContext job middleware enters and leaves
// the context per job, and a JobProcessing listener clears any leftover context (company and user) before every job (the worker is a
// long process). QUEUE_CONNECTION=sync in phpunit.xml.
// Verified in vendor (laravel/framework v13.38.0): SyncQueue::executeJob serializes the job in createPayload, the
// SyncJob unserializes it in CallQueuedHandler and runs its middleware() through a Pipeline, and SyncQueue raises
// JobProcessing before firing the job. The probe jobs implement ShouldQueue; without it dispatch runs them inline and
// no queue event is raised.
uses(RefreshDatabaseAsOwner::class);

beforeEach(function (): void {
    ProbeJobRecord::reset();
});

/**
 * Creates companies A and B with two tenant_probe rows each (a-1, a-2, b-1, b-2) through DatabaseCompanyContext, and
 * a user (the one that originates the jobs). Leaves the context cleared.
 *
 * @return array{a: Company, b: Company, user: User}
 */
function seedCompaniesForJobs(): array
{
    $context = app(DatabaseCompanyContext::class);
    $companies = ['a' => Company::factory()->create(), 'b' => Company::factory()->create()];
    $user = User::factory()->create();

    foreach ($companies as $key => $company) {
        $context->enter($company->id, $user->id);
        DB::table('tenant_probe')->insert([['label' => $key.'-1'], ['label' => $key.'-2']]);
        $context->leave();
    }

    return [...$companies, 'user' => $user];
}

function dispatchJob(object $job): void
{
    app(Dispatcher::class)->dispatch($job);
}

it('shows a job dispatched for company A only the rows of A, and one for B only the rows of B', function (): void {
    ['a' => $companyA, 'b' => $companyB, 'user' => $user] = seedCompaniesForJobs();

    dispatchJob(new ReadProbeLabelsJob($companyA->id, $user->id));
    $seenByJobA = ProbeJobRecord::$labels;

    dispatchJob(new ReadProbeLabelsJob($companyB->id, $user->id));
    $seenByJobB = ProbeJobRecord::$labels;

    expect(['runs' => ProbeJobRecord::$runs, 'a' => $seenByJobA, 'b' => $seenByJobB])->toBe([
        'runs' => 2,
        'a' => ['a-1', 'a-2'],
        'b' => ['b-1', 'b-2'],
    ]);
});

it('leaves no company and no user in context after the job is processed', function (): void {
    ['a' => $companyA, 'user' => $user] = seedCompaniesForJobs();

    dispatchJob(new ReadProbeLabelsJob($companyA->id, $user->id));

    expect([
        'ran' => ProbeJobRecord::$runs,
        'current' => app(DatabaseCompanyContext::class)->current(),
        'currentUser' => app(DatabaseCompanyContext::class)->currentUser(),
    ])->toBe([
        'ran' => 1,
        'current' => null,
        'currentUser' => null,
    ]);
});

it('lets the exception of a job that fails after entering reach the caller and leaves no company and no user in context', function (): void {
    ['a' => $companyA, 'user' => $user] = seedCompaniesForJobs();
    $context = app(DatabaseCompanyContext::class);

    $thrown = null;
    try {
        dispatchJob(new ReadProbeLabelsThenFailJob($companyA->id, $user->id));
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    expect([
        'message' => $thrown?->getMessage(),
        'seenBeforeFailing' => ProbeJobRecord::$labels,
        'userSeenBeforeFailing' => ProbeJobRecord::$userSetting,
        'current' => $context->current(),
        'currentUser' => $context->currentUser(),
    ])->toBe([
        'message' => 'Probe job failure after entering the company context.',
        'seenBeforeFailing' => ['a-1', 'a-2'],
        'userSeenBeforeFailing' => (string) $user->id,
        'current' => null,
        'currentUser' => null,
    ]);
});

it('raises JobProcessing on the sync queue before running the job (premise of the safety net)', function (): void {
    $processing = [];
    Event::listen(JobProcessing::class, function (JobProcessing $event) use (&$processing): void {
        $processing[] = ['ranYet' => ProbeJobRecord::$runs, 'connection' => $event->connectionName];
    });

    dispatchJob(new ReadProbeLabelsWithoutMiddlewareJob);

    expect($processing)->toBe([['ranYet' => 0, 'connection' => 'sync']]);
});

it('clears a leftover company context before a job without middleware, so it sees no rows (safety net)', function (): void {
    ['a' => $companyA, 'user' => $user] = seedCompaniesForJobs();

    // Simulates what remains of a worker process that died halfway through a previous job.
    app(DatabaseCompanyContext::class)->enter($companyA->id, $user->id);

    dispatchJob(new ReadProbeLabelsWithoutMiddlewareJob);

    expect(['ran' => ProbeJobRecord::$runs, 'labels' => ProbeJobRecord::$labels])->toBe([
        'ran' => 1,
        'labels' => [],
    ]);
});

it('does not let a job without middleware inherit the user left in context by a previous job (Queue::before)', function (): void {
    ['a' => $companyA, 'user' => $user] = seedCompaniesForJobs();

    // Simulates a worker whose previous job died without leaving the context.
    app(DatabaseCompanyContext::class)->enter($companyA->id, $user->id);

    dispatchJob(new ReadProbeLabelsWithoutMiddlewareJob);

    expect([
        'ran' => ProbeJobRecord::$runs,
        'companySeen' => ProbeJobRecord::$companySetting,
        'userSeen' => ProbeJobRecord::$userSetting,
    ])->toBe([
        'ran' => 1,
        'companySeen' => '',
        'userSeen' => '',
    ]);
});

it('does not let a job without middleware inherit the user of a job that ran just before it', function (): void {
    ['a' => $companyA, 'user' => $user] = seedCompaniesForJobs();

    dispatchJob(new ReadProbeLabelsJob($companyA->id, $user->id));
    $userSeenByFirst = ProbeJobRecord::$userSetting;

    dispatchJob(new ReadProbeLabelsWithoutMiddlewareJob);

    expect([
        'runs' => ProbeJobRecord::$runs,
        'first' => $userSeenByFirst,
        'second' => ProbeJobRecord::$userSetting,
    ])->toBe([
        'runs' => 2,
        'first' => (string) $user->id,
        'second' => '',
    ]);
});

it('shows the job the user of its payload, and a job dispatched for another user sees that one', function (): void {
    ['a' => $companyA, 'user' => $user] = seedCompaniesForJobs();
    $otherUser = User::factory()->create();

    dispatchJob(new ReadProbeLabelsJob($companyA->id, $user->id));
    $seenByFirst = [ProbeJobRecord::$companySetting, ProbeJobRecord::$userSetting];

    dispatchJob(new ReadProbeLabelsJob($companyA->id, $otherUser->id));
    $seenBySecond = [ProbeJobRecord::$companySetting, ProbeJobRecord::$userSetting];

    expect(['runs' => ProbeJobRecord::$runs, 'first' => $seenByFirst, 'second' => $seenBySecond])->toBe([
        'runs' => 2,
        'first' => [(string) $companyA->id, (string) $user->id],
        'second' => [(string) $companyA->id, (string) $otherUser->id],
    ]);
});

it('middleware leaves the company and the user even when the next step throws', function (): void {
    ['a' => $companyA, 'user' => $user] = seedCompaniesForJobs();
    $context = app(DatabaseCompanyContext::class);
    $middleware = new WithCompanyContext($companyA->id, $user->id);
    $job = new ReadProbeLabelsJob($companyA->id, $user->id);

    $currentInsideNext = null;
    $userInsideNext = null;
    $receivedJob = null;
    $thrown = null;
    try {
        $middleware->handle($job, function (object $passed) use ($context, &$currentInsideNext, &$userInsideNext, &$receivedJob): never {
            $currentInsideNext = $context->current();
            $userInsideNext = $context->currentUser();
            $receivedJob = $passed;

            throw new LogicException('next failed');
        });
    } catch (LogicException $e) {
        $thrown = $e;
    }

    expect([
        'message' => $thrown?->getMessage(),
        'insideNext' => $currentInsideNext,
        'userInsideNext' => $userInsideNext,
        'sameJob' => $receivedJob === $job,
        'after' => $context->current(),
        'userAfter' => $context->currentUser(),
    ])->toBe([
        'message' => 'next failed',
        'insideNext' => $companyA->id,
        'userInsideNext' => $user->id,
        'sameJob' => true,
        'after' => null,
        'userAfter' => null,
    ]);
});

it('middleware enters the company and the user before running next and leaves them after when next succeeds', function (): void {
    ['b' => $companyB, 'user' => $user] = seedCompaniesForJobs();
    $context = app(DatabaseCompanyContext::class);
    $job = new ReadProbeLabelsJob($companyB->id, $user->id);

    $currentInsideNext = null;
    $userInsideNext = null;
    $result = (new WithCompanyContext($companyB->id, $user->id))->handle($job, function (object $passed) use ($context, &$currentInsideNext, &$userInsideNext): string {
        $currentInsideNext = $context->current();
        $userInsideNext = $context->currentUser();

        return 'next result';
    });

    expect([
        'result' => $result,
        'insideNext' => $currentInsideNext,
        'userInsideNext' => $userInsideNext,
        'after' => $context->current(),
        'userAfter' => $context->currentUser(),
    ])->toBe([
        'result' => 'next result',
        'insideNext' => $companyB->id,
        'userInsideNext' => $user->id,
        'after' => null,
        'userAfter' => null,
    ]);
});

it('carries company_id and user_id in the serialized payload: the job that runs is a copy and enters the right company and user', function (): void {
    ['b' => $companyB, 'user' => $user] = seedCompaniesForJobs();
    $dispatched = new ReadProbeLabelsJob($companyB->id, $user->id);

    dispatchJob($dispatched);

    expect([
        'isCopy' => ProbeJobRecord::$runningInstanceId !== spl_object_id($dispatched),
        'ran' => ProbeJobRecord::$runs,
        'labels' => ProbeJobRecord::$labels,
        'userSeen' => ProbeJobRecord::$userSetting,
    ])->toBe([
        'isCopy' => true,
        'ran' => 1,
        'labels' => ['b-1', 'b-2'],
        'userSeen' => (string) $user->id,
    ]);
});

it('does not serialize the connection or the context inside the middleware', function (): void {
    $serialized = serialize(new WithCompanyContext(42, 7));

    expect($serialized)->not->toContain('Connection')->not->toContain('DatabaseCompanyContext');
});

it('serializes only company_id and user_id of the middleware, and they survive a round trip', function (): void {
    $copy = unserialize(serialize(new WithCompanyContext(42, 7)));

    expect([
        'properties' => (array) $copy,
        'companyId' => $copy->companyId,
        'userId' => $copy->userId,
    ])->toBe([
        'properties' => ['companyId' => 42, 'userId' => 7],
        'companyId' => 42,
        'userId' => 7,
    ]);
});
