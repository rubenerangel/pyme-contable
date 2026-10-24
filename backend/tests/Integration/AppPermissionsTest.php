<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;

// The application runs as pyme_app (default connection) and migrations run as pyme_owner
// (pgsql_owner connection, same database). These tests pin the privilege split between both.
// Privileges are read with has_table_privilege for each Postgres 17 table privilege,
// so any extra privilege shows up in the assertSame diff.
uses(RefreshDatabaseAsOwner::class);

// Complete map of every table in public (except migrations) to the privileges pyme_app must have on it, in
// alphabetical order. A new table without an entry here makes the privileges test fail.
// tenant_probe is a tests-only table (backend/tests/database/migrations): it exists in public only during the tests.
const EXPECTED_TABLE_PRIVILEGES = [
    'cache' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
    'cache_locks' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
    'companies' => ['INSERT', 'SELECT', 'UPDATE'],
    'company_user' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
    'failed_jobs' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
    'job_batches' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
    'jobs' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
    'password_reset_tokens' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
    'sessions' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
    'tenant_probe' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
    'users' => ['DELETE', 'INSERT', 'SELECT', 'UPDATE'],
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
