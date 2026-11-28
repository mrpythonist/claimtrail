<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An accounting provider connection for one organization
 * (v2.0.2).
 *
 * provider is the driver name ('mock_quickbooks' or
 * 'mock_xero'). Both are mock providers: they model the
 * provider schema and return deterministic dummy results,
 * with no live credentials and no real sync. A real provider
 * is a later swap behind the AccountingProvider contract.
 * settings holds the mock connection details (company name
 * and dummy account ids); nothing sensitive is stored.
 * last_sync_summary is the dummy export summary from the
 * latest mock sync, kept so the sync is inspectable.
 */
class AccountingConnection extends Model
{
    use BelongsToOrganization, HasUuids;

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_DISCONNECTED = 'disconnected';

    protected $fillable = [
        'organization_id',
        'provider',
        'name',
        'status',
        'settings',
        'last_sync_summary',
        'last_synced_at',
        'last_error',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'last_sync_summary' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * The user who created the connection.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
