<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// Solo catálogos: sin RefreshDatabase. Corre con la conexión por defecto (pyme_app, base pyme_test).
// pg_has_role y has_schema_privilege lanzan error SQL si el rol no existe, por eso este
// beforeEach comprueba la existencia primero y falla con un mensaje claro antes de consultarlos.
beforeEach(function (): void {
    $existe = DB::selectOne(<<<'SQL'
        select exists (select 1 from pg_roles where rolname = 'pyme_app') as existe
        SQL)->existe;

    $this->assertTrue((bool) $existe, 'El rol pyme_app no existe en el clúster de Postgres.');
});

it('pyme_app puede iniciar sesión pero no tiene ningún privilegio de administración', function (): void {
    $rol = DB::selectOne(<<<'SQL'
        select rolcanlogin, rolsuper, rolcreatedb, rolcreaterole, rolbypassrls, rolreplication
        from pg_roles
        where rolname = 'pyme_app'
        SQL);

    $this->assertTrue((bool) $rol->rolcanlogin, 'pyme_app debe poder iniciar sesión (rolcanlogin).');

    $atributos = [
        'rolsuper' => (bool) $rol->rolsuper,
        'rolcreatedb' => (bool) $rol->rolcreatedb,
        'rolcreaterole' => (bool) $rol->rolcreaterole,
        'rolbypassrls' => (bool) $rol->rolbypassrls,
        'rolreplication' => (bool) $rol->rolreplication,
    ];

    $this->assertSame(
        [
            'rolsuper' => false,
            'rolcreatedb' => false,
            'rolcreaterole' => false,
            'rolbypassrls' => false,
            'rolreplication' => false,
        ],
        $atributos,
        'pyme_app no debe tener SUPERUSER, CREATEDB, CREATEROLE, BYPASSRLS ni REPLICATION.',
    );
});

it('pyme_app no puede llegar a ser pyme_owner ni crear objetos en public', function (): void {
    $fila = DB::selectOne(<<<'SQL'
        select pg_has_role('pyme_app', 'pyme_owner', 'MEMBER')   as es_miembro_de_owner,
               has_schema_privilege('pyme_app', 'public', 'CREATE') as crea_en_public
        SQL);

    $this->assertFalse(
        (bool) $fila->es_miembro_de_owner,
        'pyme_app es miembro (directo o indirecto) de pyme_owner: podría hacer SET ROLE al dueño.',
    );
    $this->assertFalse(
        (bool) $fila->crea_en_public,
        'pyme_app tiene CREATE sobre el esquema public de la base actual.',
    );
});

it('pyme_app no es dueño de ninguna relación, esquema ni base', function (): void {
    $relaciones = array_column(DB::select(<<<'SQL'
        select c.relname
        from pg_class c
        join pg_roles r on r.oid = c.relowner
        where r.rolname = 'pyme_app'
        order by c.relname
        SQL), 'relname');

    $esquemas = array_column(DB::select(<<<'SQL'
        select n.nspname
        from pg_namespace n
        join pg_roles r on r.oid = n.nspowner
        where r.rolname = 'pyme_app'
        order by n.nspname
        SQL), 'nspname');

    $bases = array_column(DB::select(<<<'SQL'
        select d.datname
        from pg_database d
        join pg_roles r on r.oid = d.datdba
        where r.rolname = 'pyme_app'
        order by d.datname
        SQL), 'datname');

    $this->assertSame([], $relaciones, 'pyme_app es dueño de relaciones: '.implode(', ', $relaciones));
    $this->assertSame([], $esquemas, 'pyme_app es dueño de esquemas: '.implode(', ', $esquemas));
    $this->assertSame([], $bases, 'pyme_app es dueño de bases de datos: '.implode(', ', $bases));
});

it('ni pyme_app ni pyme_owner pueden conectarse a la base postgres, y sí a pyme y pyme_test', function (): void {
    // has_database_privilege lanza error SQL si el rol o la base no existen.
    $fila = DB::selectOne(<<<'SQL'
        select has_database_privilege('pyme_app',   'postgres',  'CONNECT') as app_postgres,
               has_database_privilege('pyme_owner', 'postgres',  'CONNECT') as owner_postgres,
               has_database_privilege('pyme_app',   'pyme',      'CONNECT') as app_pyme,
               has_database_privilege('pyme_owner', 'pyme',      'CONNECT') as owner_pyme,
               has_database_privilege('pyme_app',   'pyme_test', 'CONNECT') as app_pyme_test,
               has_database_privilege('pyme_owner', 'pyme_test', 'CONNECT') as owner_pyme_test
        SQL);

    $connect = [
        'pyme_app → postgres' => (bool) $fila->app_postgres,
        'pyme_owner → postgres' => (bool) $fila->owner_postgres,
        'pyme_app → pyme' => (bool) $fila->app_pyme,
        'pyme_owner → pyme' => (bool) $fila->owner_pyme,
        'pyme_app → pyme_test' => (bool) $fila->app_pyme_test,
        'pyme_owner → pyme_test' => (bool) $fila->owner_pyme_test,
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
        'CONNECT esperado: ninguno de los dos roles sobre postgres (revocar a PUBLIC), ambos sobre pyme y pyme_test.',
    );
});
