<?php

declare(strict_types=1);

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;
use Tests\Support\AccountFixtures;
use Tests\Support\LedgerFixtures;
use Tests\Support\MasterDataFixtures;
use Tests\Support\SqlAssertions;
use Tests\Support\TenantCatalog;

// The application runs as pyme_app (default connection) and migrations run as pyme_owner
// (pgsql_owner connection, same database). These tests pin the privilege split between both.
// Privileges are read with has_table_privilege for each Postgres 17 table privilege,
// so any extra privilege shows up in the assertSame diff.
uses(RefreshDatabaseAsOwner::class);

// Complete map of every table in public (except migrations) to the privileges pyme_app must have on it, in
// alphabetical order. A new table without an entry here makes the privileges test fail.
// account, construction_project, cost_center, document, document_counter, document_type, period and third_party have no
// table-level INSERT or UPDATE: pyme_app inserts only the
// columns declared in EXPECTED_COLUMN_INSERTS (never id or company_id, which take their DEFAULT) and updates only the
// columns declared in EXPECTED_COLUMN_UPDATES.
// journal_entry and journal_entry_line are the ledger: insert-only for pyme_app (SELECT, and INSERT only by column), with
// no UPDATE and no DELETE of any kind, and an empty list in EXPECTED_COLUMN_UPDATES.
// audit_log is read-only for pyme_app: it is written by SECURITY DEFINER functions that run as pyme_owner, so it has no
// INSERT or UPDATE of any kind and is declared with an empty list in EXPECTED_COLUMN_INSERTS and EXPECTED_COLUMN_UPDATES.
// municipality is the global catalog of DANE (DIVIPOLA), seeded by the migration that creates it: read-only for pyme_app,
// with an empty list in EXPECTED_COLUMN_INSERTS and EXPECTED_COLUMN_UPDATES like audit_log, and no privilege on its sequence.
// audit_probe is a tests-only table (backend/tests/database/migrations): it exists in public only during the tests. It
// is the template of the audited tables, so it is no laxer than they are: no table-level INSERT or UPDATE, and column
// privileges only on label.
// No table has UPDATE on a primary key column, except cache.key (see PRIMARY_KEY_COLUMNS_ALLOWED_TO_UPDATE). So the
// global Laravel tables (companies, company_user, users, sessions, jobs, job_batches, failed_jobs,
// password_reset_tokens, cache_locks) have no table-level UPDATE either: their updatable columns are declared in
// EXPECTED_COLUMN_UPDATES, and company_user has no UPDATE of any kind. Only cache keeps table-level UPDATE.
const EXPECTED_TABLE_PRIVILEGES = [
    'account' => ['DELETE', 'SELECT'],
    'audit_log' => ['SELECT'],
    'audit_probe' => ['DELETE', 'SELECT'],
    'cache' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
    'cache_locks' => ['DELETE', 'INSERT', 'SELECT'],
    'companies' => ['INSERT', 'SELECT'],
    'company_user' => ['DELETE', 'INSERT', 'SELECT'],
    'construction_project' => ['DELETE', 'SELECT'],
    'cost_center' => ['DELETE', 'SELECT'],
    'document' => ['DELETE', 'SELECT'],
    'document_counter' => ['SELECT'],
    'document_type' => ['SELECT'],
    'failed_jobs' => ['DELETE', 'INSERT', 'SELECT'],
    'job_batches' => ['DELETE', 'INSERT', 'SELECT'],
    'jobs' => ['DELETE', 'INSERT', 'SELECT'],
    'journal_entry' => ['SELECT'],
    'journal_entry_line' => ['SELECT'],
    'municipality' => ['SELECT'],
    'password_reset_tokens' => ['DELETE', 'INSERT', 'SELECT'],
    'period' => ['SELECT'],
    'sessions' => ['DELETE', 'INSERT', 'SELECT'],
    'third_party' => ['DELETE', 'SELECT'],
    'users' => ['DELETE', 'INSERT', 'SELECT'],
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
    'construction_project' => ['code', 'is_active', 'name'],
    'cost_center' => ['code', 'is_active', 'name'],
    'document' => ['description', 'document_date', 'document_type_id', 'reverses_document_id'],
    'document_counter' => ['document_type_id', 'last_number', 'year'],
    'document_type' => ['code', 'name', 'resets_yearly'],
    'journal_entry' => ['accounting_date', 'document_id', 'number', 'period_id'],
    'journal_entry_line' => [
        'account_id',
        'construction_project_id',
        'cost_center_id',
        'credit',
        'debit',
        'description',
        'journal_entry_id',
        'municipality_id',
        'third_party_id',
    ],
    'municipality' => [],
    'period' => ['number', 'status', 'year'],
    'third_party' => [
        'business_name',
        'country',
        'first_name',
        'first_surname',
        'id_number',
        'id_type',
        'is_active',
        'middle_names',
        'person_type',
        'second_surname',
    ],
];

