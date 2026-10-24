<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;

// Row level security by company on the tests-only table tenant_probe. The context is set with raw SQL on purpose:
// these tests prove RLS in Postgres without depending on DatabaseCompanyContext. A SQL error aborts the
// RefreshDatabase transaction in Postgres, so each failing statement lives in its own test.
uses(RefreshDatabaseAsOwner::class);

// Tables with a company_id column that are global on purpose (no RLS): the membership is read before there is an
// active company. Adding a table here is a design decision, see docs/decisiones.md, Multi-tenant.
const GLOBAL_TABLES_WITH_COMPANY_ID = ['company_user'];

// Tables with a company_id column that carry no audit trigger on purpose: audit_log is the ledger itself,
// document_counter changes on every posting (the document will be audited) and tenant_probe is the RLS-only probe
// (audit_probe is the one that carries the triggers).
const AUDIT_EXEMPT_TABLES = ['audit_log', 'document_counter', 'tenant_probe'];

// pg_trigger.tgtype bits: INSERT 4, DELETE 8, UPDATE 16.
const AUDIT_EVENTS_ALL = 4 + 8 + 16;

/**
 * Sets (or clears, with null) the company context of the connection using raw SQL.
 */
function setTenantContext(?int $companyId): void
{
    DB::select("select set_config('app.company_id', ?, false)", [$companyId === null ? '' : (string) $companyId]);
}

/**
 * Inserts two companies and one tenant_probe row for each, setting the context before each insert and never
 * passing company_id. Leaves the context cleared.
 *
 * @return array{a: int, b: int}
 */
function seedTwoCompaniesWithProbeRows(): array
{
    $now = now();
    $ids = [];

    foreach (['a' => 'Company A SAS', 'b' => 'Company B SAS'] as $key => $name) {
        $ids[$key] = (int) DB::table('companies')->insertGetId([
            'name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    foreach ($ids as $key => $companyId) {
        setTenantContext($companyId);
        DB::table('tenant_probe')->insert([
            ['label' => $key.'-1'],
            ['label' => $key.'-2'],
        ]);
    }

    setTenantContext(null);

    return $ids;
}

/**
 * Labels visible through pyme_app in the current context, sorted.
 *
 * @return list<string>
 */
function visibleProbeLabels(): array
{
    return DB::table('tenant_probe')->orderBy('label')->pluck('label')->all();
}

it('shows only the rows of the active company', function (): void {
    $ids = seedTwoCompaniesWithProbeRows();

    setTenantContext($ids['a']);
    $seenWithA = visibleProbeLabels();

    setTenantContext($ids['b']);
    $seenWithB = visibleProbeLabels();

    expect(['a' => $seenWithA, 'b' => $seenWithB])->toBe([
        'a' => ['a-1', 'a-2'],
        'b' => ['b-1', 'b-2'],
    ]);
});

it('shows no rows when the context is empty, even if there is data (fail closed)', function (): void {
    seedTwoCompaniesWithProbeRows();

    setTenantContext(null);

    expect(DB::select("select current_setting('app.company_id', true) as value")[0]->value)->toBe('');
    expect(DB::table('tenant_probe')->count())->toBe(0);
});

it('shows no rows when the context was never set in the session (fail closed)', function (): void {
    seedTwoCompaniesWithProbeRows();

    // reset_config-style reset: RESET removes the session value, so current_setting(..., true) is null or ''.
    DB::statement('reset app.company_id');

    expect(DB::table('tenant_probe')->count())->toBe(0);
});

it('stores the active company in company_id when the insert does not pass it', function (): void {
    $ids = seedTwoCompaniesWithProbeRows();

    setTenantContext($ids['a']);
    DB::table('tenant_probe')->insert(['label' => 'default-company']);

    $stored = DB::table('tenant_probe')->where('label', 'default-company')->value('company_id');

    expect((int) $stored)->toBe($ids['a']);
});

it('rejects an insert without company_id and without context (RLS policy, SQLSTATE 42501, fail closed)', function (): void {
    seedTwoCompaniesWithProbeRows();
    setTenantContext(null);

    // The WITH CHECK of the policy is evaluated before the NOT NULL constraint, so the policy rejects it first.
    expect(fn () => DB::table('tenant_probe')->insert(['label' => 'no-context']))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[0])->toBe('42501'));
});

