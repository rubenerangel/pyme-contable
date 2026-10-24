<?php

declare(strict_types=1);

use App\Domain\Ledger\PeriodStatus;
use App\Infrastructure\Ledger\PeriodLocks;
use App\Infrastructure\Ledger\PeriodNotFound;
use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Models\Company;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTruncationAsOwner;

// Period locks under concurrency: posting takes FOR SHARE and closing takes FOR UPDATE on the period row. Two pyme_app
// connections with real commits (no wrapping transaction): A is the default connection and B is a second pyme_app
// connection registered at runtime, each with its own DatabaseCompanyContext. B waits with a short lock_timeout, so
// waiting for a lock shows up as SQLSTATE 55P03. The lock methods only return the status: the caller decides what to
// do with a closed period, so they must not reject it. DatabaseTruncationAsOwner truncates the tables between tests,
// since the commits are real.
uses(DatabaseTruncationAsOwner::class);

/**
 * Runs $test with a committed company that has an open period (2026, number 1) and two pyme_app connections (A
 * default, B second), each with the company in context and its own PeriodLocks. Whatever the test leaves open is
 * rolled back, contexts are left and the second connection is closed in a finally.
 *
 * $test receives: company id, period id, locks of A, locks of B, connection A, connection B.
 */
function periodLocksRun(Closure $test): void
{
    $company = Company::factory()->create();

    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);
    DB::purge('pgsql_second');
    $connectionA = DB::connection();
    $connectionB = DB::connection('pgsql_second');

    $contextA = new DatabaseCompanyContext($connectionA);
    $contextB = new DatabaseCompanyContext($connectionB);

    try {
        $contextA->enter($company->id);
        $contextB->enter($company->id);
        $connectionB->statement("set lock_timeout = '300ms'");

        $periodId = (int) $connectionA->table('period')->insertGetId(['year' => 2026, 'number' => 1]);

        $test(
            $company->id,
            $periodId,
            new PeriodLocks($connectionA),
            new PeriodLocks($connectionB),
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
 * Asserts that the closure fails waiting for a lock (SQLSTATE 55P03, lock_timeout of B), then rolls back the aborted
 * transaction of the connection.
 */
function periodLocksExpectLockWait(ConnectionInterface $connection, Closure $statement): void
{
    try {
        expect($statement)->toThrow(fn (QueryException $e) => expect($e->errorInfo[0])->toBe('55P03'));
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
}

it('lets two lockForPosting of the same period live together without waiting', function (): void {
    periodLocksRun(function (int $companyId, int $periodId, PeriodLocks $locksA, PeriodLocks $locksB, ConnectionInterface $connectionA, ConnectionInterface $connectionB): void {
        $connectionA->beginTransaction();
        $statusSeenByA = $locksA->lockForPosting($periodId);

        // Any wait would fail here with 55P03 after 300ms.
        $connectionB->beginTransaction();
        $statusSeenByB = $locksB->lockForPosting($periodId);

        expect(['a' => $statusSeenByA, 'b' => $statusSeenByB])->toBe(['a' => PeriodStatus::Open, 'b' => PeriodStatus::Open]);
    });
});

it('makes lockForClosing in B wait while A holds lockForPosting (SQLSTATE 55P03)', function (): void {
    periodLocksRun(function (int $companyId, int $periodId, PeriodLocks $locksA, PeriodLocks $locksB, ConnectionInterface $connectionA, ConnectionInterface $connectionB): void {
        $connectionA->beginTransaction();
        $locksA->lockForPosting($periodId);

        $connectionB->beginTransaction();
        periodLocksExpectLockWait($connectionB, fn () => $locksB->lockForClosing($periodId));
    });
});

it('makes lockForPosting in B wait while A holds lockForClosing (SQLSTATE 55P03)', function (): void {
    periodLocksRun(function (int $companyId, int $periodId, PeriodLocks $locksA, PeriodLocks $locksB, ConnectionInterface $connectionA, ConnectionInterface $connectionB): void {
        $connectionA->beginTransaction();
        $locksA->lockForClosing($periodId);

        $connectionB->beginTransaction();
        periodLocksExpectLockWait($connectionB, fn () => $locksB->lockForPosting($periodId));
    });
});

it('throws LogicException outside a transaction from both lock methods', function (): void {
    periodLocksRun(function (int $companyId, int $periodId, PeriodLocks $locksA): void {
        $thrown = [];
        foreach (['lockForPosting', 'lockForClosing'] as $method) {
            try {
                $locksA->{$method}($periodId);
            } catch (LogicException $e) {
                $thrown[] = $method;
            }
        }

        expect($thrown)->toBe(['lockForPosting', 'lockForClosing']);
    });
});

it('throws PeriodNotFound when the period does not exist', function (string $method): void {
    periodLocksRun(function (int $companyId, int $periodId, PeriodLocks $locksA, $locksB, ConnectionInterface $connectionA) use ($method): void {
        $connectionA->beginTransaction();

        expect(fn () => $locksA->{$method}($periodId + 1000))->toThrow(PeriodNotFound::class);
    });
})->with(['lockForPosting', 'lockForClosing']);

it('throws PeriodNotFound for the period of another company, which RLS hides', function (string $method): void {
    periodLocksRun(function (int $companyId, int $periodId, PeriodLocks $locksA, PeriodLocks $locksB, ConnectionInterface $connectionA, ConnectionInterface $connectionB) use ($method): void {
        $otherCompany = Company::factory()->create();
        (new DatabaseCompanyContext($connectionB))->enter($otherCompany->id);

        // B is in the context of the other company and asks for the id of a period of the first company.
        $connectionB->beginTransaction();

        expect(fn () => $locksB->{$method}($periodId))->toThrow(PeriodNotFound::class);
    });
})->with(['lockForPosting', 'lockForClosing']);

it('returns the status of the period: Open, and Closed after closing it with an update, without rejecting it', function (string $method): void {
    periodLocksRun(function (int $companyId, int $periodId, PeriodLocks $locksA, PeriodLocks $locksB, ConnectionInterface $connectionA, ConnectionInterface $connectionB) use ($method): void {
        $connectionA->beginTransaction();
        $whileOpen = $locksA->{$method}($periodId);
        $connectionA->commit();

        $connectionA->table('period')->where('id', $periodId)->update(['status' => 'closed']);

        $connectionA->beginTransaction();
        $whileClosed = $locksA->{$method}($periodId);
        $connectionA->commit();

        expect(['open' => $whileOpen, 'closed' => $whileClosed])->toBe([
            'open' => PeriodStatus::Open,
            'closed' => PeriodStatus::Closed,
        ]);
    });
})->with(['lockForPosting', 'lockForClosing']);
