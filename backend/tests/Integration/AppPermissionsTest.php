<?php

declare(strict_types=1);

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;
use Tests\Support\AccountFixtures;
use Tests\Support\TenantCatalog;

// The application runs as pyme_app (default connection) and migrations run as pyme_owner
// (pgsql_owner connection, same database). These tests pin the privilege split between both.
// Privileges are read with has_table_privilege for each Postgres 17 table privilege,
// so any extra privilege shows up in the assertSame diff.
uses(RefreshDatabaseAsOwner::class);

// Complete map of every table in public (except migrations) to the privileges pyme_app must have on it, in
// alphabetical order. A new table without an entry here makes the privileges test fail.
// account, document_counter, document_type and period have no table-level INSERT or UPDATE: pyme_app inserts only the
// columns declared in EXPECTED_COLUMN_INSERTS (never id or company_id, which take their DEFAULT) and updates only the
// columns declared in EXPECTED_COLUMN_UPDATES.
// audit_log is read-only for pyme_app: it is written by SECURITY DEFINER functions that run as pyme_owner, so it has no
// INSERT or UPDATE of any kind and is declared with an empty list in EXPECTED_COLUMN_INSERTS and EXPECTED_COLUMN_UPDATES.
// audit_probe is a tests-only table (backend/tests/database/migrations): it exists in public only during the tests. It
// is the template of the audited tables, so it is no laxer than they are: no table-level INSERT or UPDATE, and column
// privileges only on label.
const EXPECTED_TABLE_PRIVILEGES = [
    'account' => ['DELETE', 'SELECT'],
    'audit_log' => ['SELECT'],
    'audit_probe' => ['DELETE', 'SELECT'],
    'cache' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
    'cache_locks' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
    'companies' => ['INSERT', 'SELECT', 'UPDATE'],
    'company_user' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
    'document_counter' => ['SELECT'],
    'document_type' => ['SELECT'],
    'failed_jobs' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
    'job_batches' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
    'jobs' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
    'password_reset_tokens' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
    'period' => ['SELECT'],
    'sessions' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
    'users' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
];

// Complete map of every table in public (except migrations) on which pyme_app has no table-level INSERT to the columns
// where it has INSERT (column privilege), in alphabetical order. A new table without table-level INSERT must be
// declared here, even if its list is empty. id and company_id are never listed: they take their DEFAULT. The generated
// period columns (type, start_date, end_date) are not listed either.
const EXPECTED_COLUMN_INSERTS = [
    'account' => [
        'code',
        'is_active',
        'is_postable',
        'name',
        'normal_balance',
        'parent_id',
        'requires_construction_project',
        'requires_cost_center',
        'requires_municipality',
        'requires_tax_detail',
        'requires_third_party',
    ],
    'audit_log' => [],
    'audit_probe' => ['label'],
    'document_counter' => ['document_type_id', 'last_number', 'year'],
    'document_type' => ['code', 'name', 'resets_yearly'],
    'period' => ['number', 'status', 'year'],
];

// Tables with a company_id column on which pyme_app may insert id or company_id, each for its own reason.
const TABLES_ALLOWED_TO_INSERT_ID_OR_COMPANY_ID = [
    // The membership is global and the application assigns its company_id (see
    // TenantCatalog::GLOBAL_TABLES_WITH_COMPANY_ID).
    'company_user',
];

// Complete map of every table in public (except migrations) on which pyme_app has no table-level UPDATE to the columns
// where it has UPDATE (column privilege), in alphabetical order. A new table without table-level UPDATE must be
// declared here, even if its list is empty.
const EXPECTED_COLUMN_UPDATES = [
    'account' => [
        'code',
        'is_active',
        'is_postable',
        'name',
        'normal_balance',
        'parent_id',
        'requires_construction_project',
        'requires_cost_center',
        'requires_municipality',
        'requires_tax_detail',
        'requires_third_party',
    ],
    'audit_log' => [],
    'audit_probe' => ['label'],
    'document_counter' => ['last_number'],
    'document_type' => ['name'],
    'period' => ['status'],
];

const TABLE_PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER', 'MAINTAIN'];

/**
 * Table privileges that $role has on public.$table, in alphabetical order.
 * If the table does not exist, to_regclass returns null and the list comes back empty.
 *
 * @return list<string>
 */
