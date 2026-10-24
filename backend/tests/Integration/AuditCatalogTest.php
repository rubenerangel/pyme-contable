<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;

// Catalog checks on the audit machinery: the SECURITY DEFINER functions, who can execute them, the audit_log policies,
// sequence and indexes. That every tenant table has the audit triggers lives in TenantIsolationTest, next to the
// other per-table catalog checks that share its list of global tables.
uses(RefreshDatabaseAsOwner::class);

/**
 * Policies on audit_log as "name | command | roles", sorted.
 *
 * @return list<string>
 */
function auditCatalogTestPolicies(): array
{
    return array_column(DB::select(<<<'SQL'
        select p.policyname || ' | ' || p.cmd || ' | ' || p.roles::text as policy
        from pg_policies p
        where p.schemaname = 'public' and p.tablename = 'audit_log'
        order by 1
        SQL), 'policy');
}

it('every SECURITY DEFINER function in public pins its search_path to pg_catalog and has no EXECUTE for PUBLIC', function (): void {
    $actual = [];
    foreach (DB::select(<<<'SQL'
        select p.proname,
               exists (
                   select 1 from unnest(coalesce(p.proconfig, '{}'::text[])) as cfg
                   where cfg like 'search_path=pg_catalog%'
               ) as pinned_search_path,
               exists (
                   select 1 from aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) as a
                   where a.grantee = 0 and a.privilege_type = 'EXECUTE'
               ) as public_can_execute
        from pg_proc p
        where p.pronamespace = 'public'::regnamespace
          and p.prosecdef
        order by p.proname
        SQL) as $row) {
        $actual[$row->proname] = [(bool) $row->pinned_search_path, (bool) $row->public_can_execute];
    }

    // Guard against passing on an empty result.
    expect($actual)->toHaveKeys(['audit_log_capture', 'audit_log_event']);

    $this->assertSame(
        array_fill_keys(array_keys($actual), [true, false]),
        $actual,
        'Function → [search_path pinned to pg_catalog first, PUBLIC can EXECUTE] for every SECURITY DEFINER function in public.',
    );
});

it('the audit functions are plpgsql, SECURITY DEFINER and owned by pyme_owner, with the expected return types', function (): void {
    $actual = [];
    foreach (DB::select(<<<'SQL'
        select p.proname,
               l.lanname,
               p.prosecdef,
               pg_get_userbyid(p.proowner) as owner,
               p.prorettype::regtype::text as returns
        from pg_proc p
        join pg_language l on l.oid = p.prolang
        where p.pronamespace = 'public'::regnamespace
          and p.proname in ('audit_log_capture', 'audit_log_event')
        order by p.proname
        SQL) as $row) {
        $actual[$row->proname] = [$row->lanname, (bool) $row->prosecdef, $row->owner, $row->returns];
    }

    $this->assertSame(
        [
            'audit_log_capture' => ['plpgsql', true, 'pyme_owner', 'trigger'],
            'audit_log_event' => ['plpgsql', true, 'pyme_owner', 'bigint'],
        ],
        $actual,
        'Function → [language, SECURITY DEFINER, owner, return type].',
    );
});

it('pyme_app can execute audit_log_event but not audit_log_capture', function (): void {
    $row = DB::selectOne(<<<'SQL'
        select has_function_privilege('pyme_app', 'public.audit_log_event(text, jsonb)', 'EXECUTE') as event_execute,
               has_function_privilege('pyme_app', 'public.audit_log_capture()', 'EXECUTE') as capture_execute
        SQL);

    $this->assertSame(
        ['audit_log_event' => true, 'audit_log_capture' => false],
        ['audit_log_event' => (bool) $row->event_execute, 'audit_log_capture' => (bool) $row->capture_execute],
        'Function → whether pyme_app has EXECUTE.',
    );
});

it('audit_log has only the policies for pyme_app to read and pyme_owner to insert, plus optionally owner_select, and none for UPDATE or DELETE', function (): void {
    $policies = auditCatalogTestPolicies();

    // owner_select exists only if INSERT ... RETURNING in audit_log_event needs it.
    $withoutOptional = array_values(array_diff($policies, ['owner_select | SELECT | {pyme_owner}']));

    $this->assertSame(
        ['owner_insert | INSERT | {pyme_owner}', 'tenant_isolation | SELECT | {pyme_app}'],
        $withoutOptional,
        'audit_log policies as "name | command | roles": no ALL, UPDATE or DELETE policy for anybody.',
    );
});

it('pyme_app has no privileges on the audit_log sequence', function (): void {
    $row = DB::selectOne(<<<'SQL'
        select has_sequence_privilege('pyme_app', 'public.audit_log_id_seq', 'USAGE') as can_use,
               has_sequence_privilege('pyme_app', 'public.audit_log_id_seq', 'SELECT') as can_select,
               has_sequence_privilege('pyme_app', 'public.audit_log_id_seq', 'UPDATE') as can_update
        SQL);

    $this->assertSame(
        ['USAGE' => false, 'SELECT' => false, 'UPDATE' => false],
        ['USAGE' => (bool) $row->can_use, 'SELECT' => (bool) $row->can_select, 'UPDATE' => (bool) $row->can_update],
        'Privilege → whether pyme_app has it on audit_log_id_seq (the functions run as the owner).',
    );
});

it('audit_log has the indexes by (company_id, table_name, record_id) and (company_id, occurred_at)', function (): void {
    $indexes = array_map(
        fn (object $row): string => $row->columns,
        DB::select(<<<'SQL'
            select string_agg(a.attname, ', ' order by k.ord) as columns
            from pg_index i
            cross join lateral unnest(i.indkey::int2[]) with ordinality as k(attnum, ord)
            join pg_attribute a on a.attrelid = i.indrelid and a.attnum = k.attnum
            where i.indrelid = to_regclass('public.audit_log')
            group by i.indexrelid
            order by 1
            SQL),
    );

    expect($indexes)->toContain('company_id, table_name, record_id');
    expect($indexes)->toContain('company_id, occurred_at');
});
