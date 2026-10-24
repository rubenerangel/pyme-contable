<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * Shared assertions for the Problem Details contract (RFC 9457, application/problem+json) and for "nothing internal
 * leaks into the body". Does not reference DomainError, so it is safe to autoload before the domain base class exists.
 */
final class ProblemAssertions
{
    /** Internal message that must never reach a response. */
    public const SECRET = 'secret-marker-9137';

    /** Value placed in the log context of a domain error; it must never reach a response either. */
    public const CONTEXT_SECRET = 'context-marker-5521';

    /**
     * Content of lang/es/errors.php, read as a plain array (no translator, so the answer is the file itself).
     *
     * @return array{codes: array<string, string>, titles: array<int|string, string>}
     */
    public static function errorsLang(): array
    {
        $path = dirname(__DIR__, 2).'/lang/es/errors.php';
        Assert::assertFileExists($path, 'lang/es/errors.php must exist.');

        $lang = require $path;
        Assert::assertIsArray($lang);
        Assert::assertIsArray($lang['codes'] ?? null, "lang/es/errors.php must have a 'codes' array.");
        Assert::assertIsArray($lang['titles'] ?? null, "lang/es/errors.php must have a 'titles' array.");

        return $lang;
    }

    public static function expectedTitle(int $status): string
    {
        $titles = self::errorsLang()['titles'];

        Assert::assertArrayHasKey('default', $titles, "errors.titles must have a 'default' entry.");

        return $titles[$status] ?? $titles['default'];
    }

    public static function expectedDetail(string $code): string
    {
        $codes = self::errorsLang()['codes'];

        Assert::assertArrayHasKey($code, $codes, "errors.codes must have a message for '{$code}'.");

        return $codes[$code];
    }

    /**
     * Asserts the Problem Details shape: status, content type, exact field set, title, detail, code and request_id
     * equal to the X-Request-Id header. Returns the decoded body.
     *
     * @param  string|null  $detail  expected detail; null means the message of errors.codes for the code
     * @return array<string, mixed>
     */
    public static function assertProblem(TestResponse $response, int $status, string $code, ?string $detail = null, bool $withErrors = false): array
    {
        $response->assertStatus($status);

        Assert::assertMatchesRegularExpression(
            '#^application/problem\+json(\s*;.*)?$#i',
            (string) $response->headers->get('Content-Type'),
            'The error response must be application/problem+json.',
        );

        $body = json_decode((string) $response->getContent(), true);
        Assert::assertIsArray($body, 'The error body must be a JSON object.');

        $expectedKeys = ['code', 'detail', 'request_id', 'status', 'title'];
        if ($withErrors) {
            $expectedKeys[] = 'errors';
        }
        sort($expectedKeys);
        $actualKeys = array_keys($body);
        sort($actualKeys);
        Assert::assertSame($expectedKeys, $actualKeys, 'The error body must have exactly the Problem Details fields (no type, instance, message, trace).');

        Assert::assertSame($status, $body['status']);
        Assert::assertSame($code, $body['code']);
        Assert::assertSame(self::expectedTitle($status), $body['title']);
        Assert::assertSame($detail ?? self::expectedDetail($code), $body['detail']);

        $headerId = $response->headers->get('X-Request-Id');
        Assert::assertIsString($headerId, 'The error response must carry the X-Request-Id header.');
        Assert::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $headerId);
        Assert::assertSame($headerId, $body['request_id'], 'request_id in the body must equal the X-Request-Id header.');

        return $body;
    }

    /**
     * Asserts that the whole body (raw and decoded again with unescaped slashes, because JSON escapes "/") contains
     * nothing internal.
     *
     * @param  list<string>  $secrets  extra strings that must not appear
     */
    public static function assertNoLeak(TestResponse $response, array $secrets = []): void
    {
        $raw = (string) $response->getContent();
        $decoded = json_decode($raw, true);
        $haystack = $raw."\n".json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $caseInsensitive = ['Exception', 'App\\', 'Illuminate', '/var/www', '.php', 'SQLSTATE', self::SECRET, self::CONTEXT_SECRET, ...$secrets];
        foreach ($caseInsensitive as $forbidden) {
            Assert::assertFalse(
                stripos($haystack, $forbidden) !== false,
                "The response body must not contain '{$forbidden}'. Body: {$raw}",
            );
        }

        foreach (['select', 'trace', 'file', 'line'] as $forbidden) {
            Assert::assertFalse(
                str_contains($haystack, $forbidden),
                "The response body must not contain '{$forbidden}'. Body: {$raw}",
            );
        }
    }
}
