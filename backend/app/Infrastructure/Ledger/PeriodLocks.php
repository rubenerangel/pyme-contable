<?php

declare(strict_types=1);

namespace App\Infrastructure\Ledger;

use App\Domain\Ledger\PeriodStatus;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * Row locks on a period: shared to post into it, exclusive to close or reopen it.
 *
 * Posts share the lock and run concurrently. Closing waits for the postings in progress; once it holds the lock, new
 * postings wait until its transaction ends. While it waits, new postings can still take their FOR SHARE: no queue is
 * guaranteed.
 * Neither method rejects a closed period: they return the status and the caller decides.
 *
 * Fixed lock order (enforced by the posting action, see PostingTransaction): document, period, accounts, document
 * counter. The reverse order could deadlock against a close. A write that touches several periods must also lock them
 * first in id order (SELECT ... ORDER BY id FOR UPDATE) or touch one row per transaction, or it can deadlock against a
 * posting.
 */
final class PeriodLocks
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function lockForPosting(int $periodId): PeriodStatus
    {
        return $this->lock($periodId, 'for share');
    }

    public function lockForClosing(int $periodId): PeriodStatus
    {
        return $this->lock($periodId, 'for update');
    }

    private function lock(int $periodId, string $mode): PeriodStatus
    {
        if ($this->connection->transactionLevel() === 0) {
            throw new LogicException('A period lock must be taken inside a transaction.');
        }

        // $mode is one of the two literals above, never user input.
        $row = $this->connection->selectOne("select status from period where id = ? {$mode}", [$periodId], false);

        if ($row === null) {
            throw PeriodNotFound::withId($periodId);
        }

        return PeriodStatus::from($row->status);
    }
}
