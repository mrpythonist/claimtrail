<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShipmentResource;
use App\Models\Organization;
use App\Models\Shipment;
use App\Permission;
use App\Services\ShipmentRules;
use App\ShipmentStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShipmentController extends Controller
{
    /**
     * Columns a list request may sort by.
     */
    protected const SORTABLE = [
        'created_at',
        'pickup_appointment',
        'delivery_appointment',
        'status',
    ];

    /**
     * List shipments of the resolved organization, with search,
     * filters, sorting, and pagination.
     *
     * The date range filters on the pickup appointment date.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewShipments->value, $organization);

        $shipments = $this->filteredQuery($request, $organization)
            ->with(['carrier', 'creator'])
            ->withCount(['events', 'evidenceFiles'])
            ->orderBy(
                $this->sortColumn($request),
                $this->sortDirection($request)
            )
            ->paginate($this->perPage($request, 15));

        return response()->json([
            'data' => ShipmentResource::collection($shipments),
            'meta' => $this->paginationMeta($shipments),
        ]);
    }

    /**
     * Create a shipment in the resolved organization.
     */
    public function store(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::CreateShipments->value, $organization);

        $validated = $request->validate(
            ShipmentRules::createRules($organization->getKey())
        );

        $shipment = $organization->shipments()->create(array_merge($validated, [
            'created_by' => $request->user()->id,
        ]));

        return response()->json([
            'data' => new ShipmentResource($shipment->load(['carrier', 'creator'])),
        ], 201);
    }

    /**
     * Show one shipment of the resolved organization.
     */
    public function show(Request $request, Shipment $shipment): JsonResponse
    {
        $organization = $this->organization($request);

        if ($shipment->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('view', $shipment);

        $shipment->load(['carrier', 'creator'])->loadCount(['events', 'evidenceFiles']);

        return response()->json([
            'data' => new ShipmentResource($shipment),
        ]);
    }

    /**
     * Update a shipment.
     *
     * Status changes must follow the transition map: a shipment moves
     * forward one step at a time, and archived shipments cannot be
     * edited at all (the policy enforces that).
     */
    public function update(Request $request, Shipment $shipment): JsonResponse
    {
        $organization = $this->organization($request);

        if ($shipment->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('update', $shipment);

        $validated = $request->validate(
            ShipmentRules::updateRules($organization->getKey(), $shipment->getKey())
        );

        if (isset($validated['status'])) {
            $target = ShipmentStatus::from($validated['status']);

            if ($target !== $shipment->status && ! $shipment->status->canTransitionTo($target)) {
                return response()->json([
                    'message' => "A {$shipment->status->label()} shipment cannot move to {$target->label()}.",
                ], 422);
            }
        }

        $shipment->update($validated);

        return response()->json([
            'data' => new ShipmentResource($shipment->fresh()->load(['carrier', 'creator'])),
        ]);
    }

    /**
     * Move several shipments to the same status.
     *
     * Each shipment follows the forward-only transition map and the
     * policy (archived shipments are read-only). A shipment that
     * cannot move is reported in `failed` with its reason instead
     * of failing the whole batch. Status changes are audit-logged
     * by the model observer.
     */
    public function bulkStatus(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::EditShipments->value, $organization);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['uuid'],
            'to_status' => ['required', 'string', Rule::in(ShipmentStatus::values())],
        ]);

        $target = ShipmentStatus::from($validated['to_status']);
        $updated = [];
        $failed = [];

        foreach (array_unique($validated['ids']) as $id) {
            $shipment = Shipment::forOrganization($organization->getKey())->whereKey($id)->first();

            if ($shipment === null) {
                $failed[] = ['id' => $id, 'reason' => 'Shipment not found.'];
                continue;
            }

            try {
                Gate::authorize('update', $shipment);
            } catch (AuthorizationException) {
                $failed[] = [
                    'id' => $id,
                    'reason' => 'This shipment cannot be edited. Archived shipments are read-only.',
                ];
                continue;
            }

            if ($target !== $shipment->status && ! $shipment->status->canTransitionTo($target)) {
                $failed[] = [
                    'id' => $id,
                    'reason' => "A {$shipment->status->label()} shipment cannot move to {$target->label()}.",
                ];
                continue;
            }

            $shipment->update(['status' => $target]);
            $updated[] = $id;
        }

        return response()->json([
            'data' => ['updated' => $updated, 'failed' => $failed],
        ]);
    }

    /**
     * Archive several shipments at once.
     *
     * Archiving is the bulk-delete equivalent: shipments are never
     * hard-deleted, because events, evidence, and claims hang off
     * them. Already archived shipments are reported as failures
     * rather than silently skipped.
     */
    public function bulkArchive(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ArchiveShipments->value, $organization);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['uuid'],
        ]);

        $updated = [];
        $failed = [];

        foreach (array_unique($validated['ids']) as $id) {
            $shipment = Shipment::forOrganization($organization->getKey())->whereKey($id)->first();

            if ($shipment === null) {
                $failed[] = ['id' => $id, 'reason' => 'Shipment not found.'];
                continue;
            }

            try {
                Gate::authorize('archive', $shipment);
            } catch (AuthorizationException) {
                $failed[] = [
                    'id' => $id,
                    'reason' => 'This shipment cannot be archived. Archived shipments are read-only.',
                ];
                continue;
            }

            $shipment->update(['status' => ShipmentStatus::Archived]);
            $updated[] = $id;
        }

        return response()->json([
            'data' => ['updated' => $updated, 'failed' => $failed],
        ]);
    }

    /**
     * Archive a shipment. Archiving is one-way in this milestone:
     * archived shipments are read-only and cannot be restored.
     */
    public function archive(Request $request, Shipment $shipment): JsonResponse
    {
        $organization = $this->organization($request);

        if ($shipment->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('archive', $shipment);

        $shipment->update(['status' => ShipmentStatus::Archived]);

        return response()->json([
            'data' => new ShipmentResource($shipment->fresh()->load(['carrier', 'creator'])),
        ]);
    }

    /**
     * Export the filtered shipment list as CSV.
     *
     * Streams the file with a cursor so large exports do not load
     * every shipment into memory at once.
     */
    public function export(Request $request): StreamedResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ExportShipments->value, $organization);

        $filename = 'claimtrail-shipments-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($request, $organization) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'internal_reference',
                'external_load_reference',
                'broker_or_customer_name',
                'carrier',
                'driver_name',
                'driver_contact',
                'pickup_location',
                'delivery_location',
                'pickup_appointment',
                'delivery_appointment',
                'actual_arrival_at',
                'actual_departure_at',
                'base_rate',
                'currency',
                'status',
                'notes',
            ]);

            $this->filteredQuery($request, $organization)
                ->with('carrier')
                ->orderBy('created_at')
                ->cursor()
                ->each(function (Shipment $shipment) use ($handle) {
                    fputcsv($handle, [
                        $shipment->internal_reference,
                        $shipment->external_load_reference,
                        $shipment->broker_or_customer_name,
                        $shipment->carrier?->name,
                        $shipment->driver_name,
                        $shipment->driver_contact,
                        $shipment->pickup_location,
                        $shipment->delivery_location,
                        $shipment->pickup_appointment?->format('Y-m-d H:i'),
                        $shipment->delivery_appointment?->format('Y-m-d H:i'),
                        $shipment->actual_arrival_at?->format('Y-m-d H:i'),
                        $shipment->actual_departure_at?->format('Y-m-d H:i'),
                        $shipment->base_rate,
                        $shipment->currency,
                        $shipment->status->value,
                        $shipment->notes,
                    ]);
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * The filtered shipment query shared by list and export.
     */
    protected function filteredQuery(Request $request, Organization $organization): Builder
    {
        // Start from the relation's query: chaining on the relation
        // itself would hand back the relation, not the builder.
        return $organization->shipments()->getQuery()
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->where(function ($query) use ($search) {
                    $query->where('internal_reference', 'like', $search)
                        ->orWhere('external_load_reference', 'like', $search)
                        ->orWhere('broker_or_customer_name', 'like', $search)
                        ->orWhere('driver_name', 'like', $search)
                        ->orWhere('pickup_location', 'like', $search)
                        ->orWhere('delivery_location', 'like', $search)
                        ->orWhereHas('carrier', fn ($carrier) => $carrier->where('name', 'like', $search));
                });
            })
            ->when(
                $request->filled('status') && in_array($request->string('status')->toString(), ShipmentStatus::values(), true),
                fn ($query) => $query->where('status', $request->string('status'))
            )
            ->when($request->filled('carrier_id'), fn ($query) => $query->where('carrier_id', $request->string('carrier_id')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('pickup_appointment', '>=', $request->string('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('pickup_appointment', '<=', $request->string('date_to')))
            ->when($request->filled('delivery_date_from'), fn ($query) => $query->whereDate('delivery_appointment', '>=', $request->string('delivery_date_from')))
            ->when($request->filled('delivery_date_to'), fn ($query) => $query->whereDate('delivery_appointment', '<=', $request->string('delivery_date_to')))
            ->when($request->filled('amount_min') && is_numeric($request->input('amount_min')), fn ($query) => $query->where('base_rate', '>=', $request->input('amount_min')))
            ->when($request->filled('amount_max') && is_numeric($request->input('amount_max')), fn ($query) => $query->where('base_rate', '<=', $request->input('amount_max')))
            ->when($request->filled('event_type'), fn ($query) => $query->whereHas('events.category', fn ($category) => $category->where('slug', $request->string('event_type'))))
            ->when($request->filled('broker'), function ($query) use ($request) {
                $broker = '%'.$request->string('broker').'%';
                $query->where('broker_or_customer_name', 'like', $broker);
            });
    }

    /**
     * Validated sort column, defaulting to newest first.
     */
    protected function sortColumn(Request $request): string
    {
        $column = $request->string('sort', 'created_at')->toString();

        return in_array($column, self::SORTABLE, true) ? $column : 'created_at';
    }

    /**
     * Validated sort direction, defaulting to descending.
     */
    protected function sortDirection(Request $request): string
    {
        return $request->string('direction', 'desc')->toString() === 'asc' ? 'asc' : 'desc';
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
