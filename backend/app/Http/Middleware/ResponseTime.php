<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds an X-Response-Time header (milliseconds) to every response.
 *
 * Clients and the operations page use it to spot slow endpoints;
 * it costs one microtime call per request. The timer starts when
 * the request enters the middleware, so it measures the app's
 * own handling, not time spent in the web server.
 */
class ResponseTime
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);

        $response = $next($request);

        $response->headers->set(
            'X-Response-Time',
            number_format((microtime(true) - $started) * 1000, 2).'ms'
        );

        return $response;
    }
}
