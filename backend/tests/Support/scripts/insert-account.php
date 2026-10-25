<?php

declare(strict_types=1);

// Child of Tests\Support\AccountInsertProcess: inserts one account in a transaction that is always rolled back, and
// reports the backend pid and the outcome on stdout, one per line. The connection configuration comes through stdin.

use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Tests\Support\AccountFixtures;

$basePath = dirname(__DIR__, 3);

require $basePath.'/vendor/autoload.php';

try {
    $input = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);

    $app = require $basePath.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    // Before any connection: use exactly the configuration of the test process instead of what the child inherits.
    $app['config']->set('database.connections.pgsql', $input['connection']);
    $app['db']->purge('pgsql');

    $connection = $app['db']->connection('pgsql');
    // Safety net against hangs, longer than the wait of the test.
    $connection->statement("set lock_timeout = '10s'");

    $context = new DatabaseCompanyContext($connection);
    $context->enter((int) $input['company_id'], (int) $input['user_id']);

    try {
        fwrite(STDOUT, ((int) $connection->selectOne('select pg_backend_pid() as pid')->pid)."\n");
        fflush(STDOUT);

        $connection->beginTransaction();
        try {
            AccountFixtures::insert($connection, (string) $input['code'], $input['parent_id'] === null ? null : (int) $input['parent_id']);
            $outcome = 'ok';
        } catch (QueryException $e) {
            $outcome = (string) $e->errorInfo[0];
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }

        fwrite(STDOUT, $outcome."\n");
        fflush(STDOUT);
    } finally {
        $context->leave();
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage()."\n");
    exit(1);
}
