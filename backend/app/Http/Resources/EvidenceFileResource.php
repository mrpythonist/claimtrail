<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvidenceFileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * The storage key and disk are never exposed: file access goes
     * through the authorized download route only.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'shipment_id' => $this->shipment_id,
            'shipment_event_id' => $this->shipment_event_id,
            'document_category' => $this->document_category->value,
            'document_category_label' => $this->document_category->label(),
            'original_filename' => $this->original_filename,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'checksum' => $this->checksum,
            'previewable' => $this->isPreviewable(),
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader ? [
                'id' => $this->uploader->id,
                'name' => $this->uploader->name,
            ] : null),
            'description' => $this->description,
            'verification_status' => $this->verification_status->value,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
