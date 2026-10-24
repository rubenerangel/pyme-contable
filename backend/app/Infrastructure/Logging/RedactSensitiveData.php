<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that replaces with '[redacted]' the value of every sensitive key in context and extra, at any
 * depth of arrays. A key is sensitive when, ignoring case, it equals one of KEYS or ends with one of SUFFIXES. The whole
 * value goes, whatever its type. Objects (the exception of a record) are left as they are.
 */
final class RedactSensitiveData implements ProcessorInterface
{
    public const REDACTED = '[redacted]';

    private const KEYS = [
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
    ];

    private const SUFFIXES = ['_password', '_token', '_secret'];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $data[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    private function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        if (in_array($key, self::KEYS, true)) {
            return true;
        }

        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
