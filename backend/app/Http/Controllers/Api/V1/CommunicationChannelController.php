<?php

namespace App\Http\Controllers\Api\V1;

use App\Comms\ChannelProviderRegistry;
use App\Http\Controllers\Controller;
use App\Models\CommunicationChannel;
use App\Models\Organization;
use App\Permission;
use App\Services\CommunicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Communication channels for one organization (v1.3.3).
 *
 * A Slack, Teams, or mock channel connected by a webhook
 * URL. Creating, updating, deleting, and testing need
 * manage-communications (owner, admin, operations
 * manager, dispatcher). Any member may list channels and
 * read the provider catalogue. The webhook URL is stored
 * encrypted and never returned by the API: the payload
 * says only whether one is stored. No live delivery
 * exists: the mock records locally and Slack and Teams
 * are schema-only, so a test records a logged delivery
 * and reports live_available false.
 */
class CommunicationChannelController extends Controller
{
    public function __construct(
        protected CommunicationService $comms,
    ) {}

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * The communication channels, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $channels = CommunicationChannel::forOrganization($organization)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $channels->map(fn (CommunicationChannel $channel) => $this->comms->channelPayload($channel))->all(),
        ]);
    }

    /**
     * The provider catalogue: every registered driver with
     * its label, live availability, and webhook URL needs.
     * Any member may read it.
     */
    public function providers(): JsonResponse
    {
        return response()->json([
            'data' => CommunicationService::providers(),
        ]);
    }

    /**
     * Create a communication channel.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ManageCommunications->value, $organization);

        $validated = $request->validate([
            'provider' => ['required', 'string', Rule::in(ChannelProviderRegistry::drivers())],
            'name' => ['required', 'string', 'max:100', Rule::unique('communication_channels')->where('organization_id', $organization->getKey())],
            'webhook_url' => ['nullable', 'string', 'max:2000'],
            'settings' => ['nullable', 'array'],
            'settings.claim_created' => ['nullable', 'boolean'],
            'settings.evidence_received' => ['nullable', 'boolean'],
            'settings.driver_link_used' => ['nullable', 'boolean'],
            'settings.sync_completed' => ['nullable', 'boolean'],
            'settings.delivery_failed' => ['nullable', 'boolean'],
        ]);

        $channel = $this->comms->createChannel(
            $organization,
            $request->user(),
            $validated['provider'],
            $validated['name'],
            $validated['webhook_url'] ?? null,
            $validated['settings'] ?? [],
        );

        return response()->json([
            'data' => $this->comms->channelPayload($channel),
        ], 201);
    }

    /**
     * One communication channel.
     */
    public function show(Request $request, CommunicationChannel $channel): JsonResponse
    {
        $organization = $this->organization($request);

        if ($channel->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->comms->channelPayload($channel),
        ]);
    }

    /**
     * Update a communication channel. The webhook URL is
     * only replaced when supplied.
     */
    public function update(Request $request, CommunicationChannel $channel): JsonResponse
    {
        $organization = $this->organization($request);

        if ($channel->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageCommunications->value, $organization);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('communication_channels')->where('organization_id', $organization->getKey())->ignore($channel->getKey())],
            'status' => ['sometimes', 'string', Rule::in([CommunicationChannel::STATUS_ACTIVE, CommunicationChannel::STATUS_DISABLED])],
            'webhook_url' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'settings' => ['sometimes', 'array'],
            'settings.claim_created' => ['nullable', 'boolean'],
            'settings.evidence_received' => ['nullable', 'boolean'],
            'settings.driver_link_used' => ['nullable', 'boolean'],
            'settings.sync_completed' => ['nullable', 'boolean'],
            'settings.delivery_failed' => ['nullable', 'boolean'],
        ]);

        $channel = $this->comms->updateChannel($channel, $request->user(), $validated);

        return response()->json([
            'data' => $this->comms->channelPayload($channel),
        ]);
    }

    /**
     * Delete a communication channel. Its deliveries go
     * with it (cascade).
     */
    public function destroy(Request $request, CommunicationChannel $channel): JsonResponse
    {
        $organization = $this->organization($request);

        if ($channel->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageCommunications->value, $organization);

        $this->comms->deleteChannel($channel, $request->user());

        return response()->json(['message' => 'Communication channel deleted.']);
    }

    /**
     * Test the connection: authenticate the channel and
     * record a test delivery. No live call is made.
     */
    public function test(Request $request, CommunicationChannel $channel): JsonResponse
    {
        $organization = $this->organization($request);

        if ($channel->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ManageCommunications->value, $organization);

        return response()->json([
            'data' => $this->comms->testChannel($channel, $request->user()),
        ]);
    }
}
