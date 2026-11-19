<?php

namespace App\Http\Controllers\Api\V1;

use App\ClaimCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeadlineSettingResource;
use App\Models\DeadlineSetting;
use App\Models\Organization;
use App\Permission;
use App\Services\CustomFieldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DeadlineSettingController extends Controller
{
    /**
     * List the organization's deadline settings.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        $settings = $organization->deadlineSettings()
            ->orderBy('scope_type')
            ->orderBy('scope_value')
            ->paginate($this->perPage($request, 50));

        return response()->json([
            'data' => DeadlineSettingResource::collection($settings),
            'meta' => $this->paginationMeta($settings),
        ]);
    }

    /**
     * Resolve the deadline setting that would apply to a claim with
     * the given categories, or null when none applies.
     */
    public function resolve(Request $request, CustomFieldService $customFields): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewDeadlines->value, $organization);

        $validated = $request->validate([
            'claim_category' => ['nullable', 'string', Rule::in(ClaimCategory::values())],
            'event_category' => ['nullable', 'string', 'max:255'],
        ]);

        $setting = $customFields->resolveDeadlineSetting(
            $organization,
            $validated['claim_category'] ?? null,
            $validated['event_category'] ?? null,
        );

        return response()->json([
            'data' => $setting !== null ? new DeadlineSettingResource($setting) : null,
        ]);
    }

    /**
     * Create a deadline setting.
     *
     * A global setting has a null scope_value; other scopes need
     * one. Each scope may have only one setting per organization:
     * the unique index covers the non-null case, and the global case
     * is checked here because unique indexes treat nulls as
     * distinct.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        $validated = $request->validate($this->rules($organization));

        $scopeValue = $validated['scope_type'] === 'global'
            ? null
            : ($validated['scope_value'] ?? null);

        if ($this->duplicateExists($organization, $validated['scope_type'], $scopeValue)) {
            return response()->json([
                'message' => 'A deadline setting for this scope already exists.',
            ], 422);
        }

        $setting = $organization->deadlineSettings()->create([
            'scope_type' => $validated['scope_type'],
            'scope_value' => $scopeValue,
            'submit_within_days' => $validated['submit_within_days'],
            'reminder_days_before' => $validated['reminder_days_before'] ?? null,
        ]);

        return response()->json([
            'data' => new DeadlineSettingResource($setting),
        ], 201);
    }

    /**
     * Show one deadline setting.
     */
    public function show(Request $request, DeadlineSetting $setting): JsonResponse
    {
        $organization = $this->organization($request);

        if ($setting->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        return response()->json([
            'data' => new DeadlineSettingResource($setting),
        ]);
    }

    /**
     * Update a deadline setting.
     */
    public function update(Request $request, DeadlineSetting $setting): JsonResponse
    {
        $organization = $this->organization($request);

        if ($setting->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        $validated = $request->validate($this->updateRules($organization, $setting));

        $scopeType = $validated['scope_type'] ?? $setting->scope_type;
        $scopeValue = $scopeType === 'global'
            ? null
            : ($validated['scope_value'] ?? $setting->scope_value);

        if ($scopeType === 'claim_category'
            && $scopeValue !== null
            && ! in_array($scopeValue, ClaimCategory::values(), true)) {
            return response()->json([
                'message' => 'The scope value must be a claim category.',
                'errors' => ['scope_value' => ['The scope value must be a claim category.']],
            ], 422);
        }

        if ($this->duplicateExists($organization, $scopeType, $scopeValue, $setting->getKey())) {
            return response()->json([
                'message' => 'A deadline setting for this scope already exists.',
            ], 422);
        }

        $setting->update([
            'scope_type' => $scopeType,
            'scope_value' => $scopeValue,
            'submit_within_days' => $validated['submit_within_days'] ?? $setting->submit_within_days,
            'reminder_days_before' => $validated['reminder_days_before'] ?? $setting->reminder_days_before,
        ]);

        return response()->json([
            'data' => new DeadlineSettingResource($setting->fresh()),
        ]);
    }

    /**
     * Delete a deadline setting.
     */
    public function destroy(Request $request, DeadlineSetting $setting): JsonResponse
    {
        $organization = $this->organization($request);

        if ($setting->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageCustomization->value, $organization);

        $setting->delete();

        return response()->json(['message' => 'Deadline setting deleted.']);
    }

    /**
     * Validation rules for creating a setting.
     *
     * @return array<string, mixed>
     */
    protected function rules(Organization $organization): array
    {
        return [
            'scope_type' => ['required', 'string', Rule::in(DeadlineSetting::SCOPES)],
            'scope_value' => [
                'nullable', 'string', 'max:255',
                // A non-global scope needs a value. The closure below
                // only runs when the key is present, so this rule
                // carries the missing-value case.
                Rule::requiredIf(fn () => in_array(
                    request()->input('scope_type'),
                    ['claim_category', 'event_category'],
                    true,
                )),
                function ($attribute, $value, $fail) {
                    $scope = request()->input('scope_type');

                    if ($scope !== 'global' && ($value === null || $value === '')) {
                        $fail('A scope value is required unless the scope is global.');
                    }

                    if ($scope === 'claim_category' && $value !== null
                        && ! in_array($value, ClaimCategory::values(), true)) {
                        $fail('The scope value must be a claim category.');
                    }
                },
            ],
            'submit_within_days' => ['required', 'integer', 'min:1', 'max:365'],
            'reminder_days_before' => ['nullable', 'array', 'max:10'],
            'reminder_days_before.*' => ['integer', 'min:0', 'max:90'],
        ];
    }

    /**
     * Validation rules for updating a setting.
     *
     * @return array<string, mixed>
     */
    protected function updateRules(Organization $organization, DeadlineSetting $setting): array
    {
        $rules = $this->rules($organization);

        $rules['scope_type'] = ['sometimes', 'string', Rule::in(DeadlineSetting::SCOPES)];
        $rules['submit_within_days'] = ['sometimes', 'integer', 'min:1', 'max:365'];

        // On update the scope in the closure must come from the
        // request when given, otherwise from the stored setting.
        $rules['scope_value'] = [
            'sometimes', 'nullable', 'string', 'max:255',
            function ($attribute, $value, $fail) use ($setting) {
                $scope = request()->input('scope_type', $setting->scope_type);

                if ($scope !== 'global' && $value === null && ! request()->has('scope_value')) {
                    // Scope value not being changed: the stored value
                    // stands, so there is nothing to check.
                    return;
                }

                if ($scope !== 'global' && ($value === null || $value === '')) {
                    $fail('A scope value is required unless the scope is global.');
                }

                if ($scope === 'claim_category' && $value !== null
                    && ! in_array($value, ClaimCategory::values(), true)) {
                    $fail('The scope value must be a claim category.');
                }
            },
        ];

        return $rules;
    }

    /**
     * Whether a setting with the same scope already exists,
     * ignoring one row on update. Null scope values are compared
     * with whereNull because a unique index treats them as distinct.
     */
    protected function duplicateExists(
        Organization $organization,
        string $scopeType,
        ?string $scopeValue,
        ?string $exceptId = null,
    ): bool {
        return $organization->deadlineSettings()
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->where('scope_type', $scopeType)
            ->where(function ($query) use ($scopeValue) {
                if ($scopeValue === null) {
                    $query->whereNull('scope_value');
                } else {
                    $query->where('scope_value', $scopeValue);
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
