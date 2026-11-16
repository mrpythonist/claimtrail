<?php

namespace App\Http\Controllers\Api\V1;

use App\ClaimStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Organization overview for the dashboard.
 *
 * Counts, money, and upcoming deadlines come from DashboardService.
 * Money figures are per currency and labeled estimated, submitted,
 * approved, or recovered; potential money is estimated money and is
 * never presented as recovered. Only real data is reported: an
 * empty organization shows zeros and empty lists, not placeholders.
 */
class DashboardController extends Controller
{
    public function show(Request $request, DashboardService $dashboard): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');
        $user = $request->user();
        $membership = $user->membershipIn($organization);

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', 'string', Rule::in(ClaimStatus::values())],
        ]);

        $overview = $dashboard->overview(
            $organization,
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null,
            isset($validated['status']) ? ClaimStatus::from($validated['status']) : null,
        );

        return response()->json([
            'data' => array_merge([
                'organization' => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                    'timezone' => $organization->timezone,
                    'created_at' => $organization->created_at->toISOString(),
                ],
                'current_user_role' => $membership?->role->value,
                'member_since' => $membership?->joined_at?->toISOString(),
            ], $overview),
        ]);
    }
}
