<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Helpers shared by the account table tests. The test chart is made up on purpose (codes 'T1', 'T1.1', names like
 * 'Test assets') and does not resemble the PUC. company_id is never passed: it comes from the context of the connection.
 */
final class AccountFixtures
{
    /**
     * Creates a company and a user and enters their context on the default connection (pyme_app), through the
     * container's DatabaseCompanyContext, and returns the company id.
     */
    public static function enterNewCompany(): int
    {
        $company = Company::factory()->create();
        app(DatabaseCompanyContext::class)->enter($company->id, User::factory()->create()->id);

        return $company->id;
    }

    /**
     * Inserts an account of the company in the context of $connection, passing only the columns that have no default,
     * and returns its id.
     */
    public static function insert(
        ConnectionInterface $connection,
        string $code,
        ?int $parentId = null,
        bool $isPostable = false,
        string $normalBalance = 'debit',
        ?string $name = null,
    ): int {
        return (int) $connection->table('account')->insertGetId([
            'code' => $code,
            'name' => $name ?? 'Test '.$code,
            'parent_id' => $parentId,
            'is_postable' => $isPostable,
            'normal_balance' => $normalBalance,
        ]);
    }
}
