<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use Illuminate\Database\ConnectionInterface;

/**
 * Active company and user of the database connection. The company is read by the RLS policies through the
 * app.company_id setting; the user travels in app.user_id.
 *
 * Session level (not transactional) because there is no transaction-mode pooler nor Octane (see
 * docs/decisiones.md). Whoever calls enter() must call leave() in a finally.
 */
final class DatabaseCompanyContext
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function enter(int $companyId, int $userId): void
    {
        $this->connection->select(
            "select set_config('app.company_id', ?, false), set_config('app.user_id', ?, false)",
            [(string) $companyId, (string) $userId],
        );
    }

    public function leave(): void
    {
        $this->connection->select("select set_config('app.company_id', '', false), set_config('app.user_id', '', false)");
    }

    public function current(): ?int
    {
        return $this->readSetting('app.company_id');
    }

    public function currentUser(): ?int
    {
        return $this->readSetting('app.user_id');
    }

    private function readSetting(string $name): ?int
    {
        $value = $this->connection
            ->selectOne("select nullif(current_setting(?, true), '')::bigint as value", [$name])
            ->value;

        return $value === null ? null : (int) $value;
    }
}
