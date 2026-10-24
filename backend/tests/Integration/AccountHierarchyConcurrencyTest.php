<?php

declare(strict_types=1);

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTruncationAsOwner;
use Tests\Support\AccountFixtures;

// Concurrency of the account hierarchy rules: two pyme_app connections with real commits (no wrapping transaction).
// A is the default connection and B is a second pyme_app connection registered at runtime, each with its own
// DatabaseCompanyContext. B waits with a short lock_timeout, so waiting for a lock shows up as SQLSTATE 55P03. After A
// ends its transaction, B retries in a new transaction (the aborted one is rolled back) and sees the committed state:
// the rule rejects with 23514 after a commit and lets the operation through after a rollback. The accounts of the
// scenarios are made up and none of them is postable unless the scenario says so, so that only the rule under test
// can reject. DatabaseTruncationAsOwner truncates the tables between tests, since the commits are real.
uses(DatabaseTruncationAsOwner::class);

/**
 * Runs $test with a committed company and two pyme_app connections (A default, B second), each with the company in
 * context. Whatever the test leaves open is rolled back, contexts are left and the second connection is closed in a
 * finally.
 *
 * $test receives: connection A, connection B.
 */
function accountConcurrencyRun(Closure $test): void
{
    $company = Company::factory()->create();
    $userId = User::factory()->create()->id;

    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
    DB::purge('pgsql_second');
    $connectionA = DB::connection();
    $connectionB = DB::connection('pgsql_second');

    $contextA = new DatabaseCompanyContext($connectionA);
    $contextB = new DatabaseCompanyContext($connectionB);

    try {
        $contextA->enter($company->id, $userId);
        $contextB->enter($company->id, $userId);
        $connectionB->statement("set lock_timeout = '300ms'");

        $test($connectionA, $connectionB);
    } finally {
        foreach ([$connectionA, $connectionB] as $connection) {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }
        $contextA->leave();
        DB::purge('pgsql_second');
    }
}

/**
 * Runs $work in a transaction of the connection with real commit. If it throws, rolls back and rethrows.
 */
function accountConcurrencyInTransaction(ConnectionInterface $connection, Closure $work): void
{
    $connection->beginTransaction();
    try {
        $work();
        $connection->commit();
    } catch (Throwable $e) {
        $connection->rollBack();

        throw $e;
    }
}

/**
 * Asserts that the closure fails with the given SQLSTATE, then rolls back whatever the connection has open.
 */
