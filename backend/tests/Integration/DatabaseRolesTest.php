<?php

declare(strict_types=1);

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
