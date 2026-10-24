<?php

declare(strict_types=1);

use App\Domain\DomainError;
use App\Domain\ErrorKind;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Tests\Support\ProbeDomainError;
use Tests\Support\ProblemAssertions;

// No response of the API carries class, file, line, trace, SQL, SQLSTATE or the internal message of an exception, with
// APP_DEBUG on or off. Every error is Problem Details (RFC 9457, application/problem+json) with code, request_id and
// a safe Spanish detail from lang/es/errors.php.
// Verified: Laravel 13.38.0 in vendor (render callbacks run after prepareException; translator addLines does not load
// the group file, so the test loads it first). Context7 /laravel/docs 13.x: assertHeader, assertJsonValidationErrors,
// Context facade. Pest 4.7.8 (Context7 /pestphp/docs): chained with() builds the cartesian product of datasets.
// Probe routes are registered inside the test, with the stack the business routes use (prefix api/v1, middleware api).

beforeEach(function (): void {
    // addLines marks the group as loaded: load the real file first, otherwise the file would never be read.
    app('translator')->load('*', 'errors', 'es');
    Lang::addLines(['errors.codes.probe_domain_error' => 'Probe safe message.'], 'es');

    Route::prefix('api/v1')->middleware('api')->group(function (): void {
        Route::get('/leak/runtime', fn () => throw new RuntimeException(ProblemAssertions::SECRET));

        Route::get('/leak/query', fn () => DB::select('select * from table_that_does_not_exist_9137'));

        Route::get('/leak/model-not-found', fn () => Company::findOrFail(987654321));

        Route::get('/leak/domain', function (): never {
            if (! class_exists(DomainError::class)) {
                throw new LogicException('App\Domain\DomainError does not exist yet.');
            }

            throw new ProbeDomainError(
                ErrorKind::Forbidden,
                'probe_domain_error',
                ProblemAssertions::SECRET,
                ['internal_ref' => ProblemAssertions::CONTEXT_SECRET],
            );
        });

        Route::get('/leak/validation', fn () => request()->validate(['amount' => 'required|integer']));

        Route::get('/leak/unauthenticated', fn () => response()->json(['ok' => true]))->middleware('auth:sanctum');

        Route::get('/leak/only-get', fn () => response()->json(['ok' => true]));
    });
});

it('answers every kind of failure as safe Problem Details, with debug on and off', function (
    string $method,
    string $uri,
    int $status,
    string $code,
    ?string $detail,
    bool $withErrors,
    bool $debug,
): void {
    config(['app.debug' => $debug]);

    $response = $this->json($method, $uri);

    ProblemAssertions::assertProblem($response, $status, $code, $detail, $withErrors);
    ProblemAssertions::assertNoLeak($response);
})->with([
    'unexpected RuntimeException' => ['GET', '/api/v1/leak/runtime', 500, 'internal_error', null, false],
    'QueryException (nonexistent table)' => ['GET', '/api/v1/leak/query', 500, 'internal_error', null, false],
    'findOrFail of a nonexistent id' => ['GET', '/api/v1/leak/model-not-found', 404, 'not_found', null, false],
    'DomainError' => ['GET', '/api/v1/leak/domain', 403, 'probe_domain_error', 'Probe safe message.', false],
    'ValidationException' => ['GET', '/api/v1/leak/validation', 422, 'validation_failed', null, true],
    'unauthenticated' => ['GET', '/api/v1/leak/unauthenticated', 401, 'unauthenticated', null, false],
    'route that does not exist' => ['GET', '/api/v1/leak/no-such-route', 404, 'not_found', null, false],
    'method not allowed' => ['POST', '/api/v1/leak/only-get', 405, 'method_not_allowed', null, false],
])->with([
    'debug on' => [true],
    'debug off' => [false],
]);

it('answers Problem Details even when the client does not ask for JSON', function (string $uri, int $status, string $code): void {
    config(['app.debug' => true]);

    $response = $this->get($uri);

    ProblemAssertions::assertProblem($response, $status, $code);
    ProblemAssertions::assertNoLeak($response);
})->with([
    'exception' => ['/api/v1/leak/runtime', 500, 'internal_error'],
    'missing route' => ['/api/v1/leak/no-such-route', 404, 'not_found'],
    'missing route outside the API prefix' => ['/no-such-route', 404, 'not_found'],
]);

it('lists the allowed methods in the Allow header of a 405', function (): void {
    $response = $this->postJson('/api/v1/leak/only-get');

    ProblemAssertions::assertProblem($response, 405, 'method_not_allowed');
    expect($response->headers->get('Allow'))->toContain('GET');
});

it('keeps the field map of a validation failure so assertJsonValidationErrors still works', function (): void {
    $response = $this->getJson('/api/v1/leak/validation');

    $response->assertJsonValidationErrors(['amount']);
    expect($response->json('errors.amount'))->toBeArray()->not->toBeEmpty();
});

it('writes validation messages in Spanish, not in English and not as raw keys', function (): void {
    $message = $this->getJson('/api/v1/leak/validation')->json('errors.amount.0');

    expect($message)->toBeString()
        ->not->toStartWith('validation.')
        ->not->toBe('The amount field is required.');
});
