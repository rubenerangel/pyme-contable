<?php

declare(strict_types=1);

use App\Http\Middleware\SetCompanyContext;
use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// SetCompanyContext on business routes: reads active_company_id from the session, revalidates the membership on every
// request and enters the database company context (RLS) for the duration of the request only. The probe routes use
// the same stack the business routes will use (prefix api/v1, middleware api + auth:sanctum + SetCompanyContext) and
// read tenant_probe, a tests-only table with RLS by company.
// Sanctum 4.3.3, Laravel 13 docs (Context7 /laravel/docs/__branch__13.x): stateful by Origin header; the user comes
// from the session guard (web). CSRF is skipped by the framework under tests.

beforeEach(function (): void {
    Route::prefix('api/v1')
        ->middleware(['api', 'auth:sanctum', SetCompanyContext::class])
        ->group(function (): void {
            Route::get('/probe', fn () => response()->json([
                'labels' => DB::table('tenant_probe')->orderBy('label')->pluck('label')->all(),
            ]));

            Route::get('/probe-fails', function (): never {
                // Reads inside the context first, so the exception happens after entering it.
                DB::table('tenant_probe')->count();

                throw new RuntimeException('Probe failure after entering the company context.');
            });
        });
});

/**
 * @return array<string, string>
 */
function companyContextSpaHeaders(): array
{
    return ['Origin' => 'http://localhost'];
}

/**
 * Creates companies A and B with two tenant_probe rows each (a-1, a-2, b-1, b-2), entering and leaving the context
 * through DatabaseCompanyContext. Leaves the context cleared.
 *
 * @return array{a: Company, b: Company}
 */
function seedCompaniesWithProbeRows(): array
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

/**
 * @param  list<Company>  $companies
 */
function memberOf(array $companies): User
{
    $user = User::factory()->create();

    foreach ($companies as $company) {
        $user->companies()->attach($company->id, ['role' => 'accountant']);
    }

    return $user;
}

it('shows only the rows of the active company and switches to the other one after activating it', function (): void {
    ['a' => $companyA, 'b' => $companyB] = seedCompaniesWithProbeRows();
    $user = memberOf([$companyA, $companyB]);

    $this->actingAs($user)->withHeaders(companyContextSpaHeaders());

    $this->postJson('/api/v1/active-company', ['company_id' => $companyA->id])->assertNoContent();
    $seenWithA = $this->getJson('/api/v1/probe')->assertOk()->json('labels');

    $this->postJson('/api/v1/active-company', ['company_id' => $companyB->id])->assertNoContent();
    $seenWithB = $this->getJson('/api/v1/probe')->assertOk()->json('labels');

    expect(['a' => $seenWithA, 'b' => $seenWithB])->toBe([
        'a' => ['a-1', 'a-2'],
        'b' => ['b-1', 'b-2'],
    ]);
});

it('does not let a user activate a company they are not a member of, and keeps showing only their own', function (): void {
    ['a' => $companyA, 'b' => $companyB] = seedCompaniesWithProbeRows();
    $user = memberOf([$companyA]);

    $this->actingAs($user)->withHeaders(companyContextSpaHeaders());

    $this->postJson('/api/v1/active-company', ['company_id' => $companyA->id])->assertNoContent();
    $this->postJson('/api/v1/active-company', ['company_id' => $companyB->id])->assertForbidden();
    $seen = $this->getJson('/api/v1/probe')->assertOk()->json('labels');

    expect($seen)->toBe(['a-1', 'a-2']);
});

it('answers 409 when the session has no active company, and enters no context', function (): void {
    ['a' => $companyA] = seedCompaniesWithProbeRows();
    $user = memberOf([$companyA]);

    $response = $this->actingAs($user)
        ->withHeaders(companyContextSpaHeaders())
        ->getJson('/api/v1/probe');

    $response->assertStatus(409);
    $response->assertJsonStructure(['message']);
    expect(app(DatabaseCompanyContext::class)->current())->toBeNull();
});

it('answers 403 and forgets the active company when the membership was removed after activating it', function (): void {
    ['a' => $companyA] = seedCompaniesWithProbeRows();
    $user = memberOf([$companyA]);

    $this->actingAs($user)->withHeaders(companyContextSpaHeaders());
    $this->postJson('/api/v1/active-company', ['company_id' => $companyA->id])->assertNoContent();
    $this->getJson('/api/v1/probe')->assertOk();

    DB::table('company_user')->where('company_id', $companyA->id)->where('user_id', $user->id)->delete();

    $response = $this->getJson('/api/v1/probe');

    $response->assertForbidden();
    $response->assertJsonStructure(['message']);
    expect(session()->has('active_company_id'))->toBeFalse();
});

