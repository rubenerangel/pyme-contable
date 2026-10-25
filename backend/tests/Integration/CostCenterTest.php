<?php

declare(strict_types=1);

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Models\CostCenter;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;
use Tests\Support\AccountFixtures;
use Tests\Support\MasterDataFixtures;

// The cost_center table (cost centers of a company): CHECK and NOT NULL constraints, defaults, uniqueness per company
// and the CostCenter model casts. The privileges of pyme_app, RLS and the audit triggers are covered by
// AppPermissionsTest and TenantIsolationTest, which read them from the catalog. Every value is made up on purpose
// ('CC1', 'Test cost center'). The company context is set with DatabaseCompanyContext and company_id is never passed.
// A SQL error aborts the RefreshDatabase transaction in Postgres, so each failing statement lives in its own test, and
// a valid control row is inserted first so that the only difference with the rejected row is the rule under test.
uses(RefreshDatabaseAsOwner::class);

/**
 * Asserts that the statement fails with a QueryException carrying the given SQLSTATE. For a CHECK (23514) or a UNIQUE
 * (23505) violation the constraint name is mandatory and must appear in the Postgres message, so that the test fails
 * if the constraint under test is dropped and another one happens to reject the row.
 */
function costCenterTestExpectSqlState(Closure $statement, string $sqlState, ?string $constraint = null): void
{
    $kind = match ($sqlState) {
        '23514' => 'check',
        '23505' => 'unique',
        default => null,
    };
    if ($kind !== null && $constraint === null) {
        throw new InvalidArgumentException("SQLSTATE {$sqlState} requires the name of the expected constraint.");
    }

    expect($statement)->toThrow(function (QueryException $e) use ($sqlState, $kind, $constraint): void {
        expect($e->errorInfo[0])->toBe($sqlState);

        if ($kind !== null) {
            expect($e->errorInfo[2])->toMatch('/violates '.$kind.' constraint "'.preg_quote((string) $constraint, '/').'"/');
        }
    });
}

// Insert and defaults.

it('inserts a cost center with the company of the context, and applies the defaults', function (): void {
    $companyId = AccountFixtures::enterNewCompany();

    DB::table('cost_center')->insert(MasterDataFixtures::costCenterRow());

    $row = DB::selectOne('select company_id, code, name, is_active from cost_center');

    expect([(int) $row->company_id, $row->code, $row->name, (bool) $row->is_active])
        ->toBe([$companyId, 'CC1', 'Test cost center', true]);
});

it('keeps is_active as given when it is false', function (): void {
    AccountFixtures::enterNewCompany();

    DB::table('cost_center')->insert(MasterDataFixtures::costCenterRow(['is_active' => false]));

    expect((bool) DB::table('cost_center')->value('is_active'))->toBeFalse();
});

// CHECK and NOT NULL constraints.

it('rejects an invalid code or name with the CHECK constraint of its column (SQLSTATE 23514)', function (string $column, string $value, string $control, string $constraint): void {
    AccountFixtures::enterNewCompany();
    DB::table('cost_center')->insert(MasterDataFixtures::costCenterRow([$column => $control]));

    costCenterTestExpectSqlState(
        fn () => DB::table('cost_center')->insert(MasterDataFixtures::costCenterRow(['code' => 'CC2', $column => $value])),
        '23514',
        $constraint,
    );
})->with([
    'empty code' => ['code', '', 'CC1', 'cost_center_code_check'],
    'blank code' => ['code', '   ', 'CC1', 'cost_center_code_check'],
    'code with a leading space' => ['code', ' CC2', 'CC2', 'cost_center_code_check'],
    'code with a trailing space' => ['code', 'CC2 ', 'CC2', 'cost_center_code_check'],
    'empty name' => ['name', '', 'Test cost center', 'cost_center_name_check'],
    'blank name' => ['name', '   ', 'Test cost center', 'cost_center_name_check'],
]);

it('rejects an insert that leaves a required column out or NULL (NOT NULL, SQLSTATE 23502)', function (string $column, bool $explicitNull): void {
    AccountFixtures::enterNewCompany();
    DB::table('cost_center')->insert(MasterDataFixtures::costCenterRow(['code' => 'CC0']));

    $row = MasterDataFixtures::costCenterRow();
    if ($explicitNull) {
        $row[$column] = null;
    } else {
        unset($row[$column]);
    }

    costCenterTestExpectSqlState(fn () => DB::table('cost_center')->insert($row), '23502');
})->with([
    'code omitted' => ['code', false],
    'name omitted' => ['name', false],
    'code NULL' => ['code', true],
    'name NULL' => ['name', true],
    'is_active NULL' => ['is_active', true],
]);

// Uniqueness per company.

it('rejects a second cost center with the same code in the company (UNIQUE, SQLSTATE 23505)', function (): void {
    AccountFixtures::enterNewCompany();
    DB::table('cost_center')->insert(MasterDataFixtures::costCenterRow());

    costCenterTestExpectSqlState(
        fn () => DB::table('cost_center')->insert(MasterDataFixtures::costCenterRow(['name' => 'Test other name'])),
        '23505',
        'cost_center_company_id_code_key',
    );
});

it('accepts another code in the same company', function (): void {
    AccountFixtures::enterNewCompany();
    DB::table('cost_center')->insert(MasterDataFixtures::costCenterRow());

    DB::table('cost_center')->insert(MasterDataFixtures::costCenterRow(['code' => 'CC2']));

    expect(DB::table('cost_center')->count())->toBe(2);
});

it('accepts the same code in another company, and each company sees only its own cost centers', function (): void {
    $context = app(DatabaseCompanyContext::class);

    $companyA = AccountFixtures::enterNewCompany();
    DB::table('cost_center')->insert(MasterDataFixtures::costCenterRow());

    $companyB = AccountFixtures::enterNewCompany();
    DB::table('cost_center')->insert(MasterDataFixtures::costCenterRow());

    $seenByB = DB::table('cost_center')->pluck('company_id')->map(fn ($id) => (int) $id)->all();
    $context->enter($companyA, User::factory()->create()->id);
    $seenByA = DB::table('cost_center')->pluck('company_id')->map(fn ($id) => (int) $id)->all();

    expect(['a' => $seenByA, 'b' => $seenByB])->toBe(['a' => [$companyA], 'b' => [$companyB]]);
});

// Model.

it('stores a cost center created through the model in the company of the context', function (): void {
    $companyId = AccountFixtures::enterNewCompany();

    $costCenter = CostCenter::create(MasterDataFixtures::costCenterRow());

    $stored = DB::selectOne('select company_id, code, is_active from cost_center where id = ?', [$costCenter->id]);

    expect([(int) $stored->company_id, $stored->code, (bool) $stored->is_active])->toBe([$companyId, 'CC1', true]);
});

it('casts is_active to a strict bool in memory and has no timestamps (without a cast the integers would stay as they are)', function (): void {
    // No database: pdo_pgsql already returns bool, so only integers handed to the model can prove the cast exists.
    $on = new CostCenter(['is_active' => 1]);
    $off = new CostCenter(['is_active' => 0]);

    expect([
        'from 1' => $on->is_active,
        'from 0' => $off->is_active,
        'timestamps' => $on->usesTimestamps(),
        'table' => $on->getTable(),
    ])->toBe(['from 1' => true, 'from 0' => false, 'timestamps' => false, 'table' => 'cost_center']);
});
