<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SecurityEventResource;
use App\Models\Organization;
use App\Permission;
use App\Services\SecurityCheckService;
use App\Services\SecurityEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Security status for one organization: the production-readiness
 * check results and the security event log.
 *
 * Both endpoints require manage-security (owner and admin). The
 * check itself is deployment-wide (APP_DEBUG, APP_KEY, and the
 * rest are app-level settings), but it is served through the
 * tenant-scoped group so the same authorization applies as the
 * rest of the security surface.
 */
class SecurityController extends Controller
{
    public function __construct(
        protected SecurityCheckService $checks,
        protected SecurityEventService $events,
    ) {}

    /**
     * Production-readiness checks. Same checks as the
     * `security:check` artisan command.
     */
    public function check(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageSecurity->value, $organization);

        return response()->json([
            'data' => $this->checks->run(),
        ]);
    }

    /**
     * Security events of the resolved organization (failed
     * logins with an organization, authorization denials),
     * newest first.
     */
    public function events(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageSecurity->value, $organization);

        $limit = max(1, min(100, (int) $request->input('limit', 50)));

        return response()->json([
            'data' => SecurityEventResource::collection(
                $this->events->recentForOrganization($organization->getKey(), $limit)
            ),
        ]);
    }

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
