<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;

// POST /api/v1/active-company: the user picks the active company of the session. Sanctum in SPA mode: the request is
// stateful because it carries an Origin inside sanctum.stateful, and the user comes from the session guard (web).
// Sanctum 4.3.3, Laravel 13 docs (Context7 /laravel/docs/__branch__13.x): stateful by Referer/Origin header.
// CSRF is skipped by the framework under tests. There is no personal_access_tokens table: cookie only.

/**
 * Headers of a request coming from the SPA (same origin as the API).
 *
 * @return array<string, string>
 */
function activeCompanySpaHeaders(): array
{
    return ['Origin' => 'http://localhost'];
}

/**
 * Creates a user that is a member of the given companies.
 *
 * @param  list<Company>  $companies
 */
function userMemberOf(array $companies): User
{
    $user = User::factory()->create();

    foreach ($companies as $company) {
        $user->companies()->attach($company->id, ['role' => 'admin']);
    }

    return $user;
}

it('rejects an unauthenticated request with 401', function (): void {
    $company = Company::factory()->create();

    $response = $this->withHeaders(activeCompanySpaHeaders())
        ->postJson('/api/v1/active-company', ['company_id' => $company->id]);

    $response->assertUnauthorized();
});

it('activates a company the user is a member of: 204 and the session stores it', function (): void {
    $companyA = Company::factory()->create();
    $user = userMemberOf([$companyA]);

    $response = $this->actingAs($user)
        ->withHeaders(activeCompanySpaHeaders())
        ->postJson('/api/v1/active-company', ['company_id' => $companyA->id]);

    $response->assertNoContent();
    expect(session('active_company_id'))->toBe($companyA->id);
});

it('replaces the active company when the user activates another one they belong to', function (): void {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = userMemberOf([$companyA, $companyB]);

    $this->actingAs($user)->withHeaders(activeCompanySpaHeaders());
    $this->postJson('/api/v1/active-company', ['company_id' => $companyA->id])->assertNoContent();
    $this->postJson('/api/v1/active-company', ['company_id' => $companyB->id])->assertNoContent();

    expect(session('active_company_id'))->toBe($companyB->id);
});

it('rejects a company the user is not a member of with 403 and leaves the session without company', function (): void {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = userMemberOf([$companyA]);

    $response = $this->actingAs($user)
        ->withHeaders(activeCompanySpaHeaders())
        ->postJson('/api/v1/active-company', ['company_id' => $companyB->id]);

    $response->assertForbidden();
    expect(session()->has('active_company_id'))->toBeFalse();
});

it('keeps the previous active company when activating a company the user is not a member of', function (): void {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $user = userMemberOf([$companyA]);

    $this->actingAs($user)->withHeaders(activeCompanySpaHeaders());
    $this->postJson('/api/v1/active-company', ['company_id' => $companyA->id])->assertNoContent();

    $this->postJson('/api/v1/active-company', ['company_id' => $companyB->id])->assertForbidden();

    expect(session('active_company_id'))->toBe($companyA->id);
});

it('rejects a nonexistent company with 403 and leaves the session unchanged', function (): void {
    $companyA = Company::factory()->create();
    $user = userMemberOf([$companyA]);
    $nonexistentId = $companyA->id + 1000;

    $this->actingAs($user)->withHeaders(activeCompanySpaHeaders());
    $this->postJson('/api/v1/active-company', ['company_id' => $companyA->id])->assertNoContent();

    $this->postJson('/api/v1/active-company', ['company_id' => $nonexistentId])->assertForbidden();

    expect(session('active_company_id'))->toBe($companyA->id);
});

it('answers a nonexistent company exactly like a company the user is not a member of (existence is not revealed)', function (): void {
    $companyA = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $user = userMemberOf([$companyA]);

    $this->actingAs($user)->withHeaders(activeCompanySpaHeaders());
    $notMember = $this->postJson('/api/v1/active-company', ['company_id' => $otherCompany->id]);
    $nonexistent = $this->postJson('/api/v1/active-company', ['company_id' => $otherCompany->id + 1000]);

    expect(['status' => $nonexistent->getStatusCode(), 'body' => $nonexistent->json()])->toBe([
        'status' => $notMember->getStatusCode(),
        'body' => $notMember->json(),
    ]);
    $nonexistent->assertJsonStructure(['message']);
});

it('rejects an invalid payload with 422 and does not touch the session', function (array $payload): void {
    $user = userMemberOf([Company::factory()->create()]);

    $response = $this->actingAs($user)
        ->withHeaders(activeCompanySpaHeaders())
        ->postJson('/api/v1/active-company', $payload);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['company_id']);
    expect(session()->has('active_company_id'))->toBeFalse();
})->with([
    'missing' => [[]],
    'null' => [['company_id' => null]],
    'not numeric' => [['company_id' => 'abc']],
    'decimal' => [['company_id' => 1.5]],
    'zero' => [['company_id' => 0]],
    'negative' => [['company_id' => -1]],
]);

it('answers 401 (not 500) to a bearer token without session, since there are no personal access tokens', function (string $token, bool $fromSpa): void {
    $company = Company::factory()->create();
    $headers = ['Authorization' => 'Bearer '.$token] + ($fromSpa ? activeCompanySpaHeaders() : []);

    $response = $this->withHeaders($headers)
        ->postJson('/api/v1/active-company', ['company_id' => $company->id]);

    $response->assertUnauthorized();
})->with([
    'malformed token from the SPA' => ['abc', true],
    'id|secret token from the SPA' => ['1|0123456789abcdef0123456789abcdef', true],
    'malformed token without SPA headers' => ['abc', false],
    'id|secret token without SPA headers' => ['1|0123456789abcdef0123456789abcdef', false],
]);
