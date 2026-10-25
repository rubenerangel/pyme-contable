<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTruncation;

// Twin of RefreshDatabaseAsOwner for concurrency tests that need real commits. The default connection is pyme_app,
// which cannot migrate nor TRUNCATE, so both jobs go through pgsql_owner (TRUNCATE does not go through RLS).
// Verified in vendor (laravel/framework v13.38.0): DatabaseTruncation migrates on first use (sharing
// RefreshDatabaseState::$migrated with RefreshDatabase) and truncates only at the start of each test.
trait DatabaseTruncationAsOwner
{
    use DatabaseTruncation {
        migrateFreshUsing as laravelMigrateFreshUsing;
    }

    // Global catalogs seeded by migrations: truncating them would leave them empty for the rest of the suite.
    // Laravel adds the migrations table to this list on its own.
    protected array $exceptTables = ['municipality'];

    protected function migrateFreshUsing()
    {
        // With --path Laravel does not load migration paths registered by packages; if one needs them, add them here.
        return array_merge($this->laravelMigrateFreshUsing(), [
            '--database' => 'pgsql_owner',
            '--path' => ['database/migrations', 'tests/database/migrations'],
        ]);
    }

    protected function connectionsToTruncate(): array
    {
        return ['pgsql_owner'];
    }

    // Picked up by Laravel's tearDown{TraitBasename} convention (registered with beforeApplicationDestroyed).
    // It also truncates at the end so that what a real commit left behind does not show up in a later test that uses
    // RefreshDatabase. Every other connection is purged first: a transaction left open on another connection would
    // make TRUNCATE (ACCESS EXCLUSIVE) wait forever.
    protected function tearDownDatabaseTruncationAsOwner(): void
    {
        $database = $this->app->make('db');

        foreach (array_keys($database->getConnections()) as $name) {
            if ($name !== 'pgsql_owner') {
                $database->purge($name);
            }
        }

        $this->truncateTablesForAllConnections();
    }
}
