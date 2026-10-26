<?php

declare(strict_types=1);

use App\Domain\Ledger\PostingLockTimeout;
use App\Infrastructure\Ledger\AccountLocks;
use App\Infrastructure\Ledger\DocumentCounters;
use App\Infrastructure\Ledger\PostingTransaction;
use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTruncationAsOwner;
use Tests\Support\AccountFixtures;
use Tests\Support\AccountLocksProcess;

// Posting lock protocol under concurrency: PostingTransaction fixes lock_timeout of its transaction and maps the wait to
// PostingLockTimeout, and AccountLocks takes the accounts FOR SHARE in ascending id order. Two pyme_app connections with
// real commits (no wrapping transaction): A is the default connection and B is a second pyme_app connection registered
// at runtime, each with its own DatabaseCompanyContext. DatabaseTruncationAsOwner truncates the tables between tests,
// since the commits are real. B does not set a session lock_timeout unless the test says so: E1 must see the 5s of
// PostingTransaction, and the others use 300ms so that a wait shows up quickly as SQLSTATE 55P03.
uses(DatabaseTruncationAsOwner::class);

/**
 * Runs $test with a committed company that has a document type 'INV' and two postable accounts ('T1' with the lower id
 * and 'T2' with the higher one), and two pyme_app connections (A default, B second) with the company in context.
 * Whatever the test leaves open is rolled back, contexts are left and the second connection is closed in a finally.
 *
 * $test receives: fixtures (type, low, high ids, company and user ids), connection A, connection B.
 *
 * @param  Closure(array{type: int, low: int, high: int, company: int, user: int}, ConnectionInterface, ConnectionInterface): void  $test
 */
function postingLockRun(Closure $test): void
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

        $typeId = (int) $connectionA->table('document_type')->insertGetId([
            'code' => 'INV',
            'name' => 'Invoice',
            'resets_yearly' => true,
        ]);
        $low = AccountFixtures::insert($connectionA, 'T1', null, true);
        $high = AccountFixtures::insert($connectionA, 'T2', null, true);

        $test(
            ['type' => $typeId, 'low' => $low, 'high' => $high, 'company' => $company->id, 'user' => $userId],
            $connectionA,
            $connectionB,
        );
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
 * Runs the closure and returns the SQLSTATE of the QueryException it throws, or null if it throws nothing else.
 * Does not roll back: the locks of the aborted transaction stay until the caller ends it.
 */
function postingLockSqlStateOf(Closure $statement): ?string
{
    try {
        $statement();
    } catch (QueryException $e) {
        return $e->errorInfo[0];
    }

    return null;
}

/**
 * Waits until the backend of the child process is blocked by the transaction of A, so the test does not depend on
 * timing. Fails after 5 seconds. Asked from A, which also proves that the child is in A's database.
 */
