<?php

use App\Domain\DomainError;
use App\Domain\ErrorKind;
use App\Http\Errors\ProblemDetailsRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\SetCompanyContext;
use App\Infrastructure\Logging\QueryExceptionReporter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Psr\Log\LogLevel;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->statefulApi();
        $middleware->alias(['company.context' => SetCompanyContext::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // nginx only forwards /api, /sanctum and /up to Laravel, so every request gets Problem Details.
        $exceptions->shouldRenderJsonWhen(fn () => true);

        // Domain errors are expected outcomes: they are answered, not reported. Only a Forbidden is, as a warning
        // with its code, because it may be someone probing; the rest (validation, not found, conflict) are not events.
        $exceptions->dontReportWhen(fn (Throwable $e) => $e instanceof DomainError && $e->kind() !== ErrorKind::Forbidden);
        $exceptions->level(DomainError::class, LogLevel::WARNING);
        $exceptions->context(fn (Throwable $e) => $e instanceof DomainError ? ['code' => $e->errorCode()] : []);

        // A failed query is logged without any value of it (see QueryExceptionReporter); stop() keeps the default
        // report from logging the original message too.
        $exceptions->report(fn (QueryException $e) => app(QueryExceptionReporter::class)->report($e))->stop();

        $exceptions->render(fn (Throwable $e, Request $request) => app(ProblemDetailsRenderer::class)->render($e, $request));
    })->create();
