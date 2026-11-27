<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DriverEvidenceLink;
use App\Models\Organization;
use App\Permission;
use App\Services\CommunicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Driver evidence submission links for one organization
 * (v1.3.3).
 *
 * A signed link that lets a driver submit evidence for
 * one shipment or claim without an account. Creating,
 * resending, and revoking need manage-communications
 * (owner, admin, operations manager, dispatcher). Any
 * member may list links and read one. The raw token is
 * returned once, at creation (and once more on resend,
 * when it rotates); only its hash is stored. A link is
 * scoped to one shipment or claim, expires, and is
 * single-use by default.
 */
class DriverEvidenceLinkController extends Controller
{
    public function __construct(
        protected CommunicationService $comms,
    ) {}

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * The organization's driver links, newest first.
     * Filters: shipment_id, claim_id, state.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate([
            'shipment_id' => ['nullable', 'uuid', Rule::exists('shipments', 'id')->where('organization_id', $organization->getKey())],
            'claim_id' => ['nullable', 'uuid', Rule::exists('claims', 'id')->where('organization_id', $organization->getKey())],
            'state' => ['nullable', 'string', Rule::in(['usable', 'used', 'expired', 'revoked'])],
        ]);

        $query = DriverEvidenceLink::forOrganization($organization)
            ->with(['shipment', 'claim'])
            ->orderByDesc('created_at');

        if (! empty($validated['shipment_id'])) {
            $query->where('shipment_id', $validated['shipment_id']);
        }

        if (! empty($validated['claim_id'])) {
            $query->where('claim_id', $validated['claim_id']);
        }

        $links = $query->get();

        if (! empty($validated['state'])) {
            $links = $links->filter(fn (DriverEvidenceLink $link) => $link->state() === $validated['state'])->values();
        }

        return response()->json([
            'data' => $links->map(fn (DriverEvidenceLink $link) => $this->comms->linkPayload($link))->all(),
        ]);
    }

    /**
     * Create a driver link. The raw token is returned once
     * in the response; the email, when a driver email is
     * given, carries the same token.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageCommunications->value, $organization);

        $validated = $request->validate([
            'shipment_id' => ['nullable', 'uuid', Rule::exists('shipments', 'id')->where('organization_id', $organization->getKey())],
            'claim_id' => ['nullable', 'uuid', Rule::exists('claims', 'id')->where('organization_id', $organization->getKey())],
            'driver_name' => ['nullable', 'string', 'max:255'],
            'driver_email' => ['nullable', 'string', 'email', 'max:255'],
            'ttl_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $result = $this->comms->createDriverLink(
            $organization,
            $request->user(),
            $validated['shipment_id'] ?? null,
            $validated['claim_id'] ?? null,
            $validated['driver_name'] ?? null,
            $validated['driver_email'] ?? null,
            $validated['ttl_hours'] ?? null,
            $validated['max_uses'] ?? null,
        );

        return response()->json([
            'data' => $this->comms->linkPayload($result['link'], $result['token']),
        ], 201);
    }

    /**
     * One driver link.
     */
    public function show(Request $request, DriverEvidenceLink $link): JsonResponse
    {
        $organization = $this->organization($request);

        if ($link->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->comms->linkPayload($link->load(['shipment', 'claim'])),
        ]);
    }

    /**
     * Revoke a driver link. Revocation is immediate and
     * permanent; the URL stops working at once.
     */
    public function destroy(Request $request, DriverEvidenceLink $link): JsonResponse
    {
        $organization = $this->organization($request);

        if ($link->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageCommunications->value, $organization);

        $link = $this->comms->revokeDriverLink($link, $request->user());

        return response()->json([
            'data' => $this->comms->linkPayload($link),
        ]);
    }

    /**
     * Resend a driver link email. The token rotates: a new
     * raw token is issued and the old URL stops working,
     * because the old token was never stored.
     */
    public function resend(Request $request, DriverEvidenceLink $link): JsonResponse
    {
        $organization = $this->organization($request);

        if ($link->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageCommunications->value, $organization);

        $result = $this->comms->resendDriverLinkEmail($link, $request->user());

        return response()->json([
            'data' => $this->comms->linkPayload($result['link'], $result['token']),
        ]);
    }

    /**
     * The submissions made through one driver link.
     */
    public function submissions(Request $request, DriverEvidenceLink $link): JsonResponse
    {
        $organization = $this->organization($request);

        if ($link->organization_id !== $organization->getKey()) {
            abort(404);
        }

        $submissions = $link->submissions()->orderByDesc('created_at')->get();

        return response()->json([
            'data' => $submissions->map(fn ($submission) => $this->comms->submissionPayload($submission))->all(),
        ]);
    }
}
