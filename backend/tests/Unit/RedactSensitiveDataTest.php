<?php

declare(strict_types=1);

use App\Infrastructure\Logging\RedactSensitiveData;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Assert;

// RedactSensitiveData: Monolog processor that replaces with '[redacted]' the value of every sensitive key in context
// and extra, at any depth. A key is sensitive when, ignoring case, it equals one of password, password_confirmation,
// current_password, token, _token, secret, authorization, cookie, x-csrf-token, x-xsrf-token, api_key, or ends with
// _password, _token or _secret. Built by hand over a LogRecord: no Laravel, no facades.
// Monolog 3.12.1 (composer.lock). Context7 /seldaek/monolog: LogRecord (datetime, channel, level, message, context,
// extra) and processors receive and return a LogRecord.

/**
 * Fails (instead of a fatal error) while the processor does not exist yet.
 */
function requireRedactSensitiveData(): RedactSensitiveData
{
    Assert::assertTrue(class_exists(RedactSensitiveData::class), 'App\Infrastructure\Logging\RedactSensitiveData must exist.');

    return new RedactSensitiveData;
}

/**
 * @param  array<int|string, mixed>  $context
 * @param  array<int|string, mixed>  $extra
 */
function redactionRecord(array $context = [], array $extra = []): LogRecord
{
    return new LogRecord(
        new DateTimeImmutable('2026-10-24 12:00:00', new DateTimeZone('UTC')),
        'testing',
        Level::Warning,
        'redaction probe',
        $context,
        $extra,
    );
}

it('redacts the value of every sensitive key, in context and in extra', function (string $key): void {
    $processor = requireRedactSensitiveData();

    $result = $processor(redactionRecord([$key => 'plain-value', 'kept' => 'visible'], [$key => 'plain-value', 'kept' => 'visible']));

    expect($result->context)->toBe([$key => '[redacted]', 'kept' => 'visible'])
        ->and($result->extra)->toBe([$key => '[redacted]', 'kept' => 'visible']);
})->with([
    'password',
    'password_confirmation',
    'current_password',
    'token',
    '_token',
    'secret',
    'authorization',
    'cookie',
    'x-csrf-token',
    'x-xsrf-token',
    'api_key',
]);

it('ignores the case of the key', function (string $key): void {
    $processor = requireRedactSensitiveData();

    $result = $processor(redactionRecord([$key => 'plain-value']));

    expect($result->context)->toBe([$key => '[redacted]']);
})->with([
    'Authorization',
    'PASSWORD',
    'X-CSRF-Token',
    'Api_Key',
    'Cookie',
    'DB_PASSWORD',
]);

it('redacts keys that end with _password, _token or _secret', function (string $key): void {
    $processor = requireRedactSensitiveData();

    $result = $processor(redactionRecord([$key => 'plain-value']));

    expect($result->context)->toBe([$key => '[redacted]']);
})->with([
    'db_password',
    'access_token',
    'refresh_token',
    'client_secret',
    'webhook_secret',
    'Smtp_Password',
]);

it('leaves keys that are not sensitive untouched', function (string $key): void {
    $processor = requireRedactSensitiveData();

    $result = $processor(redactionRecord([$key => 'plain-value']));

    expect($result->context)->toBe([$key => 'plain-value']);
})->with([
    'user_id',
    'company_id',
    'request_id',
    'amount',
    'name',
    'secretary',
    'tokenizer',
    'authorization_status',
    'cookie_count',
]);

it('redacts at any depth, including inside lists of arrays', function (): void {
    $processor = requireRedactSensitiveData();

    $result = $processor(redactionRecord([
        'user' => [
            'name' => 'Ana',
            'password' => 'hunter2',
            'profile' => ['token' => 'tok-1', 'city' => 'Cali'],
        ],
        'items' => [
            ['api_key' => 'key-1', 'sku' => 'A-1'],
            ['sku' => 'B-2', 'meta' => ['client_secret' => 'cs-1', 'qty' => 3]],
        ],
    ], [
        'headers' => ['Authorization' => 'Bearer abc', 'Accept' => 'application/json'],
    ]));

    expect($result->context)->toBe([
        'user' => [
            'name' => 'Ana',
            'password' => '[redacted]',
            'profile' => ['token' => '[redacted]', 'city' => 'Cali'],
        ],
        'items' => [
            ['api_key' => '[redacted]', 'sku' => 'A-1'],
            ['sku' => 'B-2', 'meta' => ['client_secret' => '[redacted]', 'qty' => 3]],
        ],
    ])->and($result->extra)->toBe([
        'headers' => ['Authorization' => '[redacted]', 'Accept' => 'application/json'],
    ]);
});

it('replaces the whole value of a sensitive key, whatever its type', function (): void {
    $processor = requireRedactSensitiveData();

    $result = $processor(redactionRecord([
        'password' => ['first-secret-part', 'second-secret-part'],
        'token' => 12345,
        'secret' => null,
        'cookie' => ['session' => 'abc', 'nested' => ['deeper' => 'def']],
    ]));

    expect($result->context)->toBe([
        'password' => '[redacted]',
        'token' => '[redacted]',
        'secret' => '[redacted]',
        'cookie' => '[redacted]',
    ]);
});

it('keeps the rest of the record as it was', function (): void {
    $processor = requireRedactSensitiveData();
    $record = redactionRecord(['password' => 'hunter2'], ['request_id' => 'abc']);

    $result = $processor($record);

    expect($result)->toBeInstanceOf(LogRecord::class)
        ->and($result->message)->toBe('redaction probe')
        ->and($result->level)->toBe(Level::Warning)
        ->and($result->channel)->toBe('testing')
        ->and($result->datetime)->toEqual($record->datetime)
        ->and($result->extra)->toBe(['request_id' => 'abc']);
});

it('does not touch values that are objects, such as the exception of a log record', function (): void {
    $processor = requireRedactSensitiveData();
    $exception = new RuntimeException('probe failure');

    $result = $processor(redactionRecord(['exception' => $exception, 'password' => 'hunter2']));

    expect($result->context['exception'])->toBe($exception)
        ->and($result->context['password'])->toBe('[redacted]');
});

it('keeps integer keys of lists and does not fail on them', function (): void {
    $processor = requireRedactSensitiveData();

    $result = $processor(redactionRecord(['first', 'second', 'nested' => [10 => 'ten', 11 => 'eleven']]));

    expect($result->context)->toBe(['first', 'second', 'nested' => [10 => 'ten', 11 => 'eleven']]);
});

it('does nothing to a record with empty context and extra', function (): void {
    $processor = requireRedactSensitiveData();

    $result = $processor(redactionRecord());

    expect($result->context)->toBe([])
        ->and($result->extra)->toBe([]);
});
