<?php

declare(strict_types=1);

use App\Domain\DomainError;
use App\Domain\ErrorKind;
use PHPUnit\Framework\Assert;
use Tests\Support\ProbeDomainError;

// DomainError: base of the domain errors. The message is internal (logs only); the stable code and the kind are what
// the entry layer maps to HTTP. The domain knows nothing about HTTP. No Laravel, no database.

/**
 * Fails (instead of a fatal error loading ProbeDomainError) when the base classes do not exist yet.
 */
function requireDomainErrorClasses(): void
{
    Assert::assertTrue(enum_exists(ErrorKind::class), 'App\Domain\ErrorKind must exist.');
    Assert::assertTrue(class_exists(DomainError::class), 'App\Domain\DomainError must exist.');
}

it('is an abstract RuntimeException', function (): void {
    requireDomainErrorClasses();

    $reflection = new ReflectionClass(DomainError::class);

    expect($reflection->isAbstract())->toBeTrue();
    expect($reflection->isSubclassOf(RuntimeException::class))->toBeTrue();
});

it('declares the stable code and the kind as abstract', function (): void {
    requireDomainErrorClasses();

    $reflection = new ReflectionClass(DomainError::class);

    expect($reflection->getMethod('errorCode')->isAbstract())->toBeTrue();
    expect($reflection->getMethod('kind')->isAbstract())->toBeTrue();
    expect($reflection->getMethod('context')->isAbstract())->toBeFalse();
});

it('has exactly the kinds Invalid, NotFound, Forbidden and Conflict', function (): void {
    requireDomainErrorClasses();

    $reflection = new ReflectionEnum(ErrorKind::class);

    expect($reflection->isBacked())->toBeTrue();
    expect((string) $reflection->getBackingType())->toBe('string');
    expect(array_map(fn (ErrorKind $kind): string => $kind->name, ErrorKind::cases()))
        ->toEqualCanonicalizing(['Invalid', 'NotFound', 'Forbidden', 'Conflict']);
});

it('keeps the internal message, exposes the code and the kind, and has an empty context by default', function (): void {
    requireDomainErrorClasses();

    $error = new ProbeDomainError(ErrorKind::Conflict, 'probe_code', 'internal detail for logs only');

    expect($error)->toBeInstanceOf(RuntimeException::class);
    expect([
        'message' => $error->getMessage(),
        'code' => $error->errorCode(),
        'kind' => $error->kind(),
        'context' => $error->context(),
    ])->toBe([
        'message' => 'internal detail for logs only',
        'code' => 'probe_code',
        'kind' => ErrorKind::Conflict,
        'context' => [],
    ]);
});

it('lets a concrete error provide its context for the logs', function (): void {
    requireDomainErrorClasses();

    $error = new ProbeDomainError(ErrorKind::Forbidden, 'probe_code', 'internal', ['user_id' => 7, 'company_id' => 9]);

    expect($error->context())->toBe(['user_id' => 7, 'company_id' => 9]);
});
