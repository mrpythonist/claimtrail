<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationResource extends JsonResource
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
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
            'is_unread' => $this->isUnread(),
            'read_at' => $this->read_at?->toISOString(),
            'email_sent_at' => $this->email_sent_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
