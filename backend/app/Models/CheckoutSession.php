<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An open mock checkout session.
 *
 * Created by POST /billing/checkout; completed by the mock
 * "customer pays" step (POST /billing/checkout/{id}/complete).
 * Sessions expire after 24 hours and can only be completed once.
 */
class CheckoutSession extends Model
{
    use HasUuids;

    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'organization_id',
        'provider_session_id',
        'plan',
        'interval',
        'extra_carriers',
        'status',
        'expires_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'extra_carriers' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * The organization checking out.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Whether the session can still be completed.
     */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN
            && ($this->expires_at === null || now()->lt($this->expires_at));
    }
}
