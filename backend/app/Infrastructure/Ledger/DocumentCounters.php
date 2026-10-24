<?php

declare(strict_types=1);

namespace App\Infrastructure\Ledger;

use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * Consecutive numbers without gaps per company, document type and year, on the document_counter table.
 *
 * It must run inside the transaction that posts the document: the row lock taken by the increment lasts until commit,
 * so concurrent posts of the same counter queue up, and a rollback gives the number back. It never validates that
 * year matches document_type.resets_yearly; that belongs to the posting action.
 */
final class DocumentCounters
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * Takes the next number of the counter, creating it at 1 if it does not exist. company_id comes from the
     * connection context (column DEFAULT), never from here.
     */
    public function next(int $documentTypeId, ?int $year): int
    {
        if ($this->connection->transactionLevel() === 0) {
            throw new LogicException('DocumentCounters::next() must run inside a transaction.');
        }

        // One statement: the conflict path locks the row and increments it atomically. The conflict target matches
        // the UNIQUE NULLS NOT DISTINCT index, so a NULL year is a single counter.
        $row = $this->connection->selectOne(<<<'SQL'
            insert into document_counter (document_type_id, year, last_number)
            values (?, ?, 1)
            on conflict (company_id, document_type_id, year)
            do update set last_number = document_counter.last_number + 1
            returning last_number
            SQL, [$documentTypeId, $year], false);

        return (int) $row->last_number;
    }
}
