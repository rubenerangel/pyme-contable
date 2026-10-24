<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;

// CORS is closed: SPA and API share one origin behind a single nginx (docs/decisiones.md, Seguridad), so the
// HandleCors middleware must not add any Access-Control-* header to any response, preflights included.
// Laravel 13 ships a default config (paths api/* and sanctum/csrf-cookie, allowed_origins ['*']) that opens CORS;
// the app closes it with config/cors.php and 'paths' => [].
// Default verified with config('cors') on laravel/framework v13.38.0.

const CORS_TEST_FOREIGN_ORIGIN = 'https://attacker.example';

/**
 * Every CORS response header the browser could use to grant a cross-origin page access.
 *
 * @return list<string>
 */
function corsResponseHeaders(): array
{
    return [
        'Access-Control-Allow-Origin',
        'Access-Control-Allow-Credentials',
        'Access-Control-Allow-Methods',
        'Access-Control-Allow-Headers',
        'Access-Control-Expose-Headers',
        'Access-Control-Max-Age',
    ];
}

/**
 * Names of the CORS headers present in the response.
 *
 * @return list<string>
 */
function corsHeadersPresentIn(TestResponse $response): array
{
    return array_values(array_filter(
        corsResponseHeaders(),
        fn (string $header): bool => $response->headers->has($header),
    ));
}

it('adds no CORS header to an API response for a foreign origin', function (): void {
    $response = $this->withHeaders(['Origin' => CORS_TEST_FOREIGN_ORIGIN])
        ->postJson('/api/v1/active-company', ['company_id' => 1]);

    // A real endpoint answering its normal error: the check is about headers, not about the status.
    $response->assertUnauthorized();
    expect($response->headers->has('Access-Control-Allow-Origin'))
        ->toBeFalse('API response must not carry Access-Control-Allow-Origin for a foreign origin')
        ->and($response->headers->has('Access-Control-Allow-Credentials'))
        ->toBeFalse('API response must not carry Access-Control-Allow-Credentials for a foreign origin')
        ->and(corsHeadersPresentIn($response))
        ->toBe([], 'API response must not carry any Access-Control-* header');
});

it('adds no CORS header to /sanctum/csrf-cookie for a foreign origin', function (): void {
    $response = $this->withHeaders(['Origin' => CORS_TEST_FOREIGN_ORIGIN])->get('/sanctum/csrf-cookie');

    $response->assertNoContent();
    expect($response->headers->has('Access-Control-Allow-Origin'))
        ->toBeFalse('/sanctum/csrf-cookie must not carry Access-Control-Allow-Origin for a foreign origin')
        ->and($response->headers->has('Access-Control-Allow-Credentials'))
        ->toBeFalse('/sanctum/csrf-cookie must not carry Access-Control-Allow-Credentials for a foreign origin')
        ->and(corsHeadersPresentIn($response))
        ->toBe([], '/sanctum/csrf-cookie must not carry any Access-Control-* header');
});

it('adds no CORS header to an API error for a route that does not exist', function (): void {
    $response = $this->withHeaders(['Origin' => CORS_TEST_FOREIGN_ORIGIN])->getJson('/api/v1/no-such-route');

    $response->assertNotFound();
    expect(corsHeadersPresentIn($response))->toBe([], 'error responses must not carry any Access-Control-* header');
});

it('does not answer a preflight with CORS permissions', function (string $path): void {
    $response = $this->withHeaders([
        'Origin' => CORS_TEST_FOREIGN_ORIGIN,
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'content-type,x-xsrf-token',
    ])->call('OPTIONS', $path);

    expect($response->headers->has('Access-Control-Allow-Origin'))
        ->toBeFalse('preflight must not carry Access-Control-Allow-Origin')
        ->and($response->headers->has('Access-Control-Allow-Methods'))
        ->toBeFalse('preflight must not carry Access-Control-Allow-Methods')
        ->and($response->headers->has('Access-Control-Allow-Headers'))
        ->toBeFalse('preflight must not carry Access-Control-Allow-Headers')
        ->and(corsHeadersPresentIn($response))
        ->toBe([], 'preflight must not carry any Access-Control-* header');
})->with([
    'active company' => ['/api/v1/active-company'],
    'csrf cookie' => ['/sanctum/csrf-cookie'],
]);

it('keeps config cors.paths empty: adding a path opens CORS', function (): void {
    expect(config('cors.paths'))->toBe(
        [],
        'cors.paths must be []: adding a path opens CORS, which is a decision of docs/decisiones.md '
        .'(SPA and API share one origin, so there is no CORS). Change that doc first.',
    );
});
