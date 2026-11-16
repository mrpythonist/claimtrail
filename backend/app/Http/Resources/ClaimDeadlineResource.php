<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClaimDeadlineResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'claim_id' => $this->claim_id,
            'claim' => $this->whenLoaded('claim', fn () => [
                'id' => $this->claim->id,
                'claim_reference' => $this->claim->claim_reference,
                'status' => $this->claim->status->value,
                'status_label' => $this->claim->status->label(),
            ]),
            'deadline_at' => $this->deadline_at->toISOString(),
            'timezone' => $this->timezone,
            'source_reference' => $this->source_reference,
            'reminder_days_before' => $this->reminder_days_before ?? [],
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'superseded_at' => $this->superseded_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