// Tables with a company_id column on which pyme_app may insert id or company_id, each for its own reason.
const TABLES_ALLOWED_TO_INSERT_ID_OR_COMPANY_ID = [
    // The membership is global and the application assigns its company_id (see
    // TenantCatalog::GLOBAL_TABLES_WITH_COMPANY_ID).
    'company_user',
];

// Complete map of every table in public (except migrations) on which pyme_app has no table-level UPDATE to the columns
// where it has UPDATE (column privilege), in alphabetical order. A new table without table-level UPDATE must be
// declared here, even if its list is empty. No primary key column is listed (see PRIMARY_KEY_COLUMNS_ALLOWED_TO_UPDATE);
// company_user has no UPDATE of any kind: a membership is replaced (DELETE and INSERT), never edited.
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
    'cache_locks' => ['expiration', 'owner'],
    'companies' => ['name', 'updated_at'],
    'company_user' => [],
    'construction_project' => ['code', 'is_active', 'name'],
    'cost_center' => ['code', 'is_active', 'name'],
    'document' => ['description', 'document_date', 'document_type_id', 'reverses_document_id'],
    'document_counter' => ['last_number'],
    'document_type' => ['name'],
    'failed_jobs' => ['connection', 'exception', 'failed_at', 'payload', 'queue', 'uuid'],
    'job_batches' => [
        'cancelled_at',
        'created_at',
        'failed_job_ids',
        'failed_jobs',
        'finished_at',
        'name',
        'options',
        'pending_jobs',
        'total_jobs',
    ],
    'jobs' => ['attempts', 'available_at', 'created_at', 'payload', 'queue', 'reserved_at'],
    'journal_entry' => [],
    'journal_entry_line' => [],
    'municipality' => [],
    'password_reset_tokens' => ['created_at', 'token'],
    'period' => ['status'],
    'sessions' => ['ip_address', 'last_activity', 'payload', 'user_agent', 'user_id'],
    'third_party' => [
        'business_name',
        'country',
        'first_name',
        'first_surname',
        'id_number',
        'id_type',
        'is_active',
        'middle_names',
        'person_type',
        'second_surname',
    ],
    'users' => ['created_at', 'email', 'email_verified_at', 'name', 'password', 'remember_token', 'updated_at'],
];

