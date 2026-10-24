<?php

declare(strict_types=1);

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;
use Tests\Support\AccountFixtures;

// The account table (chart of accounts with hierarchy): CHECK and NOT NULL constraints, uniqueness per company, the
// hierarchy trigger (a parent cannot be postable, no cycles, a postable account has no children), the composite foreign
// key, delete restrictions, the privileges of pyme_app and the audit trail. The test chart is made up on purpose
// ('T1', 'T1.1', 'Test assets') and does not resemble the PUC. The company context is set with DatabaseCompanyContext
// and company_id is never passed. A SQL error aborts the RefreshDatabase transaction in Postgres, so each failing
// statement lives in its own test. The hierarchy rules all raise check_violation (23514) and the same SQLSTATE can come
// from a different rule, so every failing test sets up a state where only the rule under test can fire (for example,
// the chains of the cycle tests have no postable account) and the neighbouring valid move is checked as a control.
// Concurrency of the hierarchy rules lives in AccountHierarchyConcurrencyTest.
uses(RefreshDatabaseAsOwner::class);

/**
 * Asserts that the statement fails with a QueryException carrying the given SQLSTATE.
 */
function accountTestExpectSqlState(Closure $statement, string $sqlState): void
{
    expect($statement)->toThrow(fn (QueryException $e) => expect($e->errorInfo[0])->toBe($sqlState));
}

/**
 * A valid account row (a non-postable root) with the given columns replaced.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function accountTestRow(array $overrides = []): array
{
    return array_merge([
        'code' => 'T1',
        'name' => 'Test assets',
        'is_postable' => false,
        'normal_balance' => 'debit',
    ], $overrides);
}

/**
 * Inserts an account in the context of the default connection and returns its id.
 */
function accountTestInsert(string $code, ?int $parentId = null, bool $isPostable = false): int
{
    return AccountFixtures::insert(DB::connection(), $code, $parentId, $isPostable);
}

/**
 * Parent of the account as stored.
 */
function accountTestParentOf(int $id): ?int
{
    $parentId = DB::table('account')->where('id', $id)->value('parent_id');

    return $parentId === null ? null : (int) $parentId;
}

/**
 * Chain T1 (root) > T1.1 > T1.1.1 with no postable account, so that only the cycle rule can reject a move.
 *
 * @return array{a: int, b: int, c: int}
 */
function accountTestChain(): array
{
    $a = accountTestInsert('T1');
    $b = accountTestInsert('T1.1', $a);
    $c = accountTestInsert('T1.1.1', $b);

    return ['a' => $a, 'b' => $b, 'c' => $c];
}

// Insert and defaults.

it('inserts a root account and a child with the company of the context, and applies the defaults', function (): void {
    $companyId = AccountFixtures::enterNewCompany();

    $rootId = accountTestInsert('T1');
    $childId = accountTestInsert('T1.1', $rootId, isPostable: true);

    $rows = [];
    foreach (DB::select('select * from account order by id') as $row) {
        $rows[] = [
            'id' => (int) $row->id,
            'company_id' => (int) $row->company_id,
            'code' => $row->code,
            'name' => $row->name,
            'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
            'is_postable' => (bool) $row->is_postable,
            'normal_balance' => $row->normal_balance,
            'requires_third_party' => (bool) $row->requires_third_party,
            'requires_cost_center' => (bool) $row->requires_cost_center,
            'requires_construction_project' => (bool) $row->requires_construction_project,
            'requires_municipality' => (bool) $row->requires_municipality,
            'requires_tax_detail' => (bool) $row->requires_tax_detail,
            'is_active' => (bool) $row->is_active,
        ];
    }

    $defaults = [
        'requires_third_party' => false,
        'requires_cost_center' => false,
        'requires_construction_project' => false,
        'requires_municipality' => false,
        'requires_tax_detail' => false,
        'is_active' => true,
    ];

    expect($rows)->toBe([
        [
            'id' => $rootId,
            'company_id' => $companyId,
            'code' => 'T1',
            'name' => 'Test T1',
            'parent_id' => null,
            'is_postable' => false,
            'normal_balance' => 'debit',
            ...$defaults,
        ],
        [
            'id' => $childId,
            'company_id' => $companyId,
            'code' => 'T1.1',
            'name' => 'Test T1.1',
            'parent_id' => $rootId,
            'is_postable' => true,
            'normal_balance' => 'debit',
            ...$defaults,
        ],
    ]);
});

// CHECK and NOT NULL constraints.

it('rejects invalid code, name or normal_balance with a CHECK constraint (SQLSTATE 23514)', function (string $column, string $value): void {
    AccountFixtures::enterNewCompany();

    accountTestExpectSqlState(fn () => DB::table('account')->insert(accountTestRow([$column => $value])), '23514');
})->with([
    'empty code' => ['code', ''],
    'code with a leading space' => ['code', ' T1'],
    'code with a trailing space' => ['code', 'T1 '],
    'empty name' => ['name', ''],
    'blank name' => ['name', '   '],
    'unknown normal_balance' => ['normal_balance', 'both'],
    'normal_balance with a capital' => ['normal_balance', 'Debit'],
]);

