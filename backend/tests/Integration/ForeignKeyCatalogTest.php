<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;
use Tests\Support\TenantCatalog;

// Catalog tests on the foreign keys of the tenant tables (every table with a company_id column, minus the global ones):
// each one is backed by an index, and each one that points at another tenant table carries company_id on both sides, so
// a row can only reference rows of its own company (docs/decisiones.md, Multi-tenant). They read pg_constraint and
// pg_index, so a table or a foreign key added later is covered without touching this file.
uses(RefreshDatabaseAsOwner::class);

// Foreign keys of a tenant table that need no index on purpose, as 'table.constraint', each for its own reason.
const FOREIGN_KEYS_WITHOUT_INDEX = [
    // A user with history is never deleted, and one more index on audit_log would be paid on every write.
    'audit_log.audit_log_user_id_fkey',
    // Only pyme_owner deletes or changes a municipality (in migrations), and the index would be paid on every line. A
    // report of ICA by municipality will bring its own index.
    'journal_entry_line.journal_entry_line_municipality_fkey',
];

// The only tables a simple foreign key (without company_id) of a tenant table may point at, each for its own reason
// (docs/decisiones.md, Integridad). Not "any table of TenantCatalog::TABLES_WITHOUT_COMPANY_ID": sessions, jobs or
// cache are global too and no business row should reference them.
const SIMPLE_FOREIGN_KEY_TARGETS = [
    // The company itself: its rows are visible to the application through the membership, not through company_id.
    'companies',
    // The user of an audit event or of a membership: users are global, one user belongs to many companies.
    'users',
    // Global catalog (DIVIPOLA) seeded by a migration, the same for every company.
    'municipality',
];

/**
 * The tenant tables (company_id column, global ones out) as a Postgres text[] literal to filter with
 * c.relname = any(?::text[]).
 */
function foreignKeyCatalogTenantTablesLiteral(): string
{
    return '{'.implode(',', foreignKeyCatalogTenantTables()).'}';
}

/**
 * @return list<string>
 */
function foreignKeyCatalogTenantTables(): array
{
    return array_values(array_diff(TenantCatalog::tablesWithCompanyId(), TenantCatalog::GLOBAL_TABLES_WITH_COMPANY_ID));
}

/**
 * Every foreign key of the tenant tables with the ordered column names of both sides.
 *
 * @return list<array{table: string, constraint: string, target: string, columns: list<string>, targetColumns: list<string>}>
 */