it('rejects an insert with the company_id of another company (RLS violation, SQLSTATE 42501)', function (): void {
    $ids = seedTwoCompaniesWithProbeRows();
    setTenantContext($ids['a']);

    expect(fn () => DB::table('tenant_probe')->insert(['label' => 'forged', 'company_id' => $ids['b']]))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[0])->toBe('42501'));
});

it('rejects moving a row to another company with an update (RLS violation, SQLSTATE 42501)', function (): void {
    $ids = seedTwoCompaniesWithProbeRows();
    setTenantContext($ids['a']);

    expect(fn () => DB::table('tenant_probe')->where('label', 'a-1')->update(['company_id' => $ids['b']]))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[0])->toBe('42501'));
});

it('deletes nothing of another company and its rows survive', function (): void {
    $ids = seedTwoCompaniesWithProbeRows();

    setTenantContext($ids['a']);
    $affected = DB::table('tenant_probe')->where('company_id', $ids['b'])->delete();

    setTenantContext($ids['b']);
    $survivors = visibleProbeLabels();

    expect(['affected' => $affected, 'survivors' => $survivors])->toBe([
        'affected' => 0,
        'survivors' => ['b-1', 'b-2'],
    ]);
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
          and c.relkind in ('r', 'p')
          and exists (
              select 1 from pg_attribute a
              where a.attrelid = c.oid and a.attname = 'company_id' and not a.attisdropped
          )
        order by c.relname
        SQL) as $row) {
        if (in_array($row->relname, GLOBAL_TABLES_WITH_COMPANY_ID, true)) {
            continue;
        }

        $actual[$row->relname] = [(bool) $row->rls, (bool) $row->forced, (bool) $row->has_policy];
    }

    // Guard against passing on an empty result.
    expect($actual)->toHaveKey('tenant_probe');

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
          and c.relkind in ('r', 'p')
        order by c.relname
        SQL) as $row) {
        if (in_array($row->relname, GLOBAL_TABLES_WITH_COMPANY_ID, true)) {
            continue;
        }

        $actual[$row->relname] = [(bool) $row->not_null, (bool) $row->default_from_context];
    }

    // Guard against passing on an empty result.
    expect($actual)->toHaveKey('tenant_probe');

    $this->assertSame(
        array_fill_keys(array_keys($actual), [true, true]),
        $actual,
        'Table → [company_id NOT NULL, DEFAULT reads current_setting(app.company_id)] for every tenant table (second barrier).',
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
          and c.relkind in ('r', 'p')
          and exists (
              select 1 from pg_attribute a
              where a.attrelid = c.oid and a.attname = 'company_id' and not a.attisdropped
          )
        group by c.relname
        order by c.relname
        SQL) as $row) {
        if (in_array($row->relname, [...GLOBAL_TABLES_WITH_COMPANY_ID, ...AUDIT_EXEMPT_TABLES], true)) {
            continue;
        }

        $actual[$row->relname] = (int) $row->events;
    }

    // Guard against passing on an empty result: the real audited tables and the probe must be covered.
    expect($actual)->toHaveKeys(['audit_probe', 'document_type', 'period']);

    $this->assertSame(
        array_fill_keys(array_keys($actual), AUDIT_EVENTS_ALL),
        $actual,
        'Table → events covered by AFTER ROW triggers on audit_log_capture (INSERT 4 + DELETE 8 + UPDATE 16 = 28). A tenant table must cover all three.',
    );
});
