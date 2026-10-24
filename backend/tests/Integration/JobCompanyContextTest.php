<?php

declare(strict_types=1);

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Jobs\Middleware\WithCompanyContext;
use App\Models\Company;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\RefreshDatabaseAsOwner;
use Tests\Support\ProbeJobRecord;
use Tests\Support\ReadProbeLabelsJob;
use Tests\Support\ReadProbeLabelsThenFailJob;
use Tests\Support\ReadProbeLabelsWithoutMiddlewareJob;

// Company context in jobs: company_id travels in the payload, the WithCompanyContext job middleware enters and leaves
// the context per job, and a JobProcessing listener clears any leftover context before every job (the worker is a
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
 * Creates companies A and B with two tenant_probe rows each (a-1, a-2, b-1, b-2) through DatabaseCompanyContext.
 * Leaves the context cleared.
 *
 * @return array{a: Company, b: Company}
 */
function seedCompaniesForJobs(): array
{
    $context = app(DatabaseCompanyContext::class);
    $companies = ['a' => Company::factory()->create(), 'b' => Company::factory()->create()];

    foreach ($companies as $key => $company) {
        $context->enter($company->id);
        DB::table('tenant_probe')->insert([['label' => $key.'-1'], ['label' => $key.'-2']]);
        $context->leave();
    }

    return $companies;
}

function dispatchJob(object $job): void
{
    app(Dispatcher::class)->dispatch($job);
}

it('shows a job dispatched for company A only the rows of A, and one for B only the rows of B', function (): void {
    ['a' => $companyA, 'b' => $companyB] = seedCompaniesForJobs();

    dispatchJob(new ReadProbeLabelsJob($companyA->id));
    $seenByJobA = ProbeJobRecord::$labels;

    dispatchJob(new ReadProbeLabelsJob($companyB->id));
    $seenByJobB = ProbeJobRecord::$labels;

    expect(['runs' => ProbeJobRecord::$runs, 'a' => $seenByJobA, 'b' => $seenByJobB])->toBe([
        'runs' => 2,
        'a' => ['a-1', 'a-2'],
        'b' => ['b-1', 'b-2'],
    ]);
});

it('leaves no company in context after the job is processed', function (): void {
    ['a' => $companyA] = seedCompaniesForJobs();

    dispatchJob(new ReadProbeLabelsJob($companyA->id));

    expect(['ran' => ProbeJobRecord::$runs, 'current' => app(DatabaseCompanyContext::class)->current()])->toBe([
        'ran' => 1,
        'current' => null,
    ]);
});

it('lets the exception of a job that fails after entering reach the caller and leaves no company in context', function (): void {
    ['a' => $companyA] = seedCompaniesForJobs();

    $thrown = null;
    try {
        dispatchJob(new ReadProbeLabelsThenFailJob($companyA->id));
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    expect([
        'message' => $thrown?->getMessage(),
        'seenBeforeFailing' => ProbeJobRecord::$labels,
        'current' => app(DatabaseCompanyContext::class)->current(),
    ])->toBe([
        'message' => 'Probe job failure after entering the company context.',
        'seenBeforeFailing' => ['a-1', 'a-2'],
        'current' => null,
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
    ['a' => $companyA] = seedCompaniesForJobs();

    // Simulates what remains of a worker process that died halfway through a previous job.
    app(DatabaseCompanyContext::class)->enter($companyA->id);

    dispatchJob(new ReadProbeLabelsWithoutMiddlewareJob);

    expect(['ran' => ProbeJobRecord::$runs, 'labels' => ProbeJobRecord::$labels])->toBe([
        'ran' => 1,
        'labels' => [],
    ]);
});

it('middleware leaves the context even when the next step throws', function (): void {
    ['a' => $companyA] = seedCompaniesForJobs();
    $context = app(DatabaseCompanyContext::class);
    $middleware = new WithCompanyContext($companyA->id);
    $job = new ReadProbeLabelsJob($companyA->id);

    $currentInsideNext = null;
    $receivedJob = null;
    $thrown = null;
    try {
        $middleware->handle($job, function (object $passed) use ($context, &$currentInsideNext, &$receivedJob): never {
            $currentInsideNext = $context->current();
            $receivedJob = $passed;

            throw new LogicException('next failed');
        });
    } catch (LogicException $e) {
        $thrown = $e;
    }

    expect([
        'message' => $thrown?->getMessage(),
        'insideNext' => $currentInsideNext,
        'sameJob' => $receivedJob === $job,
        'after' => $context->current(),
    ])->toBe([
        'message' => 'next failed',
        'insideNext' => $companyA->id,
        'sameJob' => true,
        'after' => null,
    ]);
});

it('middleware enters the company before running next and leaves it after when next succeeds', function (): void {
    ['b' => $companyB] = seedCompaniesForJobs();
    $context = app(DatabaseCompanyContext::class);
    $job = new ReadProbeLabelsJob($companyB->id);

    $currentInsideNext = null;
    $result = (new WithCompanyContext($companyB->id))->handle($job, function (object $passed) use ($context, &$currentInsideNext): string {
        $currentInsideNext = $context->current();

        return 'next result';
    });

    expect(['result' => $result, 'insideNext' => $currentInsideNext, 'after' => $context->current()])->toBe([
        'result' => 'next result',
        'insideNext' => $companyB->id,
        'after' => null,
    ]);
});

it('carries company_id in the serialized payload: the job that runs is a copy and enters the right company', function (): void {
    ['b' => $companyB] = seedCompaniesForJobs();
    $dispatched = new ReadProbeLabelsJob($companyB->id);

    dispatchJob($dispatched);

    expect([
        'isCopy' => ProbeJobRecord::$runningInstanceId !== spl_object_id($dispatched),
        'ran' => ProbeJobRecord::$runs,
        'labels' => ProbeJobRecord::$labels,
    ])->toBe([
        'isCopy' => true,
        'ran' => 1,
        'labels' => ['b-1', 'b-2'],
    ]);
});

it('does not serialize the connection or the context inside the middleware', function (): void {
    $serialized = serialize(new WithCompanyContext(42));

    expect($serialized)->not->toContain('Connection')->not->toContain('DatabaseCompanyContext');
});
