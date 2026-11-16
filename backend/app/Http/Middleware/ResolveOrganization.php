<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current organization for tenant-scoped API routes.
 *
 * The client sends the organization id in the X-Organization-Id header.
 * The middleware verifies the organization exists and the authenticated
 * user is an active member of it, then stores it for the request. A
 * missing header, an unknown organization, or a non-member all fail
 * closed. Unknown organizations return 404 (not 403) so ids cannot be
 * probed; non-members of a real organization also get 404 for the same
 * reason.
 */
class ResolveOrganization
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $request->header('X-Organization-Id');

        if (empty($organizationId)) {
            return response()->json([
                'message' => 'An organization must be selected.',
            ], 400);
        }

        $organization = Organization::whereKey($organizationId)->first();

        if ($organization === null) {
            return response()->json(['message' => 'Organization not found.'], 404);
        }

        $user = $request->user();

        if ($user === null || ! $user->isActiveMemberOf($organization)) {
            return response()->json(['message' => 'Organization not found.'], 404);
        }

        app(CurrentOrganization::class)->set($organization);
        $request->attributes->set('organization', $organization);

        return $next($request);
    }
}
