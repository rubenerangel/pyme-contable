<?php

declare(strict_types=1);

namespace App\Infrastructure\Ledger;

use App\Domain\Ledger\PostingLockTimeout;
use Closure;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use PDOException;

/**
 * The transaction that wraps a posting (and a reversal, which is one more posting). It fixes lock_timeout with
 * SET LOCAL as the first statement and maps a lock timeout to PostingLockTimeout. lock_timeout limits each lock wait
 * separately, not the whole posting (PostgreSQL 17: "applies separately to each lock acquisition attempt").
 *
 * Fixed lock order, the same for posting and reversal:
 *  1. document FOR UPDATE: the FK from journal_entry to document takes FOR KEY SHARE, which does not conflict with the
 *     FOR NO KEY UPDATE of a draft edit, so without the explicit lock an edit and a posting would not exclude each other;
 *  2. period FOR SHARE (PeriodLocks);
 *  3. accounts FOR SHARE, by ascending id and without repeats (AccountLocks);
 *  4. document counter, last (DocumentCounters).
 * One posting per PostingTransaction: posting two documents in the same work would take two document counters and
 * break the order.
 * A reversal is one posting: it takes FOR UPDATE only on the new reversal document. The original is not locked: once
 * posted it is immutable and its journal entry can never go away (the ledger is insert-only), and two reversals of the
 * same original are stopped by UNIQUE (company_id, reverses_document_id) on document.
 * Closing a period takes the period FOR UPDATE and deactivating an account takes the account FOR UPDATE, so they wait
 * for the postings in progress. Once they hold the lock, new postings wait; while they wait, new postings can still
 * take their FOR SHARE: no queue is guaranteed.
 *
 * It does not nest: with transactionLevel() above 0 it throws without running $work. Inside a savepoint SET LOCAL would
 * not be the first statement and would leak to the rest of the outer transaction, and the deferred balance check and a
 * 55P03 at commit would surface outside run(), unmapped.
 *
 * 40P01 (deadlock) is not mapped: with the fixed order a deadlock between postings is a bug, so it goes out as a 500
 * and stays in the log. A write that touches several accounts or periods without locking them first in id order can
 * also deadlock against a posting: such writes lock first with SELECT ... ORDER BY id FOR UPDATE, or touch one row per
 * transaction.
 *
 * Verified in Laravel 13.38.0:
 *  - Illuminate/Database/Concerns/ManagesTransactions.php: transaction() retries only with attempts above 1 and only
 *    what ConcurrencyErrorDetector flags, so attempts is 1 here and nothing depends on a retry. In a nested
 *    transaction a flagged error leaves as DeadlockException.
 *  - Illuminate/Database/ConcurrencyErrorDetector.php: flags code 40001 or a message containing "deadlock detected"
 *    (40P01). 55P03 ("canceling statement due to lock timeout") is never retried.
 */
final class PostingTransaction
{
    private const LOCK_TIMEOUT = '5s';

    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     *
     * @throws LogicException when a transaction is already open
     * @throws PostingLockTimeout when PostgreSQL cancels a statement for waiting too long on a lock, commit included
     */
    public function run(Closure $work): mixed
    {
        if ($this->connection->transactionLevel() !== 0) {
            throw new LogicException('PostingTransaction::run() must not be nested inside another transaction.');
        }

        try {
            return $this->connection->transaction(function () use ($work): mixed {
                $this->connection->statement("set local lock_timeout = '".self::LOCK_TIMEOUT."'");

                return $work();
            }, 1);
        } catch (PDOException $e) {
            if (($e->errorInfo[0] ?? (string) $e->getCode()) === '55P03') {
                throw PostingLockTimeout::afterWaiting($e);
            }

            throw $e;
        }
    }
}
