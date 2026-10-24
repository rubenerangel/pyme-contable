<?php

use App\Application\Tenancy\CompanyAccessDenied;
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
        $middleware->statefulApi();
        $middleware->alias(['company.context' => SetCompanyContext::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Same answer for a nonexistent company and for one the user is not a member of.
        $exceptions->render(fn (CompanyAccessDenied $e) => response()->json(
            ['message' => 'No tienes acceso a la empresa solicitada.'],
            403,
        ));
    })->create();
