<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GeneratedDocumentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * The storage key and disk are never exposed: downloads go
     * through the authorized download route.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'claim_id' => $this->claim_id,
            'document_type' => $this->document_type->value,
            'document_type_label' => $this->document_type->label(),
            'file_size' => $this->file_size,
            'generated_by' => $this->whenLoaded('generator', fn () => $this->generator ? [
                'id' => $this->generator->id,
                'name' => $this->generator->name,
            ] : null),
            'includes_internal_notes' => $this->includes_internal_notes,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'error_message' => $this->error_message,
            'generated_at' => $this->generated_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
