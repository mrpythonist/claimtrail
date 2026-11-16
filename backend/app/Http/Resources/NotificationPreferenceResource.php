<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationPreferenceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'event_type' => $this->event_type->value,
            'label' => $this->event_type->label(),
            'in_app' => $this->in_app,
            'email' => $this->email,
        ];
    }
}
