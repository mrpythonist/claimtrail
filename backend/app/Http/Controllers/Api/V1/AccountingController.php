<?php

namespace App\Http\Controllers\Api\V1;

use App\Accounting\AccountingProviderRegistry;
use App\Http\Controllers\Controller;
use App\Models\AccountingConnection;
use App\Models\Organization;
use App\Permission;
use App\Services\AccountingService;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Accounting integration management (v2.0.2).
 *
 * Connections are managed through the internal API by
 * members with manage-integrations (owner, admin,
 * operations manager, dispatcher) on a Starter plan or
 * better: the Free plan is refused with a 402. Only mock
 * providers exist (mock_quickbooks, mock_xero): they model
 * the provider schema and return deterministic dummy
 * results, with no live credentials and no real sync. A
 * sync exports recorded claim payments to the provider's
 * dummy shape; money is never moved.
 */
class AccountingController extends Controller
{
    public function __construct(
        protected AccountingService $accounting,
        protected EntitlementService $entitlements,
    ) {}

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * The modelled providers, with their mock labels.
     */
    public function providers(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageIntegrations->value, $organization);

        return response()->json([
            'data' => AccountingProviderRegistry::options(),
        ]);
    }

    /**
     * List the organization's accounting connections.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageIntegrations->value, $organization);

        $connections = AccountingConnection::forOrganization($organization->getKey())
            ->orderByDesc('created_at')
            ->paginate(max(1, min(100, (int) $request->input('per_page', 25))));

        return response()->json([
            'data' => array_map(
                fn (AccountingConnection $connection) => $this->accounting->present($connection),
                $connections->items(),
            ),
            'meta' => [
                'current_page' => $connections->currentPage(),
                'last_page' => $connections->lastPage(),
                'per_page' => $connections->perPage(),
                'total' => $connections->total(),
            ],
        ]);
    }

    /**
     * Connect a mock provider. The provider validates the
     * settings shape; a company name is required.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageIntegrations->value, $organization);
        $this->entitlements->authorizePublicApi($organization);

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(AccountingProviderRegistry::providers())],
            'name' => ['required', 'string', 'max:100'],
            'settings' => ['nullable', 'array'],
            'settings.company_name' => ['nullable', 'string', 'max:255'],
        ]);

        $connection = $this->accounting->connect(
            $organization,
            $request->user(),
            $validated['provider'],
            $validated['name'],
            $validated['settings'] ?? [],
        );

        return response()->json([
            'data' => $this->accounting->present($connection),
        ], 201);
    }

    /**
     * One connection.
     */
    public function show(Request $request, AccountingConnection $accounting): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageIntegrations->value, $organization);

        if ($accounting->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->accounting->present($accounting),
        ]);
    }

    /**
     * Test a connection's settings through its provider.
     * The connection row is not changed.
     */
    public function test(Request $request, AccountingConnection $accounting): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageIntegrations->value, $organization);
        $this->entitlements->authorizePublicApi($organization);

        if ($accounting->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->accounting->test($accounting),
        ]);
    }

    /**
     * Run a mock sync: export recorded payments to the
     * provider's dummy shape. Returns the dummy summary.
     */
    public function sync(Request $request, AccountingConnection $accounting): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageIntegrations->value, $organization);
        $this->entitlements->authorizePublicApi($organization);

        if ($accounting->organization_id !== $organization->getKey()) {
            abort(404);
        }

        $validated = $request->validate([
            'payment_ids' => ['nullable', 'array'],
            'payment_ids.*' => ['string', 'uuid'],
        ]);

        $summary = $this->accounting->sync($accounting, $validated['payment_ids'] ?? []);

        return response()->json([
            'data' => $summary,
        ]);
    }

    /**
     * Disconnect a connection. History stays readable.
     */
    public function destroy(Request $request, AccountingConnection $accounting): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageIntegrations->value, $organization);

        if ($accounting->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->accounting->present($this->accounting->disconnect($accounting)),
        ]);
    }
}
