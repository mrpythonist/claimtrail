<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CarrierResource;
use App\Models\Carrier;
use App\Models\Organization;
use App\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CarrierController extends Controller
{
    /**
     * List carriers of the resolved organization, with search.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewShipments->value, $organization);

        $carriers = $organization->carriers()
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', $search)
                        ->orWhere('dot_number', 'like', $search);
                });
            })
            ->orderBy('name')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => CarrierResource::collection($carriers),
            'meta' => $this->paginationMeta($carriers),
        ]);
    }

    /**
     * Create a carrier in the resolved organization.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::CreateShipments->value, $organization);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:255'],
            'dot_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $carrier = $organization->carriers()->create($validated);

        return response()->json([
            'data' => new CarrierResource($carrier),
        ], 201);
    }

    /**
     * Update a carrier. Carriers have no delete endpoint in this
     * milestone: removing a carrier would orphan its shipments, and
     * the replacement flow is a later decision.
     */
    public function update(Request $request, Carrier $carrier): JsonResponse
    {
        $organization = $this->organization($request);

        if ($carrier->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('update', $carrier);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:255'],
            'dot_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $carrier->update($validated);

        return response()->json([
            'data' => new CarrierResource($carrier->fresh()),
        ]);
    }

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * Page size from the request, clamped to the allowed range.
     */
    protected function perPage(Request $request, int $default): int
    {
        return max(1, min(100, (int) $request->input('per_page', $default)));
    }

    /**
     * Pagination metadata in the standard list shape.
     */
    protected function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
