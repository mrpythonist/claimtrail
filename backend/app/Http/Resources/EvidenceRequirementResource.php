<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvidenceRequirementResource extends JsonResource
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
            'event_category_id' => $this->event_category_id,
            'event_category' => $this->whenLoaded('eventCategory', fn () => $this->eventCategory ? [
                'id' => $this->eventCategory->id,
                'slug' => $this->eventCategory->slug,
                'label' => $this->eventCategory->label,
            ] : null),
            'document_category' => $this->document_category->value,
            'document_category_label' => $this->document_category->label(),
            'is_required' => $this->is_required,
            'notes' => $this->notes,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
