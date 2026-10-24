<?php

declare(strict_types=1);

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\Support\LogCapture;
use Tests\Support\LogsInsideJob;
use Tests\Support\LogsWithoutContextJob;
use Tests\Support\ProblemAssertions;

// Logs: one JSON record per line on stderr, with request_id, company_id, user_id and job in extra (from Context), the
// value of sensitive keys redacted, no value of a failed query, and the level decided by the kind of error. Each test
// redirects the stderr channel to a temporary file (LogCapture) and reads its lines; phpunit.xml sends the default
// channel to null, so nothing else is written. Probe routes are registered inside the test with the stack the
// business routes use (prefix api/v1, middleware api, auth:sanctum, company.context).
// Verified in vendor (laravel/framework 13.38.0, monolog/monolog 3.12.1): ContextLogProcessor copies Context into
// extra, a job payload carries the dehydrated Context and JobProcessing hydrates it (flush first), and the Router
// pipeline reports an exception inside the middleware layers, while company.context is still entered.
// Context7 /laravel/docs 13.x: Log facade (channel), Context facade (add, forget, flush).

beforeEach(function (): void {
    $this->capture = LogCapture::redirect();

    Route::prefix('api/v1')->group(function (): void {
        Route::middleware('api')->group(function (): void {
            Route::get('/log/plain', function () {
                Log::info('plain request log');

                return response()->noContent();
            });

            Route::get('/log/runtime', fn () => logProbeThrowFromSecretArgument(ProblemAssertions::SECRET));

            Route::get('/log/sql', function (): never {
                // The same recognizable value twice: the second insert breaks UNIQUE (company_id, code).
                $row = ['code' => 'LEAKCODE123', 'name' => 'Name LEAKCODE123', 'resets_yearly' => false];
                DB::table('document_type')->insert($row);
                DB::table('document_type')->insert($row);

                throw new LogicException('The second insert must break the UNIQUE constraint.');
            });

            Route::get('/log/sql-global', function (): never {
                // users has no RLS: Postgres keeps the DETAIL line with the duplicated email.
                $row = ['name' => 'Leak Probe', 'email' => 'leak.email.123@example.test', 'password' => 'not-a-real-hash'];
                DB::table('users')->insert($row);
                DB::table('users')->insert($row);

                throw new LogicException('The second insert must break the UNIQUE constraint.');
            });

            Route::post('/log/job', function (Request $request) {
                app(Dispatcher::class)->dispatch(new LogsInsideJob($request->integer('company_id'), $request->integer('user_id')));

                return response()->noContent();
            });
        });

        Route::middleware(['api', 'auth:sanctum', 'company.context'])->group(function (): void {
            Route::get('/log/company', function () {
                Log::info('company request log');

                return response()->noContent();
            });

            Route::get('/log/company-fails', fn () => throw new RuntimeException('Log probe failure inside the company'));
        });
    });
});

afterEach(function (): void {
    $this->capture->release();
});

/**
 * Throws from a function that received a secret as an argument. The message does not contain it: only the argument
 * of the frame of the trace could leak it.
 */
function logProbeThrowFromSecretArgument(string $secretArgument): never
{
    throw new RuntimeException('Log probe failure without the secret');
}

/**
 * @return array<string, string>
 */
function logSpaHeaders(): array
{
    return ['Origin' => 'http://localhost'];
}

/**
 * A user that is a member of a new company and has it as the active company, plus the company.
 *
 * @return array{user: User, company: Company}
 */
function logUserWithActiveCompany(object $test): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => 'accountant']);

    $test->actingAs($user)->withHeaders(logSpaHeaders());
    $test->postJson('/api/v1/active-company', ['company_id' => $company->id])->assertNoContent();

    return ['user' => $user, 'company' => $company];
}

it('writes one valid JSON line per record with message, level and a UTC datetime', function (): void {
    Log::info('format probe', ['order' => 1]);
    Log::error('format probe with exception', ['exception' => new RuntimeException('format boom')]);

    $lines = array_values(array_filter(explode("\n", $this->capture->raw()), static fn (string $line): bool => $line !== ''));
    $records = $this->capture->records();

    expect($lines)->toHaveCount(2)
        ->and($records)->toHaveCount(2)
        ->and(array_column($records, 'message'))->toBe(['format probe', 'format probe with exception'])
        ->and(array_column($records, 'level_name'))->toBe(['INFO', 'ERROR']);

    foreach ($records as $record) {
        expect($record['datetime'])->toBeString()->toMatch('/(\+00:00|Z)$/D');
    }
});

