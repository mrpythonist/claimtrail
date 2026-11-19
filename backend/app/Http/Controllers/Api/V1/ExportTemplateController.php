<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExportTemplateResource;
use App\Models\ExportTemplate;
use App\Models\Organization;
use App\Permission;
use App\Services\CustomFieldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ExportTemplateController extends Controller
{
    /**
     * List the organization's export templates, optionally filtered
     * by resource type.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        $resourceType = $request->query('resource_type');

        if ($resourceType !== null && ! in_array($resourceType, ExportTemplate::RESOURCE_TYPES, true)) {
            return response()->json([
                'message' => 'The resource type must be shipment or claim.',
                'errors' => ['resource_type' => ['The resource type must be shipment or claim.']],
            ], 422);
        }

        $templates = $organization->exportTemplates()
            ->when($resourceType !== null, fn ($query) => $query->where('resource_type', $resourceType))
            ->orderBy('resource_type')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->paginate($this->perPage($request, 50));

        return response()->json([
            'data' => ExportTemplateResource::collection($templates),
            'meta' => $this->paginationMeta($templates),
        ]);
    }

    /**
     * List the columns available for export templates of a resource
     * type: the fixed columns plus one `custom:<field_key>` entry per
     * active custom field.
     */
    public function availableColumns(Request $request, CustomFieldService $customFields): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        $resourceType = $request->query('resource_type');

        if (! in_array($resourceType, ExportTemplate::RESOURCE_TYPES, true)) {
            return response()->json([
                'message' => 'The resource type is required.',
                'errors' => ['resource_type' => ['The resource type must be shipment or claim.']],
            ], 422);
        }

        return response()->json([
            'data' => $customFields->availableExportColumns($resourceType, $organization),
        ]);
    }

    /**
     * Create an export template. When it is created as the default,
     * every other default for the same organization and resource
     * type is cleared.
     */
    public function store(Request $request, CustomFieldService $customFields): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        $validated = $request->validate($this->rules($organization, $customFields));

        $template = DB::transaction(function () use ($organization, $validated, $customFields) {
            if ($validated['is_default'] ?? false) {
                $customFields->clearOtherDefaults($organization, $validated['resource_type']);
            }

            return $organization->exportTemplates()->create([
                'resource_type' => $validated['resource_type'],
                'name' => $validated['name'],
                'columns' => array_values($validated['columns']),
                'is_default' => $validated['is_default'] ?? false,
            ]);
        });

        return response()->json([
            'data' => new ExportTemplateResource($template),
        ], 201);
    }

    /**
     * Show one export template.
     */
    public function show(Request $request, ExportTemplate $template): JsonResponse
    {
        $organization = $this->organization($request);

        if ($template->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        return response()->json([
            'data' => new ExportTemplateResource($template),
        ]);
    }

    /**
     * Update an export template. Setting it as the default clears
     * every other default for the same organization and resource
     * type.
     */
    public function update(Request $request, ExportTemplate $template, CustomFieldService $customFields): JsonResponse
    {
        $organization = $this->organization($request);

        if ($template->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        $validated = $request->validate($this->updateRules($organization, $template, $customFields));

        DB::transaction(function () use ($template, $validated, $organization, $customFields) {
            if ($validated['is_default'] ?? false) {
                $customFields->clearOtherDefaults(
                    $organization,
                    $template->resource_type,
                    $template->getKey(),
                );
            }

            $template->update([
                'name' => $validated['name'] ?? $template->name,
                'columns' => array_key_exists('columns', $validated)
                    ? array_values($validated['columns'])
                    : $template->columns,
                'is_default' => $validated['is_default'] ?? $template->is_default,
            ]);
        });

        return response()->json([
            'data' => new ExportTemplateResource($template->fresh()),
        ]);
    }

    /**
     * Delete an export template.
     */
    public function destroy(Request $request, ExportTemplate $template): JsonResponse
    {
        $organization = $this->organization($request);

        if ($template->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        $template->delete();

        return response()->json(['message' => 'Export template deleted.']);
    }

    /**
     * Make a template the default for its organization and resource
     * type, clearing every other default.
     */
    public function setDefault(Request $request, ExportTemplate $template, CustomFieldService $customFields): JsonResponse
    {
        $organization = $this->organization($request);

        if ($template->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        DB::transaction(function () use ($template, $organization, $customFields) {
            $customFields->clearOtherDefaults(
                $organization,
                $template->resource_type,
                $template->getKey(),
            );

            $template->update(['is_default' => true]);
        });

        return response()->json([
            'data' => new ExportTemplateResource($template->fresh()),
        ]);
    }

    /**
     * Validation rules for creating a template.
     *
     * @return array<string, mixed>
     */
    protected function rules(
        Organization $organization,
        CustomFieldService $customFields,
        ?ExportTemplate $template = null,
    ): array {
        $sometimes = $template !== null ? ['sometimes'] : [];
        $required = $template !== null ? [] : ['required'];

        return [
            'resource_type' => array_merge($required, $sometimes, [
                'string', Rule::in(ExportTemplate::RESOURCE_TYPES),
            ]),
            'name' => array_merge($required, $sometimes, [
                'string', 'max:255',
                Rule::unique('export_templates', 'name')
                    ->where('organization_id', $organization->getKey())
                    ->where('resource_type', request()->input('resource_type', $template?->resource_type))
                    ->when($template !== null, fn ($rule) => $rule->ignore($template->getKey())),
            ]),
            'columns' => array_merge($required, $sometimes, [
                'array', 'min:1', 'max:30',
            ]),
            'columns.*' => [
                'string',
                function ($attribute, $value, $fail) use ($organization, $customFields, $template) {
                    $resourceType = request()->input('resource_type', $template?->resource_type);

                    if (! in_array($resourceType, ExportTemplate::RESOURCE_TYPES, true)) {
                        return;
                    }

                    $allowed = array_column(
                        $customFields->availableExportColumns($resourceType, $organization),
                        'key',
                    );

                    if (! in_array($value, $allowed, true)) {
                        $fail('The selected column is not available for this resource type.');
                    }
                },
            ],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Validation rules for updating a template.
     *
     * @return array<string, mixed>
     */
    protected function updateRules(
        Organization $organization,
        ExportTemplate $template,
        CustomFieldService $customFields,
    ): array {
        $rules = $this->rules($organization, $customFields, $template);

        // The resource type never changes on update.
        unset($rules['resource_type']);

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
