<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('runs against Postgres on the test database, never the development one', function (): void {
    $row = DB::selectOne(<<<'SQL'
        select current_database()                          as database,
               current_setting('TimeZone')                 as timezone,
               current_setting('server_version_num')::int  as version
        SQL);

    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and($row->database)->toBe('pyme_test')
        ->and($row->timezone)->toBe('UTC')
        ->and($row->version)->toBeGreaterThanOrEqual(170000);
});

it('does not use persistent PDO connections', function (): void {
    expect(DB::connection()->getPdo()->getAttribute(PDO::ATTR_PERSISTENT))->toBeFalse();
});
