<?php

declare(strict_types=1);

namespace Tests;

use RuntimeException;

// Shared by RefreshDatabaseAsOwner and DatabaseTruncationAsOwner so both migrate the same paths. With
// MUTATION=<name> it adds tests/database/mutations/<name>, which holds a single migration dated 9999_12_31 so it runs
// after every real one. Applying a mutation before the suite would not work: migrate:fresh would wipe it.
// Any misconfiguration throws, so a typo can never turn into a silent run without the mutation.
trait OwnerMigrationPaths
{
    /** @return list<string> */
    private function ownerMigrationPaths(): array
    {
        $paths = ['database/migrations', 'tests/database/migrations'];

        $mutation = getenv('MUTATION');
        if ($mutation === false || $mutation === '') {
            return $paths;
        }

        if (preg_match('/^[a-z0-9_]+$/D', $mutation) !== 1) {
            throw new RuntimeException("MUTATION must match [a-z0-9_]+, got '{$mutation}'.");
        }

        $dir = "tests/database/mutations/{$mutation}";
        $files = glob(base_path($dir).'/*.php') ?: [];
        if (count($files) !== 1 || ! str_starts_with(basename($files[0]), '9999_12_31_')) {
            throw new RuntimeException("{$dir} must contain exactly one migration named 9999_12_31_*.php.");
        }

        $paths[] = $dir;

        return $paths;
    }
}
