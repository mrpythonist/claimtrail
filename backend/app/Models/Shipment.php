<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\ShipmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    use BelongsToOrganization, HasUuids;

    /**
     * Defaults that mirror the database column defaults, so a freshly
     * created model reads its status and currency before any refresh.
     */
    protected $attributes = [
        'currency' => 'USD',
        'status' => 'draft',
    ];

    protected $fillable = [
        'organization_id',
        'internal_reference',
        'external_load_reference',
        'broker_or_customer_name',
        'carrier_id',
        'driver_name',
        'driver_contact',
        'pickup_location',
        'delivery_location',
        'pickup_appointment',
        'delivery_appointment',
        'actual_arrival_at',
        'actual_departure_at',
        'base_rate',
        'currency',
        'status',
        'notes',
        'custom_field_values',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'pickup_appointment' => 'datetime',
            'delivery_appointment' => 'datetime',
            'actual_arrival_at' => 'datetime',
            'actual_departure_at' => 'datetime',
            'base_rate' => 'decimal:2',
            'custom_field_values' => 'array',
            'status' => ShipmentStatus::class,
        ];
    }

    /**
     * The carrier hauling this shipment, if one is assigned.
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    /**
     * The user who created this shipment.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Events recorded against this shipment.
     */
    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class);
    }

    /**
     * Evidence files attached to this shipment.
     */
    public function evidenceFiles(): HasMany
    {
        return $this->hasMany(EvidenceFile::class);
    }

    /**
     * Whether the shipment is archived and therefore read-only.
     */
    public function isArchived(): bool
    {
        return $this->status === ShipmentStatus::Archived;
    }
}
