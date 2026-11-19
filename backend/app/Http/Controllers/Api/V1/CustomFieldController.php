<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomFieldResource;
use App\Models\Claim;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\Shipment;
use App\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CustomFieldController extends Controller
{
    /**
     * List the organization's custom field definitions for one
     * resource type, in display order.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $resourceType = $this->resourceType($request);

        if ($resourceType === null) {
            return $this->resourceTypeError();
        }

        Gate::authorize($this->viewPermission($resourceType), $organization);

        $fields = $organization->customFields()
            ->where('resource_type', $resourceType)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();

        return response()->json([
            'data' => CustomFieldResource::collection($fields),
        ]);
    }

    /**
     * Create a custom field definition.
     *
     * The key is auto-slugged from the label when not given. At most
     * 20 active definitions are allowed per organization and resource
     * type. Select fields need 2 to 20 options.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        $validated = $request->validate($this->rules());

        $fieldKey = $validated['field_key'] ?? Str::slug($validated['label'], '_');

        if ($fieldKey === '') {
            return response()->json([
                'message' => 'The label must contain letters or numbers.',
            ], 422);
        }

        if ($this->keyExists($organization, $validated['resource_type'], $fieldKey)) {
            return response()->json([
                'message' => 'A custom field with this key already exists for this resource type.',
            ], 422);
        }

        $isActive = $validated['is_active'] ?? true;

        if ($isActive && $this->activeCount($organization, $validated['resource_type']) >= CustomField::MAX_ACTIVE_PER_RESOURCE) {
            return response()->json([
                'message' => 'An organization can have at most 20 active custom fields per resource type.',
            ], 422);
        }

        $field = $organization->customFields()->create([
            'resource_type' => $validated['resource_type'],
            'field_key' => $fieldKey,
            'label' => $validated['label'],
            'field_type' => $validated['field_type'],
            'is_required' => $validated['is_required'] ?? false,
            'options' => $validated['field_type'] === 'select' ? $validated['options'] : null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $isActive,
        ]);

        return response()->json([
            'data' => new CustomFieldResource($field),
        ], 201);
    }

    /**
     * Show one custom field definition.
     */
    public function show(Request $request, CustomField $field): JsonResponse
    {
        $organization = $this->organization($request);

        if ($field->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize($this->viewPermission($field->resource_type), $organization);

        return response()->json([
            'data' => new CustomFieldResource($field),
        ]);
    }

    /**
     * Update a custom field definition. The key is immutable: a
     * field_key in the request is ignored.
     */
    public function update(Request $request, CustomField $field): JsonResponse
    {
        $organization = $this->organization($request);

        if ($field->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        $validated = $request->validate($this->updateRules($field));

        if (($validated['is_active'] ?? $field->is_active) && ! $field->is_active) {
            if ($this->activeCount($organization, $field->resource_type) >= CustomField::MAX_ACTIVE_PER_RESOURCE) {
                return response()->json([
                    'message' => 'An organization can have at most 20 active custom fields per resource type.',
                ], 422);
            }
        }

        $field->update([
            'label' => $validated['label'] ?? $field->label,
            'is_required' => $validated['is_required'] ?? $field->is_required,
            'options' => array_key_exists('options', $validated)
                ? ($field->field_type === 'select' ? $validated['options'] : null)
                : $field->options,
            'sort_order' => $validated['sort_order'] ?? $field->sort_order,
            'is_active' => $validated['is_active'] ?? $field->is_active,
        ]);

        return response()->json([
            'data' => new CustomFieldResource($field->fresh()),
        ]);
    }

    /**
     * Delete a custom field definition and strip its key from every
     * shipment and claim value map in the organization.
     */
    public function destroy(Request $request, CustomField $field): JsonResponse
    {
        $organization = $this->organization($request);

        if ($field->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        $key = $field->field_key;

        foreach (['shipment' => Shipment::class, 'claim' => Claim::class] as $resourceType => $model) {
            if ($field->resource_type !== $resourceType) {
                continue;
            }

            $model::forOrganization($organization->getKey())
                ->whereNotNull('custom_field_values')
                ->cursor()
                ->each(function ($record) use ($key) {
                    $values = $record->custom_field_values ?? [];

                    if (! array_key_exists($key, $values)) {
                        return;
                    }

                    unset($values[$key]);
                    $record->update(['custom_field_values' => $values]);
                });
        }

        $field->delete();

        return response()->json(['message' => 'Custom field deleted.']);
    }

    /**
     * Validation rules for creating a definition.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'resource_type' => ['required', 'string', Rule::in(CustomField::RESOURCE_TYPES)],
            'field_key' => ['nullable', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/'],
            'label' => ['required', 'string', 'max:255'],
            'field_type' => ['required', 'string', Rule::in(CustomField::TYPES)],
            'is_required' => ['sometimes', 'boolean'],
            'options' => ['required_if:field_type,select', 'nullable', 'array', 'min:2', 'max:20'],
            'options.*' => ['string', 'min:1', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Validation rules for updating a definition.
     *
     * @return array<string, mixed>
     */
    protected function updateRules(CustomField $field): array
    {
        $rules = [
            'label' => ['sometimes', 'string', 'max:255'],
            'is_required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        if ($field->field_type === 'select') {
            $rules['options'] = ['sometimes', 'array', 'min:2', 'max:20'];
            $rules['options.*'] = ['string', 'min:1', 'max:255'];
        }

        return $rules;
    }

    /**
     * Whether a definition with this key already exists for the
     * organization and resource type.
     */
    protected function keyExists(Organization $organization, string $resourceType, string $fieldKey): bool
    {
        return $organization->customFields()
            ->where('resource_type', $resourceType)
            ->where('field_key', $fieldKey)
            ->exists();
    }

    /**
     * How many active definitions the organization has for a
     * resource type.
     */
    protected function activeCount(Organization $organization, string $resourceType): int
    {
        return $organization->customFields()
            ->where('resource_type', $resourceType)
            ->where('is_active', true)
            ->count();
    }

    /**
     * The validated resource_type query parameter, or null when it
     * is missing or invalid.
     */
    protected function resourceType(Request $request): ?string
    {
        $resourceType = $request->query('resource_type');

        return in_array($resourceType, CustomField::RESOURCE_TYPES, true)
            ? $resourceType
            : null;
    }

    /**
     * The 422 response for a missing or invalid resource_type.
     */
    protected function resourceTypeError(): JsonResponse
    {
        return response()->json([
            'message' => 'The resource type is required.',
            'errors' => ['resource_type' => ['The resource type must be shipment or claim.']],
        ], 422);
    }

    /**
     * The view permission that matches a resource type.
     */
    protected function viewPermission(string $resourceType): string
    {
        return $resourceType === 'claim'
            ? Permission::ViewClaims->value
            : Permission::ViewShipments->value;
    }

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
