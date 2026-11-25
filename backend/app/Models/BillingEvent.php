<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One webhook event received from the billing provider.
 *
 * Deduplicated by provider_event_id: a redelivered event is
 * recorded once and never applied twice. Status is processed,
 * duplicate (already seen), or failed (the handler threw; the
 * payload is kept so an operator can see what failed).
 */
class BillingEvent extends Model
{
    use HasUuids;

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNPROCESSED = 'unprocessed';

    protected $fillable = [
        'organization_id',
        'type',
        'provider_event_id',
        'payload',
        'status',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * The organization the event concerns, when it maps to one.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
