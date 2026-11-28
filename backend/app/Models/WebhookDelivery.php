<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One webhook delivery attempt batch (v2.0.2).
 *
 * A delivery is created when an event fires for an endpoint
 * and records every attempt against that endpoint: attempts
 * counts HTTP tries, http_status and response_body (truncated)
 * carry the latest try, status is pending while retries
 * remain, delivered once an attempt returns 2xx, and failed
 * once the bounded retries are exhausted. A failed delivery
 * is a recorded failure, never a silent drop.
 */
class WebhookDelivery extends Model
{
    use BelongsToOrganization, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'webhook_endpoint_id',
        'organization_id',
        'event_type',
        'payload',
        'status',
        'attempts',
        'http_status',
        'response_body',
        'next_retry_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'next_retry_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * The endpoint this delivery goes to.
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
