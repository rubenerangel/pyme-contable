<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an id, shares it with the log context and returns it in X-Request-Id, errors included. nginx
 * sets the incoming header; any value that is not 32 lowercase hex chars is replaced, so a client cannot inject
 * anything into logs or headers. Must be the first global middleware.
 */
final class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $id = is_string($incoming) && preg_match('/^[0-9a-f]{32}$/D', $incoming) === 1 ? $incoming : self::generate();

        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