function foreignKeyCatalogForeignKeys(): array
{
    $rows = [];
    foreach (DB::select(<<<'SQL'
        select src.relname as source_table,
               c.conname,
               dst.relname as target_table,
               (select json_agg(a.attname order by k.pos)
                from unnest(c.conkey) with ordinality as k(attnum, pos)
                join pg_attribute a on a.attrelid = c.conrelid and a.attnum = k.attnum) as columns,
               (select json_agg(a.attname order by k.pos)
                from unnest(c.confkey) with ordinality as k(attnum, pos)
                join pg_attribute a on a.attrelid = c.confrelid and a.attnum = k.attnum) as target_columns
        from pg_constraint c
        join pg_class src on src.oid = c.conrelid
        join pg_class dst on dst.oid = c.confrelid
        join pg_namespace n on n.oid = src.relnamespace
        where c.contype = 'f'
          and n.nspname = 'public'
          and src.relname = any(?::text[])
        order by src.relname, c.conname
        SQL, [foreignKeyCatalogTenantTablesLiteral()]) as $row) {
        $rows[] = [
            'table' => $row->source_table,
            'constraint' => $row->conname,
            'target' => $row->target_table,
            'columns' => json_decode($row->columns, true, 512, JSON_THROW_ON_ERROR),
            'targetColumns' => json_decode($row->target_columns, true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    return $rows;
}

/**
 * The foreign keys ('table.constraint') whose destination breaks the rule: to a table of the explicit list of global
 * targets it may be simple; to another tenant table it must carry company_id in the same position on both sides; to any
 * other table (a global one outside the list, or a global one that has a company_id column) it is not allowed.
 *
 * @param  list<array{table: string, constraint: string, target: string, columns: list<string>, targetColumns: list<string>}>  $foreignKeys
 * @param  list<string>  $tenantTables
 * @param  list<string>  $simpleTargets
 * @return list<string>
 */
function foreignKeyCatalogDestinationViolations(array $foreignKeys, array $tenantTables, array $simpleTargets): array
{
    $violations = [];
    foreach ($foreignKeys as $foreignKey) {
        if (in_array($foreignKey['target'], $simpleTargets, true)) {
            continue;
        }

        $carriesCompanyId = false;
        if (in_array($foreignKey['target'], $tenantTables, true)) {
            foreach ($foreignKey['columns'] as $position => $column) {
                if ($column === 'company_id' && ($foreignKey['targetColumns'][$position] ?? null) === 'company_id') {
                    $carriesCompanyId = true;
                }
            }
        }

        if (! $carriesCompanyId) {
            $violations[] = $foreignKey['table'].'.'.$foreignKey['constraint'];
        }
    }

    return $violations;
}

/**
 * Every index of the tenant tables, by table name. 'columns' are all the columns of the index in order, key columns
 * first and then the INCLUDE ones (null for an expression); 'keyColumnCount' tells how many of them are key columns.
 *
 * @return array<string, list<array{method: string, valid: bool, columns: list<?string>, keyColumnCount: int, predicate: ?string}>>
 */
function foreignKeyCatalogIndexes(): array
{
    $indexes = [];
    foreach (DB::select(<<<'SQL'
        select src.relname as table_name,
               am.amname as method,
               x.indisvalid as valid,
               x.indnkeyatts as key_column_count,
               pg_get_expr(x.indpred, x.indrelid) as predicate,
               (select json_agg(a.attname order by k.pos)
                from unnest(x.indkey::int2[]) with ordinality as k(attnum, pos)
                left join pg_attribute a on a.attrelid = x.indrelid and a.attnum = k.attnum) as columns
        from pg_index x
        join pg_class src on src.oid = x.indrelid
        join pg_class idx on idx.oid = x.indexrelid
        join pg_am am on am.oid = idx.relam
        join pg_namespace n on n.oid = src.relnamespace
        where n.nspname = 'public'
          and src.relname = any(?::text[])
        order by src.relname, idx.relname
        SQL, [foreignKeyCatalogTenantTablesLiteral()]) as $row) {
        $indexes[$row->table_name][] = [
            'method' => $row->method,
            'valid' => (bool) $row->valid,
            'columns' => json_decode($row->columns, true, 512, JSON_THROW_ON_ERROR),
            'keyColumnCount' => (int) $row->key_column_count,
            'predicate' => $row->predicate,
        ];
    }

    return $indexes;
}

/**
 * Whether the index backs the foreign key for the referential integrity checks: it is a valid btree whose first key
 * columns are exactly the columns of the foreign key in the same order (INCLUDE columns are not key columns), and it is
 * either complete or partial with the predicate '(<column> IS NOT NULL)' on a column of the foreign key other than
 * company_id (a row with that column NULL references nothing). The check could also use other partial predicates that
 * its equality implies; this is the only form the rule accepts, conservative on purpose.
 *
 * @param  list<string>  $foreignKeyColumns
 * @param  array{method: string, valid: bool, columns: list<?string>, keyColumnCount: int, predicate: ?string}  $index
 */
function foreignKeyCatalogIndexBacks(array $foreignKeyColumns, array $index): bool
{
    if ($index['method'] !== 'btree' || ! $index['valid']) {
        return false;
    }

    $count = count($foreignKeyColumns);
    if ($index['keyColumnCount'] < $count || array_slice($index['columns'], 0, $count) !== $foreignKeyColumns) {
        return false;
    }

    if ($index['predicate'] === null) {
        return true;
    }

    foreach ($foreignKeyColumns as $column) {
        if ($column !== 'company_id' && $index['predicate'] === '('.$column.' IS NOT NULL)') {
            return true;
        }
    }

    return false;
}

/**
 * The foreign keys ('table.constraint') of the tenant tables that no index backs.
 *
 * @return list<string>
 */
function foreignKeyCatalogUnindexed(): array
{
    $indexes = foreignKeyCatalogIndexes();
    $unindexed = [];
    foreach (foreignKeyCatalogForeignKeys() as $foreignKey) {
        $backed = false;
        foreach ($indexes[$foreignKey['table']] ?? [] as $index) {
            if (foreignKeyCatalogIndexBacks($foreignKey['columns'], $index)) {
                $backed = true;
                break;
            }
        }

        if (! $backed) {
            $unindexed[] = $foreignKey['table'].'.'.$foreignKey['constraint'];
        }
    }

    return $unindexed;
}

it('every foreign key of a tenant table is backed by a btree index that starts with exactly its columns, in the same order', function (): void {
    // Guard against passing on an empty result: the catalog must see the foreign keys of account and of the lines, and
    // the declared exceptions must still be foreign keys (a stale exception would hide a future one).
    $seen = foreignKeyCatalogForeignKeys();
    $names = array_map(fn (array $fk) => $fk['table'].'.'.$fk['constraint'], $seen);
    expect(array_unique(array_column($seen, 'table')))->toContain('account', 'journal_entry_line');
    expect($names)->toContain('journal_entry_line.journal_entry_line_account_fkey');
    expect($names)->toContain(...FOREIGN_KEYS_WITHOUT_INDEX);

    $this->assertSame(
        [],
        array_values(array_diff(foreignKeyCatalogUnindexed(), FOREIGN_KEYS_WITHOUT_INDEX)),
        'Foreign keys ("table.constraint") of tenant tables with no valid btree index whose first key columns are exactly the columns of the foreign key, in order (a partial one only with the predicate (<column> IS NOT NULL)). Add the index, or declare the foreign key in FOREIGN_KEYS_WITHOUT_INDEX with its reason.',
    );
});

it('the index rule accepts a complete or IS NOT NULL partial btree index that starts with the columns of the foreign key and rejects the rest', function (): void {
    $index = fn (array $overrides = []): array => array_merge([
        'method' => 'btree',
        'valid' => true,
        'columns' => ['company_id', 'third_party_id'],
        'keyColumnCount' => 2,
        'predicate' => null,
    ], $overrides);

    $backs = fn (array $overrides = [], array $columns = ['company_id', 'third_party_id']): bool => foreignKeyCatalogIndexBacks($columns, $index($overrides));

    // Accepted.
    expect([
        'exact columns' => $backs(),
        'longer key that starts with the columns' => $backs(['columns' => ['company_id', 'third_party_id', 'id'], 'keyColumnCount' => 3]),
        'INCLUDE column after the key columns' => $backs(['columns' => ['company_id', 'third_party_id', 'id'], 'keyColumnCount' => 2]),
        'partial IS NOT NULL on a column of the foreign key' => $backs(['predicate' => '(third_party_id IS NOT NULL)']),
        'single column foreign key with partial IS NOT NULL' => $backs(['columns' => ['municipality_id'], 'keyColumnCount' => 1, 'predicate' => '(municipality_id IS NOT NULL)'], ['municipality_id']),
    ])->toBe([
        'exact columns' => true,
        'longer key that starts with the columns' => true,
        'INCLUDE column after the key columns' => true,
        'partial IS NOT NULL on a column of the foreign key' => true,
        'single column foreign key with partial IS NOT NULL' => true,
    ]);

    // Rejected.
    expect([
        'partial with another predicate' => $backs(['predicate' => '(debit > 0)']),
        'partial IS NOT NULL on company_id' => $backs(['predicate' => '(company_id IS NOT NULL)']),
        'partial IS NOT NULL on a column outside the foreign key' => $backs(['predicate' => '(cost_center_id IS NOT NULL)']),
        'partial with an extra condition' => $backs(['predicate' => '((third_party_id IS NOT NULL) AND (debit > 0))']),
        'BRIN' => $backs(['method' => 'brin']),
        'hash' => $backs(['method' => 'hash']),
        'GIN' => $backs(['method' => 'gin']),
        'columns in another order' => $backs(['columns' => ['third_party_id', 'company_id']]),
        'foreign key column only as INCLUDE' => $backs(['columns' => ['company_id', 'id', 'third_party_id'], 'keyColumnCount' => 2]),
        'second foreign key column only as INCLUDE (key columns shorter than the foreign key)' => $backs(['columns' => ['company_id', 'third_party_id'], 'keyColumnCount' => 1]),
        'another column first' => $backs(['columns' => ['id', 'company_id', 'third_party_id'], 'keyColumnCount' => 3]),
        'only the first column' => $backs(['columns' => ['company_id'], 'keyColumnCount' => 1]),
        'expression instead of a column' => $backs(['columns' => ['company_id', null]]),
        'invalid index' => $backs(['valid' => false]),
    ])->toBe([
        'partial with another predicate' => false,
        'partial IS NOT NULL on company_id' => false,
        'partial IS NOT NULL on a column outside the foreign key' => false,
        'partial with an extra condition' => false,
        'BRIN' => false,
        'hash' => false,
        'GIN' => false,
        'columns in another order' => false,
        'foreign key column only as INCLUDE' => false,
        'second foreign key column only as INCLUDE (key columns shorter than the foreign key)' => false,
        'another column first' => false,
        'only the first column' => false,
        'expression instead of a column' => false,
        'invalid index' => false,
    ]);
});

it('every foreign key to another tenant table carries company_id on both sides in the same position, and a simple one points only at the listed global tables', function (): void {
    $foreignKeys = foreignKeyCatalogForeignKeys();

    // Guard against passing on an empty result: the municipality one is simple and allowed, the account one is composite.
    $byName = [];
    foreach ($foreignKeys as $foreignKey) {
        $byName[$foreignKey['table'].'.'.$foreignKey['constraint']] = $foreignKey;
    }
    expect($byName)->toHaveKeys(['journal_entry_line.journal_entry_line_municipality_fkey', 'journal_entry_line.journal_entry_line_account_fkey']);
    expect($byName['journal_entry_line.journal_entry_line_municipality_fkey'])->toMatchArray([
        'target' => 'municipality',
        'columns' => ['municipality_id'],
        'targetColumns' => ['id'],
    ]);
    expect($byName['journal_entry_line.journal_entry_line_account_fkey'])->toMatchArray([
        'target' => 'account',
        'columns' => ['company_id', 'account_id'],
        'targetColumns' => ['company_id', 'id'],
    ]);

    // The list of simple targets is a subset of the global tables; that each one exists is guaranteed by the test in
    // TenantIsolationTest that matches TABLES_WITHOUT_COMPANY_ID with the catalog.
    expect(array_diff(SIMPLE_FOREIGN_KEY_TARGETS, TenantCatalog::TABLES_WITHOUT_COMPANY_ID))->toBe([]);

    $this->assertSame(
        [],
        foreignKeyCatalogDestinationViolations($foreignKeys, foreignKeyCatalogTenantTables(), SIMPLE_FOREIGN_KEY_TARGETS),
        'Foreign keys ("table.constraint") of tenant tables whose destination breaks the rule: to a tenant table they need company_id on both sides in the same position; a simple one is allowed only to a table in SIMPLE_FOREIGN_KEY_TARGETS.',
    );
});

it('the destination rule flags a simple foreign key to a tenant table, to a global table outside the list, a reversed company_id and a table that is neither, and lets the allowed shapes pass', function (): void {
    $tenantTables = ['account', 'journal_entry_line'];
    $foreignKey = fn (string $name, string $target, array $columns, array $targetColumns): array => [
        'table' => 'journal_entry_line',
        'constraint' => $name,
        'target' => $target,
        'columns' => $columns,
        'targetColumns' => $targetColumns,
    ];

    $violations = foreignKeyCatalogDestinationViolations([
        $foreignKey('simple_to_tenant', 'account', ['account_id'], ['id']),
        $foreignKey('company_id_in_another_position', 'account', ['company_id', 'account_id'], ['id', 'company_id']),
        $foreignKey('company_id_only_on_one_side', 'account', ['company_id', 'account_id'], ['code', 'id']),
        $foreignKey('to_a_global_table_with_company_id', 'company_user', ['company_id', 'account_id'], ['company_id', 'user_id']),
        $foreignKey('composite_to_tenant', 'account', ['company_id', 'account_id'], ['company_id', 'id']),
        $foreignKey('composite_with_company_id_second', 'account', ['account_id', 'company_id'], ['id', 'company_id']),
        $foreignKey('simple_to_catalog', 'municipality', ['municipality_id'], ['id']),
        $foreignKey('simple_to_companies', 'companies', ['company_id'], ['id']),
        $foreignKey('simple_to_users', 'users', ['user_id'], ['id']),
        $foreignKey('simple_to_sessions', 'sessions', ['session_id'], ['id']),
        $foreignKey('composite_to_sessions', 'sessions', ['company_id', 'session_id'], ['company_id', 'id']),
    ], $tenantTables, ['companies', 'users', 'municipality']);

    expect($violations)->toBe([
        'journal_entry_line.simple_to_tenant',
        'journal_entry_line.company_id_in_another_position',
        'journal_entry_line.company_id_only_on_one_side',
        'journal_entry_line.to_a_global_table_with_company_id',
        'journal_entry_line.simple_to_sessions',
        'journal_entry_line.composite_to_sessions',
    ]);
});
