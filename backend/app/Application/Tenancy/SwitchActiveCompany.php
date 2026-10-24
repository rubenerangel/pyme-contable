<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

use App\Infrastructure\Tenancy\CompanyMemberships;

/**
 * Validates that the user may work on the company. Storing it as the active company is up to the caller (session).
 */
final class SwitchActiveCompany
{
    public function __construct(private readonly CompanyMemberships $memberships) {}

    /**
     * @throws CompanyAccessDenied
     */
    public function __invoke(int $userId, int $companyId): void
    {
        if (! $this->memberships->isMember($userId, $companyId)) {
            throw CompanyAccessDenied::forCompany($userId, $companyId);
        }
    }
}
