<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClaimReviewNoteResource extends JsonResource
{
    /**
     * The note text is the reviewer's own words, so it is returned
     * in full: notes are only visible to members who can view the
     * claim, and internal claim notes are never shown to brokers.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'claim_id' => $this->claim_id,
            'note' => $this->note,
            'author' => $this->whenLoaded('author', fn () => $this->author ? [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ] : null),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
