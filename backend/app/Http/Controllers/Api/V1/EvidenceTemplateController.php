<?php

namespace App\Http\Controllers\Api\V1;

use App\EvidenceDocumentCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\EvidenceTemplateResource;
use App\Models\Claim;
use App\Models\ClaimRequirement;
use App\Models\EvidenceTemplate;
use App\Models\Organization;
use App\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EvidenceTemplateController extends Controller
{
    /**
     * List the organization's evidence templates, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        $templates = $organization->evidenceTemplates()
            ->with('items')
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 50));

        return response()->json([
            'data' => EvidenceTemplateResource::collection($templates),
            'meta' => $this->paginationMeta($templates),
        ]);
    }

    /**
     * Create an evidence template with its items.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        $validated = $request->validate($this->rules($organization));

        $template = DB::transaction(function () use ($organization, $validated) {
            $template = $organization->evidenceTemplates()->create([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $this->syncItems($template, $validated['items']);

            return $template;
        });

        return response()->json([
            'data' => new EvidenceTemplateResource($template->load('items')),
        ], 201);
    }

    /**
     * Show one evidence template.
     */
    public function show(Request $request, EvidenceTemplate $template): JsonResponse
    {
        $organization = $this->organization($request);

        if ($template->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        return response()->json([
            'data' => new EvidenceTemplateResource($template->load('items')),
        ]);
    }

    /**
     * Update an evidence template. When items are given, they replace
     * the existing items wholesale.
     */
    public function update(Request $request, EvidenceTemplate $template): JsonResponse
    {
        $organization = $this->organization($request);

        if ($template->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        $validated = $request->validate($this->updateRules($organization, $template));

        DB::transaction(function () use ($template, $validated) {
            $template->update([
                'name' => $validated['name'] ?? $template->name,
                'description' => $validated['description'] ?? $template->description,
                'is_active' => $validated['is_active'] ?? $template->is_active,
            ]);

            if (array_key_exists('items', $validated)) {
                $this->syncItems($template, $validated['items']);
            }
        });

        return response()->json([
            'data' => new EvidenceTemplateResource($template->fresh()->load('items')),
        ]);
    }

    /**
     * Delete an evidence template and its items.
     */
    public function destroy(Request $request, EvidenceTemplate $template): JsonResponse
    {
        $organization = $this->organization($request);

        if ($template->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        $template->delete();

        return response()->json(['message' => 'Evidence template deleted.']);
    }

    /**
     * Apply a template to a claim.
     *
     * For each distinct document category in the template that the
     * claim does not already have a requirement row for, a
     * ClaimRequirement row is created (unfulfilled, snapshotted
     * now). Applying the same template twice creates nothing the
     * second time.
     */
    public function applyToClaim(Request $request, EvidenceTemplate $template, Claim $claim): JsonResponse
    {
        $organization = $this->organization($request);

        if ($template->organization_id !== $organization->getKey()
            || $claim->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        $existing = $claim->requirementSnapshots()
            ->pluck('document_category')
            ->map(fn ($category) => $category instanceof EvidenceDocumentCategory
                ? $category->value
                : (string) $category)
            ->all();

        $created = 0;
        $skipped = 0;
        $now = now();

        $seen = [];

        foreach ($template->items()->orderBy('sort_order')->get() as $item) {
            $category = $item->document_category->value;

            if (isset($seen[$category])) {
                $skipped++;
                continue;
            }

            $seen[$category] = true;

            if (in_array($category, $existing, true)) {
                $skipped++;
                continue;
            }

            ClaimRequirement::create([
                'organization_id' => $organization->getKey(),
                'claim_id' => $claim->getKey(),
                'document_category' => $category,
                'is_required' => $item->is_required,
                'is_fulfilled' => false,
                'snapshot_at' => $now,
            ]);

            $existing[] = $category;
            $created++;
        }

        return response()->json([
            'data' => [
                'created' => $created,
                'skipped' => $skipped,
                'template_id' => (string) $template->getKey(),
                'claim_id' => (string) $claim->getKey(),
            ],
        ]);
    }

    /**
     * Replace a template's items with the given list.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function syncItems(EvidenceTemplate $template, array $items): void
    {
        $template->items()->delete();

        foreach ($items as $index => $item) {
            $template->items()->create([
                'document_category' => $item['document_category'],
                'label' => $item['label'],
                'is_required' => $item['is_required'] ?? true,
                'sort_order' => $item['sort_order'] ?? $index,
            ]);
        }
    }

    /**
     * Validation rules for creating a template.
     *
     * @return array<string, mixed>
     */
    protected function rules(Organization $organization, ?EvidenceTemplate $template = null): array
    {
        $sometimes = $template !== null ? ['sometimes'] : [];

        $nameRules = $template !== null
            ? array_merge(['sometimes'], [
                'string', 'max:255',
                Rule::unique('evidence_templates', 'name')
                    ->where('organization_id', $organization->getKey())
                    ->ignore($template->getKey()),
            ])
            : [
                'required', 'string', 'max:255',
                Rule::unique('evidence_templates', 'name')
                    ->where('organization_id', $organization->getKey()),
            ];

        return [
            'name' => $nameRules,
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'items' => array_merge($template !== null ? ['sometimes'] : ['required'], ['array', 'min:1', 'max:20']),
            'items.*.document_category' => ['required', 'string', Rule::in(EvidenceDocumentCategory::values())],
            'items.*.label' => ['required', 'string', 'max:255'],
            'items.*.is_required' => ['sometimes', 'boolean'],
            'items.*.sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * Validation rules for updating a template.
     *
     * @return array<string, mixed>
     */
    protected function updateRules(Organization $organization, EvidenceTemplate $template): array
    {
        $rules = $this->rules($organization, $template);

        $rules['items'] = ['sometimes', 'array', 'min:1', 'max:20'];

        return $rules;
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
