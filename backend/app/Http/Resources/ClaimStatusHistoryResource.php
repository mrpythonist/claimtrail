<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClaimStatusHistoryResource extends JsonResource
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
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'changed_by' => $this->whenLoaded('changer', fn () => $this->changer ? [
                'id' => $this->changer->id,
                'name' => $this->changer->name,
            ] : null),
            'reason' => $this->reason,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
