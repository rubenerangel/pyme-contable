<?php

declare(strict_types=1);

use App\Domain\Ledger\PostingLockTimeout;
use App\Infrastructure\Ledger\PostingTransaction;
use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTruncationAsOwner;

// PostingTransaction::run(): the transaction that wraps a posting, with lock_timeout fixed by SET LOCAL as its first
// statement and SQLSTATE 55P03 mapped to PostingLockTimeout. run() refuses to nest, so these tests cannot live inside the
// transaction of RefreshDatabase: DatabaseTruncationAsOwner runs them with real commits, on the default connection
// (pyme_app), and truncates between tests. The real wait for a lock lives in PostingLockConcurrencyTest.
uses(DatabaseTruncationAsOwner::class);

/**
 * Runs $test with the default connection (pyme_app) and a committed company in its context, at transaction level 0.
 * Whatever the test leaves open is rolled back, the query log is switched off and the context is left in a finally.
 */
function postingTransactionRun(Closure $test): void
{
    $company = Company::factory()->create();
    $userId = User::factory()->create()->id;

    $connection = DB::connection();
    $context = new DatabaseCompanyContext($connection);

    try {
        $context->enter($company->id, $userId);

        $test($connection);
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        $connection->disableQueryLog();
        $connection->flushQueryLog();
        $context->leave();
    }
}

/**
 * A QueryException as the driver would raise it: a PDOException with errorInfo and the SQLSTATE as its code (a string,
 * as pdo_pgsql sets it), wrapped by Laravel.
 */
function postingTransactionQueryException(string $sqlState, string $driverMessage): QueryException
{
    $pdoException = new PDOException("SQLSTATE[{$sqlState}]: {$driverMessage}");
    $pdoException->errorInfo = [$sqlState, 7, $driverMessage];
    (new ReflectionProperty(Exception::class, 'code'))->setValue($pdoException, $sqlState);

    return new QueryException('pgsql', 'select 1', [], $pdoException);
}

it('sets a local lock_timeout of 5s as the first statement of its transaction', function (): void {
    postingTransactionRun(function (Connection $connection): void {
        $posting = new PostingTransaction($connection);

        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $insideWork = $posting->run(fn () => $connection->selectOne('show lock_timeout')->lock_timeout);
        $loggedQueries = array_column($connection->getQueryLog(), 'query');
        $connection->disableQueryLog();

        // SET LOCAL ends with the transaction: the session goes back to its own value.
        $afterRun = $connection->selectOne('show lock_timeout')->lock_timeout;

        expect([
            'insideWork' => $insideWork,
            'firstStatement' => $loggedQueries[0] ?? null,
            'afterRun' => $afterRun,
        ])->toBe([
            'insideWork' => '5s',
            'firstStatement' => "set local lock_timeout = '5s'",
            'afterRun' => '0',
        ]);
    });
});

it('returns the result of the work and rolls back its writes when the work throws', function (): void {
    postingTransactionRun(function (Connection $connection): void {
        $posting = new PostingTransaction($connection);

        $result = $posting->run(fn () => 'work result');

        $failure = new RuntimeException('work failed');
        $thrown = null;
        try {
            $posting->run(function () use ($connection, $failure): void {
                $connection->table('document_type')->insert([
                    'code' => 'D2',
                    'name' => 'Rolled back',
                    'resets_yearly' => true,
                ]);

                throw $failure;
            });
        } catch (Throwable $e) {
            $thrown = $e;
        }

        expect([
            'result' => $result,
            'sameException' => $thrown === $failure,
            'documentTypeExists' => $connection->table('document_type')->where('code', 'D2')->exists(),
            'transactionLevel' => $connection->transactionLevel(),
        ])->toBe([
            'result' => 'work result',
            'sameException' => true,
            'documentTypeExists' => false,
            'transactionLevel' => 0,
        ]);
    });
});

it('maps SQLSTATE 55P03 to PostingLockTimeout keeping the original exception as previous', function (): void {
    postingTransactionRun(function (Connection $connection): void {
        $posting = new PostingTransaction($connection);
        $queryException = postingTransactionQueryException('55P03', 'canceling statement due to lock timeout');

        // A 55P03 is never retried: the work must run exactly once.
        $executions = 0;
        $thrown = null;
        try {
            $posting->run(function () use ($queryException, &$executions): never {
                $executions++;

                throw $queryException;
            });
        } catch (Throwable $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeInstanceOf(PostingLockTimeout::class);
        expect($thrown->getPrevious())->toBe($queryException);
        expect($executions)->toBe(1);
    });
});

it('lets other SQLSTATEs through unchanged', function (string $sqlState, string $driverMessage): void {
    postingTransactionRun(function (Connection $connection) use ($sqlState, $driverMessage): void {
        $posting = new PostingTransaction($connection);
        $queryException = postingTransactionQueryException($sqlState, $driverMessage);

        // Laravel retries a deadlock only if transaction() gets attempts > 1 and the detector recognises the message.
        // ConcurrencyErrorDetector (Laravel 13.38.0) looks for the lowercase "deadlock detected", as pdo_pgsql delivers it
        // ("ERROR:  deadlock detected"). Pin it, or the execution count below could not tell a retry from a single run.
        if ($sqlState === '40P01') {
            expect($queryException->getMessage())->toContain('deadlock detected');
        }

        $executions = 0;
        $thrown = null;
        try {
            $posting->run(function () use ($queryException, &$executions): never {
                $executions++;

                throw $queryException;
            });
        } catch (Throwable $e) {
            $thrown = $e;
        }

        expect($thrown)->toBe($queryException);
        expect($executions)->toBe(1);
    });
})->with([
    '40P01 deadlock' => ['40P01', 'deadlock detected'],
    '23514 check violation' => ['23514', 'new row for relation "journal_entry" violates check constraint'],
]);

it('refuses to run nested inside an open transaction without running the work', function (): void {
    postingTransactionRun(function (Connection $connection): void {
        $posting = new PostingTransaction($connection);
        $ran = false;
        $thrown = null;

        $connection->beginTransaction();
        try {
            $posting->run(function () use (&$ran): int {
                $ran = true;

                return 1;
            });
        } catch (Throwable $e) {
            $thrown = $e;
        } finally {
            $connection->rollBack();
        }

        expect(['thrownLogicException' => $thrown instanceof LogicException, 'ran' => $ran])->toBe([
            'thrownLogicException' => true,
            'ran' => false,
        ]);
    });
});
