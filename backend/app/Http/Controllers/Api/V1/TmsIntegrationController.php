<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\TmsIntegration;
use App\Models\TmsSyncRun;
use App\Permission;
use App\Services\TmsService;
use App\Tms\TmsProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * TMS integrations for one organization (v1.3.2).
 *
 * A generic provider adapter plus a mock provider, with
 * Trimble TMW.Suite modelled schema-only. Creating,
 * updating, deleting, testing, and syncing need
 * manage-tms-integrations (owner, admin, operations
 * manager, dispatcher). Any member may list integrations,
 * read one, and list sync runs. Credentials are never
 * returned by the API: the payload says only whether any
 * are stored.
 */
class TmsIntegrationController extends Controller
{
    public function __construct(
        protected TmsService $tms,
    ) {}

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * The TMS integrations, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $integrations = TmsIntegration::forOrganization($organization)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $integrations->map(fn (TmsIntegration $integration) => $this->tms->integrationPayload($integration))->all(),
        ]);
    }

    /**
     * The provider catalogue: every registered driver with
     * its label, live availability, capabilities, and the
     * credential fields it needs. Any member may read it.
     */
    public function providers(): JsonResponse
    {
        return response()->json([
            'data' => TmsService::providers(),
        ]);
    }

    /**
     * Create a TMS integration.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageTmsIntegrations->value, $organization);

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(TmsProviderRegistry::drivers())],
            'name' => ['required', 'string', 'max:100', Rule::unique('tms_integrations')->where('organization_id', $organization->getKey())],
            'credentials' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
            'settings.sync_shipments' => ['nullable', 'boolean'],
            'settings.sync_status' => ['nullable', 'boolean'],
            'settings.sync_appointments' => ['nullable', 'boolean'],
            'settings.sync_documents' => ['nullable', 'boolean'],
            'settings.auto_sync' => ['nullable', 'boolean'],
            'settings.sync_interval_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ]);

        $integration = $this->tms->createIntegration(
            $organization,
            $request->user(),
            $validated['provider'],
            $validated['name'],
            $validated['credentials'] ?? [],
            $validated['settings'] ?? [],
        );

        return response()->json([
            'data' => $this->tms->integrationPayload($integration),
        ], 201);
    }

    /**
     * One TMS integration.
     */
    public function show(Request $request, TmsIntegration $integration): JsonResponse
    {
        $organization = $this->organization($request);

        if ($integration->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->tms->integrationPayload($integration),
        ]);
    }

    /**
     * Update a TMS integration. Credentials are only
     * replaced when supplied.
     */
    public function update(Request $request, TmsIntegration $integration): JsonResponse
    {
        $organization = $this->organization($request);

        if ($integration->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageTmsIntegrations->value, $organization);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('tms_integrations')->where('organization_id', $organization->getKey())->ignore($integration->getKey())],
            'status' => ['sometimes', 'string', Rule::in([TmsIntegration::STATUS_ACTIVE, TmsIntegration::STATUS_DISABLED])],
            'credentials' => ['sometimes', 'array'],
            'settings' => ['sometimes', 'array'],
            'settings.sync_shipments' => ['nullable', 'boolean'],
            'settings.sync_status' => ['nullable', 'boolean'],
            'settings.sync_appointments' => ['nullable', 'boolean'],
            'settings.sync_documents' => ['nullable', 'boolean'],
            'settings.auto_sync' => ['nullable', 'boolean'],
            'settings.sync_interval_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ]);

        $integration = $this->tms->updateIntegration($integration, $request->user(), $validated);

        return response()->json([
            'data' => $this->tms->integrationPayload($integration),
        ]);
    }

    /**
     * Delete a TMS integration. Its sync runs go with it;
     * shipments and evidence created by syncs stay.
     */
    public function destroy(Request $request, TmsIntegration $integration): JsonResponse
    {
        $organization = $this->organization($request);

        if ($integration->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageTmsIntegrations->value, $organization);

        $this->tms->deleteIntegration($integration, $request->user());

        return response()->json(['message' => 'TMS integration deleted.']);
    }

    /**
     * Test the connection: authenticate against the
     * provider. No sync runs, and no live call for a
     * schema-only provider.
     */
    public function test(Request $request, TmsIntegration $integration): JsonResponse
    {
        $organization = $this->organization($request);

        if ($integration->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageTmsIntegrations->value, $organization);

        return response()->json([
            'data' => $this->tms->testConnection($integration),
        ]);
    }

    /**
     * Run a sync now. Returns the recorded sync run with
     * its final status and summary.
     */
    public function sync(Request $request, TmsIntegration $integration): JsonResponse
    {
        $organization = $this->organization($request);

        if ($integration->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageTmsIntegrations->value, $organization);

        $validated = $request->validate([
            'kind' => ['sometimes', 'string', Rule::in(TmsSyncRun::kinds())],
        ]);

        $run = $this->tms->runSync($integration, $validated['kind'] ?? TmsSyncRun::KIND_FULL, $request->user());

        return response()->json([
            'data' => $this->tms->syncRunPayload($run),
        ]);
    }
}
