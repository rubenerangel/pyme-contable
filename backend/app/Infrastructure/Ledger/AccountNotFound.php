<?php

declare(strict_types=1);

namespace App\Infrastructure\Ledger;

use RuntimeException;

/**
 * Some accounts do not exist or are not visible to the active company (row level security hides them).
 */
final class AccountNotFound extends RuntimeException
{
    /**
     * @param  list<int>  $ids  The ids that were not found.
     */
    public static function withIds(array $ids): self
    {
        return new self('Accounts not found: '.implode(', ', $ids).'.');
    }
}
