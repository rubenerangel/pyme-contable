<?php

declare(strict_types=1);

use App\Infrastructure\Logging\RedactSensitiveData;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Logger;
use PHPUnit\Framework\Assert;

// Logging catalog: what the configuration promises, read without running a request. The default channel is stderr with
// one JSON record per line and the redaction processor, and the connections hide the bindings of a failed query.
// phpunit.xml forces LOG_CHANNEL=null, so the default is read by evaluating config/logging.php with the variables
// that could override it removed from the environment, and restored afterwards.
// Does not touch the database. Monolog 3.12.1, Laravel 13.38.0 (composer.lock). Context7 /seldaek/monolog:
// JsonFormatter, LogRecord and processors; /laravel/docs 13.x: Log::channel and the monolog driver.

/**
 * config/logging.php as it evaluates with no LOG_CHANNEL, LOG_STACK or LOG_STDERR_FORMATTER in the environment.
 *
 * @return array<string, mixed>
 */
function loggingConfigWithoutEnvironment(): array
{
    $keys = ['LOG_CHANNEL', 'LOG_STACK', 'LOG_STDERR_FORMATTER'];
    $saved = [];

    foreach ($keys as $key) {
        $saved[$key] = ['env' => $_ENV[$key] ?? null, 'server' => $_SERVER[$key] ?? null, 'process' => getenv($key)];
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    try {
        $config = require base_path('config/logging.php');
    } finally {
        foreach ($saved as $key => $values) {
            if ($values['env'] !== null) {
                $_ENV[$key] = $values['env'];
            }
            if ($values['server'] !== null) {
                $_SERVER[$key] = $values['server'];
            }
            if ($values['process'] !== false) {
                putenv($key.'='.$values['process']);
            }
        }
    }

    Assert::assertIsArray($config);

    return $config;
}

it('defaults to the stderr channel when nothing overrides it', function (): void {
    $config = loggingConfigWithoutEnvironment();

    expect($config['default'])->toBe('stderr');
});

it('keeps the stderr channel pointed at php://stderr through the monolog driver', function (): void {
    $stderr = loggingConfigWithoutEnvironment()['channels']['stderr'] ?? null;

    expect($stderr)->toBeArray()
        ->and($stderr['driver'])->toBe('monolog')
        ->and($stderr['handler_with']['stream'])->toBe('php://stderr');
});

it('formats the stderr channel as JSON and registers the redaction processor on it', function (): void {
    $logger = Log::channel('stderr')->getLogger();
    Assert::assertInstanceOf(Logger::class, $logger);

    $formatters = array_map(
        static fn (object $handler): ?object => $handler instanceof FormattableHandlerInterface ? $handler->getFormatter() : null,
        $logger->getHandlers(),
    );
    $redactors = array_filter(
        $logger->getProcessors(),
        static fn (callable $processor): bool => $processor instanceof RedactSensitiveData,
    );

    expect($formatters)->not->toBeEmpty()
        ->and($formatters[0])->toBeInstanceOf(JsonFormatter::class)
        ->and($redactors)->toHaveCount(1);
});

it('forces the null channel under phpunit so the tests write no logs by themselves', function (): void {
    expect(config('logging.default'))->toBe('null');
});

it('hides the bindings of a failed query on every database connection', function (string $connection): void {
    expect(config("database.connections.{$connection}.mask_bindings_in_exception_messages"))->toBeTrue();
})->with(['pgsql', 'pgsql_owner']);

it('does not keep arguments in exception traces (they carry passwords and NIT)', function (): void {
    // Set in docker/php/php.ini: it applies once the php image is rebuilt.
    expect(ini_get('zend.exception_ignore_args'))->toBeIn(['1', 'On', 'on', 'true']);
});

it('documents the stderr channel as the default in .env.example', function (): void {
    $lines = file(base_path('.env.example'), FILE_IGNORE_NEW_LINES);
    Assert::assertIsArray($lines);

    expect($lines)->toContain('LOG_CHANNEL=stderr');
});
