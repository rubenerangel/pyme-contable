<?php

declare(strict_types=1);

namespace App\Http\Errors;

use App\Domain\DomainError;
use App\Domain\ErrorKind;
use App\Http\Middleware\AssignRequestId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Turns any exception into Problem Details (RFC 9457, application/problem+json). The body is built only from status,
 * code and the Spanish messages of lang/es/errors.php: never from the exception message, class, trace or app.debug.
 * Laravel runs it after converting ModelNotFound, TokenMismatch and Authorization into HTTP exceptions.
 */
final class ProblemDetailsRenderer
{
    public function __construct(private readonly Translator $translator) {}

    /**
     * Null leaves the response to Laravel: an HttpResponseException carries a response built on purpose.
     */
    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if ($e instanceof HttpResponseException) {
            return null;
        }

        $headers = [];
        $errors = null;

        if ($e instanceof DomainError) {
            $status = match ($e->kind()) {
                ErrorKind::Invalid => 422,
                ErrorKind::NotFound => 404,
                ErrorKind::Forbidden => 403,
                ErrorKind::Conflict => 409,
            };
            $code = $e->errorCode();
        } elseif ($e instanceof ValidationException) {
            $status = 422;
            $code = 'validation_failed';
            $errors = $e->errors();
        } elseif ($e instanceof AuthenticationException) {
            $status = 401;
            $code = 'unauthenticated';
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $code = $this->codeForStatus($status);
            $headers = $e->getHeaders();
        } else {
            $status = 500;
            $code = 'internal_error';
        }

        $requestId = Context::get('request_id');
        if (! is_string($requestId)) {
            // The exception happened before AssignRequestId ran.
            $requestId = AssignRequestId::generate();
            Context::add('request_id', $requestId);
        }

        $body = [
            'status' => $status,
            'title' => $this->title($status),
            'detail' => $this->translator->get("errors.codes.{$code}"),
            'code' => $code,
            'request_id' => $requestId,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return new JsonResponse($body, $status, [
            ...$headers,
            'Content-Type' => 'application/problem+json',
            AssignRequestId::HEADER => $requestId,
        ], JSON_UNESCAPED_UNICODE);
    }

    private function codeForStatus(int $status): string
    {
        return match (true) {
            $status === 400 => 'bad_request',
            $status === 401 => 'unauthenticated',
            $status === 403 => 'forbidden',
            $status === 404 => 'not_found',
            $status === 405 => 'method_not_allowed',
            $status === 419 => 'csrf_token_mismatch',
            $status === 429 => 'too_many_requests',
            $status === 503 => 'service_unavailable',
            $status >= 500 => 'internal_error',
            default => 'http_error',
        };
    }

    private function title(int $status): string
    {
        $title = $this->translator->get("errors.titles.{$status}");

        return $title === "errors.titles.{$status}" ? $this->translator->get('errors.titles.default') : $title;
    }
}
