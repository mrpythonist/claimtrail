<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline response security headers for every response.
 *
 * The API returns JSON, not pages, but the headers still matter:
 * nosniff stops browsers from guessing a content type for a
 * response, DENY keeps the app out of frames entirely, and the
 * permissions policy disables browser features this API never uses.
 * No Content-Security-Policy is set here: the API serves no HTML
 * of its own (the only HTML page is the default Laravel welcome
 * view), and CSP belongs on the Next.js frontend instead.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=()'
        );

        return $response;
    }
}
