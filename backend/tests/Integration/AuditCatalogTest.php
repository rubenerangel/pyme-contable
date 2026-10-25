<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;

// Catalog checks on the functions owned by pyme_owner or in public and on the audit machinery: the search_path of
// every function, who can execute the SECURITY DEFINER ones, the audit_log policies, sequence and indexes. That every
// tenant table has the audit triggers lives in TenantIsolationTest, next to the other per-table catalog checks that
// share its list of global tables.
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

// The functions the migrations write, as p.oid::regprocedure::text prints them: the guard against passing on an empty
// result.
const EXPECTED_FUNCTIONS = [
    'account_enforce_hierarchy()',
    'audit_log_capture()',
    'audit_log_event(text,jsonb)',
    'document_counter_check_increment()',
];

it('every function and procedure owned by pyme_owner or in public, SECURITY DEFINER or not, pins the exact search_path pg_catalog, public, pg_temp', function (): void {
    $actual = [];
    // Keyed by signature (p.oid::regprocedure::text, e.g. "audit_log_event(text,jsonb)"), not by name: an overload or a
    // same-named function in another schema gets its own entry and cannot be masked by a well-configured one. The
    // schema shows only when it is not on the search_path. Filtered by owner or schema: a function pyme_owner creates
    // in any schema is covered, and so is one in public created by another role (postgres in db/init or a hotfix),
    // which is the riskiest if SECURITY DEFINER. Functions of extensions are not excluded: there are none today. The
    // value must be exactly "pg_catalog, public, pg_temp". Anything between pg_catalog and pg_temp, such as "$user", lets a schema named
    // after the role shadow a table. pg_temp must be listed explicitly and last: if it is not in the search_path,
    // Postgres searches the session's temporary schema first, so a role with TEMP could shadow a table (audit_log,
    // account) with a temporary one. This also holds for INVOKER trigger functions, which run with the search_path of
    // whoever fires the trigger. prokind 'p' (procedures) is included so a future CREATE PROCEDURE cannot skip the
    // rule.
    foreach (DB::select(<<<'SQL'
        select p.oid::regprocedure::text as signature,
               'search_path=pg_catalog, public, pg_temp' = any (coalesce(p.proconfig, '{}'::text[])) as pinned_search_path
        from pg_proc p
        where (p.pronamespace = 'public'::regnamespace or p.proowner = 'pyme_owner'::regrole)
          and p.prokind in ('f', 'p')
        order by 1
        SQL) as $row) {
        $actual[$row->signature] = (bool) $row->pinned_search_path;
    }

    // Guard against passing on an empty result.
    expect($actual)->toHaveKeys(EXPECTED_FUNCTIONS);

    $this->assertSame(
        array_fill_keys(array_keys($actual), true),
        $actual,
        'Function or procedure signature → search_path pinned to exactly "pg_catalog, public, pg_temp", for every one '
            .'owned by pyme_owner or in public.',
    );
});

it('no SECURITY DEFINER function or procedure owned by pyme_owner or in public has EXECUTE for PUBLIC', function (): void {
    // Keyed by signature and filtered by owner or schema, as in the search_path test. acldefault('f', ...) is also the
    // default for procedures.
    // INVOKER trigger functions keep the default EXECUTE for PUBLIC and that is acceptable: calling one directly fails
    // with 0A000 (trigger functions can only be called as triggers).
    $actual = [];
    foreach (DB::select(<<<'SQL'
        select p.oid::regprocedure::text as signature,
               exists (
                   select 1 from aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) as a
                   where a.grantee = 0 and a.privilege_type = 'EXECUTE'
               ) as public_can_execute
        from pg_proc p
        where (p.pronamespace = 'public'::regnamespace or p.proowner = 'pyme_owner'::regrole)
          and p.prokind in ('f', 'p')
          and p.prosecdef
        order by 1
        SQL) as $row) {
        $actual[$row->signature] = (bool) $row->public_can_execute;
    }

    // Guard against passing on an empty result.
    expect($actual)->toHaveKeys(['audit_log_capture()', 'audit_log_event(text,jsonb)']);

    $this->assertSame(
        array_fill_keys(array_keys($actual), false),
        $actual,
        'Function or procedure signature → whether PUBLIC can EXECUTE it, for every SECURITY DEFINER one owned by pyme_owner or '
            .'in public.',
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
