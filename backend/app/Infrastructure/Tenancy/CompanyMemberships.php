<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use Illuminate\Database\ConnectionInterface;

/**
 * Membership lookup on company_user (global table, no RLS: it is read before there is an active company).
 */
final class CompanyMemberships
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function isMember(int $userId, int $companyId): bool
    {
        return $this->connection->table('company_user')
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->exists();
    }
}
