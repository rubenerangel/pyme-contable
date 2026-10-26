<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Catalogs only: no RefreshDatabase. Runs on the default connection (pyme_app, database pyme_test).
// pg_has_role and has_schema_privilege raise an SQL error if the role does not exist, so this
// beforeEach checks existence first and fails with a clear message before querying them.
beforeEach(function (): void {
    $exists = DB::selectOne(<<<'SQL'
        select exists (select 1 from pg_roles where rolname = 'pyme_app') as exists
        SQL)->exists;

    $this->assertTrue((bool) $exists, 'Role pyme_app does not exist in the Postgres cluster.');
});

it('pyme_app can log in but has no administrative privileges', function (): void {
    $role = DB::selectOne(<<<'SQL'
        select rolcanlogin, rolsuper, rolcreatedb, rolcreaterole, rolbypassrls, rolreplication
        from pg_roles
        where rolname = 'pyme_app'
        SQL);

    $this->assertTrue((bool) $role->rolcanlogin, 'pyme_app must be able to log in (rolcanlogin).');

    $attributes = [
        'rolsuper' => (bool) $role->rolsuper,
        'rolcreatedb' => (bool) $role->rolcreatedb,
        'rolcreaterole' => (bool) $role->rolcreaterole,
        'rolbypassrls' => (bool) $role->rolbypassrls,
        'rolreplication' => (bool) $role->rolreplication,
    ];

    $this->assertSame(
        [
            'rolsuper' => false,
            'rolcreatedb' => false,
            'rolcreaterole' => false,
            'rolbypassrls' => false,
            'rolreplication' => false,
        ],
        $attributes,
        'pyme_app must not have SUPERUSER, CREATEDB, CREATEROLE, BYPASSRLS or REPLICATION.',
    );
});

it('pyme_app cannot become pyme_owner nor create objects in public', function (): void {
    $row = DB::selectOne(<<<'SQL'
        select pg_has_role('pyme_app', 'pyme_owner', 'MEMBER')   as is_owner_member,
               has_schema_privilege('pyme_app', 'public', 'CREATE') as can_create_in_public
        SQL);

    $this->assertFalse(
        (bool) $row->is_owner_member,
        'pyme_app is a member (direct or indirect) of pyme_owner: it could SET ROLE to the owner.',
    );
    $this->assertFalse(
        (bool) $row->can_create_in_public,
        'pyme_app has CREATE on the public schema of the current database.',
    );
});

it('pyme_app does not own any relation, schema or database', function (): void {
    $relations = array_column(DB::select(<<<'SQL'
        select c.relname
        from pg_class c
        join pg_roles r on r.oid = c.relowner
        where r.rolname = 'pyme_app'
        order by c.relname
        SQL), 'relname');

    $schemas = array_column(DB::select(<<<'SQL'
        select n.nspname
        from pg_namespace n
        join pg_roles r on r.oid = n.nspowner
        where r.rolname = 'pyme_app'
        order by n.nspname
        SQL), 'nspname');

    $databases = array_column(DB::select(<<<'SQL'
        select d.datname
        from pg_database d
        join pg_roles r on r.oid = d.datdba
        where r.rolname = 'pyme_app'
        order by d.datname
        SQL), 'datname');

    $this->assertSame([], $relations, 'pyme_app owns relations: '.implode(', ', $relations));
    $this->assertSame([], $schemas, 'pyme_app owns schemas: '.implode(', ', $schemas));
    $this->assertSame([], $databases, 'pyme_app owns databases: '.implode(', ', $databases));
});

it('pyme_app has neither TEMP nor CREATE on the pyme and pyme_test databases', function (): void {
    // TEMP would let pyme_app create temporary tables that shadow real ones for any function that does not pin
    // pg_temp last in its search_path. CREATE would let it add schemas. By default PUBLIC receives only TEMP and CONNECT
    // on a database (acldefault('d', ...) = {=Tc/...}), never CREATE: the CREATE assert is a guard against an explicit
    // GRANT, while TEMP is the one that must be revoked from PUBLIC.
    $row = DB::selectOne(<<<'SQL'
        select has_database_privilege('pyme_app', 'pyme',      'TEMP')   as pyme_temp,
               has_database_privilege('pyme_app', 'pyme',      'CREATE') as pyme_create,
               has_database_privilege('pyme_app', 'pyme_test', 'TEMP')   as pyme_test_temp,
               has_database_privilege('pyme_app', 'pyme_test', 'CREATE') as pyme_test_create
        SQL);

    $this->assertSame(
        [
            'pyme_app TEMP on pyme' => false,
            'pyme_app CREATE on pyme' => false,
            'pyme_app TEMP on pyme_test' => false,
            'pyme_app CREATE on pyme_test' => false,
        ],
        [
            'pyme_app TEMP on pyme' => (bool) $row->pyme_temp,
            'pyme_app CREATE on pyme' => (bool) $row->pyme_create,
            'pyme_app TEMP on pyme_test' => (bool) $row->pyme_test_temp,
            'pyme_app CREATE on pyme_test' => (bool) $row->pyme_test_create,
        ],
        'Privilege on database → whether pyme_app has it (directly or through PUBLIC). Expected none of them.',
    );
});

