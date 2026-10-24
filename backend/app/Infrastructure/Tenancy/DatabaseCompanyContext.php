<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use Illuminate\Database\ConnectionInterface;

/**
 * Active company of the database connection, read by the RLS policies through the app.company_id setting.
 *
 * Session level (not transactional) because there is no transaction-mode pooler nor Octane (see
 * docs/decisiones.md). Whoever calls enter() must call leave() in a finally.
 */
final class DatabaseCompanyContext
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function enter(int $companyId): void
    {
        $this->connection->select("select set_config('app.company_id', ?, false)", [(string) $companyId]);
    }

    public function leave(): void
    {
        $this->connection->select("select set_config('app.company_id', '', false)");
    }

    public function current(): ?int
    {
        $value = $this->connection
            ->selectOne("select nullif(current_setting('app.company_id', true), '')::bigint as value")
            ->value;

        return $value === null ? null : (int) $value;
    }
}
