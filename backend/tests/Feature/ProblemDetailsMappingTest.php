<?php

declare(strict_types=1);

use App\Domain\DomainError;
use App\Domain\ErrorKind;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Assert;
use Tests\Support\ProbeDomainError;
use Tests\Support\ProblemAssertions;

// Mapping of exceptions to status and code in the Problem Details renderer. Every exception carries the internal
// message ProblemAssertions::SECRET, which must never reach the body.
// Verified: Laravel 13.38.0 in vendor (prepareException turns ModelNotFound, TokenMismatch and Authorization into
// HTTP exceptions before the render callbacks). Context7 /laravel/docs 13.x: abort() headers, HttpResponseException.

beforeEach(function (): void {
    app('translator')->load('*', 'errors', 'es');
    Lang::addLines(['errors.codes.probe_domain_error' => 'Probe safe message.'], 'es');

    Route::prefix('api/v1')->middleware('api')->group(function (): void {
        Route::get('/map/abort/{status}', fn (int $status) => abort($status, ProblemAssertions::SECRET));

        Route::get('/map/abort-with-headers', fn () => abort(429, ProblemAssertions::SECRET, ['Retry-After' => '30']));

        Route::get('/map/token-mismatch', fn () => throw new TokenMismatchException(ProblemAssertions::SECRET));

        Route::get('/map/authorization', fn () => throw new AuthorizationException(ProblemAssertions::SECRET));

        Route::get('/map/authentication', fn () => throw new AuthenticationException(ProblemAssertions::SECRET));

        Route::get('/map/response-exception', fn () => throw new HttpResponseException(
            response()->json(['custom' => 'on purpose'], 202, ['X-Custom' => 'kept']),
        ));

        Route::get('/map/kind/{kind}', function (string $kind): never {
            if (! class_exists(DomainError::class)) {
                throw new LogicException('App\Domain\DomainError does not exist yet.');
            }

            throw new ProbeDomainError(
                constant(ErrorKind::class.'::'.$kind),
                'probe_domain_error',
                ProblemAssertions::SECRET,
                ['internal_ref' => ProblemAssertions::CONTEXT_SECRET],
            );
        });
    });
});

it('maps an HTTP exception to its status and generic code, never using its message', function (int $status, string $code): void {
    config(['app.debug' => true]);

    $response = $this->getJson('/api/v1/map/abort/'.$status);

    ProblemAssertions::assertProblem($response, $status, $code);
    ProblemAssertions::assertNoLeak($response);
})->with([
    '400' => [400, 'bad_request'],
    '401' => [401, 'unauthenticated'],
    '403' => [403, 'forbidden'],
    '404' => [404, 'not_found'],
    '405' => [405, 'method_not_allowed'],
    '419' => [419, 'csrf_token_mismatch'],
    '429' => [429, 'too_many_requests'],
    '503' => [503, 'service_unavailable'],
    'other 4xx: 409' => [409, 'http_error'],
    'other 4xx: 418' => [418, 'http_error'],
    'other 4xx: 422 raised as HTTP exception' => [422, 'http_error'],
    'other 5xx: 500' => [500, 'internal_error'],
    'other 5xx: 502 (title falls to default)' => [502, 'internal_error'],
]);

it('keeps the headers of the HTTP exception', function (): void {
    $response = $this->getJson('/api/v1/map/abort-with-headers');

    ProblemAssertions::assertProblem($response, 429, 'too_many_requests');
    expect($response->headers->get('Retry-After'))->toBe('30');
});

it('maps the exceptions Laravel converts to HTTP exceptions', function (string $uri, int $status, string $code): void {
    config(['app.debug' => true]);

    $response = $this->getJson($uri);

    ProblemAssertions::assertProblem($response, $status, $code);
    ProblemAssertions::assertNoLeak($response);
})->with([
    'TokenMismatchException' => ['/api/v1/map/token-mismatch', 419, 'csrf_token_mismatch'],
    'AuthorizationException' => ['/api/v1/map/authorization', 403, 'forbidden'],
    'AuthenticationException' => ['/api/v1/map/authentication', 401, 'unauthenticated'],
]);

it('leaves a response built on purpose by an HttpResponseException untouched', function (): void {
    $response = $this->getJson('/api/v1/map/response-exception');

    $response->assertStatus(202);
    $response->assertExactJson(['custom' => 'on purpose']);
    expect($response->headers->get('X-Custom'))->toBe('kept');
    expect((string) $response->headers->get('Content-Type'))->not->toContain('problem+json');
});

it('maps every ErrorKind to its status with the code of the error and the safe detail', function (): void {
    Assert::assertTrue(enum_exists(ErrorKind::class), 'App\Domain\ErrorKind must exist.');
    Assert::assertTrue(class_exists(DomainError::class), 'App\Domain\DomainError must exist.');

    $expectedStatus = ['Invalid' => 422, 'NotFound' => 404, 'Forbidden' => 403, 'Conflict' => 409];
    $names = array_map(fn (ErrorKind $kind): string => $kind->name, ErrorKind::cases());

    // A new case without a status here must fail: the mapping has to be decided for it.
    expect($names)->toEqualCanonicalizing(array_keys($expectedStatus));

    foreach ($expectedStatus as $name => $status) {
        config(['app.debug' => true]);

        $response = $this->getJson('/api/v1/map/kind/'.$name);

        ProblemAssertions::assertProblem($response, $status, 'probe_domain_error', 'Probe safe message.');
        ProblemAssertions::assertNoLeak($response);
    }
});