it('then answers 409 on the next request after the membership was removed (the stale company is gone)', function (): void {
    ['a' => $companyA] = seedCompaniesWithProbeRows();
    $user = memberOf([$companyA]);

    $this->actingAs($user)->withHeaders(companyContextSpaHeaders());
    $this->postJson('/api/v1/active-company', ['company_id' => $companyA->id])->assertNoContent();
    DB::table('company_user')->where('company_id', $companyA->id)->where('user_id', $user->id)->delete();
    $this->getJson('/api/v1/probe')->assertForbidden();

    $this->getJson('/api/v1/probe')->assertStatus(409);
});

it('leaves no company in the database context after any request', function (): void {
    ['a' => $companyA, 'b' => $companyB] = seedCompaniesWithProbeRows();
    $user = memberOf([$companyA]);
    $context = app(DatabaseCompanyContext::class);
    $afterEach = [];

    $this->actingAs($user)->withHeaders(companyContextSpaHeaders());

    $this->getJson('/api/v1/probe')->assertStatus(409);
    $afterEach['no active company'] = $context->current();

    $this->postJson('/api/v1/active-company', ['company_id' => $companyB->id])->assertForbidden();
    $afterEach['activation rejected'] = $context->current();

    $this->postJson('/api/v1/active-company', ['company_id' => $companyA->id])->assertNoContent();
    $afterEach['activation accepted'] = $context->current();

    $this->getJson('/api/v1/probe')->assertOk();
    $afterEach['business request'] = $context->current();

    expect($afterEach)->toBe([
        'no active company' => null,
        'activation rejected' => null,
        'activation accepted' => null,
        'business request' => null,
    ]);
});

it('leaves the context cleared even if the route throws after entering it', function (): void {
    ['a' => $companyA] = seedCompaniesWithProbeRows();
    $user = memberOf([$companyA]);

    $this->actingAs($user)->withHeaders(companyContextSpaHeaders());
    $this->postJson('/api/v1/active-company', ['company_id' => $companyA->id])->assertNoContent();

    $response = $this->getJson('/api/v1/probe-fails');

    $response->assertStatus(500);
    expect(app(DatabaseCompanyContext::class)->current())->toBeNull();
});

it('leaves the context cleared when the exception crosses the middleware (finally, not just a rendered 500)', function (): void {
    ['a' => $companyA] = seedCompaniesWithProbeRows();
    $user = memberOf([$companyA]);

    $this->actingAs($user)->withHeaders(companyContextSpaHeaders());
    $this->postJson('/api/v1/active-company', ['company_id' => $companyA->id])->assertNoContent();

    // With the handler on, Laravel's pipeline renders the exception inside each middleware layer, so $next() returns
    // a 500 and the middleware never sees the throw. Without the handler the exception does cross SetCompanyContext.
    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/api/v1/probe-fails'))
        ->toThrow(RuntimeException::class, 'Probe failure after entering the company context.');
    expect(app(DatabaseCompanyContext::class)->current())->toBeNull();
});

it('does not leak the previous request context into the next one after a failure', function (): void {
    ['a' => $companyA, 'b' => $companyB] = seedCompaniesWithProbeRows();
    $user = memberOf([$companyA, $companyB]);

    $this->actingAs($user)->withHeaders(companyContextSpaHeaders());
    $this->postJson('/api/v1/active-company', ['company_id' => $companyA->id])->assertNoContent();
    $this->getJson('/api/v1/probe-fails')->assertStatus(500);

    $this->postJson('/api/v1/active-company', ['company_id' => $companyB->id])->assertNoContent();
    $seen = $this->getJson('/api/v1/probe')->assertOk()->json('labels');

    expect($seen)->toBe(['b-1', 'b-2']);
});

it('rejects an unauthenticated request with 401 and enters no context', function (): void {
    seedCompaniesWithProbeRows();

    $response = $this->withHeaders(companyContextSpaHeaders())->getJson('/api/v1/probe');

    $response->assertUnauthorized();
    expect(app(DatabaseCompanyContext::class)->current())->toBeNull();
});
