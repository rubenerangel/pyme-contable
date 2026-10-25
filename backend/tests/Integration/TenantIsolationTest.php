<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;
use Tests\Support\AccountFixtures;
use Tests\Support\TenantCatalog;

// Row level security by company on account, the reference table of the pattern. The context is set with raw SQL on
// purpose: these tests prove RLS in Postgres without depending on DatabaseCompanyContext. A SQL error aborts the
// RefreshDatabase transaction in Postgres, so each failing statement lives in its own test.
uses(RefreshDatabaseAsOwner::class);

// Tables with a company_id column that carry no audit trigger on purpose: audit_log is the ledger itself and
// document_counter changes on every posting (the document will be audited). audit_probe is the tests-only table that
// carries the triggers.
const AUDIT_EXEMPT_TABLES = ['audit_log', 'document_counter'];

// The company predicate every tenant policy must use, as Postgres 17 prints it in pg_policies. If a Postgres upgrade
// changes how it prints this expression, update it here after checking that the meaning is the same.
const CANONICAL_TENANT_PREDICATE = "(company_id = (NULLIF(current_setting('app.company_id'::text, true), ''::text))::bigint)";

// Policy columns shown as "name | permissive | command | roles | USING | WITH CHECK" ("-" when absent).
const CANONICAL_TENANT_POLICY = 'tenant_isolation | PERMISSIVE | ALL | {pyme_app} | '.CANONICAL_TENANT_PREDICATE.' | '.CANONICAL_TENANT_PREDICATE;

// Tables whose policies differ from the default (one tenant_isolation FOR ALL policy for pyme_app). audit_log is
// append-only: pyme_app can only read its company's rows (no INSERT, UPDATE or DELETE policy), and the SECURITY
// DEFINER functions, which run as pyme_owner, insert the rows. owner_select exists because INSERT ... RETURNING
// in audit_log_event needs SELECT on the row. Adding a table here is a design decision.
const POLICY_EXCEPTIONS = [
    'audit_log' => [
        'tenant_isolation | PERMISSIVE | SELECT | {pyme_app} | '.CANONICAL_TENANT_PREDICATE.' | -',
        'owner_insert | PERMISSIVE | INSERT | {pyme_owner} | - | '.CANONICAL_TENANT_PREDICATE,
        'owner_select | PERMISSIVE | SELECT | {pyme_owner} | '.CANONICAL_TENANT_PREDICATE.' | -',
    ],
];

// pg_trigger.tgtype bits: INSERT 4, DELETE 8, UPDATE 16.
const AUDIT_EVENTS_ALL = 4 + 8 + 16;

/**
 * Sets (or clears, with null) the company and the user context of the connection using raw SQL. The audit log of
 * account needs app.user_id on insert and delete.
 */
function setTenantContext(?int $companyId, ?int $userId = null): void
{
    DB::select(
        "select set_config('app.company_id', ?, false), set_config('app.user_id', ?, false)",
        [$companyId === null ? '' : (string) $companyId, $userId === null ? '' : (string) $userId],
    );
}

/**
 * Inserts a user, two companies and two account rows for each (a-1, a-2, b-1, b-2 as code), setting the context
 * before each insert and never passing company_id. Leaves the context cleared.
 *
 * @return array{a: int, b: int, user: int}
 */
