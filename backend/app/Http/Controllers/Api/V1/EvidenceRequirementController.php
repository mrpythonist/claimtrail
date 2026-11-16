<?php

namespace App\Http\Controllers\Api\V1;

use App\EvidenceDocumentCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\EvidenceRequirementResource;
use App\Models\EvidenceRequirement;
use App\Models\Organization;
use App\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EvidenceRequirementController extends Controller
{
    /**
     * List the organization's evidence requirements.
     *
     * Global requirements (no event category) are listed first, then
     * category-specific ones.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewClaims->value, $organization);

        $requirements = $organization->evidenceRequirements()
            ->with('eventCategory')
            ->orderByRaw('event_category_id IS NOT NULL')
            ->orderBy('document_category')
            ->paginate($this->perPage($request, 50));

        return response()->json([
            'data' => EvidenceRequirementResource::collection($requirements),
            'meta' => $this->paginationMeta($requirements),
        ]);
    }

    /**
     * Create an evidence requirement.
     *
     * The uniqueness check runs in application code because the
     * database unique index treats null event categories as distinct
     * values: two global requirements for the same document category
     * would otherwise slip through.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageEvidenceRequirements->value, $organization);

        $validated = $request->validate($this->rules($organization));

        if ($this->duplicateExists($organization, $validated)) {
            return response()->json([
                'message' => 'A requirement for this document category already exists for this event type.',
            ], 422);
        }

        $requirement = $organization->evidenceRequirements()->create(array_merge($validated, [
            'created_by' => $request->user()->id,
        ]));

        return response()->json([
            'data' => new EvidenceRequirementResource($requirement->load('eventCategory')),
        ], 201);
    }

    /**
     * Update an evidence requirement.
     */
    public function update(Request $request, EvidenceRequirement $requirement): JsonResponse
    {
        $organization = $this->organization($request);

        if ($requirement->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('update', $requirement);

        $validated = $request->validate($this->rules($organization, $requirement));

        $merged = array_merge(
            [
                'event_category_id' => $requirement->event_category_id,
                'document_category' => $requirement->document_category->value,
            ],
            $validated
        );

        if ($this->duplicateExists($organization, $merged, $requirement->getKey())) {
            return response()->json([
                'message' => 'A requirement for this document category already exists for this event type.',
            ], 422);
        }

        $requirement->update($validated);

        return response()->json([
            'data' => new EvidenceRequirementResource($requirement->fresh()->load('eventCategory')),
        ]);
    }

    /**
     * Delete an evidence requirement.
     */
    public function destroy(Request $request, EvidenceRequirement $requirement): JsonResponse
    {
        $organization = $this->organization($request);

        if ($requirement->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('delete', $requirement);

        $requirement->delete();

        return response()->json(['message' => 'Evidence requirement deleted.']);
    }

    /**
     * Validation rules for requirement input.
     *
     * The category must be visible to the organization: a system
     * category or one of its own custom categories.
     *
     * @return array<string, mixed>
     */
    protected function rules(Organization $organization, ?EvidenceRequirement $requirement = null): array
    {
        $sometimes = $requirement !== null ? ['sometimes'] : [];

        return [
            'event_category_id' => array_merge($sometimes, [
                'nullable', 'uuid',
                Rule::exists('event_categories', 'id')->where(function ($query) use ($organization) {
                    $query->whereNull('organization_id')
                        ->orWhere('organization_id', $organization->getKey());
                }),
            ]),
            'document_category' => array_merge($sometimes, [
                'string', Rule::in(EvidenceDocumentCategory::values()),
            ]),
            'is_required' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Whether a requirement with the same category scope and document
     * category already exists, ignoring one row on update.
     */
    protected function duplicateExists(Organization $organization, array $values, ?string $exceptId = null): bool
    {
        return $organization->evidenceRequirements()
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->where('document_category', $values['document_category'])
            ->where(function ($query) use ($values) {
                if (($values['event_category_id'] ?? null) === null) {
                    $query->whereNull('event_category_id');
                } else {
                    $query->where('event_category_id', $values['event_category_id']);
                }
            })
            ->exists();
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
