<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PartnerLink;
use App\Services\AgencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The carrier side of the partner-link flow (v2.1.0).
 *
 * These routes are NOT tenant-scoped: the carrier user is
 * acting on a link that names their organization, not on an
 * organization selected by header. Approval and rejection need
 * an owner or admin of the CARRIER organization, so an agency
 * can never approve its own link.
 *
 * This mirrors the invitation-accept route: account-level auth
 * with the organization resolved from the record, not the
 * header.
 */
class PartnerLinkController extends Controller
{
    public function __construct(
        protected AgencyService $agency,
    ) {}

    /**
     * Pending links where the caller's organization is the
     * carrier: the inbox a carrier owner checks to approve or
     * reject partnership requests.
     */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();

        $organizationIds = $user->memberships()
            ->withoutGlobalScope('organization')
            ->pluck('organization_id')
            ->all();

        $links = PartnerLink::query()
            ->whereIn('carrier_organization_id', $organizationIds)
            ->where('status', PartnerLink::STATUS_PENDING)
            ->with(['agencyOrganization'])
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $links
                ->map(fn (PartnerLink $link) => array_merge(
                    $this->agency->presentLink($link),
                    ['agency_organization_name' => $link->agencyOrganization?->name],
                ))
                ->all(),
        ]);
    }

    /**
     * Approve a pending link, from the carrier side.
     */
    public function approve(Request $request, PartnerLink $partnerLink): JsonResponse
    {
        $link = $this->agency->approveLink($partnerLink, $request->user());

        return response()->json([
            'data' => $this->agency->presentLink($link),
        ]);
    }

    /**
     * Reject a pending link, from the carrier side.
     */
    public function reject(Request $request, PartnerLink $partnerLink): JsonResponse
    {
        $link = $this->agency->rejectLink($partnerLink, $request->user());

        return response()->json([
            'data' => $this->agency->presentLink($link),
        ]);
    }
}
