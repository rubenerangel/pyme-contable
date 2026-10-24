<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;

/**
 * Logs a failed query without any value of it. The message of Postgres carries a DETAIL line with the offending
 * values (for example the key of a violated UNIQUE) and the SQL of the exception message may carry the bindings, so
 * neither the exception nor its message is logged: only a fixed message, the SQLSTATE, the connection, the SQL with
 * placeholders and the Postgres message up to its DETAIL line.
 */
final class QueryExceptionReporter
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function report(QueryException $e): void
    {
        // The previous exception is the driver one: its message has no SQL appended.
        $previous = $e->getPrevious() ?? $e;

        $this->logger->error('Database query failed', [
            'sqlstate' => $e->errorInfo[0] ?? (string) $e->getCode(),
            'connection' => $e->getConnectionName(),
            'sql' => $e->getSql(),
            'database_error' => $this->withoutDetail($previous->getMessage()),
        ]);
    }

    private function withoutDetail(string $message): string
    {
        return preg_replace('/\R\s*DETAIL:.*\z/s', '', $message) ?? '';
    }
}
