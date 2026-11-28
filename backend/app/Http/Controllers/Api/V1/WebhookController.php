<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Permission;
use App\Services\EntitlementService;
use App\Services\WebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Webhook endpoint management (v2.0.2).
 *
 * Endpoints are managed through the internal API by members
 * with manage-integrations (owner, admin, operations
 * manager, dispatcher) on a Starter plan or better: the
 * Free plan is refused with a 402. The signing secret is
 * generated server-side, returned exactly once at creation
 * under plain_text_secret, and stored encrypted; the target
 * URL is stored encrypted and never returned — lists show
 * only the host. Deliveries are read-only history.
 */
class WebhookController extends Controller
{
    public function __construct(
        protected WebhookDispatcher $dispatcher,
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
     * List the organization's endpoints, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageIntegrations->value, $organization);

        $endpoints = WebhookEndpoint::forOrganization($organization->getKey())
            ->orderByDesc('created_at')
            ->paginate(max(1, min(100, (int) $request->input('per_page', 25))));

        return response()->json([
            'data' => array_map(
                fn (WebhookEndpoint $endpoint) => $this->dispatcher->presentEndpoint($endpoint),
                $endpoints->items(),
            ),
            'meta' => [
                'current_page' => $endpoints->currentPage(),
                'last_page' => $endpoints->lastPage(),
                'per_page' => $endpoints->perPage(),
                'total' => $endpoints->total(),
            ],
        ]);
    }

    /**
     * Create an endpoint. The signing secret is returned
     * once, under plain_text_secret, and never again.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageIntegrations->value, $organization);
        $this->entitlements->authorizePublicApi($organization);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url', 'max:2000'],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', Rule::in(WebhookDispatcher::events())],
        ]);

        $secret = Str::random(48);

        $endpoint = WebhookEndpoint::create([
            'organization_id' => $organization->getKey(),
            'name' => $validated['name'],
            'url' => $validated['url'],
            'secret' => $secret,
            'events' => $validated['events'] ?? [],
            'status' => WebhookEndpoint::STATUS_ACTIVE,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'data' => $this->dispatcher->presentEndpoint($endpoint),
            'plain_text_secret' => $secret,
        ], 201);
    }

    /**
     * One endpoint.
     */
    public function show(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageIntegrations->value, $organization);

        if ($webhook->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->dispatcher->presentEndpoint($webhook),
        ]);
    }

    /**
     * Change an endpoint's name, events, or status.
     * The URL and secret cannot be changed here: rotate
     * them by deleting and recreating the endpoint, so a
     * stale secret is never silently kept.
     */
    public function update(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageIntegrations->value, $organization);
        $this->entitlements->authorizePublicApi($organization);

        if ($webhook->organization_id !== $organization->getKey()) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', Rule::in(WebhookDispatcher::events())],
            'status' => ['nullable', 'string', Rule::in([WebhookEndpoint::STATUS_ACTIVE, WebhookEndpoint::STATUS_DISABLED])],
        ]);

        $webhook->update(array_filter($validated, fn ($value) => $value !== null));

        return response()->json([
            'data' => $this->dispatcher->presentEndpoint($webhook->fresh()),
        ]);
    }

    /**
     * Delete an endpoint. Its delivery history goes with it.
     */
    public function destroy(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageIntegrations->value, $organization);

        if ($webhook->organization_id !== $organization->getKey()) {
            abort(404);
        }

        $webhook->delete();

        return response()->json(['message' => 'Webhook endpoint deleted.']);
    }

    /**
     * Send a test event to the endpoint immediately and
     * return the delivery row. The test payload is marked
     * test so a receiver never mistakes it for a real event.
     */
    public function test(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageIntegrations->value, $organization);
        $this->entitlements->authorizePublicApi($organization);

        if ($webhook->organization_id !== $organization->getKey()) {
            abort(404);
        }

        if ($webhook->status !== WebhookEndpoint::STATUS_ACTIVE) {
            return response()->json(['message' => 'A disabled endpoint cannot be tested.'], 422);
        }

        $delivery = $this->dispatcher->test($webhook);

        return response()->json([
            'data' => $this->dispatcher->presentDelivery($delivery),
        ]);
    }

    /**
     * Delivery history for one endpoint, newest first.
     */
    public function deliveries(Request $request, WebhookEndpoint $webhook): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageIntegrations->value, $organization);

        if ($webhook->organization_id !== $organization->getKey()) {
            abort(404);
        }

        $deliveries = WebhookDelivery::query()
            ->where('webhook_endpoint_id', $webhook->getKey())
            ->orderByDesc('created_at')
            ->paginate(max(1, min(100, (int) $request->input('per_page', 25))));

        return response()->json([
            'data' => array_map(
                fn (WebhookDelivery $delivery) => $this->dispatcher->presentDelivery($delivery),
                $deliveries->items(),
            ),
            'meta' => [
                'current_page' => $deliveries->currentPage(),
                'last_page' => $deliveries->lastPage(),
                'per_page' => $deliveries->perPage(),
                'total' => $deliveries->total(),
            ],
        ]);
    }
}
