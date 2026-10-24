<?php

declare(strict_types=1);

namespace App\Infrastructure\Ledger;

use RuntimeException;

/**
 * The period does not exist or is not visible to the active company (row level security hides it).
 */
final class PeriodNotFound extends RuntimeException
{
    public static function withId(int $periodId): self
    {
        return new self("Period {$periodId} not found.");
    }
}
