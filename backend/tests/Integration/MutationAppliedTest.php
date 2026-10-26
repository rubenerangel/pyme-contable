<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\RefreshDatabaseAsOwner;

// The SQL mutation runner (G3) needs proof that the mutation was migrated: a mutation whose SQL fails would error
// every test and pass for a kill. Without MUTATION it guards the opposite: no mutation leaked into a normal run.
uses(RefreshDatabaseAsOwner::class);

it('migrates exactly the mutation named in MUTATION and no other', function (): void {
    $mutation = getenv('MUTATION');
    $expected = [];
    if ($mutation !== false && $mutation !== '') {
        $files = glob(base_path("tests/database/mutations/{$mutation}").'/*.php') ?: [];
        $expected = array_map(fn (string $file): string => basename($file, '.php'), $files);
    }

    $applied = DB::connection('pgsql_owner')->table('migrations')->pluck('migration')
        ->filter(fn (string $name): bool => str_starts_with($name, '9999_12_31_'))
        ->values()
        ->all();

    expect($applied)->toBe($expected);
});