function seedTwoCompaniesWithAccounts(): array
{
    $now = now();
    $userId = User::factory()->create()->id;
    $ids = [];

    foreach (['a' => 'Company A SAS', 'b' => 'Company B SAS'] as $key => $name) {
        $ids[$key] = (int) DB::table('companies')->insertGetId([
            'name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    foreach ($ids as $key => $companyId) {
        setTenantContext($companyId, $userId);
        DB::table('account')->insert([
            AccountFixtures::minimalRow($key.'-1'),
            AccountFixtures::minimalRow($key.'-2'),
        ]);
    }

    setTenantContext(null, null);

    return [...$ids, 'user' => $userId];
}

/**
 * Account codes visible through pyme_app in the current context, sorted.
 *
 * @return list<string>
 */
function visibleAccountCodes(): array
{
    return DB::table('account')->orderBy('code')->pluck('code')->all();
}

/**
 * The tables with a company_id column minus the global ones and $alsoExcluded, as a Postgres text[] literal to filter
 * with c.relname = any(?::text[]).
 *
 * @param  list<string>  $alsoExcluded
 */
function tenantTablesLiteral(array $alsoExcluded = []): string
{
    $tables = array_diff(
        TenantCatalog::tablesWithCompanyId(),
        TenantCatalog::GLOBAL_TABLES_WITH_COMPANY_ID,
        $alsoExcluded,
    );

    return '{'.implode(',', $tables).'}';
}

it('shows only the rows of the active company', function (): void {
    $ids = seedTwoCompaniesWithAccounts();

    setTenantContext($ids['a']);
    $seenWithA = visibleAccountCodes();

    setTenantContext($ids['b']);
    $seenWithB = visibleAccountCodes();

    expect(['a' => $seenWithA, 'b' => $seenWithB])->toBe([
        'a' => ['a-1', 'a-2'],
        'b' => ['b-1', 'b-2'],
    ]);
});

it('shows no rows when the context is empty, even if there is data (fail closed)', function (): void {
    seedTwoCompaniesWithAccounts();

    setTenantContext(null, null);

    expect(DB::select("select current_setting('app.company_id', true) as value")[0]->value)->toBe('');
    expect(DB::table('account')->count())->toBe(0);
});

it('shows no rows when the context was never set in the session (fail closed)', function (): void {
    seedTwoCompaniesWithAccounts();

    // reset_config-style reset: RESET removes the session value, so current_setting(..., true) is null or ''.
    DB::statement('reset app.company_id');

    expect(DB::table('account')->count())->toBe(0);
});

it('stores the active company in company_id when the insert does not pass it', function (): void {
    $ids = seedTwoCompaniesWithAccounts();

    setTenantContext($ids['a'], $ids['user']);
    DB::table('account')->insert(AccountFixtures::minimalRow('default-company'));

    $stored = DB::table('account')->where('code', 'default-company')->value('company_id');

    expect((int) $stored)->toBe($ids['a']);
});

it('rejects an insert without context (RLS policy, SQLSTATE 42501, fail closed)', function (): void {
    seedTwoCompaniesWithAccounts();
    setTenantContext(null, null);

    // The WITH CHECK of the policy is evaluated before the NOT NULL constraint, so the policy rejects it first. The
    // message tells it apart from the other 42501 causes (a missing privilege, audit_log_capture without app.user_id):
    // a WITH CHECK (true) would let the row reach NOT NULL (23502) and this test would see it.
    expect(fn () => DB::table('account')->insert(AccountFixtures::minimalRow('no-context')))
        ->toThrow(function (QueryException $e): void {
            expect($e->errorInfo[0])->toBe('42501');
            expect($e->getMessage())->toContain('new row violates row-level security policy for table "account"');
        });
});

// Not tested here: an insert with the company_id of another company. pyme_app has no INSERT privilege on company_id,
// so it cannot even try: AppPermissionsTest covers it (dataset with an explicit company_id, 42501 "permission denied").
// The WITH CHECK of the policy stays pinned by the exact policies test below and by the insert without context above.

it('rejects moving a row to another company with an update (no UPDATE privilege on company_id, SQLSTATE 42501)', function (): void {
    $ids = seedTwoCompaniesWithAccounts();
    setTenantContext($ids['a'], $ids['user']);

    expect(fn () => DB::table('account')->where('code', 'a-1')->update(['company_id' => $ids['b']]))
        ->toThrow(function (QueryException $e): void {
            expect($e->errorInfo[0])->toBe('42501');
            expect($e->getMessage())->toContain('permission denied for table account');
        });
});

it('deletes nothing of another company and its rows survive', function (): void {
    $ids = seedTwoCompaniesWithAccounts();

    setTenantContext($ids['a'], $ids['user']);
    $affected = DB::table('account')->where('company_id', $ids['b'])->delete();

    setTenantContext($ids['b']);
    $survivors = visibleAccountCodes();

    expect(['affected' => $affected, 'survivors' => $survivors])->toBe([
        'affected' => 0,
        'survivors' => ['b-1', 'b-2'],
    ]);
});

it('the public tables without a company_id column are exactly TenantCatalog::TABLES_WITHOUT_COMPANY_ID', function (): void {
    $actual = array_column(DB::select(<<<'SQL'
        select c.relname
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public'
          and c.relkind in ('r', 'p')
          and not exists (
              select 1 from pg_attribute a
              where a.attrelid = c.oid and a.attname = 'company_id' and not a.attisdropped
          )
        order by c.relname collate "C"
        SQL), 'relname');

    // Guard against passing on an empty result.
    expect($actual)->toContain('users');

    $extra = array_values(array_diff($actual, TenantCatalog::TABLES_WITHOUT_COMPANY_ID));
    $missing = array_values(array_diff(TenantCatalog::TABLES_WITHOUT_COMPANY_ID, $actual));

    $this->assertSame(
        TenantCatalog::TABLES_WITHOUT_COMPANY_ID,
        $actual,
        'Tables of public without company_id versus TenantCatalog::TABLES_WITHOUT_COMPANY_ID. Extra (a business table that forgot company_id, or a global one not declared): '
            .json_encode($extra).'. Missing (declared global but absent or with company_id): '.json_encode($missing).'.',
    );
});

it('every public table with a company_id column has RLS enabled, forced and at least one policy, except the global tables', function (): void {
    $actual = [];
    foreach (DB::select(<<<'SQL'
        select c.relname,
               c.relrowsecurity as rls,
               c.relforcerowsecurity as forced,
               exists (
                   select 1 from pg_policies p
                   where p.schemaname = n.nspname and p.tablename = c.relname
               ) as has_policy
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public'
          and c.relname = any(?::text[])
        order by c.relname
        SQL, [tenantTablesLiteral()]) as $row) {
        $actual[$row->relname] = [(bool) $row->rls, (bool) $row->forced, (bool) $row->has_policy];
    }

    // Guard against passing on an empty result.
    expect($actual)->toHaveKeys(['account', 'third_party', 'cost_center', 'construction_project']);

    $this->assertSame(
        array_fill_keys(array_keys($actual), [true, true, true]),
        $actual,
        'Table → [rls enabled, rls forced, has policy] for every tenant table. A table with company_id must have all three.',
    );
});

it('every public table with a company_id column also has it NOT NULL with a DEFAULT read from the context, except the global tables', function (): void {
    $actual = [];
    foreach (DB::select(<<<'SQL'
        select c.relname,
               a.attnotnull as not_null,
               coalesce(pg_get_expr(d.adbin, d.adrelid) like '%current_setting(''app.company_id''%', false) as default_from_context
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        join pg_attribute a on a.attrelid = c.oid and a.attname = 'company_id' and not a.attisdropped
        left join pg_attrdef d on d.adrelid = c.oid and d.adnum = a.attnum
        where n.nspname = 'public'
          and c.relname = any(?::text[])
        order by c.relname
        SQL, [tenantTablesLiteral()]) as $row) {
        $actual[$row->relname] = [(bool) $row->not_null, (bool) $row->default_from_context];
    }

    // Guard against passing on an empty result.
    expect($actual)->toHaveKeys(['account', 'third_party', 'cost_center', 'construction_project']);

    $this->assertSame(
        array_fill_keys(array_keys($actual), [true, true]),
        $actual,
        'Table → [company_id NOT NULL, DEFAULT reads current_setting(app.company_id)] for every tenant table (second barrier).',
    );
});

it('every public table with a company_id column has exactly the expected policies, except the global tables', function (): void {
    // Left join so that a tenant table with no policy at all shows up with an empty list instead of disappearing.
    $actual = [];
    foreach (DB::select(<<<'SQL'
        select c.relname,
               p.policyname || ' | ' || p.permissive || ' | ' || p.cmd || ' | ' || p.roles::text
                   || ' | ' || coalesce(p.qual, '-') || ' | ' || coalesce(p.with_check, '-') as policy
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        left join pg_policies p on p.schemaname = n.nspname and p.tablename = c.relname
        where n.nspname = 'public'
          and c.relname = any(?::text[])
        SQL, [tenantTablesLiteral()]) as $row) {
        $actual[$row->relname] ??= [];
        if ($row->policy !== null) {
            $actual[$row->relname][] = $row->policy;
        }
    }

    // Guard against passing on an empty result: the exception, account and the master tables must be covered.
    expect($actual)->toHaveKeys(['account', 'audit_log', 'third_party', 'cost_center', 'construction_project']);

    $expected = [];
    foreach (array_keys($actual) as $table) {
        $expected[$table] = POLICY_EXCEPTIONS[$table] ?? [CANONICAL_TENANT_POLICY];
    }

    // Sort in PHP so the comparison does not depend on the database collation.
    $sorted = static function (array $policiesByTable): array {
        ksort($policiesByTable);

        return array_map(static function (array $policies): array {
            sort($policies, SORT_STRING);

            return $policies;
        }, $policiesByTable);
    };
    $actual = $sorted($actual);
    $expected = $sorted($expected);

    $this->assertSame(
        $expected,
        $actual,
        'Table → policies as "name | permissive | command | roles | USING | WITH CHECK" ("-" when absent). Every tenant table has only tenant_isolation, PERMISSIVE, ALL, {pyme_app}, with the canonical company predicate in USING and WITH CHECK, unless it is declared in POLICY_EXCEPTIONS. An extra, missing or looser policy fails here.',
    );
});

it('every public tenant table, except the global and the exempt ones, has AFTER ROW triggers that run audit_log_capture for INSERT, UPDATE and DELETE', function (): void {
    $actual = [];
    foreach (DB::select(<<<'SQL'
        select c.relname,
               coalesce(
                   bit_or(t.tgtype & 28) filter (
                       where (t.tgtype & 1) = 1      -- FOR EACH ROW
                         and (t.tgtype & 2) = 0      -- not BEFORE
                         and (t.tgtype & 64) = 0     -- not INSTEAD OF
                         and p.proname = 'audit_log_capture'
                         and p.pronamespace = 'public'::regnamespace
                   ),
                   0
               )::int as events
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        left join pg_trigger t on t.tgrelid = c.oid and not t.tgisinternal
        left join pg_proc p on p.oid = t.tgfoid
        where n.nspname = 'public'
          and c.relname = any(?::text[])
        group by c.relname
        order by c.relname
        SQL, [tenantTablesLiteral(AUDIT_EXEMPT_TABLES)]) as $row) {
        $actual[$row->relname] = (int) $row->events;
    }

    // Guard against passing on an empty result: the real audited tables and the probe must be covered.
    expect($actual)->toHaveKeys(['account', 'audit_probe', 'document_type', 'period', 'third_party', 'cost_center', 'construction_project']);

    $this->assertSame(
        array_fill_keys(array_keys($actual), AUDIT_EVENTS_ALL),
        $actual,
        'Table → events covered by AFTER ROW triggers on audit_log_capture (INSERT 4 + DELETE 8 + UPDATE 16 = 28). A tenant table must cover all three.',
    );
});

it('every unique constraint and unique index of a tenant table, primary key aside, has company_id among its key columns', function (): void {
    // A UNIQUE without company_id would answer 23505 to one company when the value exists in another one: an existence
    // oracle across companies (docs/decisiones.md, Multi-tenant: unique keys and composite FKs with company_id). This
    // covers constraints (they own a unique index), plain, partial and expression indexes. An expression has indkey 0,
    // which never matches company_id, so it only counts if company_id is also a column of the index. INCLUDE columns
    // (beyond indnkeyatts) do not enforce uniqueness and are not counted.
    $actual = [];
    foreach (DB::select(<<<'SQL'
        select c.relname as table_name,
               i.relname as index_name,
               exists (
                   select 1
                   from pg_attribute a
                   join unnest(x.indkey::int2[]) with ordinality as k(attnum, pos) on k.attnum = a.attnum
                   where a.attrelid = c.oid
                     and a.attname = 'company_id'
                     and not a.attisdropped
                     and k.pos <= x.indnkeyatts
               ) as has_company_id
        from pg_index x
        join pg_class c on c.oid = x.indrelid
        join pg_class i on i.oid = x.indexrelid
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public'
          and c.relname = any(?::text[])
          and x.indisunique
          and not x.indisprimary
        order by c.relname, i.relname
        SQL, [tenantTablesLiteral()]) as $row) {
        $actual[$row->table_name.': '.$row->index_name] = (bool) $row->has_company_id;
    }

    // Guard against passing on an empty result: the unique keys of account and third_party must be seen.
    $seenTables = array_unique(array_map(fn (string $key) => explode(': ', $key)[0], array_keys($actual)));
    expect($seenTables)->toContain('account', 'third_party');

    $this->assertSame(
        [],
        array_keys(array_filter($actual, fn (bool $hasCompanyId) => ! $hasCompanyId)),
        'Unique constraints and unique indexes ("table: index") of tenant tables with no company_id among their key columns.',
    );
});
