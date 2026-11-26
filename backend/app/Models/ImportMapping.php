<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A saved CSV column mapping for one organization (v1.3.1).
 *
 * mapping is exact header text to shipment field name, the same
 * shape the import preview accepts as an override. target is
 * 'shipments' today; claims follow later. Names are unique per
 * organization. One mapping per target may be the default, used
 * when a preview, schedule, or sync run names no mapping.
 */
class ImportMapping extends Model
{
    use BelongsToOrganization, HasUuids;

    public const TARGET_SHIPMENTS = 'shipments';

    protected $fillable = [
        'organization_id',
        'name',
        'target',
        'mapping',
        'is_default',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'is_default' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ImportRun::class, 'import_mapping_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ImportSchedule::class, 'import_mapping_id');
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class, 'import_mapping_id');
    }
}
