<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShipmentEventResource;
use App\Models\EventCategory;
use App\Models\Organization;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Permission;
use App\ShipmentEventStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ShipmentEventController extends Controller
{
    /**
     * List events for one shipment, newest first.
     */
    public function index(Request $request, Shipment $shipment): JsonResponse
    {
        $organization = $this->organization($request);

        if ($shipment->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::ViewEvents->value, $organization);

        $events = $shipment->events()
            ->with(['category', 'reporter'])
            ->orderByDesc('event_date')
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => ShipmentEventResource::collection($events),
            'meta' => $this->paginationMeta($events),
        ]);
    }

    /**
     * Record an event against a shipment.
     *
     * Events are potential opportunities only: creating one never
     * qualifies it for compensation. The duration is computed from
     * the start and end times when both are present.
     */
    public function store(Request $request, Shipment $shipment): JsonResponse
    {
        $organization = $this->organization($request);

        if ($shipment->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize(Permission::CreateEvents->value, $organization);

        if ($shipment->isArchived()) {
            return response()->json([
                'message' => 'Events cannot be added to an archived shipment.',
            ], 422);
        }

        $validated = $request->validate($this->rules($organization));

        $event = $shipment->events()->create(array_merge($validated, [
            'organization_id' => $organization->getKey(),
            'reported_by' => $request->user()->id,
            'duration_minutes' => ShipmentEvent::computeDuration(
                $validated['start_time'] ?? null,
                $validated['end_time'] ?? null
            ) ?? $validated['duration_minutes'] ?? null,
        ]));

        return response()->json([
            'data' => new ShipmentEventResource($event->load(['category', 'reporter'])),
        ], 201);
    }

    /**
     * Show one event.
     */
    public function show(Request $request, ShipmentEvent $event): JsonResponse
    {
        $organization = $this->organization($request);

        if ($event->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('view', $event);

        $event->load(['category', 'reporter']);

        return response()->json([
            'data' => new ShipmentEventResource($event),
        ]);
    }

    /**
     * Update an event. The duration is recomputed from the merged
     * start and end times when both are present after the update.
     */
    public function update(Request $request, ShipmentEvent $event): JsonResponse
    {
        $organization = $this->organization($request);

        if ($event->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('update', $event);

        $rules = $this->rules($organization);

        foreach ($rules as $field => $fieldRules) {
            $rules[$field] = array_merge(['sometimes'], $fieldRules);
        }

        $rules['status'] = ['sometimes', 'string', Rule::in(ShipmentEventStatus::values())];

        $validated = $request->validate($rules);

        $start = $validated['start_time'] ?? $this->timeValue($event->start_time);
        $end = $validated['end_time'] ?? $this->timeValue($event->end_time);
        $computed = ShipmentEvent::computeDuration($start, $end);

        if ($computed !== null) {
            $validated['duration_minutes'] = $computed;
        }

        $event->update($validated);

        return response()->json([
            'data' => new ShipmentEventResource($event->fresh()->load(['category', 'reporter'])),
        ]);
    }

    /**
     * Delete an event. Evidence attached to the event keeps its
     * shipment link; only the event reference is cleared.
     */
    public function destroy(Request $request, ShipmentEvent $event): JsonResponse
    {
        $organization = $this->organization($request);

        if ($event->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('delete', $event);

        $event->delete();

        return response()->json(['message' => 'Event deleted.']);
    }

    /**
     * Validation rules for event input.
     *
     * The category must be visible to the organization: a system
     * category or one of its own custom categories.
     *
     * @return array<string, mixed>
     */
    protected function rules(Organization $organization): array
    {
        return [
            'event_category_id' => [
                'required', 'uuid',
                Rule::exists('event_categories', 'id')->where(function ($query) use ($organization) {
                    $query->whereNull('organization_id')
                        ->orWhere('organization_id', $organization->getKey());
                }),
            ],
            'event_date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            // The end must be after the start, but only when both are
            // present: either time may be recorded on its own.
            'end_time' => ['nullable', 'date_format:H:i', function ($attribute, $value, $fail) {
                $start = request()->input('start_time');

                if ($start !== null && $start !== '' && $value <= $start) {
                    $fail('The end time must be after the start time.');
                }
            }],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'location' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:5000'],
            'estimated_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Normalize a stored time value to HH:MM for duration math.
     */
    protected function timeValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 5);
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
