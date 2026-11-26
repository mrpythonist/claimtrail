<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A TMS integration for one organization (v1.3.2).
 *
 * provider is the driver name ('mock' or 'trimble-tmw').
 * credentials are stored encrypted and hold the provider's
 * auth material; the mock needs none, Trimble needs a
 * client id, client secret, and account id. settings selects
 * what syncs: sync_shipments, sync_status,
 * sync_appointments, sync_documents (all true by default),
 * auto_sync (false by default), and sync_interval_hours
 * (24 by default). status is active or disabled; a disabled
 * integration never syncs. last_error carries the latest
 * failure message for the monitoring view.
 */
class TmsIntegration extends Model
{
    use BelongsToOrganization, HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'organization_id',
        'provider',
        'name',
        'status',
        'credentials',
        'settings',
        'last_sync_at',
        'last_error',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'last_sync_at' => 'datetime',
        ];
    }

    /**
     * The sync runs of this integration, newest first.
     */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(TmsSyncRun::class, 'tms_integration_id')->orderByDesc('created_at');
    }

    /**
     * The user who created this integration.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The effective settings: stored settings over the
     * defaults, so older rows without a key still behave.
     *
     * @return array{sync_shipments: bool, sync_status: bool, sync_appointments: bool, sync_documents: bool, auto_sync: bool, sync_interval_hours: int}
     */
    public function effectiveSettings(): array
    {
        $settings = $this->settings ?? [];

        return [
            'sync_shipments' => (bool) ($settings['sync_shipments'] ?? true),
            'sync_status' => (bool) ($settings['sync_status'] ?? true),
            'sync_appointments' => (bool) ($settings['sync_appointments'] ?? true),
            'sync_documents' => (bool) ($settings['sync_documents'] ?? true),
            'auto_sync' => (bool) ($settings['auto_sync'] ?? false),
            'sync_interval_hours' => max(1, min(168, (int) ($settings['sync_interval_hours'] ?? 24))),
        ];
    }

    /**
     * The stored credentials, or an empty array when none
     * were supplied (the mock needs none).
     *
     * @return array<string, mixed>
     */
    public function credentialsArray(): array
    {
        return $this->credentials ?? [];
    }

    /**
     * Whether this integration may sync now: active, and
     * at least one sync kind enabled.
     */
    public function canSync(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $settings = $this->effectiveSettings();

        return $settings['sync_shipments']
            || $settings['sync_status']
            || $settings['sync_appointments']
            || $settings['sync_documents'];
    }

    /**
     * Whether an auto-sync is due: auto-sync on, active,
     * and the last sync is older than the interval (or
     * never synced).
     */
    public function autoSyncDue(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $settings = $this->effectiveSettings();

        if (! $settings['auto_sync']) {
            return false;
        }

        if ($this->last_sync_at === null) {
            return true;
        }

        return $this->last_sync_at->addHours($settings['sync_interval_hours'])->isPast();
    }
}
