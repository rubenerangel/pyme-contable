<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * Assertions on the SQLSTATE and the constraint of a statement Postgres rejects. A bare SQLSTATE is not enough: the same
 * code (23514, 23505, 23503) can come from another rule, so the test would pass after the constraint under test is
 * dropped. For the violations a constraint reports, its name is mandatory and must appear in the Postgres message.
 */
final class SqlAssertions
{
    private const CONSTRAINT_KINDS = [
        '23514' => 'check',
        '23505' => 'unique',
        '23503' => 'foreign key',
    ];

    /**
     * Asserts that the statement fails with a QueryException carrying the given SQLSTATE.
     *
     * For 23514 (CHECK), 23505 (UNIQUE) and 23503 (FOREIGN KEY) either the constraint name or, for an error a trigger
     * raises, a fragment of its message must be given. The constraint is matched as
     * 'violates <kind> constraint "<name>"'; the message fragment is matched as a substring of the Postgres message.
     */
    public static function expectRejected(Closure $statement, string $sqlState, ?string $constraint = null, ?string $message = null): void
    {
        $kind = self::CONSTRAINT_KINDS[$sqlState] ?? null;
        if ($kind !== null && $constraint === null && $message === null) {
            throw new InvalidArgumentException("SQLSTATE {$sqlState} requires the name of the expected constraint or a message.");
        }

        if ($kind === null && $constraint !== null) {
            throw new InvalidArgumentException("SQLSTATE {$sqlState} does not report a constraint name this helper can match.");
        }

        expect($statement)->toThrow(function (QueryException $e) use ($sqlState, $kind, $constraint, $message): void {
            expect($e->errorInfo[0])->toBe($sqlState);

            if ($constraint !== null) {
                expect($e->errorInfo[2])->toMatch('/violates '.$kind.' constraint "'.preg_quote($constraint, '/').'"/');
            }

            if ($message !== null) {
                expect($e->errorInfo[2])->toContain($message);
            }
        });
    }
}
