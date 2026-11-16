<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Responses\ApiException;
use App\Http\Responses\ApiExceptionRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Every request gets a correlation id before anything else runs (B06).
        $middleware->prepend(AssignRequestId::class);

        $middleware->append(SecurityHeaders::class);
        $middleware->throttleApi('api');

        $middleware->alias([
            'permission' => EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // All /api responses use one error envelope (A04).
        // Expected business refusals (suspended account, own-status change...) are not server errors.
        $exceptions->dontReport([ApiException::class]);

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/*')
            ? ApiExceptionRenderer::render($e, $request)
            : null);
    })
    ->create();
