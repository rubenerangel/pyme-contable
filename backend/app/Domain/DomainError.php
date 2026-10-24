<?php

declare(strict_types=1);

namespace App\Domain;

use RuntimeException;

/**
 * Base of the errors the business rules raise on purpose. The exception message is internal, in English, for logs
 * only. What the user sees is the Spanish message of errorCode() in lang/es/errors.php. Both errorCode() and kind()
 * are constants of the concrete class: they must not depend on constructor arguments.
 */
abstract class DomainError extends RuntimeException
{
    /** Stable code, snake_case in English. It is part of the API contract. */
    abstract public function errorCode(): string;

    abstract public function kind(): ErrorKind;

    /**
     * Data for the log. Laravel appends it to the log entry of the exception; it never reaches the response.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [];
    }
}