function accountConcurrencyExpectSqlState(ConnectionInterface $connection, Closure $statement, string $sqlState): void
{
    try {
        expect($statement)->toThrow(fn (QueryException $e) => expect($e->errorInfo[0])->toBe($sqlState));
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
}

/**
 * Asserts that the closure fails waiting for a lock (SQLSTATE 55P03, lock_timeout of B), then rolls back the aborted
 * transaction of the connection.
 */
function accountConcurrencyExpectLockWait(ConnectionInterface $connection, Closure $statement): void
{
    accountConcurrencyExpectSqlState($connection, $statement, '55P03');
}

/**
 * Asserts that, in a new transaction of the connection, the closure fails with 23514 (hierarchy rule, check_violation).
 */
function accountConcurrencyExpectRuleViolation(ConnectionInterface $connection, Closure $statement): void
{
    $connection->beginTransaction();
    accountConcurrencyExpectSqlState($connection, $statement, '23514');
}

/**
 * Marks the account postable with the connection.
 */
function accountConcurrencyMarkPostable(ConnectionInterface $connection, int $id): void
{
    $connection->table('account')->where('id', $id)->update(['is_postable' => true]);
}

/**
 * Sets the parent of the account with the connection.
 */
function accountConcurrencySetParent(ConnectionInterface $connection, int $id, int $parentId): void
{
    $connection->table('account')->where('id', $id)->update(['parent_id' => $parentId]);
}

/**
 * Account as stored, read with the connection (committed state only, since the connection is not the one writing).
 *
 * @return array{is_postable: bool, parent_id: int|null, children: int}
 */
function accountConcurrencyStored(ConnectionInterface $connection, int $id): array
{
    $row = $connection->selectOne(
        'select is_postable, parent_id, (select count(*) from account c where c.parent_id = a.id) as children from account a where a.id = ?',
        [$id],
    );

    return [
        'is_postable' => (bool) $row->is_postable,
        'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
        'children' => (int) $row->children,
    ];
}

// A creates a child under P while B marks P postable.

it('makes B wait when it marks the parent postable while A creates a child without commit, and rejects it after A commits (SQLSTATE 23514)', function (): void {
    accountConcurrencyRun(function (ConnectionInterface $a, ConnectionInterface $b): void {
        $parentId = AccountFixtures::insert($a, 'T1');

        $a->beginTransaction();
        AccountFixtures::insert($a, 'T1.1', $parentId);

        $b->beginTransaction();
        accountConcurrencyExpectLockWait($b, fn () => accountConcurrencyMarkPostable($b, $parentId));

        $a->commit();
        accountConcurrencyExpectRuleViolation($b, fn () => accountConcurrencyMarkPostable($b, $parentId));

        expect(accountConcurrencyStored($b, $parentId))->toBe(['is_postable' => false, 'parent_id' => null, 'children' => 1]);
    });
});

it('lets B mark the parent postable when A rolls back the creation of the child', function (): void {
    accountConcurrencyRun(function (ConnectionInterface $a, ConnectionInterface $b): void {
        $parentId = AccountFixtures::insert($a, 'T1');

        $a->beginTransaction();
        AccountFixtures::insert($a, 'T1.1', $parentId);

        $b->beginTransaction();
        accountConcurrencyExpectLockWait($b, fn () => accountConcurrencyMarkPostable($b, $parentId));

        $a->rollBack();
        accountConcurrencyInTransaction($b, fn () => accountConcurrencyMarkPostable($b, $parentId));

        expect(accountConcurrencyStored($b, $parentId))->toBe(['is_postable' => true, 'parent_id' => null, 'children' => 0]);
    });
});

// A marks P postable while B creates a child under P.

it('makes B wait when it creates a child while A marks the parent postable without commit, and rejects it after A commits (SQLSTATE 23514)', function (): void {
    accountConcurrencyRun(function (ConnectionInterface $a, ConnectionInterface $b): void {
        $parentId = AccountFixtures::insert($a, 'T1');

        $a->beginTransaction();
        accountConcurrencyMarkPostable($a, $parentId);

        $b->beginTransaction();
        accountConcurrencyExpectLockWait($b, fn () => AccountFixtures::insert($b, 'T1.1', $parentId));

        $a->commit();
        accountConcurrencyExpectRuleViolation($b, fn () => AccountFixtures::insert($b, 'T1.1', $parentId));

        expect(accountConcurrencyStored($b, $parentId))->toBe(['is_postable' => true, 'parent_id' => null, 'children' => 0]);
    });
});

it('lets B create the child when A rolls back the marking of the parent as postable', function (): void {
    accountConcurrencyRun(function (ConnectionInterface $a, ConnectionInterface $b): void {
        $parentId = AccountFixtures::insert($a, 'T1');

        $a->beginTransaction();
        accountConcurrencyMarkPostable($a, $parentId);

        $b->beginTransaction();
        accountConcurrencyExpectLockWait($b, fn () => AccountFixtures::insert($b, 'T1.1', $parentId));

        $a->rollBack();
        accountConcurrencyInTransaction($b, fn () => AccountFixtures::insert($b, 'T1.1', $parentId));

        expect(accountConcurrencyStored($b, $parentId))->toBe(['is_postable' => false, 'parent_id' => null, 'children' => 1]);
    });
});

// Crossed moves: A puts X under Y while B puts Y under X.

it('makes B wait when it moves Y under X while A moves X under Y without commit, and rejects the cycle after A commits (SQLSTATE 23514)', function (): void {
    accountConcurrencyRun(function (ConnectionInterface $a, ConnectionInterface $b): void {
        $x = AccountFixtures::insert($a, 'T1');
        $y = AccountFixtures::insert($a, 'T2');

        $a->beginTransaction();
        accountConcurrencySetParent($a, $x, $y);

        $b->beginTransaction();
        accountConcurrencyExpectLockWait($b, fn () => accountConcurrencySetParent($b, $y, $x));

        $a->commit();
        accountConcurrencyExpectRuleViolation($b, fn () => accountConcurrencySetParent($b, $y, $x));

        expect(['x' => accountConcurrencyStored($b, $x)['parent_id'], 'y' => accountConcurrencyStored($b, $y)['parent_id']])
            ->toBe(['x' => $y, 'y' => null]);
    });
});

it('lets B move Y under X when A rolls back the move of X under Y', function (): void {
    accountConcurrencyRun(function (ConnectionInterface $a, ConnectionInterface $b): void {
        $x = AccountFixtures::insert($a, 'T1');
        $y = AccountFixtures::insert($a, 'T2');

        $a->beginTransaction();
        accountConcurrencySetParent($a, $x, $y);

        $b->beginTransaction();
        accountConcurrencyExpectLockWait($b, fn () => accountConcurrencySetParent($b, $y, $x));

        $a->rollBack();
        accountConcurrencyInTransaction($b, fn () => accountConcurrencySetParent($b, $y, $x));

        expect(['x' => accountConcurrencyStored($b, $x)['parent_id'], 'y' => accountConcurrencyStored($b, $y)['parent_id']])
            ->toBe(['x' => null, 'y' => $x]);
    });
});
