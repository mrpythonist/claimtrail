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
 *
 * v1.1.3 adds a strict Content-Security-Policy (the API serves no
 * HTML, so nothing may load and it may not be framed) and HSTS,
 * sent when the request arrived over HTTPS or when it is enabled
 * in config/security.php (on by default in production).
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
        $response->headers->set('Content-Security-Policy', (string) config('security.csp'));

        if ($request->secure() || config('security.hsts.enabled')) {
            $hsts = 'max-age='.(int) config('security.hsts.max_age', 31536000);

            if (config('security.hsts.include_subdomains')) {
                $hsts .= '; includeSubDomains';
            }

            $response->headers->set('Strict-Transport-Security', $hsts);
        }

        return $response;
    }
}
