<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationInvitationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * The invitation token is never exposed here; it only travels in
     * the invitation email.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'email' => $this->email,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'status' => $this->accepted_at !== null
                ? 'accepted'
                : ($this->expires_at->isPast() ? 'expired' : 'pending'),
            'expires_at' => $this->expires_at->toISOString(),
            'accepted_at' => $this->accepted_at?->toISOString(),
            'invited_by' => $this->whenLoaded('inviter', fn () => [
                'id' => $this->inviter->id,
                'name' => $this->inviter->name,
                'email' => $this->inviter->email,
            ]),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
