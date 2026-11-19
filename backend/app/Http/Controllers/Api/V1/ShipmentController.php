<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShipmentResource;
use App\Models\Organization;
use App\Models\Shipment;
use App\Permission;
use App\Services\CustomFieldService;
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
    public function store(Request $request, CustomFieldService $customFields): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::CreateShipments->value, $organization);

        $validated = $request->validate(array_merge(
            ShipmentRules::createRules($organization->getKey()),
            ['custom_field_values' => ['nullable', 'array']],
        ));

        $values = $customFields->validateValues(
            $organization,
            'shipment',
            $request->input('custom_field_values'),
        );

        $shipment = $organization->shipments()->create(array_merge($validated, [
            'custom_field_values' => $values,
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
    public function update(Request $request, Shipment $shipment, CustomFieldService $customFields): JsonResponse
    {
        $organization = $this->organization($request);

        if ($shipment->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('update', $shipment);

        $validated = $request->validate(array_merge(
            ShipmentRules::updateRules($organization->getKey(), $shipment->getKey()),
            ['custom_field_values' => ['nullable', 'array']],
        ));

        if (array_key_exists('custom_field_values', $validated)) {
            $validated['custom_field_values'] = $customFields->validateValues(
                $organization,
                'shipment',
                $validated['custom_field_values'],
                $shipment->custom_field_values ?? [],
            );
        }

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
     * every shipment into memory at once. When an export template id
     * is given, its columns are used instead of the default set; a
     * template from another organization, or one for claims, is a
     * 422.
     */
    public function export(Request $request): StreamedResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ExportShipments->value, $organization);

        $columns = CustomFieldService::SHIPMENT_COLUMNS;
        $template = null;

        if ($request->filled('export_template_id')) {
            $template = $organization->exportTemplates()
                ->whereKey($request->input('export_template_id'))
                ->first();

            if ($template === null || $template->resource_type !== 'shipment') {
                abort(422, 'The export template does not belong to this organization or is not a shipment template.');
            }

            $columns = $template->columns ?? CustomFieldService::SHIPMENT_COLUMNS;
        }

        $filename = 'claimtrail-shipments-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($request, $organization, $columns) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, $columns);

            $this->filteredQuery($request, $organization)
                ->with('carrier')
                ->orderBy('created_at')
                ->cursor()
                ->each(function (Shipment $shipment) use ($handle, $columns) {
                    fputcsv($handle, $this->shipmentRowValues($shipment, $columns));
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * One export row for a shipment, in the given column order.
     * Unknown columns are skipped defensively by emitting an empty
     * cell, so a stored template never breaks an export.
     *
     * @param  array<int, string>  $columns
     * @return array<int, mixed>
     */
    protected function shipmentRowValues(Shipment $shipment, array $columns): array
    {
        $values = $shipment->custom_field_values ?? [];

        $fixed = [
            'internal_reference' => $shipment->internal_reference,
            'external_load_reference' => $shipment->external_load_reference,
            'broker_or_customer_name' => $shipment->broker_or_customer_name,
            'carrier' => $shipment->carrier?->name,
            'driver_name' => $shipment->driver_name,
            'driver_contact' => $shipment->driver_contact,
            'pickup_location' => $shipment->pickup_location,
            'delivery_location' => $shipment->delivery_location,
            'pickup_appointment' => $shipment->pickup_appointment?->format('Y-m-d H:i'),
            'delivery_appointment' => $shipment->delivery_appointment?->format('Y-m-d H:i'),
            'actual_arrival_at' => $shipment->actual_arrival_at?->format('Y-m-d H:i'),
            'actual_departure_at' => $shipment->actual_departure_at?->format('Y-m-d H:i'),
            'base_rate' => $shipment->base_rate,
            'currency' => $shipment->currency,
            'status' => $shipment->status->value,
            'notes' => $shipment->notes,
        ];

        return array_map(function (string $column) use ($fixed, $values) {
            if (str_starts_with($column, 'custom:')) {
                return $this->customFieldCellValue($values[substr($column, 7)] ?? null);
            }

            return $fixed[$column] ?? '';
        }, $columns);
    }

    /**
     * A custom field value as a CSV cell: booleans read true/false,
     * anything else passes through as-is.
     */
    protected function customFieldCellValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return $value;
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