function tablePrivilegesOf(string $role, string $table): array
{
    return array_column(DB::select(<<<'SQL'
        select p.privilege
        from unnest(?::text[]) as p(privilege)
        where has_table_privilege(?, to_regclass('public.' || quote_ident(?)), p.privilege)
        order by p.privilege
        SQL, ['{'.implode(',', TABLE_PRIVILEGES).'}', $role, $table]), 'privilege');
}

it('the default connection is pyme_app and pgsql_owner is pyme_owner, both on pyme_test', function (): void {
    $sql = 'select current_user as username, current_database() as database';

    $default = DB::selectOne($sql);
    $owner = DB::connection('pgsql_owner')->selectOne($sql);

    $this->assertSame(
        [
            'default' => ['username' => 'pyme_app', 'database' => 'pyme_test'],
            'pgsql_owner' => ['username' => 'pyme_owner', 'database' => 'pyme_test'],
        ],
        [
            'default' => ['username' => $default->username, 'database' => $default->database],
            'pgsql_owner' => ['username' => $owner->username, 'database' => $owner->database],
        ],
        'Expected user and database per connection: the app as pyme_app, migrations as pyme_owner.',
    );
});

it('all tables in the public schema belong to pyme_owner', function (): void {
    $owners = [];
    foreach (DB::select(<<<'SQL'
        select c.relname, r.rolname
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        join pg_roles r on r.oid = c.relowner
        where n.nspname = 'public'
          and c.relkind in ('r', 'p')
        order by c.relname
        SQL) as $row) {
        $owners[$row->relname] = $row->rolname;
    }

    $this->assertSame(
        [],
        array_values(array_diff([...array_keys(EXPECTED_TABLE_PRIVILEGES), 'migrations'], array_keys($owners))),
        'Tables are missing from public: was the test database migrated?',
    );

    $this->assertSame(
        array_fill_keys(array_keys($owners), 'pyme_owner'),
        $owners,
        'Every table in public must belong to pyme_owner (table → owner map).',
    );
});

it('pyme_app has exactly the expected privileges on every public table except migrations', function (): void {
    $actual = [];
    foreach (DB::select(<<<'SQL'
        select c.relname
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public'
          and c.relkind in ('r', 'p')
          and c.relname <> 'migrations'
        order by c.relname
        SQL) as $row) {
        $actual[$row->relname] = tablePrivilegesOf('pyme_app', $row->relname);
    }

    $expected = EXPECTED_TABLE_PRIVILEGES;
    ksort($expected);
    ksort($actual);

    $this->assertSame(
        $expected,
        $actual,
        'pyme_app privileges per table (table → privileges). A table missing from EXPECTED_TABLE_PRIVILEGES shows up as extra in the diff.',
    );
});

it('pyme_app has exactly the expected UPDATE columns on every public table without table-level UPDATE', function (): void {
    $actual = [];
    foreach (DB::select(<<<'SQL'
        select c.oid, c.relname
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public'
          and c.relkind in ('r', 'p')
          and c.relname <> 'migrations'
          and not has_table_privilege('pyme_app', c.oid, 'UPDATE')
        order by c.relname
        SQL) as $table) {
        $columns = array_column(DB::select(<<<'SQL'
            select a.attname
            from pg_attribute a
            where a.attrelid = ?
              and a.attnum > 0
              and not a.attisdropped
              and has_column_privilege('pyme_app', a.attrelid, a.attnum, 'UPDATE')
            order by a.attname
            SQL, [$table->oid]), 'attname');

        $actual[$table->relname] = $columns;
    }

    $this->assertSame(
        EXPECTED_COLUMN_UPDATES,
        $actual,
        'Table → columns where pyme_app has UPDATE, for every table without table-level UPDATE. A new table without table-level UPDATE must be declared in EXPECTED_COLUMN_UPDATES.',
    );
});

it('pyme_app has exactly the expected INSERT columns on every public table without table-level INSERT', function (): void {
    $actual = [];
    foreach (DB::select(<<<'SQL'
        select c.oid, c.relname
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public'
          and c.relkind in ('r', 'p')
          and c.relname <> 'migrations'
          and not has_table_privilege('pyme_app', c.oid, 'INSERT')
        order by c.relname
        SQL) as $table) {
        $columns = array_column(DB::select(<<<'SQL'
            select a.attname
            from pg_attribute a
            where a.attrelid = ?
              and a.attnum > 0
              and not a.attisdropped
              and has_column_privilege('pyme_app', a.attrelid, a.attnum, 'INSERT')
            order by a.attname
            SQL, [$table->oid]), 'attname');

        $actual[$table->relname] = $columns;
    }

    $this->assertSame(
        EXPECTED_COLUMN_INSERTS,
        $actual,
        'Table → columns where pyme_app has INSERT, for every table without table-level INSERT. A new table without table-level INSERT must be declared in EXPECTED_COLUMN_INSERTS.',
    );
});

