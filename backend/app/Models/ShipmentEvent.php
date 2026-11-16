<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\ShipmentEventStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShipmentEvent extends Model
{
    use BelongsToOrganization, HasUuids;

    /**
     * Defaults that mirror the database column defaults, so a freshly
     * created model reads its status and currency before any refresh.
     */
    protected $attributes = [
        'currency' => 'USD',
        'status' => 'potential',
    ];

    protected $fillable = [
        'organization_id',
        'shipment_id',
        'event_category_id',
        'event_date',
        'start_time',
        'end_time',
        'duration_minutes',
        'location',
        'description',
        'estimated_amount',
        'currency',
        'notes',
        'reported_by',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'estimated_amount' => 'decimal:2',
            'status' => ShipmentEventStatus::class,
        ];
    }

    /**
     * The shipment this event belongs to.
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * The category of this event.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class, 'event_category_id');
    }

    /**
     * The user who reported this event.
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /**
     * Evidence files attached to this event.
     */
    public function evidenceFiles(): HasMany
    {
        return $this->hasMany(EvidenceFile::class, 'shipment_event_id');
    }

    /**
     * Minutes between two HH:MM times, or null when either is missing.
     *
     * Times are compared on the same day. A negative result means the
     * end time is before the start time, which validation rejects
     * before this is ever stored.
     */
    public static function computeDuration(?string $start, ?string $end): ?int
    {
        if ($start === null || $start === '' || $end === null || $end === '') {
            return null;
        }

        $startMinutes = self::timeToMinutes($start);
        $endMinutes = self::timeToMinutes($end);

        if ($startMinutes === null || $endMinutes === null) {
            return null;
        }

        return $endMinutes - $startMinutes;
    }

    protected static function timeToMinutes(string $time): ?int
    {
        if (! preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return $hours * 60 + $minutes;
    }
}
