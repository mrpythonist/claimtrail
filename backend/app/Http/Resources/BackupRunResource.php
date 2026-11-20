<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BackupRunResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * The backup path is internal (a storage location, not a
     * URL) and is not exposed; restores go through the artisan
     * command. The checksum is exposed so an operator can compare
     * it against the manifest.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'driver' => $this->driver,
            'status' => $this->status,
            'size_bytes' => $this->size_bytes,
            'checksum' => $this->checksum,
            'error_message' => $this->error_message,
            'restored_at' => $this->restored_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