it('pyme_app cannot insert id or company_id on any public table with a company_id column, except the declared ones', function (): void {
    $tenantTables = TenantCatalog::tablesWithCompanyId();

    // Guard against passing on an empty result: the catalog must see the tenant tables this rule is about.
    foreach (['account', 'audit_log', 'document_counter', 'document_type', 'period'] as $table) {
        $this->assertContains($table, $tenantTables, "The catalog query must find $table among the tables with a company_id column.");
    }

    $actual = [];
    foreach (DB::select(<<<'SQL'
        select c.relname, a.attname
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        join pg_attribute a on a.attrelid = c.oid
        where n.nspname = 'public'
          and c.relname = any(?::text[])
          and a.attnum > 0
          and not a.attisdropped
          and a.attname in ('id', 'company_id')
          and has_column_privilege('pyme_app', c.oid, a.attnum, 'INSERT')
        order by c.relname, a.attname
        SQL, ['{'.implode(',', $tenantTables).'}']) as $row) {
        if (in_array($row->relname, TABLES_ALLOWED_TO_INSERT_ID_OR_COMPANY_ID, true)) {
            continue;
        }

        $actual[] = $row->relname.'.'.$row->attname;
    }

    $this->assertSame([], $actual, 'Table.column pairs where pyme_app can insert id or company_id and must not.');
});

it('pyme_app has no privileges on migrations', function (): void {
    $this->assertSame(
        [],
        tablePrivilegesOf('pyme_app', 'migrations'),
        'pyme_app must not touch the migrations table.',
    );
});

it('pyme_app has no TRUNCATE, REFERENCES, TRIGGER or MAINTAIN on any public table', function (): void {
    $extra = array_column(DB::select(<<<'SQL'
        select c.relname || ': ' || p.privilege as pair
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        cross join unnest(array['TRUNCATE', 'REFERENCES', 'TRIGGER', 'MAINTAIN']) as p(privilege)
        where n.nspname = 'public'
          and c.relkind in ('r', 'p')
          and has_table_privilege('pyme_app', c.oid, p.privilege)
        order by c.relname, p.privilege
        SQL), 'pair');

    $this->assertSame([], $extra, 'Table: privilege pairs that pyme_app must not have.');
});

it('pyme_app can insert into jobs without an id and gets one (USAGE on jobs_id_seq)', function (): void {
    $id = DB::table('jobs')->insertGetId([
        'queue' => 'test',
        'payload' => '{}',
        'attempts' => 0,
        'available_at' => 0,
        'created_at' => 0,
    ]);

    $this->assertGreaterThan(0, (int) $id, 'The insert into jobs as pyme_app must return a generated id.');
});

/**
 * Creates a company and enters its context on the default connection (pyme_app).
 */
function enterCompanyForSequenceChecks(): Company
{
    $company = Company::factory()->create();
    app(DatabaseCompanyContext::class)->enter($company->id, User::factory()->create()->id);

    return $company;
}

it('pyme_app can insert into period without an id and gets one (USAGE on period_id_seq)', function (): void {
    enterCompanyForSequenceChecks();

    $id = DB::table('period')->insertGetId(['year' => 2026, 'number' => 1]);

    $this->assertGreaterThan(0, (int) $id, 'The insert into period as pyme_app must return a generated id.');
});

it('pyme_app can insert into document_type without an id and gets one (USAGE on document_type_id_seq)', function (): void {
    enterCompanyForSequenceChecks();

    $id = DB::table('document_type')->insertGetId(['code' => 'INV', 'name' => 'Invoice', 'resets_yearly' => true]);

    $this->assertGreaterThan(0, (int) $id, 'The insert into document_type as pyme_app must return a generated id.');
});

