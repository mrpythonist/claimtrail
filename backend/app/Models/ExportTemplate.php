<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved column selection for a shipment or claim CSV export.
 *
 * Columns are fixed column keys or `custom:<field_key>` entries for
 * active custom fields of the same resource type. At most one
 * template per organization and resource type is the default.
 */
class ExportTemplate extends Model
{
    use BelongsToOrganization, HasUuids;

    /**
     * Resource types an export template may cover.
     *
     * @var array<int, string>
     */
    public const RESOURCE_TYPES = ['shipment', 'claim'];

    protected $attributes = [
        'is_default' => false,
    ];

    protected $fillable = [
        'organization_id',
        'resource_type',
        'name',
        'columns',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'is_default' => 'boolean',
        ];
    }
}
