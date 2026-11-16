<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClaimResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Requested, accepted, and recovered amounts are separate fields
     * on purpose: the frontend must never present one as another.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'claim_reference' => $this->claim_reference,
            'shipment_id' => $this->shipment_id,
            'shipment' => $this->whenLoaded('shipment', fn () => [
                'id' => $this->shipment->id,
                'internal_reference' => $this->shipment->internal_reference,
                'broker_or_customer_name' => $this->shipment->broker_or_customer_name,
                'status' => $this->shipment->status->value,
            ]),
            'shipment_event_id' => $this->shipment_event_id,
            'event' => $this->whenLoaded('event', fn () => $this->event ? [
                'id' => $this->event->id,
                'event_date' => $this->event->event_date?->toDateString(),
                'description' => $this->event->description,
            ] : null),
            'claim_category' => $this->claim_category->value,
            'claim_category_label' => $this->claim_category->label(),
            'description' => $this->description,
            'requested_amount' => $this->requested_amount,
            'accepted_amount' => $this->accepted_amount,
            'recovered_amount' => $this->recovered_amount,
            'currency' => $this->currency,
            'internal_notes' => $this->internal_notes,
            'external_broker_reference' => $this->external_broker_reference,
            'submission_deadline_at' => $this->submission_deadline_at?->toISOString(),
            'submitted_at' => $this->submitted_at?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'rejected_at' => $this->rejected_at?->toISOString(),
            'paid_at' => $this->paid_at?->toISOString(),
            'rejection_reason' => $this->rejection_reason,
            'payment_reference' => $this->payment_reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'allowed_transitions' => array_map(
                fn ($status) => ['value' => $status->value, 'label' => $status->label()],
                $this->status->allowedTransitions()
            ),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'updated_by' => $this->whenLoaded('updater', fn () => $this->updater ? [
                'id' => $this->updater->id,
                'name' => $this->updater->name,
            ] : null),
            'evidence_count' => $this->whenCounted('evidenceFiles'),
            'active_deadline' => $this->whenLoaded('activeDeadline', fn () => $this->activeDeadline
                ? new ClaimDeadlineResource($this->activeDeadline)
                : null),
            'latest_document' => $this->whenLoaded('latestReadyDocument', fn () => $this->latestReadyDocument
                ? new GeneratedDocumentResource($this->latestReadyDocument)
                : null),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
