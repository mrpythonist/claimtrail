<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'organization' => \App\Http\Middleware\ResolveOrganization::class,
            'correlation' => \App\Http\Middleware\CorrelationId::class,
        ]);

        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Laravel defaults guest redirects to route('login'), which
        // this API-only app does not define; the redirect lookup
        // itself would throw a 500 before the 401 is ever rendered.
        // Guests are never redirected: the exception handler below
        // answers API requests with JSON 401.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // This is an API-only app: there is no login route, so the
        // default unauthenticated handling (redirect to route('login'))
        // would throw a 500 for API requests that do not ask for JSON.
        // Answer every unauthenticated API request with JSON 401.
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();
