<?php

declare(strict_types=1);

// Child of Tests\Support\AccountLocksProcess: calls the real AccountLocks::lockForPosting() inside a transaction that is
// always rolled back, and reports the backend pid and the outcome on stdout, one per line. The connection configuration
// comes through stdin.

use App\Infrastructure\Ledger\AccountLocks;
use App\Infrastructure\Tenancy\DatabaseCompanyContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;

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
    $connection->statement("set statement_timeout = '15s'");

    $context = new DatabaseCompanyContext($connection);
    $context->enter((int) $input['company_id'], (int) $input['user_id']);

    try {
        fwrite(STDOUT, ((int) $connection->selectOne('select pg_backend_pid() as pid')->pid)."\n");
        fflush(STDOUT);

        $connection->beginTransaction();
        try {
            // Force a sequential scan: without ORDER BY, FOR SHARE would then lock in physical order, with the higher id
            // first, so a missing ORDER BY in AccountLocks shows up as a wrong lock order.
            $connection->statement('set local enable_indexscan = off');
            $connection->statement('set local enable_bitmapscan = off');

            // Ids exactly in the order received: the ascending order is up to AccountLocks, not to the caller.
            (new AccountLocks($connection))->lockForPosting(array_map('intval', $input['account_ids']));
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
