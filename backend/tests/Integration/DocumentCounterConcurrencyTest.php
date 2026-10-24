<?php

declare(strict_types=1);

use App\Infrastructure\Ledger\DocumentCounters;
use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTruncationAsOwner;

// Consecutive numbering under concurrency: two pyme_app connections with real commits (no wrapping transaction).
// A is the default connection and B is a second pyme_app connection registered at runtime, each with its own
// DatabaseCompanyContext. B waits with a short lock_timeout, so waiting for a lock shows up as SQLSTATE 55P03.
// DatabaseTruncationAsOwner truncates the tables between tests, since the commits are real.
uses(DatabaseTruncationAsOwner::class);

/**
 * Runs $test with a committed company and document type and two pyme_app connections (A default, B second), each
 * with the company in context and its own DocumentCounters. Whatever the test leaves open is rolled back, contexts are
 * left and the second connection is closed in a finally.
 *
 * $test receives: company id, document type id, counters of A, counters of B, connection A, connection B.
 */
function counterConcurrencyRun(Closure $test): void
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

        $typeId = (int) $connectionA->table('document_type')->insertGetId([
            'code' => 'INV',
            'name' => 'Invoice',
            'resets_yearly' => true,
        ]);

        $test(
            $company->id,
            $typeId,
            new DocumentCounters($connectionA),
            new DocumentCounters($connectionB),
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
 * Runs $work in a transaction of the connection with real commit and returns its result. If it throws, rolls back
 * and rethrows.
 */
function counterConcurrencyInTransaction(ConnectionInterface $connection, Closure $work): mixed
{
    $connection->beginTransaction();
    try {
        $result = $work();
        $connection->commit();

        return $result;
    } catch (Throwable $e) {
        $connection->rollBack();

        throw $e;
    }
}

/**
 * Asserts that the closure fails waiting for a lock (SQLSTATE 55P03, lock_timeout of B), then rolls back the aborted
 * transaction of the connection.
 */
function counterConcurrencyExpectLockWait(ConnectionInterface $connection, Closure $statement): void
{
    try {
        expect($statement)->toThrow(fn (QueryException $e) => expect($e->errorInfo[0])->toBe('55P03'));
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
}

/**
 * Counters of the company as stored, read with the connection, as a map "type:year" → last_number (year NULL is
 * written as "null"). Needs the company in the context of the connection.
 *
 * @return array<string, int>
 */
function counterConcurrencyStoredCounters(ConnectionInterface $connection): array
{
    $stored = [];
    foreach ($connection->select('select document_type_id, year, last_number from document_counter order by 1, 2') as $row) {
        $stored[$row->document_type_id.':'.($row->year ?? 'null')] = (int) $row->last_number;
    }

    return $stored;
}

it('next outside a transaction throws LogicException and creates or changes no counter', function (): void {
    counterConcurrencyRun(function (int $companyId, int $typeId, DocumentCounters $countersA, $countersB, ConnectionInterface $connectionA): void {
        // An existing counter at 1, committed, and a year that has no counter yet.
        counterConcurrencyInTransaction($connectionA, fn () => $countersA->next($typeId, 2026));
        $before = counterConcurrencyStoredCounters($connectionA);

        $thrown = [];
        foreach ([2026, 2027] as $year) {
            try {
                $countersA->next($typeId, $year);
            } catch (LogicException $e) {
                $thrown[] = $year;
            }
        }

        expect(['thrown' => $thrown, 'before' => $before, 'after' => counterConcurrencyStoredCounters($connectionA)])->toBe([
            'thrown' => [2026, 2027],
            'before' => [$typeId.':2026' => 1],
            'after' => [$typeId.':2026' => 1],
        ]);
    });
});

it('makes B wait on an existing counter while A holds the next number, and B gets the one after A commits', function (): void {
    counterConcurrencyRun(function (int $companyId, int $typeId, DocumentCounters $countersA, DocumentCounters $countersB, ConnectionInterface $connectionA, ConnectionInterface $connectionB): void {
        counterConcurrencyInTransaction($connectionA, fn () => $countersA->next($typeId, 2026));

        $connectionA->beginTransaction();
        $takenByA = $countersA->next($typeId, 2026);

        $connectionB->beginTransaction();
        counterConcurrencyExpectLockWait($connectionB, fn () => $countersB->next($typeId, 2026));

        $connectionA->commit();
        $takenByB = counterConcurrencyInTransaction($connectionB, fn () => $countersB->next($typeId, 2026));

        expect(['a' => $takenByA, 'b' => $takenByB])->toBe(['a' => 2, 'b' => 3]);
    });
});

it('gives B the same number A took when A rolls back (no gaps)', function (): void {
    counterConcurrencyRun(function (int $companyId, int $typeId, DocumentCounters $countersA, DocumentCounters $countersB, ConnectionInterface $connectionA, ConnectionInterface $connectionB): void {
        counterConcurrencyInTransaction($connectionA, fn () => $countersA->next($typeId, 2026));

        $connectionA->beginTransaction();
        $takenByA = $countersA->next($typeId, 2026);
        $connectionA->rollBack();

        $takenByB = counterConcurrencyInTransaction($connectionB, fn () => $countersB->next($typeId, 2026));

        expect(['a' => $takenByA, 'b' => $takenByB, 'stored' => counterConcurrencyStoredCounters($connectionB)])->toBe([
            'a' => 2,
            'b' => 2,
            'stored' => [$typeId.':2026' => 2],
        ]);
    });
});

it('makes B wait while A creates the counter without commit, and B gets 2 after A commits', function (): void {
    counterConcurrencyRun(function (int $companyId, int $typeId, DocumentCounters $countersA, DocumentCounters $countersB, ConnectionInterface $connectionA, ConnectionInterface $connectionB): void {
        $connectionA->beginTransaction();
        $takenByA = $countersA->next($typeId, 2026);

        $connectionB->beginTransaction();
        counterConcurrencyExpectLockWait($connectionB, fn () => $countersB->next($typeId, 2026));

        $connectionA->commit();
        $takenByB = counterConcurrencyInTransaction($connectionB, fn () => $countersB->next($typeId, 2026));

        expect(['a' => $takenByA, 'b' => $takenByB])->toBe(['a' => 1, 'b' => 2]);
    });
});

it('gives B number 1 when A created the counter and rolled back', function (): void {
    counterConcurrencyRun(function (int $companyId, int $typeId, DocumentCounters $countersA, DocumentCounters $countersB, ConnectionInterface $connectionA, ConnectionInterface $connectionB): void {
        $connectionA->beginTransaction();
        $takenByA = $countersA->next($typeId, 2026);

        $connectionB->beginTransaction();
        counterConcurrencyExpectLockWait($connectionB, fn () => $countersB->next($typeId, 2026));

        $connectionA->rollBack();
        $takenByB = counterConcurrencyInTransaction($connectionB, fn () => $countersB->next($typeId, 2026));

        expect(['a' => $takenByA, 'b' => $takenByB, 'stored' => counterConcurrencyStoredCounters($connectionB)])->toBe([
            'a' => 1,
            'b' => 1,
            'stored' => [$typeId.':2026' => 1],
        ]);
    });
});

it('does not make B wait when it asks for another document type or another year than the one A holds', function (): void {
    counterConcurrencyRun(function (int $companyId, int $typeId, DocumentCounters $countersA, DocumentCounters $countersB, ConnectionInterface $connectionA, ConnectionInterface $connectionB): void {
        $otherTypeId = (int) $connectionA->table('document_type')->insertGetId([
            'code' => 'PAY',
            'name' => 'Payment',
            'resets_yearly' => true,
        ]);

        $connectionA->beginTransaction();
        $takenByA = $countersA->next($typeId, 2026);

        // Any wait would fail here with 55P03 after 300ms.
        $connectionB->beginTransaction();
        $otherType = $countersB->next($otherTypeId, 2026);
        $otherYear = $countersB->next($typeId, 2027);
        $connectionB->commit();
        $connectionA->commit();

        expect(['a' => $takenByA, 'otherType' => $otherType, 'otherYear' => $otherYear])->toBe([
            'a' => 1,
            'otherType' => 1,
            'otherYear' => 1,
        ]);
    });
});
