<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentResource extends JsonResource
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
            'internal_reference' => $this->internal_reference,
            'external_load_reference' => $this->external_load_reference,
            'broker_or_customer_name' => $this->broker_or_customer_name,
            'carrier_id' => $this->carrier_id,
            'carrier' => $this->whenLoaded('carrier', fn () => $this->carrier ? [
                'id' => $this->carrier->id,
                'name' => $this->carrier->name,
            ] : null),
            'driver_name' => $this->driver_name,
            'driver_contact' => $this->driver_contact,
            'pickup_location' => $this->pickup_location,
            'delivery_location' => $this->delivery_location,
            'pickup_appointment' => $this->pickup_appointment?->toISOString(),
            'delivery_appointment' => $this->delivery_appointment?->toISOString(),
            'actual_arrival_at' => $this->actual_arrival_at?->toISOString(),
            'actual_departure_at' => $this->actual_departure_at?->toISOString(),
            'base_rate' => $this->base_rate,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'notes' => $this->notes,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'events_count' => $this->whenCounted('events'),
            'evidence_count' => $this->whenCounted('evidenceFiles'),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
