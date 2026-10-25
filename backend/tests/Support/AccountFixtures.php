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
     * A minimal valid row for account, without id or company_id (both come from the context of the connection).
     *
     * @return array{code: string, name: string, is_postable: bool, normal_balance: string}
     */
    public static function minimalRow(string $code = 'T1'): array
    {
        return ['code' => $code, 'name' => 'Test '.$code, 'is_postable' => false, 'normal_balance' => 'debit'];
    }

    /**
     * Inserts an account of the company in the context of $connection, with the columns of minimalRow() plus parent_id,
     * and returns its id. id, company_id and the flags take their DEFAULT.
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
