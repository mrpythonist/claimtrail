<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
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
            'name' => $this->name,
            'slug' => $this->slug,
            'timezone' => $this->timezone,
            'settings' => $this->settings,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
            'current_user_role' => $this->when(
                isset($this->pivot),
                fn () => $this->pivot->role
            ),
            'current_user_status' => $this->when(
                isset($this->pivot),
                fn () => $this->pivot->status
            ),
        ];
    }
}
