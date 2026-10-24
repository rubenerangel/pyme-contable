<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('corre contra Postgres en la base de pruebas, nunca en la de desarrollo', function (): void {
    $fila = DB::selectOne(<<<'SQL'
        select current_database()                          as base,
               current_setting('TimeZone')                 as zona,
               current_setting('server_version_num')::int  as version
        SQL);

    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and($fila->base)->toBe('pyme_test')
        ->and($fila->zona)->toBe('UTC')
        ->and($fila->version)->toBeGreaterThanOrEqual(170000);
});

it('no usa conexiones PDO persistentes', function (): void {
    expect(DB::connection()->getPdo()->getAttribute(PDO::ATTR_PERSISTENT))->toBeFalse();
});