it('puts the request_id of the request in extra, the same one the response carries', function (): void {
    $response = $this->getJson('/api/v1/log/plain');

    $records = $this->capture->named('plain request log');

    expect($records)->toHaveCount(1)
        ->and($records[0]['extra']['request_id'])->toBe($response->headers->get('X-Request-Id'))
        ->and($records[0]['extra'])->not->toHaveKeys(['company_id', 'user_id']);
});

it('uses the incoming X-Request-Id when it is valid', function (): void {
    $incoming = 'abcdef0123456789abcdef0123456789';

    $this->withHeaders(['X-Request-Id' => $incoming])->getJson('/api/v1/log/plain');

    expect($this->capture->named('plain request log')[0]['extra']['request_id'])->toBe($incoming);
});

it('puts company_id and user_id in extra inside a company request, and not in a log written after it', function (): void {
    ['user' => $user, 'company' => $company] = logUserWithActiveCompany($this);

    $response = $this->getJson('/api/v1/log/company')->assertNoContent();
    Log::info('after the request');

    $inside = $this->capture->named('company request log');
    $after = $this->capture->named('after the request');

    expect($inside)->toHaveCount(1)
        ->and((string) $inside[0]['extra']['company_id'])->toBe((string) $company->id)
        ->and((string) $inside[0]['extra']['user_id'])->toBe((string) $user->id)
        ->and($inside[0]['extra']['request_id'])->toBe($response->headers->get('X-Request-Id'))
        ->and($after)->toHaveCount(1)
        ->and($after[0]['extra'])->not->toHaveKeys(['company_id', 'user_id']);
});

it('reports the exception of a failing company request with its company_id and user_id, and clears them afterwards', function (): void {
    ['user' => $user, 'company' => $company] = logUserWithActiveCompany($this);

    $this->getJson('/api/v1/log/company-fails')->assertStatus(500);
    Log::info('after the failure');

    $errors = $this->capture->atLevel('ERROR');
    $after = $this->capture->named('after the failure');

    expect($errors)->toHaveCount(1)
        ->and((string) $errors[0]['extra']['company_id'])->toBe((string) $company->id)
        ->and((string) $errors[0]['extra']['user_id'])->toBe((string) $user->id)
        ->and($after)->toHaveCount(1)
        ->and($after[0]['extra'])->not->toHaveKeys(['company_id', 'user_id']);
});

it('does not carry the company of one request into the log of the next request of another user', function (): void {
    ['company' => $firstCompany] = logUserWithActiveCompany($this);
    $this->getJson('/api/v1/log/company')->assertNoContent();

    ['user' => $secondUser, 'company' => $secondCompany] = logUserWithActiveCompany($this);
    $this->getJson('/api/v1/log/company')->assertNoContent();

    $records = $this->capture->named('company request log');

    expect($records)->toHaveCount(2)
        ->and([(string) $records[0]['extra']['company_id'], (string) $records[1]['extra']['company_id']])
        ->toBe([(string) $firstCompany->id, (string) $secondCompany->id])
        ->and((string) $records[1]['extra']['user_id'])->toBe((string) $secondUser->id);
});

it('logs a job dispatched inside a request with the request_id, company_id, user_id, job and job_id', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create();

    // The route has no company.context: company_id and user_id can only come from WithCompanyContext in the job.
    $response = $this->postJson('/api/v1/log/job', ['company_id' => $company->id, 'user_id' => $user->id])->assertNoContent();

    $records = $this->capture->named('inside job');

    expect($records)->toHaveCount(1);

    $extra = $records[0]['extra'];

    expect($extra['request_id'])->toBe($response->headers->get('X-Request-Id'))
        ->and((string) $extra['company_id'])->toBe((string) $company->id)
        ->and((string) $extra['user_id'])->toBe((string) $user->id)
        ->and($extra['job'])->toBe(LogsInsideJob::class)
        ->and($extra['job_id'])->toBeString()->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D');
});

