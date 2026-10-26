<?php

declare(strict_types=1);

use App\Infrastructure\Tenancy\CompanyScope;
use App\Models\Account;
use App\Models\ConstructionProject;
use App\Models\CostCenter;
use App\Models\ThirdParty;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;
use Tests\Support\TenantCatalog;

// Catalog tests over every Eloquent model of a tenant table: they read app/Models and the Postgres catalog, so a new
// model is covered without touching this file. The per-model behaviour lives in each model's own test.
uses(RefreshDatabaseAsOwner::class);

// The models this catalog must find. Guards against passing on an empty or partial result.
const TENANT_MODEL_CATALOG_EXPECTED_MODELS = [Account::class, ThirdParty::class, CostCenter::class, ConstructionProject::class];

/**
 * Every concrete Eloquent model under app/Models, instantiated so that it boots and registers its global scopes.
 *
 * @return array<class-string<Model>, Model>
 */
function tenantModelCatalogModels(): array
{
    $modelsPath = app_path('Models');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modelsPath, FilesystemIterator::SKIP_DOTS));

    $models = [];
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // App\Models\Sub\Name from Sub/Name.php relative to app/Models.
        $relative = substr($file->getPathname(), strlen($modelsPath) + 1, -strlen('.php'));
        $class = 'App\\Models\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $models[$class] = new $class;
    }

    return $models;
}

/**
 * The models of tenant tables (tables of public with a company_id column, minus the global ones), by class.
 *
 * @return array<class-string<Model>, Model>
 */
function tenantModelCatalogTenantModels(): array
{
    $tenantTables = array_diff(
        TenantCatalog::tablesWithCompanyId(),
        TenantCatalog::GLOBAL_TABLES_WITH_COMPANY_ID,
    );

    return array_filter(
        tenantModelCatalogModels(),
        fn (Model $model): bool => in_array($model->getTable(), $tenantTables, true),
    );
}

it('every model of a tenant table has the company scope and takes neither id nor company_id from mass assignment', function (): void {
    $publicTables = array_column(DB::select(<<<'SQL'
        select c.relname
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public' and c.relkind in ('r', 'p')
        SQL), 'relname');

    // A table with a schema prefix or a typo would otherwise be skipped silently by the tenant filter.
    $withoutTable = [];
    foreach (tenantModelCatalogModels() as $class => $model) {
        if (! in_array($model->getTable(), $publicTables, true)) {
            $withoutTable[] = $class.' → '.$model->getTable();
        }
    }

    $actual = [];
    foreach (tenantModelCatalogTenantModels() as $class => $model) {
        $actual[$class] = [$class::hasGlobalScope(CompanyScope::class), $model->isFillable('id'), $model->isFillable('company_id')];
    }

    $this->assertSame([], $withoutTable, 'Models whose getTable() is not a table of public (model → table).');

    // Guard against passing on an empty result.
    expect($actual)->toHaveKeys(TENANT_MODEL_CATALOG_EXPECTED_MODELS);

    $this->assertSame(
        array_fill_keys(array_keys($actual), [true, false, false]),
        $actual,
        'Model → [has CompanyScope, id fillable, company_id fillable] for every model of a tenant table. It must have the scope and neither column fillable.',
    );
});

it('the fillable columns of every model of a tenant table are exactly the columns where pyme_app has INSERT', function (): void {
    $actual = [];
    $insertable = [];
    foreach (tenantModelCatalogTenantModels() as $class => $model) {
        $fillable = $model->getFillable();
        sort($fillable, SORT_STRING);
        $actual[$class] = $fillable;

        $columns = array_column(DB::select(<<<'SQL'
            select a.attname
            from pg_attribute a
            where a.attrelid = to_regclass('public.' || quote_ident(?))
              and a.attnum > 0
              and not a.attisdropped
              and has_column_privilege('pyme_app', a.attrelid, a.attnum, 'INSERT')
            SQL, [$model->getTable()]), 'attname');
        sort($columns, SORT_STRING);
        $insertable[$class] = $columns;
    }

    // Guard against passing on an empty result.
    expect($actual)->toHaveKeys(TENANT_MODEL_CATALOG_EXPECTED_MODELS);

    $this->assertSame(
        $insertable,
        $actual,
        'Model → fillable columns (sorted) must equal model → columns where pyme_app has INSERT, read from the catalog. A column insertable but not fillable, or fillable without INSERT privilege, shows up in the diff.',
    );
});
