<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Writes structured error entries for unhandled server errors.
 *
 * Only 5xx failures are recorded: validation errors, 404s, and
 * 403s are normal API traffic, not errors to monitor. Each entry
 * carries the exception class and message, the route and method,
 * the user and organization when the request had them, and the
 * correlation id, so an entry can be traced to the request and
 * audit rows around it. Reporting never throws: a broken error
 * log must not turn one failure into two.
 */
class ErrorReporter
{
    /**
     * Report an exception if it is a server error.
     */
    public static function report(Throwable $exception, ?Request $request = null): void
    {
        if (self::statusOf($exception) < 500) {
            return;
        }

        try {
            Log::channel('errors')->error($exception->getMessage(), [
                'exception' => $exception::class,
                'file' => $exception->getFile().':'.$exception->getLine(),
                'route' => $request?->path(),
                'method' => $request?->method(),
                'user_id' => $request?->user()?->getKey(),
                'organization_id' => $request?->attributes->get('organization')?->getKey(),
                'correlation_id' => app(Correlation::class)->get(),
            ]);
        } catch (Throwable) {
            // Error reporting must never break the response.
        }
    }

    /**
     * HTTP status the exception maps to: 500 unless it is an
     * HttpException with its own status.
     */
    protected static function statusOf(Throwable $exception): int
    {
        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getStatusCode();
        }

        return 500;
    }
}
