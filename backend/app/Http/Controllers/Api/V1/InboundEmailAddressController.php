<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InboundEmailAddress;
use App\Models\Organization;
use App\Permission;
use App\Services\EmailIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Organization-specific inbound email addresses (v1.3.0).
 *
 * Any member may list the organization's addresses (they need
 * to know where to forward mail). Creating, regenerating, and
 * deactivating need manage-email-intake (owner, admin,
 * operations manager, dispatcher). Listing also provisions the
 * default address for organizations created before v1.3.0.
 */
class InboundEmailAddressController extends Controller
{
    public function __construct(
        protected EmailIntakeService $intake,
    ) {}

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * The organization's addresses, active first. Ensures the
     * default address exists, so older organizations get one
     * on first list.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $this->intake->ensureDefaultAddress($organization);

        $addresses = InboundEmailAddress::forOrganization($organization)
            ->orderByDesc('active')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $addresses->map(fn (InboundEmailAddress $address) => $this->intake->addressPayload($address))->all(),
        ]);
    }

    /**
     * Create an additional address for the organization.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageEmailIntake->value, $organization);

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $address = $this->intake->createAddress($organization, $validated['label'] ?? null);

        return response()->json([
            'data' => $this->intake->addressPayload($address),
        ], 201);
    }

    /**
     * Replace an address's local part with a fresh random one.
     * The old address stops working immediately.
     */
    public function regenerate(Request $request, InboundEmailAddress $address): JsonResponse
    {
        $organization = $this->organization($request);

        if ($address->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageEmailIntake->value, $organization);

        return response()->json([
            'data' => $this->intake->addressPayload($this->intake->regenerate($address)),
        ]);
    }

    /**
     * Deactivate an address. The last active address cannot be
     * deactivated.
     */
    public function deactivate(Request $request, InboundEmailAddress $address): JsonResponse
    {
        $organization = $this->organization($request);

        if ($address->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageEmailIntake->value, $organization);

        return response()->json([
            'data' => $this->intake->addressPayload($this->intake->deactivate($address)),
        ]);
    }
}