function postingLockAwaitBlockedBy(ConnectionInterface $a, int $blockedPid): void
{
    $deadline = microtime(true) + 5.0;

    do {
        $row = $a->selectOne('select pg_backend_pid() = any(pg_blocking_pids(?::int)) as blocked', [$blockedPid]);
        if ((bool) $row->blocked) {
            return;
        }
        usleep(20_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException(sprintf('the lock child, backend pid %d, was never blocked by A', $blockedPid));
}

it('throws LogicException from AccountLocks outside a transaction', function (): void {
    postingLockRun(function (array $fixtures, ConnectionInterface $connectionA): void {
        $locks = new AccountLocks($connectionA);

        $thrown = null;
        try {
            $locks->lockForPosting([$fixtures['low'], $fixtures['high']]);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeInstanceOf(LogicException::class);
    });
});

it('fails with PostingLockTimeout after about 5s when another transaction holds the document counter, without hanging', function (): void {
    postingLockRun(function (array $fixtures, ConnectionInterface $connectionA, ConnectionInterface $connectionB): void {
        $typeId = $fixtures['type'];
        $countersA = new DocumentCounters($connectionA);
        $countersB = new DocumentCounters($connectionB);
        $posting = new PostingTransaction($connectionB);

        // Safety net only: without the SET LOCAL of PostingTransaction, B would wait here and fail with 57014 instead
        // of hanging the suite. Session lock_timeout stays at its default on purpose.
        $connectionB->statement("set statement_timeout = '15s'");

        // The counter row must exist before A holds it, otherwise B would wait on the insert instead of the row lock.
        $connectionA->beginTransaction();
        $countersA->next($typeId, 2026);
        $connectionA->commit();

        $connectionA->beginTransaction();
        $takenByA = $countersA->next($typeId, 2026);

        $thrown = null;
        $startedAt = hrtime(true);
        try {
            $posting->run(fn () => $countersB->next($typeId, 2026));
        } catch (Throwable $e) {
            $thrown = $e;
        }
        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        expect($thrown)->toBeInstanceOf(PostingLockTimeout::class);
        $previous = $thrown->getPrevious();
        expect($previous)->toBeInstanceOf(QueryException::class);
        expect($previous->errorInfo[0])->toBe('55P03');

        expect($elapsedSeconds)->toBeGreaterThanOrEqual(4.5);
        expect($elapsedSeconds)->toBeLessThanOrEqual(10.0);

        expect([
            'transactionLevelOfB' => $connectionB->transactionLevel(),
            'lockTimeoutOfB' => $connectionB->selectOne('show lock_timeout')->lock_timeout,
        ])->toBe([
            'transactionLevelOfB' => 0,
            'lockTimeoutOfB' => '0',
        ]);

        // A commits and B tries again: it gets the number after A's, with no gap.
        $connectionA->commit();
        $takenByBAfterwards = $posting->run(fn () => $countersB->next($typeId, 2026));

        expect(['a' => $takenByA, 'b' => $takenByBAfterwards])->toBe(['a' => 2, 'b' => 3]);
    });
});

it('locks accounts in ascending id order whatever the input order', function (): void {
    postingLockRun(function (array $fixtures, ConnectionInterface $connectionA, ConnectionInterface $connectionB): void {
        ['low' => $low, 'high' => $high] = $fixtures;
        expect($low)->toBeLessThan($high);

        // Invert the physical order: the new version of low lands after high (a HOT update puts it in the same page with
        // a higher item number, otherwise in a later page). name does not fire the hierarchy rules. Without this, low is
        // both the lowest id and the first row inserted, so any plan returns it first even with no ORDER BY, and the test
        // could not tell whether AccountLocks sorts.
        $connectionA->update('update account set name = ? where id = ?', ['Test T1 renamed', $low]);

        // B must be a separate process that is still waiting while A probes. A probe run after B failed with 55P03 sees
        // no lock of B whatever the order: the error aborts the statement and Postgres releases its locks before the
        // ROLLBACK. The data is committed (no wrapping transaction), so the child sees it through its own connection.
        $child = null;
        try {
            // Precondition, in A's transaction before its lock: with index and bitmap scans off the plan is a sequential
            // scan, and without ORDER BY it must return the higher id first. If not, the physical order was not inverted
            // and this test would pass whether or not AccountLocks has ORDER BY.
            $connectionA->beginTransaction();
            $connectionA->statement('set local enable_indexscan = off');
            $connectionA->statement('set local enable_bitmapscan = off');
            $firstWithoutOrderBy = $connectionA->selectOne(
                'select id from account where id = any(?::bigint[])',
                ['{'.$low.','.$high.'}'],
                false,
            );
            expect((int) $firstWithoutOrderBy->id)->toBe(
                $high,
                'precondition: without ORDER BY the sequential scan must return the higher id first; the physical order was not inverted',
            );

            // A holds the lower account exclusively.
            $connectionA->select('select id from account where id = ? for update', [$low], false);

            // B asks for [high, low], the reverse of the ascending order, through the real AccountLocks.
            $child = AccountLocksProcess::start($fixtures['company'], $fixtures['user'], [$high, $low]);
            postingLockAwaitBlockedBy($connectionA, $child->pid());

            // B is still waiting: if it had taken high before waiting for low, this NOWAIT would fail with 55P03.
            $rowsSeenByA = $connectionA->select('select id from account where id = ? for update nowait', [$high], false);

            $connectionA->rollBack();
            $resultOfB = $child->result();
        } finally {
            while ($connectionA->transactionLevel() > 0) {
                $connectionA->rollBack();
            }
            $child?->close();
        }

        expect([
            'highLockedByA' => array_map(fn (object $row): int => (int) $row->id, $rowsSeenByA),
            'resultOfB' => $resultOfB,
        ])->toBe([
            'highLockedByA' => [$high],
            'resultOfB' => 'ok',
        ]);
    });
});

it('sees the shared lock of lockForPosting (control of the probe in the ascending order test)', function (): void {
    postingLockRun(function (array $fixtures, ConnectionInterface $connectionA, ConnectionInterface $connectionB): void {
        $high = $fixtures['high'];
        $locksB = new AccountLocks($connectionB);

        $connectionB->beginTransaction();
        $locksB->lockForPosting([$high]);

        $connectionA->beginTransaction();
        $sqlStateOfA = postingLockSqlStateOf(
            fn () => $connectionA->select('select id from account where id = ? for update nowait', [$high], false),
        );

        expect($sqlStateOfA)->toBe('55P03');
    });
});

it('makes a key-preserving UPDATE of a locked account wait (FOR SHARE, not FOR KEY SHARE)', function (): void {
    postingLockRun(function (array $fixtures, ConnectionInterface $connectionA, ConnectionInterface $connectionB): void {
        $accountId = $fixtures['low'];

        // An UPDATE that does not change the key takes FOR NO KEY UPDATE, which conflicts with FOR SHARE but not with
        // FOR KEY SHARE (Postgres 17, table 13.3). The FOR UPDATE probes of the two tests above conflict with both modes,
        // so they cannot tell them apart; this one does. pyme_app has UPDATE on account.is_active.
        $sqlStateOfA = null;
        (new PostingTransaction($connectionB))->run(function () use ($connectionA, $connectionB, $accountId, &$sqlStateOfA): void {
            (new AccountLocks($connectionB))->lockForPosting([$accountId]);

            $connectionA->beginTransaction();
            try {
                $connectionA->statement("set local lock_timeout = '300ms'");
                $sqlStateOfA = postingLockSqlStateOf(
                    fn () => $connectionA->update('update account set is_active = false where id = ?', [$accountId]),
                );
            } finally {
                $connectionA->rollBack();
            }
        });

        // B has committed by now: nothing changed the account.
        $isActive = $connectionA->table('account')->where('id', $accountId)->value('is_active');

        expect(['sqlStateOfA' => $sqlStateOfA, 'isActive' => $isActive])->toBe([
            'sqlStateOfA' => '55P03',
            'isActive' => true,
        ]);
    });
});
