<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\DomainError;
use App\Domain\ErrorKind;

/**
 * Concrete DomainError for tests: kind, code, internal message and log context are chosen by the test.
 * Referencing this class loads App\Domain\DomainError, so tests must check that the base class exists first.
 */
final class ProbeDomainError extends DomainError
{
    /**
     * @param  array<string, mixed>  $probeContext  empty means "use the default context of DomainError"
     */
    public function __construct(
        private readonly ErrorKind $probeKind,
        private readonly string $probeCode,
        string $internalMessage,
        private readonly array $probeContext = [],
    ) {
        parent::__construct($internalMessage);
    }

    public function errorCode(): string
    {
        return $this->probeCode;
    }

    public function kind(): ErrorKind
    {
        return $this->probeKind;
    }

    public function context(): array
    {
        return $this->probeContext === [] ? parent::context() : $this->probeContext;
    }
}
