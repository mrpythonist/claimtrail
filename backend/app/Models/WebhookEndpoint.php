<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A webhook subscription for one organization (v2.0.2).
 *
 * url is the target endpoint, stored encrypted; secret is
 * the HMAC signing secret, stored encrypted and shown once
 * at creation. events is the list of event types the
 * endpoint receives (empty means every event type). status
 * is active or disabled; a disabled endpoint never receives
 * a delivery. last_error carries the latest delivery
 * failure message for the monitoring view.
 *
 * Event types (see WebhookDispatcher::EVENTS): claim.created,
 * claim.updated, claim.paid, payment.recorded,
 * document.processed, workflow.run_completed.
 */
class WebhookEndpoint extends Model
{
    use BelongsToOrganization, HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'organization_id',
        'name',
        'url',
        'secret',
        'events',
        'status',
        'last_delivered_at',
        'last_error',
        'created_by',
    ];

    protected $hidden = [
        'url',
        'secret',
    ];

    protected function casts(): array
    {
        return [
            'url' => 'encrypted',
            'secret' => 'encrypted',
            'events' => 'array',
            'last_delivered_at' => 'datetime',
        ];
    }

    /**
     * Whether the endpoint wants an event type. An empty
     * events list means every event type.
     */
    public function wants(string $event): bool
    {
        $events = $this->events ?? [];

        return $events === [] || in_array($event, $events, true);
    }

    /**
     * The deliveries sent to this endpoint, newest first.
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'webhook_endpoint_id')->orderByDesc('created_at');
    }

    /**
     * The user who created the endpoint.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
