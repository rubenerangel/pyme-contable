<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * What the catalog tests need to know about the tables that carry a company_id column. It lives here and not as a
 * constant in a test file because Pest constants depend on the order the test files are loaded.
 */
final class TenantCatalog
{
    /**
     * Tables with a company_id column that are global on purpose (no RLS): the membership is read before there is an
     * active company. Adding a table here is a design decision, see docs/decisiones.md, Multi-tenant.
     */
    public const GLOBAL_TABLES_WITH_COMPANY_ID = ['company_user'];

    /**
     * Complete list of the tables of public without a company_id column (global, no RLS), sorted by name. company_user
     * is also global but has a company_id column, so it is in GLOBAL_TABLES_WITH_COMPANY_ID and not here. Adding a
     * table here is deciding that it is global, see docs/decisiones.md, Multi-tenant. A business table that forgets
     * its company_id shows up as extra in the catalog test of TenantIsolationTest and fails it.
     */
    public const TABLES_WITHOUT_COMPANY_ID = [
        'cache',
        'cache_locks',
        'companies',
        'failed_jobs',
        'job_batches',
        'jobs',
        'migrations',
        'municipality',
        'password_reset_tokens',
        'sessions',
        'users',
    ];

    /**
     * Tables of public (ordinary or partitioned) with a company_id column that is not dropped, sorted by name.
     *
     * @return list<string>
     */
    public static function tablesWithCompanyId(): array
    {
        return array_column(DB::select(<<<'SQL'
            select c.relname
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public'
              and c.relkind in ('r', 'p')
              and exists (
                  select 1 from pg_attribute a
                  where a.attrelid = c.oid and a.attname = 'company_id' and not a.attisdropped
              )
            order by c.relname
            SQL), 'relname');
    }
}
