<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CommunicationDelivery;
use App\Models\Organization;
use App\Services\CommunicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Communication delivery history for one organization
 * (v1.3.3).
 *
 * The monitoring view for channel messages: every
 * attempt a channel made, whatever started it (an API
 * test, a service send, or the comms:test command),
 * with its event, status, and plain-language detail.
 * Any member may list and read deliveries. No live
 * delivery exists in v1.3.3, so every delivery is logged
 * (recorded locally) or failed (malformed webhook URL).
 */
class CommunicationDeliveryController extends Controller
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
     * The organization's deliveries, newest first.
     * Filters: communication_channel_id, event, status.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate([
            'communication_channel_id' => ['nullable', 'uuid', Rule::exists('communication_channels', 'id')->where('organization_id', $organization->getKey())],
            'event' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in([CommunicationDelivery::STATUS_LOGGED, CommunicationDelivery::STATUS_SENT, CommunicationDelivery::STATUS_FAILED])],
        ]);

        $query = CommunicationDelivery::forOrganization($organization)->orderByDesc('created_at');

        if (! empty($validated['communication_channel_id'])) {
            $query->where('communication_channel_id', $validated['communication_channel_id']);
        }

        if (! empty($validated['event'])) {
            $query->where('event', $validated['event']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $deliveries = $query->get();

        return response()->json([
            'data' => $deliveries->map(fn (CommunicationDelivery $delivery) => $this->comms->deliveryPayload($delivery))->all(),
        ]);
    }

    /**
     * One delivery.
     */
    public function show(Request $request, CommunicationDelivery $delivery): JsonResponse
    {
        $organization = $this->organization($request);

        if ($delivery->organization_id !== $organization->getKey()) {
            abort(404);
        }

        return response()->json([
            'data' => $this->comms->deliveryPayload($delivery),
        ]);
    }
}
