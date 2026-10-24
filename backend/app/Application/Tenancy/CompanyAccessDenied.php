<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

use RuntimeException;

/**
 * The user is not a member of the company. Also raised for a nonexistent company, so existence is not revealed.
 */
final class CompanyAccessDenied extends RuntimeException
{
    public static function forCompany(int $userId, int $companyId): self
    {
        return new self("User {$userId} cannot access company {$companyId}.");
    }
}
