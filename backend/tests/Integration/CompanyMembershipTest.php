<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;

// Constraints of companies and company_user (company_user in the glossary). Inserts go through DB::table so the
// constraint tests do not depend on models or factories. A SQL error aborts the RefreshDatabase transaction in
// Postgres, so each failing statement lives in its own test.
uses(RefreshDatabaseAsOwner::class);

/**
 * Inserts a company and a user and returns their ids.
 *
 * @return array{company_id: int, user_id: int}
 */
function insertCompanyAndUser(): array
{
    $now = now();

    $companyId = DB::table('companies')->insertGetId([
        'name' => 'Constructora Ejemplo SAS',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $userId = DB::table('users')->insertGetId([
        'name' => 'Test User',
        'email' => 'membership-'.uniqid().'@example.com',
        'password' => 'x',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return ['company_id' => (int) $companyId, 'user_id' => (int) $userId];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertMembership(array $overrides): void
{
    $now = now();

    DB::table('company_user')->insert($overrides + [
        'role' => 'admin',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('rejects a role outside admin and accountant (check violation, SQLSTATE 23514)', function (): void {
    $ids = insertCompanyAndUser();

    expect(fn () => insertMembership($ids + ['role' => 'superuser']))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[0])->toBe('23514'));
});

it('accepts the roles admin and accountant', function (): void {
    $ids = insertCompanyAndUser();
    $otherUserId = DB::table('users')->insertGetId([
        'name' => 'Other User',
        'email' => 'other-'.uniqid().'@example.com',
        'password' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    insertMembership($ids + ['role' => 'admin']);
    insertMembership(['company_id' => $ids['company_id'], 'user_id' => (int) $otherUserId, 'role' => 'accountant']);

    expect(DB::table('company_user')->where('company_id', $ids['company_id'])->orderBy('role')->pluck('role')->all())
        ->toBe(['accountant', 'admin']);
});

it('rejects a duplicate membership for the same company and user (unique violation, SQLSTATE 23505)', function (): void {
    $ids = insertCompanyAndUser();
    insertMembership($ids);

    expect(fn () => insertMembership($ids + ['role' => 'accountant']))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[0])->toBe('23505'));
});

it('rejects a membership for a nonexistent company (foreign key violation, SQLSTATE 23503)', function (): void {
    $ids = insertCompanyAndUser();

    expect(fn () => insertMembership(['company_id' => $ids['company_id'] + 1000000, 'user_id' => $ids['user_id']]))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[0])->toBe('23503'));
});

it('rejects a membership for a nonexistent user (foreign key violation, SQLSTATE 23503)', function (): void {
    $ids = insertCompanyAndUser();

    expect(fn () => insertMembership(['company_id' => $ids['company_id'], 'user_id' => $ids['user_id'] + 1000000]))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[0])->toBe('23503'));
});

it('a user with memberships in two companies gets both with the right pivot role, and each company lists the user', function (): void {
    $user = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    DB::table('company_user')->insert([
        ['company_id' => $companyA->id, 'user_id' => $user->id, 'role' => 'admin', 'created_at' => now(), 'updated_at' => now()],
        ['company_id' => $companyB->id, 'user_id' => $user->id, 'role' => 'accountant', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $rolesByCompany = $user->companies()->get()
        ->mapWithKeys(fn (Company $company): array => [$company->id => $company->pivot->role])
        ->all();

    expect($rolesByCompany)->toBe([
        $companyA->id => 'admin',
        $companyB->id => 'accountant',
    ]);

    expect($companyA->users()->pluck('users.id')->all())->toBe([$user->id]);
    expect($companyB->users()->pluck('users.id')->all())->toBe([$user->id]);
});
