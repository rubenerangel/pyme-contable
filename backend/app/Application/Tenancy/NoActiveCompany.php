<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

use App\Domain\DomainError;
use App\Domain\ErrorKind;

/**
 * A business request arrived with no active company in the session.
 */
final class NoActiveCompany extends DomainError
{
    public static function inSession(): self
    {
        return new self('No active company in the session.');
    }

    public function errorCode(): string
    {
        return 'no_active_company';
    }

    public function kind(): ErrorKind
    {
        return ErrorKind::Conflict;
    }
}
