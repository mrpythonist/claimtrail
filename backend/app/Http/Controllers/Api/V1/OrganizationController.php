<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\MembershipStatus;
use App\Models\Organization;
use App\OrganizationRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrganizationController extends Controller
{
    /**
     * List the organizations the authenticated user belongs to.
     */
    public function index(Request $request): JsonResponse
    {
        $organizations = $request->user()
            ->organizations()
            ->withPivot(['role', 'status', 'joined_at'])
            ->orderBy('organizations.name')
            ->get();

        return response()->json([
            'data' => OrganizationResource::collection($organizations),
        ]);
    }

    /**
     * Create an organization. The creator becomes its owner.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Organization::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['sometimes', 'string', 'timezone'],
        ]);

        $organization = DB::transaction(function () use ($request, $validated) {
            $organization = Organization::create([
                'name' => $validated['name'],
                'slug' => $this->uniqueSlug($validated['name']),
                'timezone' => $validated['timezone'] ?? 'UTC',
            ]);

            $organization->members()->create([
                'user_id' => $request->user()->id,
                'role' => OrganizationRole::Owner,
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
            ]);

            return $organization;
        });

        return response()->json([
            'data' => new OrganizationResource($organization),
        ], 201);
    }

    /**
     * Show one organization the user belongs to.
     */
    public function show(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('view', $organization);

        return response()->json([
            'data' => new OrganizationResource($organization),
        ]);
    }

    /**
     * Update organization settings.
     *
     * Only three settings keys are accepted: default_currency,
     * date_format, and claim_reference_prefix. Unknown settings
     * keys are rejected, and the given settings are merged over the
     * stored ones instead of replacing them.
     */
    public function update(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('update', $organization);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'settings' => ['sometimes', 'array'],
            'settings.default_currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            'settings.date_format' => ['sometimes', 'nullable', 'string', Rule::in(['Y-m-d', 'd/m/Y', 'm/d/Y'])],
            'settings.claim_reference_prefix' => ['sometimes', 'nullable', 'string', 'max:10', 'regex:/^[A-Z0-9-]+$/'],
        ]);

        // Check the raw input for unknown keys, not the validated
        // data: validation drops keys with no rule, so a settings
        // object holding only unknown keys would otherwise vanish
        // from the validated data and slip past this check.
        if ($request->has('settings')) {
            $allowed = ['default_currency', 'date_format', 'claim_reference_prefix'];
            $unknown = array_diff(array_keys((array) $request->input('settings', [])), $allowed);

            if (! empty($unknown)) {
                return response()->json([
                    'message' => 'Unknown organization settings: '.implode(', ', $unknown).'.',
                    'errors' => ['settings' => ['Unknown organization settings: '.implode(', ', $unknown).'.']],
                ], 422);
            }

            $validated['settings'] = array_merge(
                $organization->settings ?? [],
                $validated['settings'] ?? [],
            );
        }

        $organization->update($validated);

        return response()->json([
            'data' => new OrganizationResource($organization->fresh()),
        ]);
    }

    /**
     * Confirm the user may work in an organization and return it with
     * their membership. The frontend stores the choice and sends it on
     * later requests in the X-Organization-Id header; switching itself
     * is client-side, this endpoint only validates the choice.
     */
    public function switch(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('view', $organization);

        return response()->json([
            'data' => new OrganizationResource($organization),
        ]);
    }

    /**
     * Build a URL-safe slug that does not collide with an existing one.
     */
    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (Organization::where('slug', $slug)->exists());

        return $slug;
    }
}
