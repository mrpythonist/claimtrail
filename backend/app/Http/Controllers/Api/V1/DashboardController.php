<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organization overview for the dashboard.
 *
 * Only reports what exists: members, invitations, shipments, events,
 * evidence, and (from Milestone 3) claims and deadlines. Financial
 * figures arrive with the reporting module in Milestone 4 and must
 * never be invented here.
 */
class DashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('organization');
        $user = $request->user();
        $membership = $user->membershipIn($organization);

        return response()->json([
            'data' => [
                'organization' => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                    'timezone' => $organization->timezone,
                    'created_at' => $organization->created_at->toISOString(),
                ],
                'current_user_role' => $membership?->role->value,
                'member_since' => $membership?->joined_at?->toISOString(),
                'counts' => [
                    'members_total' => $organization->members()->count(),
                    'members_active' => $organization->activeMembers()->count(),
                    'members_inactive' => $organization->members()->where('status', 'inactive')->count(),
                    'invitations_pending' => $organization->pendingInvitations()->count(),
                    'shipments_total' => $organization->shipments()->count(),
                    'shipments_with_events' => $organization->shipments()->whereHas('events')->count(),
                    'events_open' => $organization->shipmentEvents()->where('status', 'potential')->count(),
                    'events_total' => $organization->shipmentEvents()->count(),
                    'evidence_total' => $organization->evidenceFiles()->count(),
                    'claims_total' => $organization->claims()->count(),
                    'claims_open' => $organization->claims()->whereIn('status', [
                        'potential', 'evidence_required', 'under_review', 'ready_to_submit',
                    ])->count(),
                    'claims_ready' => $organization->claims()->where('status', 'ready_to_submit')->count(),
                    'claims_submitted' => $organization->claims()->whereIn('status', [
                        'submitted', 'accepted', 'partially_accepted',
                    ])->count(),
                    'claims_paid' => $organization->claims()->where('status', 'paid')->count(),
                    'deadlines_upcoming' => $organization->claimDeadlines()->active()->where('status', 'upcoming')->count(),
                    'deadlines_due_soon' => $organization->claimDeadlines()->active()->where('status', 'due_soon')->count(),
                    'deadlines_overdue' => $organization->claimDeadlines()->active()->where('status', 'overdue')->count(),
                ],
            ],
        ]);
    }
}
