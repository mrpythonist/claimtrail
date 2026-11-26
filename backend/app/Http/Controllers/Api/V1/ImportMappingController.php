<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ImportMapping;
use App\Models\Organization;
use App\Permission;
use App\Services\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Saved CSV mappings for one organization (v1.3.1).
 *
 * A mapping is exact header text to shipment field name, the
 * same shape the import preview accepts as an override. Any
 * member may list and read mappings; creating, updating, and
 * deleting need manage-imports (owner, admin, operations
 * manager, dispatcher). Names are unique per organization.
 * One mapping per target may be the default.
 */
class ImportMappingController extends Controller
{
    public function __construct(
        protected ImportService $imports,
    ) {}

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * The organization's saved mappings, defaults first.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $mappings = ImportMapping::forOrganization($organization)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $mappings->map(fn (ImportMapping $mapping) => $this->imports->mappingPayload($mapping))->all(),
        ]);
    }

    /**
     * Save a mapping.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageImports->value, $organization);

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('import_mappings', 'name')->where('organization_id', $organization->getKey()),
            ],
            'target' => ['sometimes', 'string', Rule::in([ImportMapping::TARGET_SHIPMENTS])],
            'mapping' => ['required', 'array', 'min:1'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $mapping = $this->imports->createMapping(
            $organization,
            $request->user(),
            $validated['name'],
            $validated['target'] ?? ImportMapping::TARGET_SHIPMENTS,
            $validated['mapping'],
            $validated['is_default'] ?? false,
        );

        return response()->json([
            'data' => $this->imports->mappingPayload($mapping),
        ], 201);
    }

    /**
     * One saved mapping.
     */
    public function show(Request $request, ImportMapping $mapping): JsonResponse
    {
        $organization = $this->organization($request);

        if ($mapping->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->imports->mappingPayload($mapping),
        ]);
    }

    /**
     * Update a saved mapping.
     */
    public function update(Request $request, ImportMapping $mapping): JsonResponse
    {
        $organization = $this->organization($request);

        if ($mapping->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageImports->value, $organization);

        $validated = $request->validate([
            'name' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('import_mappings', 'name')
                    ->where('organization_id', $organization->getKey())
                    ->ignore($mapping->getKey()),
            ],
            'target' => ['sometimes', 'string', Rule::in([ImportMapping::TARGET_SHIPMENTS])],
            'mapping' => ['sometimes', 'array', 'min:1'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['mapping'])) {
            $validated['mapping'] = ImportService::validatedMapping($validated['mapping']);
        }

        $mapping = $this->imports->updateMapping($mapping, $request->user(), $validated);

        return response()->json([
            'data' => $this->imports->mappingPayload($mapping),
        ]);
    }

    /**
     * Delete a saved mapping. Runs, schedules, and sync runs
     * keep working: their mapping link is nulled.
     */
    public function destroy(Request $request, ImportMapping $mapping): JsonResponse
    {
        $organization = $this->organization($request);

        if ($mapping->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageImports->value, $organization);

        $this->imports->deleteMapping($mapping, $request->user());

        return response()->json(['message' => 'Mapping deleted.']);
    }
}
