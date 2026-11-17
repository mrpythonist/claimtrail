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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
                                ->orWhere('internal_reference', 'like', $search)
                                ->orWhereHas('carrier', fn ($carrier) => $carrier->where('name', 'like', $search));
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
            ->when($request->filled('amount_min') && is_numeric($request->input('amount_min')), fn ($query) => $query->where('requested_amount', '>=', $request->input('amount_min')))
            ->when($request->filled('amount_max') && is_numeric($request->input('amount_max')), fn ($query) => $query->where('requested_amount', '<=', $request->input('amount_max')))
            ->when($request->filled('deadline_from'), fn ($query) => $query->whereDate('submission_deadline_at', '>=', $request->string('deadline_from')))
            ->when($request->filled('deadline_to'), fn ($query) => $query->whereDate('submission_deadline_at', '<=', $request->string('deadline_to')))
            ->when($request->filled('deadline_status'), function ($query) use ($request) {
                $deadlineStatus = $request->string('deadline_status')->toString();

                // Overdue and upcoming only count while the claim is
                // still being prepared: a submitted claim has met its
                // deadline, even if the date has passed.
                $preSubmission = [
                    ClaimStatus::Potential->value,
                    ClaimStatus::EvidenceRequired->value,
                    ClaimStatus::UnderReview->value,
                    ClaimStatus::ReadyToSubmit->value,
                ];

                if ($deadlineStatus === 'overdue') {
                    $query->whereNotNull('submission_deadline_at')
                        ->where('submission_deadline_at', '<', now())
                        ->whereIn('status', $preSubmission);
                } elseif ($deadlineStatus === 'upcoming') {
                    $query->whereNotNull('submission_deadline_at')
                        ->where('submission_deadline_at', '>=', now())
                        ->where('submission_deadline_at', '<=', now()->addDays(7))
                        ->whereIn('status', $preSubmission);
                } elseif ($deadlineStatus === 'none') {
                    $query->whereNull('submission_deadline_at');
                }
            })
            ->when($request->filled('event_type'), fn ($query) => $query->whereHas('event.category', fn ($category) => $category->where('slug', $request->string('event_type'))))
            ->when($request->filled('broker'), function ($query) use ($request) {
                $broker = '%'.$request->string('broker').'%';
                $query->whereHas('shipment', fn ($shipment) => $shipment->where('broker_or_customer_name', 'like', $broker));
            })
            ->when($request->filled('carrier_id'), fn ($query) => $query->whereHas('shipment', fn ($shipment) => $shipment->where('carrier_id', $request->string('carrier_id'))))
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
     * Review a claim that is under review.
     *
     * Approve moves it to ready to submit; requesting changes moves
     * it back to evidence required. The comment is required when
     * requesting changes, is recorded as a reviewer note, and
     * becomes the reason on the status history row, so the decision
     * and its reasoning stay linked.
     */
    public function review(Request $request, Claim $claim, ClaimTransitionService $transitions): JsonResponse
    {
        $organization = $this->organization($request);

        if ($claim->organization_id !== $organization->getKey()) {
            abort(404);
        }

        if ($claim->status !== ClaimStatus::UnderReview) {
            return response()->json([
                'message' => 'Only a claim under review can be reviewed. This claim is '.$claim->status->label().'.',
            ], 422);
        }

        Gate::authorize(Permission::ReviewClaims->value, $organization);

        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(['approve', 'request_changes'])],
            'comment' => ['nullable', 'string', 'max:5000'],
        ]);

        $comment = trim((string) ($validated['comment'] ?? ''));

        if ($validated['decision'] === 'request_changes' && $comment === '') {
            return response()->json([
                'message' => 'A comment is required when requesting changes.',
                'errors' => ['comment' => ['A comment is required when requesting changes.']],
            ], 422);
        }

        $target = $validated['decision'] === 'approve'
            ? ClaimStatus::ReadyToSubmit
            : ClaimStatus::EvidenceRequired;

        $claim = $transitions->transition($claim, $target, $request->user(), [
            'reason' => $comment !== '' ? $comment : null,
        ]);

        if ($comment !== '') {
            $claim->reviewNotes()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $request->user()->id,
                'note' => $comment,
            ]);
        }

        return response()->json([
            'data' => new ClaimResource($claim->load(['shipment', 'event', 'creator', 'updater'])),
        ]);
    }

    /**
     * Move several claims to the same status.
     *
     * Each claim is transitioned on its own through the transition
     * service, so its own map, data rules, history row, audit row,
     * and notifications all apply. A claim that cannot move is
     * reported in `failed` with its reason instead of failing the
     * whole batch: bulk work is partial-success by design. Moving
     * to paid is rejected here, as in the single endpoint.
     */
    public function bulkTransition(Request $request, ClaimTransitionService $transitions): JsonResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['uuid'],
            'to_status' => ['required', 'string', Rule::in(array_diff(ClaimStatus::values(), [ClaimStatus::Paid->value]))],
            'reason' => ['nullable', 'string', 'max:2000'],
            'accepted_amount' => ['nullable', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
            'submitted_at' => ['nullable', 'date'],
        ]);

        $target = ClaimStatus::from($validated['to_status']);
        $data = array_filter([
            'reason' => $validated['reason'] ?? null,
            'accepted_amount' => $validated['accepted_amount'] ?? null,
            'rejection_reason' => $validated['rejection_reason'] ?? null,
            'submitted_at' => $validated['submitted_at'] ?? null,
        ], fn ($value) => $value !== null);

        $updated = [];
        $failed = [];

        foreach (array_unique($validated['ids']) as $id) {
            $claim = Claim::forOrganization($organization->getKey())->whereKey($id)->first();

            if ($claim === null) {
                $failed[] = ['id' => $id, 'reason' => 'Claim not found.'];
                continue;
            }

            try {
                Gate::authorize('transitionTo', [$claim, $target]);

                $result = $transitions->transition($claim, $target, $request->user(), $data);

                $updated[] = [
                    'id' => (string) $result->getKey(),
                    'claim_reference' => $result->claim_reference,
                    'status' => $result->status->value,
                ];
            } catch (AuthorizationException) {
                $failed[] = [
                    'id' => $id,
                    'reason' => 'You do not have permission to move this claim to '.$target->label().'.',
                ];
            } catch (ValidationException $exception) {
                $failed[] = [
                    'id' => $id,
                    'reason' => collect($exception->errors())->flatten()->first()
                        ?? 'The claim cannot move to '.$target->label().'.',
                ];
            }
        }

        return response()->json([
            'data' => ['updated' => $updated, 'failed' => $failed],
        ]);
    }

    /**
     * Delete several claims.
     *
     * Only pre-submission claims can be deleted; anything submitted
     * or beyond is part of the broker trail and must be closed
     * instead. Related history, notes, deadlines, and documents are
     * removed by the database cascades, and the deletion itself is
     * audit-logged by the observer.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::DeleteClaims->value, $organization);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['uuid'],
        ]);

        $deleted = [];
        $failed = [];

        foreach (array_unique($validated['ids']) as $id) {
            $claim = Claim::forOrganization($organization->getKey())->whereKey($id)->first();

            if ($claim === null) {
                $failed[] = ['id' => $id, 'reason' => 'Claim not found.'];
                continue;
            }

            try {
                Gate::authorize('delete', $claim);
            } catch (AuthorizationException) {
                $failed[] = [
                    'id' => $id,
                    'reason' => 'This claim cannot be deleted. Only pre-submission claims can be deleted.',
                ];
                continue;
            }

            $claim->delete();
            $deleted[] = $id;
        }

        return response()->json([
            'data' => ['deleted' => $deleted, 'failed' => $failed],
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