// Primary key columns (table.column) of public tables on which pyme_app may have UPDATE, each for its own reason.
const PRIMARY_KEY_COLUMNS_ALLOWED_TO_UPDATE = [
    // DatabaseStore::putMany calls upsert($values, 'key') without $update, and the query builder then generates
    // ON CONFLICT (key) DO UPDATE SET key = excluded.key, ... : without UPDATE on key the cache fails with 42501.
    'cache.key',
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
    foreach (['account', 'audit_log', 'construction_project', 'cost_center', 'document', 'document_counter', 'document_type', 'journal_entry', 'journal_entry_line', 'period', 'third_party'] as $table) {
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

it('pyme_app has no UPDATE on any primary key column of a public table, except the declared ones', function (): void {
    $primaryKeyColumns = <<<'SQL'
        select c.relname || '.' || a.attname as pair, has_column_privilege('pyme_app', c.oid, a.attnum, 'UPDATE') as can_update
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        join pg_index i on i.indrelid = c.oid and i.indisprimary
        join pg_attribute a on a.attrelid = c.oid and a.attnum = any(i.indkey)
        where n.nspname = 'public'
          and c.relkind in ('r', 'p')
        order by c.relname, a.attname
        SQL;
    $rows = DB::select($primaryKeyColumns);

    // Guard against passing on an empty result: the query must see the primary keys this rule is about.
    $all = array_column($rows, 'pair');
    foreach (['account.id', 'companies.id', 'company_user.company_id', 'company_user.user_id', 'users.id'] as $pair) {
        $this->assertContains($pair, $all, "The catalog query must find $pair among the primary key columns.");
    }

    $actual = [];
    foreach ($rows as $row) {
        if ($row->can_update) {
            $actual[] = $row->pair;
        }
    }
    sort($actual);

    // assertSame in both directions: a new offender fails, and so does an exception that is no longer needed.
    $this->assertSame(
        PRIMARY_KEY_COLUMNS_ALLOWED_TO_UPDATE,
        $actual,
        'Table.column primary key pairs where pyme_app has UPDATE (table or column privilege). Only the declared exceptions are allowed.',
    );
});

// One statement per test: a SQL error aborts the transaction of RefreshDatabase. The row exists, so the UPDATE reaches the
// privilege check on a real row. The message tells a missing privilege apart from the other 42501 causes (RLS).
it('does not let pyme_app update a primary key column (no UPDATE privilege on the column, SQLSTATE 42501)', function (string $table, string $column): void {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The new value is the current one: the only reason to fail is the privilege on the column.
    $current = match ("$table.$column") {
        'companies.id', 'company_user.company_id' => $company->id,
        'company_user.user_id', 'users.id' => $user->id,
    };
    $where = match ($table) {
        'companies' => ['id' => $company->id],
        'users' => ['id' => $user->id],
        'company_user' => ['company_id' => $company->id, 'user_id' => $user->id],
    };

    SqlAssertions::expectRejected(
        fn () => DB::table($table)->where($where)->update([$column => $current]),
        '42501',
        message: 'permission denied for table',
    );
})->with([
    'companies.id' => ['companies', 'id'],
    'company_user.company_id' => ['company_user', 'company_id'],
    'company_user.user_id' => ['company_user', 'user_id'],
    'users.id' => ['users', 'id'],
]);

it('lets pyme_app update the name of a company and moves updated_at', function (): void {
    $company = Company::factory()->create(['name' => 'Before']);
    DB::table('companies')->where('id', $company->id)->update(['updated_at' => '2020-01-01 00:00:00']);
    $company->refresh();

    $company->name = 'After';
    $company->save();

    $stored = DB::table('companies')->where('id', $company->id)->first();
    $this->assertSame('After', $stored->name, 'The name must be updated as pyme_app.');
    $this->assertNotSame('2020-01-01 00:00:00', substr((string) $stored->updated_at, 0, 19), 'updated_at must change with the save.');
});

it('lets pyme_app update the name of a user', function (): void {
    $user = User::factory()->create(['name' => 'Before']);

    $user->name = 'After';
    $user->save();

    $this->assertSame('After', DB::table('users')->where('id', $user->id)->value('name'), 'The name must be updated as pyme_app.');
});

it('lets pyme_app update the remember token of a user', function (): void {
    $user = User::factory()->create();

    $user->setRememberToken('remember-token-0123456789');
    $user->save();

    $this->assertSame(
        'remember-token-0123456789',
        DB::table('users')->where('id', $user->id)->value('remember_token'),
        'The remember token must be updated as pyme_app.',
    );
});

it('pyme_app has no privileges on the municipality sequence', function (): void {
    $row = DB::selectOne(<<<'SQL'
        select has_sequence_privilege('pyme_app', 'public.municipality_id_seq', 'USAGE') as can_use,
               has_sequence_privilege('pyme_app', 'public.municipality_id_seq', 'SELECT') as can_select,
               has_sequence_privilege('pyme_app', 'public.municipality_id_seq', 'UPDATE') as can_update
        SQL);

    $this->assertSame(
        ['USAGE' => false, 'SELECT' => false, 'UPDATE' => false],
        ['USAGE' => (bool) $row->can_use, 'SELECT' => (bool) $row->can_select, 'UPDATE' => (bool) $row->can_update],
        'Privilege → whether pyme_app has it on municipality_id_seq (only the owner writes the catalog).',
    );
});

// The balance trigger of journal_entry_line validates an entry only when the inserted line has the greatest id of its
// entry. That rule holds while ids only grow: setval would let a caller move the sequence back and hand out a smaller id
// after a greater one, so pyme_app has USAGE (nextval) and nothing else on the sequence.
it('does not let pyme_app move journal_entry_line_id_seq backwards (only USAGE, no UPDATE: setval gives SQLSTATE 42501)', function (): void {
    $row = DB::selectOne(<<<'SQL'
        select has_sequence_privilege('pyme_app', 'public.journal_entry_line_id_seq', 'USAGE') as can_use,
               has_sequence_privilege('pyme_app', 'public.journal_entry_line_id_seq', 'UPDATE') as can_update
        SQL);

    $this->assertSame(
        ['USAGE' => true, 'UPDATE' => false],
        ['USAGE' => (bool) $row->can_use, 'UPDATE' => (bool) $row->can_update],
        'Privilege → whether pyme_app has it on journal_entry_line_id_seq (USAGE yes, UPDATE no).',
    );

    SqlAssertions::expectRejected(
        fn () => DB::select("select setval('journal_entry_line_id_seq', 1)"),
        '42501',
        message: 'permission denied for sequence',
    );
});

it('pyme_app has no UPDATE (table or any column) and no DELETE on the ledger tables journal_entry and journal_entry_line, nor on audit_log', function (): void {
    $tables = ['audit_log', 'journal_entry', 'journal_entry_line'];

    // Guard against passing on an empty result: has_*_privilege returns NULL for a table that does not exist, and a
    // NULL would filter every row out and pass.
    $found = array_column(DB::select(<<<'SQL'
        select c.relname
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public'
          and c.relkind in ('r', 'p')
          and c.relname = any(?::text[])
        order by c.relname
        SQL, ['{'.implode(',', $tables).'}']), 'relname');
    $this->assertSame($tables, $found, 'The ledger tables and audit_log must exist in public for this rule to be checked.');

    $extra = array_column(DB::select(<<<'SQL'
        select c.relname || ': ' || p.privilege as pair
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        cross join unnest(array['UPDATE', 'DELETE']) as p(privilege)
        where n.nspname = 'public'
          and c.relname = any(?::text[])
          and case p.privilege
                  when 'UPDATE' then has_any_column_privilege('pyme_app', c.oid, 'UPDATE')
                  else has_table_privilege('pyme_app', c.oid, 'DELETE')
              end
        order by c.relname, p.privilege
        SQL, ['{'.implode(',', $tables).'}']), 'pair');

    $this->assertSame([], $extra, 'Table: privilege pairs that pyme_app must not have (UPDATE on the table or on any column, DELETE).');
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

it('pyme_app can insert into third_party without an id and gets one (USAGE on third_party_id_seq)', function (): void {
    enterCompanyForSequenceChecks();

    $id = DB::table('third_party')->insertGetId(MasterDataFixtures::thirdPartyRow());

    $this->assertGreaterThan(0, (int) $id, 'The insert into third_party as pyme_app must return a generated id.');
});

it('pyme_app can insert into cost_center without an id and gets one (USAGE on cost_center_id_seq)', function (): void {
    enterCompanyForSequenceChecks();

    $id = DB::table('cost_center')->insertGetId(MasterDataFixtures::costCenterRow());

    $this->assertGreaterThan(0, (int) $id, 'The insert into cost_center as pyme_app must return a generated id.');
});

it('pyme_app can insert into construction_project without an id and gets one (USAGE on construction_project_id_seq)', function (): void {
    enterCompanyForSequenceChecks();

    $id = DB::table('construction_project')->insertGetId(MasterDataFixtures::constructionProjectRow());

    $this->assertGreaterThan(0, (int) $id, 'The insert into construction_project as pyme_app must return a generated id.');
});

it('pyme_app can insert into document without an id and gets one (USAGE on document_id_seq)', function (): void {
    enterCompanyForSequenceChecks();
    $typeId = LedgerFixtures::insertDocumentType();

    $id = DB::table('document')->insertGetId(['document_type_id' => $typeId, 'document_date' => '2026-01-15']);

    $this->assertGreaterThan(0, (int) $id, 'The insert into document as pyme_app must return a generated id.');
});

it('pyme_app can insert into journal_entry without an id and gets one (USAGE on journal_entry_id_seq)', function (): void {
    enterCompanyForSequenceChecks();
    $periodId = LedgerFixtures::insertPeriod();
    $documentId = LedgerFixtures::insertDocument(LedgerFixtures::insertDocumentType());

    $id = DB::table('journal_entry')->insertGetId([
        'document_id' => $documentId,
        'period_id' => $periodId,
        'accounting_date' => '2026-01-15',
        'number' => 1,
    ]);

    $this->assertGreaterThan(0, (int) $id, 'The insert into journal_entry as pyme_app must return a generated id.');
});

it('pyme_app can insert into journal_entry_line without an id and gets one (USAGE on journal_entry_line_id_seq)', function (): void {
    enterCompanyForSequenceChecks();
    $periodId = LedgerFixtures::insertPeriod();
    $documentId = LedgerFixtures::insertDocument(LedgerFixtures::insertDocumentType());
    $entryId = LedgerFixtures::insertEntry($documentId, $periodId);
    $accountId = LedgerFixtures::insertPostableAccount('T1');

    $id = DB::table('journal_entry_line')->insertGetId([
        'journal_entry_id' => $entryId,
        'account_id' => $accountId,
        'debit' => '100.00',
    ]);

    $this->assertGreaterThan(0, (int) $id, 'The insert into journal_entry_line as pyme_app must return a generated id.');
});

const TENANT_TABLES_WITH_COLUMN_INSERTS = [
    'account' => ['account'],
    'period' => ['period'],
    'document_type' => ['document_type'],
    'document_counter' => ['document_counter'],
    'third_party' => ['third_party'],
    'cost_center' => ['cost_center'],
    'construction_project' => ['construction_project'],
    'document' => ['document'],
    'journal_entry' => ['journal_entry'],
    'journal_entry_line' => ['journal_entry_line'],
];

/**
 * A minimal valid row for the table, without id or company_id. The company context must already be entered, because
 * document_counter and the ledger tables need rows of the company first (a document type, a period, a document, an entry).
 *
 * @return array<string, mixed>
 */
function appPermissionsMinimalRow(string $table): array
{
    return match ($table) {
        'account' => AccountFixtures::minimalRow(),
        'period' => ['year' => 2026, 'number' => 1],
        'third_party' => MasterDataFixtures::thirdPartyRow(),
        'cost_center' => MasterDataFixtures::costCenterRow(),
        'construction_project' => MasterDataFixtures::constructionProjectRow(),
        'document_type' => ['code' => 'INV', 'name' => 'Invoice', 'resets_yearly' => true],
        // last_number must be 1 on insert (counter trigger).
        'document_counter' => [
            'document_type_id' => DB::table('document_type')->insertGetId(['code' => 'INV', 'name' => 'Invoice', 'resets_yearly' => true]),
            'year' => 2026,
            'last_number' => 1,
        ],
        'document' => [
            'document_type_id' => LedgerFixtures::insertDocumentType(),
            'document_date' => '2026-01-15',
        ],
        'journal_entry' => [
            'document_id' => LedgerFixtures::insertDocument(LedgerFixtures::insertDocumentType()),
            'period_id' => LedgerFixtures::insertPeriod(),
            'accounting_date' => '2026-01-15',
            'number' => 1,
        ],
        'journal_entry_line' => [
            'journal_entry_id' => LedgerFixtures::insertEntry(
                LedgerFixtures::insertDocument(LedgerFixtures::insertDocumentType()),
                LedgerFixtures::insertPeriod(),
            ),
            'account_id' => LedgerFixtures::insertPostableAccount('T1'),
            'debit' => '100.00',
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
