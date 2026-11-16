<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClaimRequirementResource extends JsonResource
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
            'claim_id' => $this->claim_id,
            'document_category' => $this->document_category->value,
            'document_category_label' => $this->document_category->label(),
            'is_required' => $this->is_required,
            'is_fulfilled' => $this->is_fulfilled,
            'evidence_file_id' => $this->evidence_file_id,
            'snapshot_at' => $this->snapshot_at->toISOString(),
            'overridden_by' => $this->whenLoaded('overrider', fn () => $this->overrider ? [
                'id' => $this->overrider->id,
                'name' => $this->overrider->name,
            ] : null),
            'override_reason' => $this->override_reason,
            'overridden_at' => $this->overridden_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