it('rejects an insert that omits is_postable or normal_balance (NOT NULL, no default, SQLSTATE 23502)', function (string $omitted): void {
    AccountFixtures::enterNewCompany();

    accountTestExpectSqlState(
        fn () => DB::table('account')->insert(array_diff_key(accountTestRow(), [$omitted => true])),
        '23502',
    );
})->with([
    'is_postable' => ['is_postable'],
    'normal_balance' => ['normal_balance'],
]);

it('accepts both normal_balance values', function (): void {
    AccountFixtures::enterNewCompany();

    AccountFixtures::insert(DB::connection(), 'T1', normalBalance: 'debit');
    AccountFixtures::insert(DB::connection(), 'T2', normalBalance: 'credit');

    expect(DB::table('account')->orderBy('code')->pluck('normal_balance')->all())->toBe(['debit', 'credit']);
});

// Uniqueness per company.

it('rejects a second account with the same code in the company (UNIQUE, SQLSTATE 23505)', function (): void {
    AccountFixtures::enterNewCompany();
    accountTestInsert('T1');

    accountTestExpectSqlState(fn () => accountTestInsert('T1'), '23505');
});

it('accepts the same code in another company, and each company sees only its own accounts', function (): void {
    $context = app(DatabaseCompanyContext::class);

    $companyA = AccountFixtures::enterNewCompany();
    accountTestInsert('T1');

    $companyB = AccountFixtures::enterNewCompany();
    accountTestInsert('T1');

    $seenByB = DB::table('account')->pluck('company_id')->map(fn ($id) => (int) $id)->all();
    $context->enter($companyA, User::factory()->create()->id);
    $seenByA = DB::table('account')->pluck('company_id')->map(fn ($id) => (int) $id)->all();

    expect(['a' => $seenByA, 'b' => $seenByB])->toBe(['a' => [$companyA], 'b' => [$companyB]]);
});

// Hierarchy.

it('rejects an account that is its own parent (SQLSTATE 23514)', function (): void {
    AccountFixtures::enterNewCompany();
    $id = accountTestInsert('T1');

    accountTestExpectSqlState(fn () => DB::table('account')->where('id', $id)->update(['parent_id' => $id]), '23514');
});

it('rejects creating a child under a postable account (SQLSTATE 23514)', function (): void {
    AccountFixtures::enterNewCompany();
    $parentId = accountTestInsert('T1', isPostable: true);

    accountTestExpectSqlState(fn () => accountTestInsert('T1.1', $parentId), '23514');
});

it('rejects moving an existing account under a postable account (SQLSTATE 23514)', function (): void {
    AccountFixtures::enterNewCompany();
    $postableId = accountTestInsert('T1', isPostable: true);
    $movedId = accountTestInsert('T2');

    accountTestExpectSqlState(fn () => DB::table('account')->where('id', $movedId)->update(['parent_id' => $postableId]), '23514');
});

it('moves an existing account under a non-postable account', function (): void {
    AccountFixtures::enterNewCompany();
    $parentId = accountTestInsert('T1');
    $movedId = accountTestInsert('T2');

    $affected = DB::table('account')->where('id', $movedId)->update(['parent_id' => $parentId]);

    expect(['affected' => $affected, 'parent_id' => accountTestParentOf($movedId)])->toBe([
        'affected' => 1,
        'parent_id' => $parentId,
    ]);
});

it('rejects marking postable an account that has children (SQLSTATE 23514)', function (): void {
    AccountFixtures::enterNewCompany();
    $parentId = accountTestInsert('T1');
    accountTestInsert('T1.1', $parentId);

    accountTestExpectSqlState(fn () => DB::table('account')->where('id', $parentId)->update(['is_postable' => true]), '23514');
});

it('marks postable an account that has no children', function (): void {
    AccountFixtures::enterNewCompany();
    $id = accountTestInsert('T1');

    $affected = DB::table('account')->where('id', $id)->update(['is_postable' => true]);

    expect(['affected' => $affected, 'is_postable' => (bool) DB::table('account')->where('id', $id)->value('is_postable')])->toBe([
        'affected' => 1,
        'is_postable' => true,
    ]);
});

it('rejects moving an ancestor under its own descendant (SQLSTATE 23514)', function (string $target): void {
    AccountFixtures::enterNewCompany();
    $chain = accountTestChain();

    accountTestExpectSqlState(
        fn () => DB::table('account')->where('id', $chain['a'])->update(['parent_id' => $chain[$target]]),
        '23514',
    );
})->with([
    'under its grandchild' => ['c'],
    'under its child' => ['b'],
]);

