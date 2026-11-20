<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Permission;
use App\Services\RetentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Data retention settings for one organization.
 *
 * The effective settings are the config defaults merged over
 * with the organization's own overrides, which live in the
 * organization's settings JSON under the `retention` key. A
 * value of 0 disables purging for that kind (kept forever).
 * The weekly purge itself runs through `retention:purge`.
 */
class RetentionController extends Controller
{
    public function __construct(
        protected RetentionService $retention,
    ) {}

    /**
     * Effective retention settings, with defaults and overrides.
     */
    public function show(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageSecurity->value, $organization);

        return response()->json([
            'data' => [
                'effective' => $this->retention->effectiveSettings($organization),
                'defaults' => $this->defaults(),
                'overrides' => $organization->settings['retention'] ?? [],
            ],
        ]);
    }

    /**
     * Set per-organization retention overrides, in days.
     *
     * Only the kinds in the request are changed; the rest keep
     * their current value. A null value clears the override and
     * restores the config default.
     */
    public function update(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageSecurity->value, $organization);

        $rules = [];

        foreach (RetentionService::kinds() as $kind) {
            $rules[$kind] = ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'];
        }

        $validated = $request->validate($rules);

        $retention = $organization->settings['retention'] ?? [];

        foreach ($validated as $kind => $days) {
            if ($days === null) {
                unset($retention[$kind]);
            } else {
                $retention[$kind] = $days;
            }
        }

        $settings = $organization->settings ?? [];
        $settings['retention'] = $retention;
        $organization->update(['settings' => $settings]);

        return response()->json([
            'data' => [
                'effective' => $this->retention->effectiveSettings($organization->fresh()),
                'defaults' => $this->defaults(),
                'overrides' => $retention,
            ],
        ]);
    }

    /**
     * The config defaults, in days, per kind.
     *
     * @return array<string, int>
     */
    protected function defaults(): array
    {
        $defaults = [];

        foreach (RetentionService::kinds() as $kind) {
            $defaults[$kind] = (int) config('retention.'.RetentionService::configKey($kind), 0);
        }

        return $defaults;
    }

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }
}