it('does not give a later job without context the request_id, company or user of the earlier one', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create();

    $this->postJson('/api/v1/log/job', ['company_id' => $company->id, 'user_id' => $user->id])->assertNoContent();

    // A worker picks the next job with no Context of its own: the one of the caller is dropped, as on dispatch from a
    // command.
    Context::flush();
    app(Dispatcher::class)->dispatch(new LogsWithoutContextJob);

    $first = $this->capture->named('inside job');
    $later = $this->capture->named('job without context');

    expect($first)->toHaveCount(1)
        ->and($later)->toHaveCount(1);

    $extra = $later[0]['extra'];

    expect($extra)->not->toHaveKeys(['request_id', 'company_id', 'user_id'])
        ->and($extra['job'])->toBe(LogsWithoutContextJob::class)
        ->and($extra['job_id'])->toBeString()->not->toBe('')->not->toBe($first[0]['extra']['job_id']);
});

it('redacts the sensitive keys of a nested log context and keeps the rest', function (): void {
    Log::info('redaction probe', [
        'user' => ['name' => 'Ana', 'password' => 'pw-leak-1', 'profile' => ['token' => 'token-leak-2', 'city' => 'Cali']],
        'headers' => ['Authorization' => 'Bearer leak-3', 'Accept' => 'application/json'],
        'form' => ['_token' => 'csrf-leak-4', 'amount' => '100.00'],
        'settings' => ['db_password' => 'dbpw-leak-5', 'db_host' => 'postgres'],
        'order_id' => 77,
    ]);

    $raw = $this->capture->raw();
    foreach (['pw-leak-1', 'token-leak-2', 'leak-3', 'csrf-leak-4', 'dbpw-leak-5'] as $secret) {
        expect(str_contains($raw, $secret))->toBeFalse("The log must not contain '{$secret}'. Log: {$raw}");
    }

    expect($this->capture->named('redaction probe')[0]['context'])->toBe([
        'user' => ['name' => 'Ana', 'password' => '[redacted]', 'profile' => ['token' => '[redacted]', 'city' => 'Cali']],
        'headers' => ['Authorization' => '[redacted]', 'Accept' => 'application/json'],
        'form' => ['_token' => '[redacted]', 'amount' => '100.00'],
        'settings' => ['db_password' => '[redacted]', 'db_host' => 'postgres'],
        'order_id' => 77,
    ]);
});

it('redacts a sensitive key that comes from Context, not only from the log context', function (): void {
    Context::add('api_key', 'api-key-leak-6');
    Context::add('trace_label', 'visible');

    Log::info('context redaction probe');

    $raw = $this->capture->raw();
    $extra = $this->capture->named('context redaction probe')[0]['extra'];

    expect(str_contains($raw, 'api-key-leak-6'))->toBeFalse("The log must not contain the api_key of Context. Log: {$raw}")
        ->and($extra['api_key'])->toBe('[redacted]')
        ->and($extra['trace_label'])->toBe('visible');
});

it('does not write the value of a sensitive key into the message through a placeholder', function (): void {
    Log::info('Login with {password} for {user}', ['password' => 'placeholder-leak-7', 'user' => 'ana']);

    $raw = $this->capture->raw();
    $record = $this->capture->records()[0];

    expect(str_contains($raw, 'placeholder-leak-7'))->toBeFalse("The log must not contain the password of a placeholder. Log: {$raw}")
        ->and($record['message'])->toContain('ana');
});

it('logs a 500 as an error with the class and the trace of the exception, and no argument of the trace', function (): void {
    $response = $this->getJson('/api/v1/log/runtime');

    $response->assertStatus(500);

    $raw = $this->capture->raw();
    expect(str_contains($raw, ProblemAssertions::SECRET))->toBeFalse("The log must not contain the secret argument of the trace. Log: {$raw}");

    $errors = $this->capture->atLevel('ERROR');

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['message'])->toBe('Log probe failure without the secret')
        ->and($errors[0]['context']['exception']['class'])->toBe(RuntimeException::class)
        ->and($errors[0]['context']['exception']['trace'])->toBeArray()->not->toBeEmpty()
        ->and($errors[0]['extra']['request_id'])->toBe($response->headers->get('X-Request-Id'));
});

