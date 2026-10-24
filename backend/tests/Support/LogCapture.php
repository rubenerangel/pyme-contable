<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Redirects the stderr log channel to a temporary file so a test can read what the application logged, one JSON
 * record per line. Does not reference production classes, so it is safe to autoload before they exist.
 *
 * The channel is rebuilt (forgetChannel) because the log manager caches the channel with the stream it first opened.
 */
final class LogCapture
{
    private function __construct(private readonly string $path) {}

    public static function redirect(): self
    {
        $path = tempnam(sys_get_temp_dir(), 'pyme-log-');
        Assert::assertIsString($path, 'A temporary file for the log capture must be created.');

        config([
            'logging.default' => 'stderr',
            'logging.channels.stderr.handler_with.stream' => $path,
        ]);
        app('log')->forgetChannel('stderr');

        return new self($path);
    }

    /**
     * Everything written to the channel, as is.
     */
    public function raw(): string
    {
        $content = file_get_contents($this->path);
        Assert::assertIsString($content, 'The captured log file must be readable.');

        return $content;
    }

    /**
     * Every record, decoded. Fails if a line is not a valid JSON object.
     *
     * @return list<array<string, mixed>>
     */
    public function records(): array
    {
        $records = [];

        foreach (explode("\n", $this->raw()) as $number => $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            Assert::assertIsArray($decoded, 'Log line '.($number + 1).' must be a JSON object. Line: '.$line);
            $records[] = $decoded;
        }

        return $records;
    }

    /**
     * Records with the given message.
     *
     * @return list<array<string, mixed>>
     */
    public function named(string $message): array
    {
        return array_values(array_filter(
            $this->records(),
            static fn (array $record): bool => ($record['message'] ?? null) === $message,
        ));
    }

    /**
     * Records with the given level name (INFO, WARNING, ERROR...).
     *
     * @return list<array<string, mixed>>
     */
    public function atLevel(string $levelName): array
    {
        return array_values(array_filter(
            $this->records(),
            static fn (array $record): bool => ($record['level_name'] ?? null) === $levelName,
        ));
    }

    public function release(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}
