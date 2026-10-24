<?php

use App\Domain\DomainError;
use App\Http\Errors\ProblemDetailsRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\SetCompanyContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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

        // Domain errors are expected outcomes: they are answered, not reported.
        $exceptions->dontReport(DomainError::class);

        $exceptions->render(fn (Throwable $e, Request $request) => app(ProblemDetailsRenderer::class)->render($e, $request));
    })->create();
