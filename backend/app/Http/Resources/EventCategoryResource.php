<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventCategoryResource extends JsonResource
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
            'slug' => $this->slug,
            'label' => $this->label,
            'description' => $this->description,
            'is_system' => $this->isSystem(),
            'is_active' => $this->is_active ?? true,
            'default_estimated_amount' => $this->default_estimated_amount,
            'default_currency' => $this->default_currency,
        ];
    }
}