it('moves a descendant up under an ancestor, which is not a cycle', function (): void {
    AccountFixtures::enterNewCompany();
    $chain = accountTestChain();

    $affected = DB::table('account')->where('id', $chain['c'])->update(['parent_id' => $chain['a']]);

    expect(['affected' => $affected, 'parent_id' => accountTestParentOf($chain['c'])])->toBe([
        'affected' => 1,
        'parent_id' => $chain['a'],
    ]);
});

// Composite foreign key (company_id, parent_id).

it('rejects a parent that belongs to another company (composite FK, SQLSTATE 23503)', function (): void {
    AccountFixtures::enterNewCompany();
    $foreignParentId = accountTestInsert('T1');

    AccountFixtures::enterNewCompany();

    accountTestExpectSqlState(fn () => accountTestInsert('T1.1', $foreignParentId), '23503');
});

it('rejects a parent that does not exist (composite FK, SQLSTATE 23503)', function (): void {
    AccountFixtures::enterNewCompany();

    accountTestExpectSqlState(fn () => accountTestInsert('T1.1', 999_999_999), '23503');
});

// Delete.

it('rejects deleting an account that has children (FK without cascade, SQLSTATE 23503)', function (): void {
    AccountFixtures::enterNewCompany();
    $parentId = accountTestInsert('T1');
    accountTestInsert('T1.1', $parentId);

    accountTestExpectSqlState(fn () => DB::table('account')->where('id', $parentId)->delete(), '23503');
});

it('deletes an account that has no children', function (): void {
    AccountFixtures::enterNewCompany();
    $parentId = accountTestInsert('T1');
    $leafId = accountTestInsert('T1.1', $parentId);

    $affected = DB::table('account')->where('id', $leafId)->delete();

    expect(['affected' => $affected, 'left' => DB::table('account')->pluck('id')->map(fn ($id) => (int) $id)->all()])->toBe([
        'affected' => 1,
        'left' => [$parentId],
    ]);
});

// Privileges of pyme_app (the full per-column map is in AppPermissionsTest).

it('does not let pyme_app update company_id or id (no UPDATE privilege on the column, SQLSTATE 42501)', function (string $column): void {
    $companyId = AccountFixtures::enterNewCompany();
    $id = accountTestInsert('T1');
    $newValue = $column === 'id' ? 999_999 : $companyId;

    accountTestExpectSqlState(fn () => DB::table('account')->where('id', $id)->update([$column => $newValue]), '42501');
})->with([
    'company_id' => ['company_id'],
    'id' => ['id'],
]);

// Audit trail.

/**
 * Audit rows of the company in context for the account table, oldest first, with the values decoded.
 *
 * @return list<array{operation: string, record_id: int|null, old_values: array<string, mixed>|null, new_values: array<string, mixed>|null}>
 */
function accountTestAuditRows(): array
{
    return array_map(fn (object $row): array => [
        'operation' => $row->operation,
        'record_id' => $row->record_id === null ? null : (int) $row->record_id,
        'old_values' => $row->old_values === null ? null : json_decode($row->old_values, true, 512, JSON_THROW_ON_ERROR),
        'new_values' => $row->new_values === null ? null : json_decode($row->new_values, true, 512, JSON_THROW_ON_ERROR),
    ], DB::select(<<<'SQL'
        select operation, record_id, old_values::text as old_values, new_values::text as new_values
        from audit_log
        where table_name = 'account'
        order by id
        SQL));
}

it('records the INSERT of an account in audit_log with its new values', function (): void {
    $companyId = AccountFixtures::enterNewCompany();
    $id = accountTestInsert('T1');

    $rows = accountTestAuditRows();

    expect(array_column($rows, 'operation'))->toBe(['INSERT']);
    expect($rows[0]['record_id'])->toBe($id);
    expect($rows[0]['old_values'])->toBeNull();
    expect([$rows[0]['new_values']['code'] ?? null, $rows[0]['new_values']['company_id'] ?? null])->toBe(['T1', $companyId]);
});

it('records the UPDATE of an account in audit_log with the old and the new name', function (): void {
    AccountFixtures::enterNewCompany();
    $id = accountTestInsert('T1');

    DB::table('account')->where('id', $id)->update(['name' => 'Test renamed']);

    $rows = accountTestAuditRows();

    expect(array_column($rows, 'operation'))->toBe(['INSERT', 'UPDATE']);
    expect($rows[1]['record_id'])->toBe($id);
    expect([$rows[1]['old_values']['name'] ?? null, $rows[1]['new_values']['name'] ?? null])->toBe(['Test T1', 'Test renamed']);
});

it('records the DELETE of an account in audit_log with its old values', function (): void {
    AccountFixtures::enterNewCompany();
    $id = accountTestInsert('T1');

    DB::table('account')->where('id', $id)->delete();

    $rows = accountTestAuditRows();

    expect(array_column($rows, 'operation'))->toBe(['INSERT', 'DELETE']);
    expect($rows[1]['record_id'])->toBe($id);
    expect($rows[1]['new_values'])->toBeNull();
    expect($rows[1]['old_values']['code'] ?? null)->toBe('T1');
});
