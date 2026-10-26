<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Runs AccountLocks::lockForPosting() in a separate PHP process, to observe a lock statement that stays waiting while
 * the test process keeps working. A probe run after a failed statement of B sees nothing: Postgres aborts the statement
 * and releases its locks before the ROLLBACK. The only way to look at what B holds while it waits is for B to keep
 * waiting, and PDO is synchronous, so B runs in its own process.
 *
 * The child (scripts/lock-accounts.php) opens its own pyme_app connection with exactly the connection configuration of
 * the test process, received through stdin instead of depending on what it inherits from the environment (credentials
 * do not travel in argv either), enters the company context, begins a transaction and calls the real AccountLocks with
 * the ids in the order received.
 * It prints, one per line: the backend pid, then 'ok' or the SQLSTATE of the failure. It always rolls back at the end.
 *
 * The caller must call close() in a finally, after releasing whatever the child may be waiting for.
 */
final class AccountLocksProcess
{
    private const READ_TIMEOUT_SECONDS = 15.0;

    private const EXIT_TIMEOUT_SECONDS = 5.0;

    private string $stdoutBuffer = '';

    private string $stderrBuffer = '';

    private bool $closed = false;

    /**
     * @param  resource  $process
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    private function __construct(private $process, private $stdout, private $stderr) {}

    /**
     * Starts the child with the configuration of the 'pgsql' connection of this process (pyme_test, pyme_app).
     *
     * @param  list<int>  $accountIds  in the order the child must pass them to AccountLocks
     */
    public static function start(int $companyId, int $userId, array $accountIds): self
    {
        $payload = json_encode([
            'connection' => config('database.connections.pgsql'),
            'company_id' => $companyId,
            'user_id' => $userId,
            'account_ids' => array_values($accountIds),
        ], JSON_THROW_ON_ERROR);

        $process = proc_open(
            [PHP_BINARY, __DIR__.'/scripts/lock-accounts.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
        );

        if ($process === false) {
            throw new RuntimeException('Could not start the account locks process.');
        }

        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return new self($process, $pipes[1], $pipes[2]);
    }

    /**
     * Backend pid (pg_backend_pid()) of the connection of the child.
     */
    public function pid(): int
    {
        $line = $this->readLine();

        if (preg_match('/^\d+$/', $line) !== 1) {
            throw new RuntimeException(sprintf("The account locks process did not print a backend pid, it printed '%s'.%s", $line, $this->stderrSuffix()));
        }

        return (int) $line;
    }

    /**
     * Outcome of the lock: 'ok' or the SQLSTATE of the failure. Must be read after pid().
     */
    public function result(): string
    {
        return $this->readLine();
    }

    /**
     * Waits for the child to end; if it does not end in a short while, terminates it. Idempotent.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        $deadline = microtime(true) + self::EXIT_TIMEOUT_SECONDS;
        while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
            usleep(20_000);
        }
        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process);
        }

        fclose($this->stdout);
        fclose($this->stderr);
        proc_close($this->process);
    }

    private function readLine(): string
    {
        $deadline = microtime(true) + self::READ_TIMEOUT_SECONDS;

        while (($newline = strpos($this->stdoutBuffer, "\n")) === false) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('Timed out waiting for the account locks process.'.$this->stderrSuffix());
            }

            $read = [$this->stdout];
            $write = null;
            $except = null;
            $seconds = (int) $remaining;
            if (stream_select($read, $write, $except, $seconds, (int) (($remaining - $seconds) * 1_000_000)) === false) {
                throw new RuntimeException('stream_select failed on the account locks process.');
            }

            $chunk = fread($this->stdout, 8192);
            if ($chunk === false || ($chunk === '' && feof($this->stdout))) {
                throw new RuntimeException('The account locks process ended without printing the expected line.'.$this->stderrSuffix());
            }
            $this->stdoutBuffer .= $chunk;
        }

        $line = substr($this->stdoutBuffer, 0, $newline);
        $this->stdoutBuffer = substr($this->stdoutBuffer, $newline + 1);

        return trim($line);
    }

    private function stderrSuffix(): string
    {
        $chunk = fread($this->stderr, 65536);
        if ($chunk !== false) {
            $this->stderrBuffer .= $chunk;
        }

        return $this->stderrBuffer === '' ? ' The child printed nothing to stderr.' : ' Child stderr: '.trim($this->stderrBuffer);
    }
}
