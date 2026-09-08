<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Let the same-origin SPA authenticate against /api/* with the
        // session cookie instead of a bearer token.
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // The throttle middleware raises this with an untranslated "Too many
        // requests.", which would be the one English sentence left in an
        // otherwise French interface. Rendered here rather than per route so
        // every throttled endpoint answers the same way, and so the wait the
        // middleware already computed is passed on instead of guessed at.
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $seconds = (int) ($exception->getHeaders()['Retry-After'] ?? 60);

            return response()->json([
                'message' => $request->routeIs('login')
                    ? __('auth.throttle', ['seconds' => $seconds])
                    : __('http.throttle', ['seconds' => $seconds]),
            ], 429, $exception->getHeaders());
        });
    })->create();
