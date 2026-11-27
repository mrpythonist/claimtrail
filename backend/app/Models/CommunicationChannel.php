<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A communication channel for one organization (v1.3.3).
 *
 * provider is the driver name ('mock', 'slack', or
 * 'teams'). webhook_url holds the vendor incoming-webhook
 * URL, stored encrypted; the mock needs none. settings
 * selects which events the channel receives:
 * claim_created, evidence_received, driver_link_used,
 * sync_completed, delivery_failed (all true by default).
 * status is active or disabled; a disabled channel never
 * receives a message. last_error carries the latest
 * failure message for the monitoring view.
 */
class CommunicationChannel extends Model
{
    use BelongsToOrganization, HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const EVENTS = [
        'claim_created',
        'evidence_received',
        'driver_link_used',
        'sync_completed',
        'delivery_failed',
    ];

    protected $fillable = [
        'organization_id',
        'provider',
        'name',
        'status',
        'webhook_url',
        'settings',
        'last_tested_at',
        'last_error',
        'created_by',
    ];

    protected $hidden = [
        'webhook_url',
    ];

    protected function casts(): array
    {
        return [
            'webhook_url' => 'encrypted',
            'settings' => 'array',
            'last_tested_at' => 'datetime',
        ];
    }

    /**
     * The deliveries sent through this channel, newest first.
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(CommunicationDelivery::class, 'communication_channel_id')->orderByDesc('created_at');
    }

    /**
     * The user who created this channel.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The effective settings: stored settings over the
     * defaults, so older rows without a key still behave.
     *
     * @return array<string, bool>
     */
    public function effectiveSettings(): array
    {
        $settings = $this->settings ?? [];
        $effective = [];

        foreach (self::EVENTS as $event) {
            $effective[$event] = (bool) ($settings[$event] ?? true);
        }

        return $effective;
    }

    /**
     * Whether this channel wants a message for an event:
     * active, and the event enabled in its settings.
     */
    public function wantsEvent(string $event): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        return $this->effectiveSettings()[$event] ?? false;
    }
}
