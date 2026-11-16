<?php

namespace App\Http\Controllers\Api\V1;

use App\ClaimCategory;
use App\ClaimStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClaimResource;
use App\Http\Resources\ClaimStatusHistoryResource;
use App\Http\Resources\EvidenceFileResource;
use App\Models\Claim;
use App\Models\EvidenceFile;
use App\Models\Organization;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\NotificationType;
use App\Permission;
use App\Services\ClaimReferenceService;
use App\Services\ClaimTransitionService;
use App\Services\NotificationService;
use App\Services\ReadinessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ClaimController extends Controller
{
    /**
     * Columns a list request may sort by.
     */
    protected const SORTABLE = [
        'created_at',
        'requested_amount',
        'status',
        'submission_deadline_at',
    ];

    /**
     * List claims of the resolved organization, with search,
     * filters, sorting, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ViewClaims->value, $organization);

        $claims = $organization->claims()->getQuery()
            ->with(['shipment', 'event'])
            ->withCount('evidenceFiles')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->where(function ($query) use ($search) {
                    $query->where('claim_reference', 'like', $search)
                        ->orWhere('description', 'like', $search)
                        ->orWhere('external_broker_reference', 'like', $search)
                        ->orWhereHas('shipment', function ($query) use ($search) {
                            $query->where('broker_or_customer_name', 'like', $search)
                                ->orWhere('internal_reference', 'like', $search);
                        });
                });
            })
            ->when(
                $request->filled('status') && in_array($request->string('status')->toString(), ClaimStatus::values(), true),
                fn ($query) => $query->where('status', $request->string('status'))
            )
            ->when(
                $request->filled('claim_category') && in_array($request->string('claim_category')->toString(), ClaimCategory::values(), true),
                fn ($query) => $query->where('claim_category', $request->string('claim_category'))
            )
            ->when($request->filled('shipment_id'), fn ($query) => $query->where('shipment_id', $request->string('shipment_id')))
            ->orderBy(
                $this->sortColumn($request),
                $this->sortDirection($request)
            )
            ->paginate($this->perPage($request, 15));

        return response()->json([
            'data' => ClaimResource::collection($claims),
            'meta' => $this->paginationMeta($claims),
        ]);
    }

    /**
     * Create a claim for a shipment, optionally tied to an event.
     *
     * The reference is generated per organization (CLM-YYYY-NNNN).
     * When no category is given and the claim has an event, the
     * category is inherited from the event.
     */
    public function store(Request $request, ClaimReferenceService $references, NotificationService $notifications): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::CreateClaims->value, $organization);

        $validated = $request->validate([
            'shipment_id' => [
                'required', 'uuid',
                Rule::exists('shipments', 'id')->where('organization_id', $organization->getKey()),
            ],
            'shipment_event_id' => [
                'nullable', 'uuid',
                Rule::exists('shipment_events', 'id')->where('organization_id', $organization->getKey()),
            ],
            'claim_category' => ['nullable', 'string', Rule::in(ClaimCategory::values())],
            'description' => ['nullable', 'string', 'max:5000'],
            'requested_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'external_broker_reference' => ['nullable', 'string', 'max:255'],
            'submission_deadline_at' => ['nullable', 'date'],
        ]);

        $shipment = Shipment::forOrganization($organization->getKey())
            ->whereKey($validated['shipment_id'])
            ->firstOrFail();

        $event = null;

        if (! empty($validated['shipment_event_id'])) {
            $event = ShipmentEvent::forOrganization($organization->getKey())
                ->whereKey($validated['shipment_event_id'])
                ->firstOrFail();

            if ($event->shipment_id !== $shipment->getKey()) {
                return response()->json([
                    'message' => 'The event does not belong to this shipment.',
                ], 422);
            }
        }

        $category = $validated['claim_category'] ?? null;

        if ($category === null && $event !== null) {
            $category = ClaimCategory::tryFrom($event->category?->slug ?? '')?->value
                ?? ClaimCategory::Other->value;
        }

        $claim = $organization->claims()->create(array_merge($validated, [
            'claim_reference' => $references->nextReference($organization),
            'claim_category' => $category ?? ClaimCategory::Other->value,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        // The audit row for the creation comes from the observer.
        // The notification is sent here, not from the observer, so
        // seed data and test helpers never notify anyone.
        $notifications->notify(
            $organization,
            $notifications->membersWithPermission($organization, Permission::ViewClaims, $request->user()),
            NotificationType::ClaimCreated,
            'New claim '.$claim->claim_reference,
            'A new '.$claim->claim_category->label().' claim was created for '.$claim->currency.' '.number_format((float) $claim->requested_amount, 2).'.',
            [
                'claim_id' => (string) $claim->getKey(),
                'claim_reference' => $claim->claim_reference,
                'shipment_id' => (string) $claim->shipment_id,
            ],
            null,
            $request->user(),
        );

        return response()->json([
            'data' => new ClaimResource($claim->load(['shipment', 'event', 'creator'])),
        ], 201);
    }

    /**
     * Show one claim of the resolved organization.
     */
    public function show(Request $request, Claim $claim): JsonResponse
    {
        $organization = $this->organization($request);

        if ($claim->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('view', $claim);

        $claim->load(['shipment.carrier', 'event.category', 'creator', 'updater', 'activeDeadline'])
            ->loadCount('evidenceFiles');

        return response()->json([
            'data' => new ClaimResource($claim),
        ]);
    }

    /**
     * Update a claim.
     *
     * The substance of a claim (amount, currency, category, shipment
     * and event links) is frozen once it is submitted. Notes and the
     * external broker reference stay editable until the claim is paid
     * or closed. Status never changes through this endpoint: use the
     * transition endpoint.
     */
    public function update(Request $request, Claim $claim): JsonResponse
    {
        $organization = $this->organization($request);

        if ($claim->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('update', $claim);

        $validated = $request->validate([
            'shipment_event_id' => [
                'nullable', 'uuid',
                Rule::exists('shipment_events', 'id')->where('organization_id', $organization->getKey()),
            ],
            'claim_category' => ['sometimes', 'string', Rule::in(ClaimCategory::values())],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'requested_amount' => ['sometimes', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'internal_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'external_broker_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'submission_deadline_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if (! $claim->allowsFieldEdits()) {
            $frozen = array_intersect(
                array_keys($validated),
                ['shipment_event_id', 'claim_category', 'requested_amount', 'currency']
            );

            if (! empty($frozen)) {
                return response()->json([
                    'message' => 'A submitted claim can no longer change its amount, category, or event link.',
                ], 422);
            }
        }

        if (! empty($validated['shipment_event_id'])) {
            $event = ShipmentEvent::forOrganization($organization->getKey())
                ->whereKey($validated['shipment_event_id'])
                ->firstOrFail();

            if ($event->shipment_id !== $claim->shipment_id) {
                return response()->json([
                    'message' => 'The event does not belong to this claim shipment.',
                ], 422);
            }
        }

        $validated['updated_by'] = $request->user()->id;

        $claim->update($validated);

        return response()->json([
            'data' => new ClaimResource($claim->fresh()->load(['shipment', 'event', 'creator', 'updater'])),
        ]);
    }

    /**
     * Move a claim to a new status.
     *
     * The transition service enforces the map and the per-transition
     * data rules. Moving to paid is rejected here: payments go
     * through the record-payment endpoint.
     */
    public function transition(Request $request, Claim $claim, ClaimTransitionService $transitions): JsonResponse
    {
        $organization = $this->organization($request);

        if ($claim->organization_id !== $organization->getKey()) {
            abort(404);
        }

        $validated = $request->validate([
            'to_status' => ['required', 'string', Rule::in(ClaimStatus::values())],
            'reason' => ['nullable', 'string', 'max:2000'],
            'accepted_amount' => ['nullable', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
            'submitted_at' => ['nullable', 'date'],
        ]);

        $target = ClaimStatus::from($validated['to_status']);

        Gate::authorize('transitionTo', [$claim, $target]);

        $claim = $transitions->transition($claim, $target, $request->user(), $validated);

        return response()->json([
            'data' => new ClaimResource($claim->load(['shipment', 'event', 'creator', 'updater'])),
        ]);
    }

    /**
     * Record an actual payment and move the claim to paid.
     */
    public function recordPayment(Request $request, Claim $claim, ClaimTransitionService $transitions): JsonResponse
    {
        $organization = $this->organization($request);

        if ($claim->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('recordPayment', $claim);

        $validated = $request->validate([
            'recovered_amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'payment_date' => ['required', 'date'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $claim = $transitions->recordPayment($claim, $request->user(), $validated);

        return response()->json([
            'data' => new ClaimResource($claim->load(['shipment', 'event', 'creator', 'updater'])),
        ]);
    }

    /**
     * List the append-only status history of a claim.
     */
    public function history(Request $request, Claim $claim): JsonResponse
    {
        $organization = $this->organization($request);

        if ($claim->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('view', $claim);

        $entries = $claim->statusHistories()
            ->with('changer')
            ->orderBy('created_at')
            ->paginate($this->perPage($request, 50));

        return response()->json([
            'data' => ClaimStatusHistoryResource::collection($entries),
            'meta' => $this->paginationMeta($entries),
        ]);
    }

    /**
     * Attach an evidence file to a claim.
     *
     * The evidence must belong to the same organization and the same
     * shipment as the claim: a claim can only be supported by
     * evidence from its own shipment.
     */
    public function attachEvidence(Request $request, Claim $claim): JsonResponse
    {
        $organization = $this->organization($request);

        if ($claim->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('attachEvidence', $claim);

        $validated = $request->validate([
            'evidence_file_id' => [
                'required', 'uuid',
                Rule::exists('evidence_files', 'id')->where('organization_id', $organization->getKey()),
            ],
        ]);

        $evidence = EvidenceFile::forOrganization($organization->getKey())
            ->whereKey($validated['evidence_file_id'])
            ->firstOrFail();

        if ($evidence->shipment_id !== $claim->shipment_id) {
            return response()->json([
                'message' => 'Only evidence from the claim shipment can be attached.',
            ], 422);
        }

        $claim->evidenceFiles()->syncWithoutDetaching([
            $evidence->getKey() => ['attached_by' => $request->user()->id],
        ]);

        return response()->json([
            'data' => new EvidenceFileResource($evidence->load('uploader')),
        ], 201);
    }

    /**
     * Detach an evidence file from a claim. The file itself is not
     * deleted; only the link is removed.
     */
    public function detachEvidence(Request $request, Claim $claim, EvidenceFile $evidence): JsonResponse
    {
        $organization = $this->organization($request);

        if ($claim->organization_id !== $organization->getKey()
            || $evidence->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('attachEvidence', $claim);

        $claim->evidenceFiles()->detach($evidence->getKey());

        return response()->json(['message' => 'Evidence detached from the claim.']);
    }

    /**
     * List the evidence files attached to a claim.
     */
    public function evidence(Request $request, Claim $claim): JsonResponse
    {
        $organization = $this->organization($request);

        if ($claim->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('view', $claim);

        $files = $claim->evidenceFiles()
            ->with('uploader')
            ->orderByDesc('claim_evidence.created_at')
            ->paginate($this->perPage($request, 25));

        return response()->json([
            'data' => EvidenceFileResource::collection($files),
            'meta' => $this->paginationMeta($files),
        ]);
    }

    /**
     * Compute the live evidence readiness breakdown for a claim.
     */
    public function readiness(Request $request, Claim $claim, ReadinessService $readiness): JsonResponse
    {
        $organization = $this->organization($request);

        if ($claim->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('view', $claim);

        $claim->load(['event', 'requirementSnapshots.overrider']);

        return response()->json([
            'data' => array_merge($readiness->compute($claim), [
                'snapshots' => $claim->requirementSnapshots->map(
                    fn ($row) => [
                        'document_category' => $row->document_category->value,
                        'document_category_label' => $row->document_category->label(),
                        'is_required' => $row->is_required,
                        'is_fulfilled' => $row->is_fulfilled,
                        'evidence_file_id' => $row->evidence_file_id,
                        'snapshot_at' => $row->snapshot_at->toISOString(),
                        'overridden_by' => $row->overrider ? [
                            'id' => $row->overrider->id,
                            'name' => $row->overrider->name,
                        ] : null,
                        'override_reason' => $row->override_reason,
                        'overridden_at' => $row->overridden_at?->toISOString(),
                    ]
                )->values()->all(),
            ]),
        ]);
    }

    /**
     * Override the readiness assessment with a mandatory explanation.
     */
    public function overrideReadiness(Request $request, Claim $claim, ReadinessService $readiness): JsonResponse
    {
        $organization = $this->organization($request);

        if ($claim->organization_id !== $organization->getKey()) {
            abort(404);
        }

        Gate::authorize('overrideReadiness', $claim);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $result = $readiness->override($claim, $request->user(), $validated['reason']);

        return response()->json(['data' => $result]);
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
