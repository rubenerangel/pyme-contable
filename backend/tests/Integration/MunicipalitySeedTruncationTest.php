<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\DatabaseTruncationAsOwner;
use Tests\Support\DivipolaCsv;

// municipality is a global catalog seeded by the migration that creates it, not by the tests. DatabaseTruncationAsOwner
// truncates every table at the start of each test, which would wipe the seed unless municipality is excluded from the
// truncation: later tests in the same run (or any test that relies on the catalog) would then see an empty table. The
// test calls truncateTablesForAllConnections() explicitly, the same path as the initial truncation and as
// tearDownDatabaseTruncationAsOwner. It does not rely on the initial truncation: when RefreshDatabaseState::$migrated
// is false (file run alone) the trait migrates instead of truncating.
uses(DatabaseTruncationAsOwner::class);

it('keeps the seeded municipalities after truncating all tables of DatabaseTruncationAsOwner', function (): void {
    $expected = count(DivipolaCsv::rows());

    // Guard against passing on an empty result.
    expect($expected)->toBeGreaterThan(1000);

    $this->truncateTablesForAllConnections();

    expect(DB::table('municipality')->count())->toBe($expected);
});