it('pyme_app can insert into document_counter without an id and gets one (USAGE on document_counter_id_seq)', function (): void {
    enterCompanyForSequenceChecks();
    $typeId = DB::table('document_type')->insertGetId(['code' => 'INV', 'name' => 'Invoice', 'resets_yearly' => true]);

    $id = DB::table('document_counter')->insertGetId(['document_type_id' => $typeId, 'year' => 2026, 'last_number' => 1]);

    $this->assertGreaterThan(0, (int) $id, 'The insert into document_counter as pyme_app must return a generated id.');
});

const TENANT_TABLES_WITH_COLUMN_INSERTS = [
    'account' => ['account'],
    'period' => ['period'],
    'document_type' => ['document_type'],
    'document_counter' => ['document_counter'],
];

/**
 * A minimal valid row for the table, without id or company_id. The company context must already be entered, because
 * document_counter needs a document type of the company first.
 *
 * @return array<string, mixed>
 */
function appPermissionsMinimalRow(string $table): array
{
    return match ($table) {
        'account' => AccountFixtures::minimalRow(),
        'period' => ['year' => 2026, 'number' => 1],
        'document_type' => ['code' => 'INV', 'name' => 'Invoice', 'resets_yearly' => true],
        // last_number must be 1 on insert (counter trigger).
        'document_counter' => [
            'document_type_id' => DB::table('document_type')->insertGetId(['code' => 'INV', 'name' => 'Invoice', 'resets_yearly' => true]),
            'year' => 2026,
            'last_number' => 1,
        ],
    };
}

/**
 * Asserts that the insert is rejected for lack of privilege on the column: SQLSTATE 42501 and the message Postgres 17
 * gives for a missing privilege ("permission denied for table ..."). The message tells it apart from the other 42501
 * causes: the WITH CHECK of RLS ("new row violates row-level security policy") and audit_log_capture without
 * app.user_id.
 */
function appPermissionsExpectInsertDenied(Closure $statement): void
{
    expect($statement)->toThrow(function (QueryException $e): void {
        expect($e->errorInfo[0])->toBe('42501');
        expect($e->getMessage())->toContain('permission denied for table');
    });
}

// One statement per test: a SQL error aborts the transaction of RefreshDatabase. The rows are otherwise valid, so the
// only reason to fail is the privilege on the column.
it('does not let pyme_app insert an explicit id (no INSERT privilege on the column, SQLSTATE 42501)', function (string $table): void {
    enterCompanyForSequenceChecks();
    $row = appPermissionsMinimalRow($table);

    appPermissionsExpectInsertDenied(fn () => DB::table($table)->insert($row + ['id' => 900_000_001]));
})->with(TENANT_TABLES_WITH_COLUMN_INSERTS);

it('does not let pyme_app insert an explicit company_id, not even its own (no INSERT privilege on the column, SQLSTATE 42501)', function (string $table): void {
    $company = enterCompanyForSequenceChecks();
    $row = appPermissionsMinimalRow($table);

    appPermissionsExpectInsertDenied(fn () => DB::table($table)->insert($row + ['company_id' => $company->id]));
})->with(TENANT_TABLES_WITH_COLUMN_INSERTS);

it('there are no default privileges (pg_default_acl is empty)', function (): void {
    $rows = array_column(DB::select(<<<'SQL'
        select defaclobjtype::text || ' ' || defaclacl::text as acl
        from pg_default_acl
        order by 1
        SQL), 'acl');

    $this->assertSame([], $rows, 'pg_default_acl must be empty: no default privileges.');
});

it('a new table created by pyme_owner starts with no privileges for pyme_app', function (): void {
    // Provisional until block 4. RefreshDatabaseAsOwner does not wrap pgsql_owner: the rollback is mandatory.
    $owner = DB::connection('pgsql_owner');

    $owner->beginTransaction();
    try {
        $owner->statement('create table app_permissions_probe (id bigint primary key)');

        $privileges = [];
        foreach (TABLE_PRIVILEGES as $privilege) {
            $privileges[$privilege] = (bool) $owner->selectOne(
                "select has_table_privilege('pyme_app', 'public.app_permissions_probe', ?) as has_privilege",
                [$privilege],
            )->has_privilege;
        }

        $this->assertSame(
            array_fill_keys(TABLE_PRIVILEGES, false),
            $privileges,
            'A new table must grant no privileges to pyme_app until a migration grants them.',
        );
    } finally {
        $owner->rollBack();
    }
});
