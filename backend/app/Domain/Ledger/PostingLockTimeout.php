<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\DomainError;
use App\Domain\ErrorKind;
use Throwable;

/**
 * A posting waited for a row lock (document, period, accounts or document counter) longer than lock_timeout and
 * PostgreSQL cancelled it (SQLSTATE 55P03). The caller can try again.
 */
final class PostingLockTimeout extends DomainError
{
    public static function afterWaiting(Throwable $previous): self
    {
        return new self('Timed out waiting for a row lock while posting.', 0, $previous);
    }

    public function errorCode(): string
    {
        return 'posting_lock_timeout';
    }

    public function kind(): ErrorKind
    {
        return ErrorKind::Conflict;
    }
}
