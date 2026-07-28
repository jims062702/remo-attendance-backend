<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA (cookie) authentication: requests from a configured
        // stateful domain are given the session + CSRF stack, so the token
        // never has to be readable by JavaScript.
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->append(HandleCors::class);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'active' => EnsureAccountIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API is JSON-only: never redirect to a login page, never render
        // an HTML error view. Domain exceptions render themselves (see
        // App\Exceptions\DomainException) and are left untouched here.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'code' => 'auth.unauthenticated',
                ], 401);
            }

            return null;
        });

        // In production, replace unexpected 5xx detail with a generic message
        // so stack traces and query fragments never reach a client.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            if (config('app.debug') || $e instanceof HttpExceptionInterface) {
                return null;
            }

            report($e);

            return response()->json([
                'message' => 'An unexpected error occurred. Please try again.',
                'code' => 'server.error',
            ], 500);
        });
    })->create();
