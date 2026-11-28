<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\CorrelationId;
use App\Http\Middleware\ResolveOrganization;
use App\Http\Middleware\ResponseTime;
use App\Http\Middleware\SecurityHeaders;
use App\Services\SecurityEventService;
use App\Support\ErrorReporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'organization' => ResolveOrganization::class,
            'correlation' => CorrelationId::class,
            'api-key' => AuthenticateApiKey::class,
        ]);

        $middleware->append(SecurityHeaders::class);
        $middleware->append(ResponseTime::class);

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

        // Structured error monitoring (v1.1.1): server errors are
        // written to the errors channel with route, user, org, and
        // correlation id. 4xx responses are normal traffic and are
        // not recorded. Reporting never throws.
        $exceptions->report(function (Throwable $exception) {
            ErrorReporter::report($exception, request());
        });

        // Authorization denials are security events (v1.1.3):
        // recorded with the route and the organization when one
        // was resolved. A render callback is used, not a report
        // callback: AuthorizationException is in Laravel's
        // internal do-not-report list, so report callbacks never
        // fire for it. The union type matters: prepareException
        // converts AuthorizationException into an
        // AccessDeniedHttpException before render callbacks run,
        // so matching only the first type would never fire.
        // Returning null lets the default 403 rendering continue.
        // Recording never throws, so it cannot break the error
        // response.
        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $exception, Request $request) {
            try {
                app(SecurityEventService::class)
                    ->recordAuthorizationDenied($request);
            } catch (Throwable) {
                // Reporting must never break the response.
            }

            return null;
        });
    })->create();
