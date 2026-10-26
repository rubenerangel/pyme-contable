<?php

declare(strict_types=1);

namespace App\Infrastructure\Ledger;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use LogicException;

/**
 * Shared row locks on the accounts a posting touches.
 *
 * Fixed lock order: document, period, accounts, document counter (see PostingTransaction).
 */
final class AccountLocks
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * Locks the accounts FOR SHARE in a single statement: PostgreSQL sorts by id before locking, so every posting takes
     * them in ascending id order whatever the order of $accountIds, and = ANY returns each row once, so repeated ids
     * are harmless. Deactivating an account takes the row FOR UPDATE and waits for the postings; no queue is
     * guaranteed for the postings that arrive while it waits (see PostingTransaction).
     *
     * @param  list<int>  $accountIds
     *
     * @throws InvalidArgumentException when an element is not an int, before any query
     * @throws AccountNotFound when an account does not exist or is not visible to the active company
     */
    public function lockForPosting(array $accountIds): void
    {
        if ($this->connection->transactionLevel() === 0) {
            throw new LogicException('AccountLocks::lockForPosting() must run inside a transaction.');
        }

        foreach ($accountIds as $accountId) {
            if (! is_int($accountId)) {
                throw new InvalidArgumentException('Account ids must be int, got '.get_debug_type($accountId).'.');
            }
        }

        $rows = $this->connection->select(
            'select id from account where id = any(?::bigint[]) order by id for share',
            ['{'.implode(',', $accountIds).'}'],
            false,
        );

        $found = array_map(static fn (object $row): int => (int) $row->id, $rows);
        $missing = array_values(array_unique(array_diff($accountIds, $found)));

        if ($missing !== []) {
            throw AccountNotFound::withIds($missing);
        }
    }
}
