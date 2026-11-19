<?php

namespace App\Http\Controllers\Api\V1;

use App\ClaimCategory;
use App\ClaimStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClaimResource;
use App\Http\Resources\ClaimStatusHistoryResource;
use App\Http\Resources\EvidenceFileResource;
use App\Models\Claim;
use App\Models\ClaimDeadline;
use App\Models\EvidenceFile;
use App\Models\Organization;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\NotificationType;
use App\Permission;
use App\Services\ClaimReferenceService;
use App\Services\ClaimTransitionService;
use App\Services\CustomFieldService;
use App\Services\DeadlineStatusService;
use App\Services\NotificationService;
use App\Services\ReadinessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        $claims = $this->filteredQuery($request, $organization)
            ->with(['shipment', 'event'])
            ->withCount('evidenceFiles')
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
     * Export the filtered claim list as CSV, using the same filters
     * as the claim list. When an export template id is given, its
     * columns are used; a template from another organization, or one
     * for shipments, is a 422. Without a template the default claim
     * column set is used.
     */
    public function export(Request $request): StreamedResponse
    {
        $organization = $this->organization($request);

        Gate::authorize(Permission::ExportReports->value, $organization);

        $columns = CustomFieldService::CLAIM_COLUMNS;

        if ($request->filled('export_template_id')) {
            $template = $organization->exportTemplates()
                ->whereKey($request->input('export_template_id'))
                ->first();

            if ($template === null || $template->resource_type !== 'claim') {
                abort(422, 'The export template does not belong to this organization or is not a claim template.');
            }

            $columns = $template->columns ?? CustomFieldService::CLAIM_COLUMNS;
        }

        $filename = 'claimtrail-claims-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($request, $organization, $columns) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, $columns);

            $this->filteredQuery($request, $organization)
                ->with(['shipment', 'event.category'])
                ->orderBy('created_at')
                ->cursor()
                ->each(function (Claim $claim) use ($handle, $columns) {
                    fputcsv($handle, $this->claimRowValues($claim, $columns));
                });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * One export row for a claim, in the given column order.
     * Unknown columns are skipped defensively by emitting an empty
     * cell, so a stored template never breaks an export.
     *
     * @param  array<int, string>  $columns
     * @return array<int, mixed>
     */
    protected function claimRowValues(Claim $claim, array $columns): array
    {
        $values = $claim->custom_field_values ?? [];

        $fixed = [
            'claim_reference' => $claim->claim_reference,
            'shipment_reference' => $claim->shipment?->internal_reference,
            'broker_or_customer_name' => $claim->shipment?->broker_or_customer_name,
            'claim_category' => $claim->claim_category->value,
            'description' => $claim->description,
            'requested_amount' => $claim->requested_amount,
            'accepted_amount' => $claim->accepted_amount,
            'recovered_amount' => $claim->recovered_amount,
            'currency' => $claim->currency,
            'status' => $claim->status->value,
            'submission_deadline_at' => $claim->submission_deadline_at?->format('Y-m-d H:i'),
            'submitted_at' => $claim->submitted_at?->format('Y-m-d H:i'),
            'created_at' => $claim->created_at?->format('Y-m-d H:i'),
        ];

        return array_map(function (string $column) use ($fixed, $values) {
            if (str_starts_with($column, 'custom:')) {
                $value = $values[substr($column, 7)] ?? null;

                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }

                if (is_array($value)) {
                    return json_encode($value);
                }

                return $value;
            }

            return $fixed[$column] ?? '';
        }, $columns);
    }

    /**
     * Create the claim deadline from the organization deadline
     * default, when one applies, and point the claim at it.
     */
    protected function applyDeadlineDefault(
        Organization $organization,
        Claim $claim,
        ?ShipmentEvent $event,
        CustomFieldService $customFields,
        DeadlineStatusService $statuses,
    ): void {
        $setting = $customFields->resolveDeadlineSetting(
            $organization,
            $claim->claim_category->value,
            $event?->category?->slug,
        );

        if ($setting === null) {
            return;
        }

        $deadlineAt = now($organization->timezone ?? 'UTC')
            ->addDays($setting->submit_within_days);

        $deadline = $claim->deadlines()->create([
            'organization_id' => $organization->getKey(),
            'deadline_at' => $deadlineAt,
            'timezone' => $organization->timezone ?? 'UTC',
            'source_reference' => 'Organization deadline default',
            'reminder_days_before' => $setting->reminder_days_before,
        ]);

        $deadline->update(['status' => $statuses->compute($deadline->fresh()->load('claim'))]);

        $claim->update(['submission_deadline_at' => $deadlineAt]);
    }

    /**
     * The filtered claim query shared by list and export.
     */
    protected function filteredQuery(Request $request, Organization $organization): Builder
    {
        return $organization->claims()->getQuery()
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
            ->when($request->filled('carrier_id'), fn ($query) => $query->whereHas('shipment', fn ($shipment) => $shipment->where('carrier_id', $request->string('carrier_id'))));
    }

    /**
     * Create a claim for a shipment, optionally tied to an event.
     *
     * The reference is generated per organization (CLM-YYYY-NNNN).
     * When no category is given and the claim has an event, the
     * category is inherited from the event.
     */
    public function store(
        Request $request,
        ClaimReferenceService $references,
        NotificationService $notifications,
        CustomFieldService $customFields,
        DeadlineStatusService $statuses,
    ): JsonResponse {
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
            'custom_field_values' => ['nullable', 'array'],
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

        $customValues = $customFields->validateValues(
            $organization,
            'claim',
            $request->input('custom_field_values'),
        );

        $claim = $organization->claims()->create(array_merge($validated, [
            'claim_reference' => $references->nextReference($organization),
            'claim_category' => $category ?? ClaimCategory::Other->value,
            'custom_field_values' => $customValues,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        // When no explicit deadline was given, the organization
        // deadline default applies: the setting is resolved by claim
        // category, then event category, then the global setting. An
        // explicit submission_deadline_at always wins.
        if (empty($validated['submission_deadline_at'])) {
            $this->applyDeadlineDefault($organization, $claim, $event, $customFields, $statuses);
        }

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
    public function update(Request $request, Claim $claim, CustomFieldService $customFields): JsonResponse
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
            'custom_field_values' => ['sometimes', 'nullable', 'array'],
        ]);

        if (array_key_exists('custom_field_values', $validated)) {
            $validated['custom_field_values'] = $customFields->validateValues(
                $organization,
                'claim',
                $validated['custom_field_values'],
                $claim->custom_field_values ?? [],
            );
        }

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
