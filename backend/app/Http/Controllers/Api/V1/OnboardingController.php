<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Permission;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OnboardingController extends Controller
{
    public function __construct(
        protected OnboardingService $onboarding,
    ) {}

    /**
     * Onboarding progress for the resolved organization.
     *
     * Any active member can read it: the checklist is the shared
     * first-steps view for the whole team. The organization
     * middleware already guarantees membership.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->onboarding->progress($this->organization($request)),
        ]);
    }

    /**
     * Dismiss the onboarding checklist for the whole organization.
     *
     * Dismissal is organization-wide, so only someone who manages
     * the organization can do it. Dismissing hides the panel; the
     * steps themselves stay derivable and the endpoint keeps
     * reporting them.
     */
    public function dismiss(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageOrganization->value, $organization);

        $organization->onboarding_dismissed_at = now();
        $organization->save();

        return response()->json([
            'data' => ['dismissed' => true],
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