it('neither pyme_app nor pyme_owner can connect to the postgres database, but both can to pyme and pyme_test', function (): void {
    // has_database_privilege raises an SQL error if the role or the database does not exist.
    $row = DB::selectOne(<<<'SQL'
        select has_database_privilege('pyme_app',   'postgres',  'CONNECT') as app_postgres,
               has_database_privilege('pyme_owner', 'postgres',  'CONNECT') as owner_postgres,
               has_database_privilege('pyme_app',   'pyme',      'CONNECT') as app_pyme,
               has_database_privilege('pyme_owner', 'pyme',      'CONNECT') as owner_pyme,
               has_database_privilege('pyme_app',   'pyme_test', 'CONNECT') as app_pyme_test,
               has_database_privilege('pyme_owner', 'pyme_test', 'CONNECT') as owner_pyme_test
        SQL);

    $connect = [
        'pyme_app → postgres' => (bool) $row->app_postgres,
        'pyme_owner → postgres' => (bool) $row->owner_postgres,
        'pyme_app → pyme' => (bool) $row->app_pyme,
        'pyme_owner → pyme' => (bool) $row->owner_pyme,
        'pyme_app → pyme_test' => (bool) $row->app_pyme_test,
        'pyme_owner → pyme_test' => (bool) $row->owner_pyme_test,
    ];

    $this->assertSame(
        [
            'pyme_app → postgres' => false,
            'pyme_owner → postgres' => false,
            'pyme_app → pyme' => true,
            'pyme_owner → pyme' => true,
            'pyme_app → pyme_test' => true,
            'pyme_owner → pyme_test' => true,
        ],
        $connect,
        'Expected CONNECT: neither role on postgres (revoked from PUBLIC), both on pyme and pyme_test.',
    );
});

it('pg_db_role_setting has no default for pyme_app and no app.* default for any role or database', function (): void {
    // A per-role or per-database default for app.company_id would make a connection that never called enter()
    // stop failing closed (it would silently see a company). Any default set for pyme_app survives migrate:fresh,
    // because it lives in the cluster catalog and not in the schema. setrole = 0 means all roles and
    // setdatabase = 0 means all databases. The legitimate {TimeZone=UTC} rows (setrole = 0) must not match.
    $rows = DB::select(<<<'SQL'
        select case when s.setrole = 0 then '(all roles)'
                    else coalesce(r.rolname, s.setrole::text) end         as role_name,
               case when s.setdatabase = 0 then '(all databases)'
                    else coalesce(d.datname, s.setdatabase::text) end     as database_name,
               s.setconfig::text                                          as setconfig
        from pg_db_role_setting s
        left join pg_roles r    on r.oid = s.setrole
        left join pg_database d on d.oid = s.setdatabase
        where r.rolname = 'pyme_app'
           or exists (select 1 from unnest(s.setconfig) as c(item) where c.item ilike 'app.%')
        order by role_name, database_name
        SQL);

    $found = array_map(
        static fn (object $row): string => "role={$row->role_name} database={$row->database_name} setconfig={$row->setconfig}",
        $rows,
    );

    $this->assertSame(
        [],
        $found,
        'pg_db_role_setting has defaults for pyme_app or an app.* default for some role/database: '.implode('; ', $found),
    );
});

it('pg_parameter_acl grants nothing on app.* parameters and nothing to PUBLIC or to roles pyme_app belongs to', function (): void {
    // Any grantee on an app.* parameter (for example pyme_owner) would open ALTER DATABASE ... SET app.company_id.
    // A privilege (SET or ALTER SYSTEM) for PUBLIC or for a role pyme_app is a member of would let pyme_app change a
    // server parameter: SET on session_replication_role would allow 'replica' and switch off the ledger triggers
    // (today journal_entry_enforce_posting). Grantee 0 is PUBLIC, which is not a role anyone can be a member of:
    // pg_has_role returns false for 0, so PUBLIC needs its own branch. CASE and not OR because the evaluation order
    // of OR is not guaranteed, and the explicit branch keeps the intent readable.
    $rows = DB::select(<<<'SQL'
        select p.parname,
               p.paracl::text as paracl
        from pg_parameter_acl p
        where p.parname ilike 'app.%'
           or exists (select 1 from aclexplode(p.paracl) a
                      where case when a.grantee = 0 then true
                                 else pg_has_role('pyme_app', a.grantee, 'MEMBER') end)
        order by p.parname
        SQL);

    $found = array_map(
        static fn (object $row): string => "parname={$row->parname} paracl={$row->paracl}",
        $rows,
    );

    $this->assertSame(
        [],
        $found,
        'pg_parameter_acl has an app.* parameter or a privilege for PUBLIC or for a role pyme_app belongs to: '.implode('; ', $found),
    );
});

it('pyme_app cannot set an app.company_id default for itself', function (bool $scopedToDatabase): void {
    // The catalog tests above detect a default that is already set; this one proves pyme_app cannot set it. An ordinary
    // role can set its own defaults for userset parameters (ALTER ROLE docs, PG17); app.* is rejected because it is a
    // placeholder with no SET privilege. Each attempt runs in its own transaction and is always rolled back: ALTER ROLE
    // is transactional, so if it ever succeeds the default must not stay in the cluster.
    $database = '"'.str_replace('"', '""', DB::connection()->getDatabaseName()).'"';

    $sql = $scopedToDatabase
        ? "ALTER ROLE CURRENT_USER IN DATABASE {$database} SET app.company_id = '1'"
        : "ALTER ROLE CURRENT_USER SET app.company_id = '1'";

    $error = null;

    DB::beginTransaction();
    try {
        DB::statement($sql);
    } catch (QueryException $e) {
        $error = $e;
    } finally {
        DB::rollBack();
    }

    $this->assertNotNull($error, "pyme_app was able to run: {$sql}");
    $this->assertSame(
        '42501',
        $error->errorInfo[0] ?? null,
        "Expected SQLSTATE 42501 (insufficient_privilege) for: {$sql}. Got: {$error->getMessage()}",
    );
})->with([
    'role-wide default' => [false],
    'database-scoped default' => [true],
]);
