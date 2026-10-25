<?php

declare(strict_types=1);

use App\Domain\Ledger\NormalBalance;
use App\Infrastructure\Tenancy\CompanyScope;
use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;
use Tests\Support\AccountFixtures;
use Tests\Support\TenantCatalog;

// The Account model and its CompanyScope on top of account, which has RLS. Here the model is what is under test, so the
// context is entered through DatabaseCompanyContext and not with raw SQL (TenantIsolationTest covers RLS itself). A SQL
// error aborts the RefreshDatabase transaction in Postgres, so each failing statement lives in its own test.
uses(RefreshDatabaseAsOwner::class);

/**
 * Creates two companies with two accounts each (a-1, a-2, b-1, b-2 as code), entering each context through
 * DatabaseCompanyContext and never passing company_id. Leaves the context cleared.
 *
 * @return array{a: int, b: int, user: int}
 */
function accountModelSeedTwoCompanies(): array
{
    $context = app(DatabaseCompanyContext::class);
    $userId = User::factory()->create()->id;
    $ids = [];

    foreach (['a', 'b'] as $key) {
        $ids[$key] = Company::factory()->create()->id;
        $context->enter($ids[$key], $userId);
        AccountFixtures::insert(DB::connection(), $key.'-1');
        AccountFixtures::insert(DB::connection(), $key.'-2');
    }

    $context->leave();

    return [...$ids, 'user' => $userId];
}

it('shows only the accounts of the company in the context, and none without context', function (): void {
    $ids = accountModelSeedTwoCompanies();
    $context = app(DatabaseCompanyContext::class);

    $context->enter($ids['a'], $ids['user']);
    $seenWithA = Account::query()->orderBy('code')->pluck('code')->all();

    $context->leave();
    $countWithoutContext = Account::count();

    expect(['with a' => $seenWithA, 'without context' => $countWithoutContext])->toBe([
        'with a' => ['a-1', 'a-2'],
        'without context' => 0,
    ]);
});

