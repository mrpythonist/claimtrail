<?php

namespace App\Http\Middleware;

use App\Support\Correlation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a correlation id to every API request.
 *
 * A client-supplied X-Correlation-Id header is honored when it is
 * a sane value (short, printable); otherwise a UUID is generated.
 * The id is stored for the request, echoed back on the response,
 * and picked up by AuditService so audit rows can be traced to the
 * request that caused them.
 */
class CorrelationId
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('X-Correlation-Id');
        $id = $this->usable($header) ? $header : (string) Str::uuid();

        app(Correlation::class)->set($id);
        $request->attributes->set('correlation_id', $id);

        $response = $next($request);
        $response->headers->set('X-Correlation-Id', $id);

        return $response;
    }

    /**
     * Whether a client-supplied id is safe to keep: short and made
     * of letters, digits, hyphens, and underscores.
     */
    protected function usable(?string $header): bool
    {
        return $header !== null
            && $header !== ''
            && strlen($header) <= 64
            && preg_match('/^[A-Za-z0-9\-_]+$/', $header) === 1;
    }
}
