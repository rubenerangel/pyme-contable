<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Tests\Support\ProblemAssertions;

// request_id: assigned by the first global middleware from X-Request-Id (nginx sets it) when it is 32 lowercase hex
// chars, generated otherwise, loaded into Context and returned in X-Request-Id on every response, errors included.
// Context7 /laravel/docs 13.x: Context facade (add/get), withHeaders.

beforeEach(function (): void {
    Route::prefix('api/v1')->middleware('api')->group(function (): void {
        Route::get('/request-id/ok', fn () => response()->json(['context' => Context::get('request_id')]));

        Route::get('/request-id/fails', fn () => throw new RuntimeException(ProblemAssertions::SECRET));
    });
});

it('respects a valid incoming X-Request-Id and shares it with Context, the header and the error body', function (): void {
    $incoming = 'abcdef0123456789abcdef0123456789';

    $ok = $this->withHeaders(['X-Request-Id' => $incoming])->getJson('/api/v1/request-id/ok');
    $failing = $this->withHeaders(['X-Request-Id' => $incoming])->getJson('/api/v1/request-id/fails');

    $ok->assertOk();
    expect([
        'ok header' => $ok->headers->get('X-Request-Id'),
        'ok context' => $ok->json('context'),
        'error header' => $failing->headers->get('X-Request-Id'),
        'error body' => $failing->json('request_id'),
    ])->toBe([
        'ok header' => $incoming,
        'ok context' => $incoming,
        'error header' => $incoming,
        'error body' => $incoming,
    ]);
});

it('replaces an invalid incoming X-Request-Id with a generated one', function (string $incoming): void {
    $response = $this->withHeaders(['X-Request-Id' => $incoming])->getJson('/api/v1/request-id/ok');

    $id = (string) $response->headers->get('X-Request-Id');
    expect($id)->not->toBe($incoming)
        ->and($id)->toMatch('/^[0-9a-f]{32}$/D')
        ->and($response->json('context'))->toBe($id);
})->with([
    'uppercase' => ['ABCDEF0123456789ABCDEF0123456789'],
    'too short' => ['abcdef0123456789abcdef012345678'],
    'too long' => ['abcdef0123456789abcdef01234567890'],
    'not hex' => ['ghijkl0123456789abcdef0123456789'],
    'uuid with dashes' => ['123e4567-e89b-12d3-a456-426614174000'],
    'trailing newline (a plain $ would accept it)' => ["abcdef0123456789abcdef0123456789\n"],
    'line injection' => ["abcdef0123456789abcdef0123456789\r\nX-Injected: 1"],
    'empty' => [''],
]);

it('does not let a header injection through to the response', function (): void {
    $response = $this->withHeaders(['X-Request-Id' => "abcdef0123456789abcdef0123456789\r\nX-Injected: 1"])
        ->getJson('/api/v1/request-id/fails');

    expect($response->headers->has('X-Injected'))->toBeFalse();
    ProblemAssertions::assertProblem($response, 500, 'internal_error');
});

it('generates a request_id when the header is missing', function (): void {
    $response = $this->getJson('/api/v1/request-id/ok');

    $response->assertOk();
    expect((string) $response->headers->get('X-Request-Id'))->toMatch('/^[0-9a-f]{32}$/D')
        ->and($response->json('context'))->toBe($response->headers->get('X-Request-Id'));
});

it('generates a different request_id for each request', function (): void {
    $first = (string) $this->getJson('/api/v1/request-id/ok')->headers->get('X-Request-Id');
    $second = (string) $this->getJson('/api/v1/request-id/ok')->headers->get('X-Request-Id');
    $third = (string) $this->getJson('/api/v1/request-id/fails')->headers->get('X-Request-Id');

    expect([$first, $second, $third])->each->toMatch('/^[0-9a-f]{32}$/D');
    expect(count(array_unique([$first, $second, $third])))->toBe(3);
});

it('returns X-Request-Id also on a route that does not exist', function (): void {
    $response = $this->getJson('/api/v1/request-id/no-such-route');

    ProblemAssertions::assertProblem($response, 404, 'not_found');
});

it('returns X-Request-Id on success, not only on errors', function (): void {
    $response = $this->getJson('/up');

    $response->assertOk();
    expect((string) $response->headers->get('X-Request-Id'))->toMatch('/^[0-9a-f]{32}$/D');
});