it('logs a violated UNIQUE with its SQLSTATE and the SQL with placeholders, and never the values', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    // No leave(): the failed statement aborts the RefreshDatabase transaction, which is rolled back at the end.
    app(DatabaseCompanyContext::class)->enter($company->id, $user->id);

    $response = $this->getJson('/api/v1/log/sql');

    $response->assertStatus(500);

    $raw = $this->capture->raw();
    expect(str_contains($raw, 'LEAKCODE123'))->toBeFalse("No value of the query may reach the log. Log: {$raw}")
        ->and(str_contains($raw, 'DETAIL'))->toBeFalse("The DETAIL line of the Postgres message must not reach the log. Log: {$raw}");

    $errors = $this->capture->atLevel('ERROR');

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['message'])->toBe('Database query failed')
        ->and($errors[0]['context']['sqlstate'])->toBe('23505')
        ->and($errors[0]['context']['connection'])->toBe('pgsql')
        ->and($errors[0]['context']['sql'])->toBeString()->toContain('document_type')->toContain('?')
        ->and(json_encode($errors[0]['context']))->toContain('duplicate key value violates unique constraint')
        ->and($errors[0]['extra']['request_id'])->toBe($response->headers->get('X-Request-Id'));
});

// Two cases on purpose: on a table with RLS (document_type) Postgres already omits the DETAIL line of a violated
// UNIQUE, so only a global table without RLS (users) shows that the reporter itself removes DETAIL and the values.
it('logs a violated UNIQUE on a table without RLS with no DETAIL line and no value', function (): void {
    $response = $this->getJson('/api/v1/log/sql-global');

    $response->assertStatus(500);

    $raw = $this->capture->raw();
    expect(str_contains($raw, 'leak.email.123'))->toBeFalse("No value of the query may reach the log. Log: {$raw}")
        ->and(str_contains($raw, 'DETAIL'))->toBeFalse("The DETAIL line of the Postgres message must not reach the log. Log: {$raw}");

    $errors = $this->capture->atLevel('ERROR');

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['message'])->toBe('Database query failed')
        ->and($errors[0]['context']['sqlstate'])->toBe('23505')
        ->and($errors[0]['context']['sql'])->toBeString()->toContain('users')->toContain('?')
        ->and(json_encode($errors[0]['context']))->toContain('duplicate key value violates unique constraint')
        ->and($errors[0]['extra']['request_id'])->toBe($response->headers->get('X-Request-Id'));
});

it('logs a company access denial as a warning with its code and the ids of the error context', function (): void {
    $company = Company::factory()->create();
    $foreign = Company::factory()->create();
    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => 'accountant']);

    $this->actingAs($user)->withHeaders(logSpaHeaders());
    $this->postJson('/api/v1/active-company', ['company_id' => $foreign->id])->assertForbidden();

    $warnings = $this->capture->atLevel('WARNING');

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['context']['code'])->toBe('company_access_denied')
        ->and((int) $warnings[0]['context']['user_id'])->toBe($user->id)
        ->and((int) $warnings[0]['context']['company_id'])->toBe($foreign->id)
        ->and($this->capture->atLevel('ERROR'))->toBe([]);
});

it('logs the loss of access to the active company as a warning with its code', function (): void {
    ['user' => $user, 'company' => $company] = logUserWithActiveCompany($this);
    DB::table('company_user')->where('company_id', $company->id)->where('user_id', $user->id)->delete();

    $this->getJson('/api/v1/log/company')->assertForbidden();

    $warnings = $this->capture->atLevel('WARNING');

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['context']['code'])->toBe('active_company_access_revoked')
        ->and($this->capture->atLevel('ERROR'))->toBe([]);
});

it('does not log a no-active-company conflict: it is an expected business rejection', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $user->companies()->attach($company->id, ['role' => 'accountant']);

    $this->actingAs($user)->withHeaders(logSpaHeaders());
    $this->getJson('/api/v1/log/company')->assertStatus(409)->assertJsonPath('code', 'no_active_company');

    expect($this->capture->raw())->toBe('');
});
