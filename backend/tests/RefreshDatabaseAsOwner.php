<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

// The default connection is pyme_app, which cannot migrate. The nested trait's method wins over the one in
// RefreshDatabase, and an override in TestCase would not work because it loses against the trait.
trait RefreshDatabaseAsOwner
{
    use RefreshDatabase {
        migrateFreshUsing as laravelMigrateFreshUsing;
    }
    use OwnerMigrationPaths;

    protected function migrateFreshUsing()
    {
        // With --path Laravel does not load migration paths registered by packages; if one needs them, add them here.
        return array_merge($this->laravelMigrateFreshUsing(), [
            '--database' => 'pgsql_owner',
            '--path' => $this->ownerMigrationPaths(),
        ]);
    }
}
