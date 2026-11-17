<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SavedViewResource;
use App\Models\Organization;
use App\Models\SavedView;
use App\Models\User;
use App\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SavedViewController extends Controller
{
    /**
     * List the signed-in member's saved views in this organization,
     * optionally limited to one resource type.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $resourceType = $request->string('resource_type')->toString() ?: null;

        if ($resourceType !== null && ! in_array($resourceType, SavedView::resourceTypes(), true)) {
            return response()->json([
                'message' => 'The resource type must be shipment or claim.',
            ], 422);
        }

        if ($resourceType !== null) {
            $this->authorizeView($organization, $resourceType, $request->user());
        } elseif (! $request->user()->hasPermissionIn($organization, Permission::ViewShipments)
            && ! $request->user()->hasPermissionIn($organization, Permission::ViewClaims)) {
            abort(403);
        }

        $views = $organization->savedViews()
            ->where('user_id', $request->user()->id)
            ->when($resourceType !== null, fn ($query) => $query->where('resource_type', $resourceType))
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => SavedViewResource::collection($views),
        ]);
    }

    /**
     * Save the current list filters as a named view.
     *
     * The filter payload is a flat map of list query parameter names
     * to scalar values. Keys are limited to the parameters the list
     * endpoints actually accept, so a saved view can never smuggle
     * an unexpected filter in.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'resource_type' => ['required', 'string', Rule::in(SavedView::resourceTypes())],
            'filters' => ['present', 'array', 'max:20'],
        ]);

        $this->authorizeView($organization, $validated['resource_type'], $request->user());

        $filters = $this->cleanFilters($validated['resource_type'], $validated['filters']);

        if ($filters === null) {
            return response()->json([
                'message' => 'Filters must be a flat map of filter names to text values.',
            ], 422);
        }

        $existing = $organization->savedViews()
            ->where('user_id', $request->user()->id)
            ->where('resource_type', $validated['resource_type'])
            ->count();

        if ($existing >= SavedView::MAX_PER_USER_PER_RESOURCE) {
            return response()->json([
                'message' => 'You already have the maximum of '.SavedView::MAX_PER_USER_PER_RESOURCE.' saved views for this list.',
            ], 422);
        }

        $view = $organization->savedViews()->create([
            'user_id' => $request->user()->id,
            'resource_type' => $validated['resource_type'],
            'name' => $validated['name'],
            'filters' => $filters,
        ]);

        return response()->json([
            'data' => new SavedViewResource($view),
        ], 201);
    }

    /**
     * Rename a saved view or replace its filters.
     */
    public function update(Request $request, SavedView $savedView): JsonResponse
    {
        $organization = $this->organization($request);

        if ($savedView->organization_id !== $organization->getKey()
            || $savedView->user_id !== $request->user()->id) {
            abort(404);
        }

        $this->authorizeView($organization, $savedView->resource_type, $request->user());

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'filters' => ['sometimes', 'array', 'max:20'],
        ]);

        if (array_key_exists('filters', $validated)) {
            $filters = $this->cleanFilters($savedView->resource_type, $validated['filters']);

            if ($filters === null) {
                return response()->json([
                    'message' => 'Filters must be a flat map of filter names to text values.',
                ], 422);
            }

            $validated['filters'] = $filters;
        }

        $savedView->update($validated);

        return response()->json([
            'data' => new SavedViewResource($savedView->fresh()),
        ]);
    }

    /**
     * Delete a saved view.
     */
    public function destroy(Request $request, SavedView $savedView): JsonResponse
    {
        $organization = $this->organization($request);

        if ($savedView->organization_id !== $organization->getKey()
            || $savedView->user_id !== $request->user()->id) {
            abort(404);
        }

        $savedView->delete();

        return response()->json(['message' => 'Saved view deleted.']);
    }

    /**
     * Filter parameters the list endpoints accept, per resource.
     *
     * @return array<int, string>
     */
    protected function allowedFilterKeys(string $resourceType): array
    {
        return $resourceType === SavedView::RESOURCE_SHIPMENT
            ? [
                'search', 'status', 'carrier_id', 'date_from', 'date_to',
                'delivery_date_from', 'delivery_date_to', 'amount_min',
                'amount_max', 'event_type', 'broker', 'sort', 'direction',
            ]
            : [
                'search', 'status', 'claim_category', 'shipment_id',
                'amount_min', 'amount_max', 'deadline_from', 'deadline_to',
                'deadline_status', 'event_type', 'broker', 'carrier_id',
                'sort', 'direction',
            ];
    }

    /**
     * Keep only allowed keys with scalar values, dropping empties.
     * Returns null when a value is not a flat scalar.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string>|null
     */
    protected function cleanFilters(string $resourceType, array $filters): ?array
    {
        $allowed = $this->allowedFilterKeys($resourceType);
        $clean = [];

        foreach ($filters as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                continue;
            }

            if (! is_scalar($value) && $value !== null) {
                return null;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $clean[$key] = (string) $value;
        }

        return $clean;
    }

    /**
     * The view permission for the list this view belongs to.
     */
    protected function authorizeView(Organization $organization, string $resourceType, User $user): void
    {
        $permission = $resourceType === SavedView::RESOURCE_SHIPMENT
            ? Permission::ViewShipments
            : Permission::ViewClaims;

        Gate::authorize($permission->value, $organization);
    }

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