it('filters by company on its own with RLS turned off, and shows none without context (the scope fails closed on its own)', function (): void {
    $owner = DB::connection('pgsql_owner');
    // RefreshDatabaseAsOwner does not wrap pgsql_owner: the rollback is mandatory, and it also undoes the ALTERs.
    $owner->beginTransaction();

    try {
        // The ALTER TABLE takes ACCESS EXCLUSIVE on account and would wait for any lock held by the transaction of
        // RefreshDatabase on the default connection (pyme_app). That connection must not touch account before this
        // point; lock_timeout makes a mistake fail in seconds instead of hanging.
        $owner->statement("set local lock_timeout = '5s'");
        $owner->statement('ALTER TABLE account DISABLE ROW LEVEL SECURITY');
        // The audit trigger would need app.user_id and the hierarchy trigger is not under test here.
        $owner->statement('ALTER TABLE account DISABLE TRIGGER USER');

        // Everything goes through the owner connection: the rows of pyme_app are uncommitted and invisible to it.
        $now = now();
        $ids = [];
        foreach (['a' => 'Company A SAS', 'b' => 'Company B SAS'] as $key => $name) {
            $ids[$key] = (int) $owner->table('companies')->insertGetId([
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($ids as $key => $companyId) {
            foreach ([1, 2] as $n) {
                $owner->table('account')->insert([
                    'company_id' => $companyId,
                    ...AccountFixtures::minimalRow($key.'-'.$n),
                ]);
            }
        }

        // Local to the transaction (true), so nothing leaks to the connection after the rollback.
        $owner->select("select set_config('app.company_id', ?, true)", [(string) $ids['a']]);

        $scoped = Account::on('pgsql_owner')->orderBy('code')->pluck('code')->all();
        $unscoped = Account::on('pgsql_owner')->withoutGlobalScope(CompanyScope::class)->orderBy('code')->pluck('code')->all();

        // Context cleared (empty string, as DatabaseCompanyContext::leave leaves it): the scope alone must show nothing.
        $owner->select("select set_config('app.company_id', ?, true)", ['']);

        $scopedWithoutContext = Account::on('pgsql_owner')->orderBy('code')->pluck('code')->all();
        $unscopedWithoutContext = Account::on('pgsql_owner')->withoutGlobalScope(CompanyScope::class)->orderBy('code')->pluck('code')->all();

        // The controls prove RLS is really off: without the scope the four rows show up, with and without context.
        expect([
            'scoped' => $scoped,
            'unscoped' => $unscoped,
            'scoped without context' => $scopedWithoutContext,
            'unscoped without context' => $unscopedWithoutContext,
        ])->toBe([
            'scoped' => ['a-1', 'a-2'],
            'unscoped' => ['a-1', 'a-2', 'b-1', 'b-2'],
            'scoped without context' => [],
            'unscoped without context' => ['a-1', 'a-2', 'b-1', 'b-2'],
        ]);
    } finally {
        while ($owner->transactionLevel() > 0) {
            $owner->rollBack();
        }
    }
});

it('qualifies the company column with the table, so a self join is not ambiguous', function (): void {
    AccountFixtures::enterNewCompany();
    $parentId = AccountFixtures::insert(DB::connection(), 'parent-1');
    AccountFixtures::insert(DB::connection(), 'child-1', $parentId);

    // Without the qualification Postgres would fail with "column reference company_id is ambiguous". The parent has
    // no parent of its own, so the join leaves only the child.
    $codes = Account::query()
        ->join('account as parent', 'parent.id', '=', 'account.parent_id')
        ->pluck('account.code')
        ->all();

    expect($codes)->toBe(['child-1']);
});

it('does not take company_id from mass assignment: the row ends up in the company of the context', function (): void {
    $companyA = AccountFixtures::enterNewCompany();
    $companyB = Company::factory()->create()->id;

    Account::create([...AccountFixtures::minimalRow('mass-assigned'), 'company_id' => $companyB]);

    $stored = DB::table('account')->where('code', 'mass-assigned')->value('company_id');

    expect((int) $stored)->toBe($companyA);
});

it('rejects assigning company_id directly and saving (no UPDATE privilege on the column, SQLSTATE 42501)', function (): void {
    AccountFixtures::enterNewCompany();
    $companyB = Company::factory()->create()->id;
    $account = Account::create(AccountFixtures::minimalRow('moved'));

    $account->company_id = $companyB;

    expect(fn () => $account->save())
        ->toThrow(function (QueryException $e): void {
            expect($e->errorInfo[0])->toBe('42501');
            expect($e->getMessage())->toContain('permission denied for table account');
        });
});

it('rejects forceCreate with company_id even if it is the own company (no INSERT privilege on the column, SQLSTATE 42501)', function (): void {
    $companyA = AccountFixtures::enterNewCompany();

    expect(fn () => Account::forceCreate([...AccountFixtures::minimalRow('forced'), 'company_id' => $companyA]))
        ->toThrow(function (QueryException $e): void {
            expect($e->errorInfo[0])->toBe('42501');
            expect($e->getMessage())->toContain('permission denied for table account');
        });
});

it('casts normal_balance to NormalBalance when read back from the database', function (): void {
    AccountFixtures::enterNewCompany();

    $account = Account::create([
        ...AccountFixtures::minimalRow('cast-1'),
        'normal_balance' => NormalBalance::Credit,
    ]);

    expect($account->fresh()->normal_balance)->toBe(NormalBalance::Credit);
});

it('casts every boolean attribute to a strict bool in memory (without a cast the integers would stay as they are)', function (): void {
    // No database: pdo_pgsql already returns bool, so only integers handed to the model can prove the casts exist.
    $on = new Account([
        'is_postable' => 1,
        'requires_third_party' => 1,
        'requires_cost_center' => 1,
        'requires_construction_project' => 1,
        'requires_municipality' => 1,
        'requires_tax_detail' => 1,
        'is_active' => 1,
    ]);
    $off = new Account([
        'is_postable' => 0,
        'requires_third_party' => 0,
        'requires_cost_center' => 0,
        'requires_construction_project' => 0,
        'requires_municipality' => 0,
        'requires_tax_detail' => 0,
        'is_active' => 0,
    ]);

    $read = fn (Account $account): array => [
        'is_postable' => $account->is_postable,
        'requires_third_party' => $account->requires_third_party,
        'requires_cost_center' => $account->requires_cost_center,
        'requires_construction_project' => $account->requires_construction_project,
        'requires_municipality' => $account->requires_municipality,
        'requires_tax_detail' => $account->requires_tax_detail,
        'is_active' => $account->is_active,
    ];

    expect(['from 1' => $read($on), 'from 0' => $read($off)])->toBe([
        'from 1' => array_fill_keys(array_keys($read($on)), true),
        'from 0' => array_fill_keys(array_keys($read($off)), false),
    ]);
});

it('every model of a tenant table has the company scope and takes neither id nor company_id from mass assignment', function (): void {
    $tenantTables = array_diff(
        TenantCatalog::tablesWithCompanyId(),
        TenantCatalog::GLOBAL_TABLES_WITH_COMPANY_ID,
    );

    $publicTables = array_column(DB::select(<<<'SQL'
        select c.relname
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = 'public' and c.relkind in ('r', 'p')
        SQL), 'relname');

    $modelsPath = app_path('Models');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($modelsPath, FilesystemIterator::SKIP_DOTS));

    $actual = [];
    $withoutTable = [];
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

        // Instantiated so the model boots and registers its global scopes.
        $model = new $class;

        // A table with a schema prefix or a typo would otherwise be skipped silently by the tenant filter below.
        if (! in_array($model->getTable(), $publicTables, true)) {
            $withoutTable[] = $class.' → '.$model->getTable();
        }

        if (! in_array($model->getTable(), $tenantTables, true)) {
            continue;
        }

        $actual[$class] = [$class::hasGlobalScope(CompanyScope::class), $model->isFillable('id'), $model->isFillable('company_id')];
    }

    $this->assertSame([], $withoutTable, 'Models whose getTable() is not a table of public (model → table).');

    // Guard against passing on an empty result.
    expect($actual)->toHaveKey(Account::class);

    $this->assertSame(
        array_fill_keys(array_keys($actual), [true, false, false]),
        $actual,
        'Model → [has CompanyScope, id fillable, company_id fillable] for every model of a tenant table. It must have the scope and neither column fillable.',
    );
});
