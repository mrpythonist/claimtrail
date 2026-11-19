<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A custom field definition for shipments or claims.
 *
 * Definitions are per organization and resource type. Values live
 * as a JSON map on the shipment or claim row itself, keyed by
 * field_key. A definition is validated by CustomFieldService; this
 * model only stores the definition.
 */
class CustomField extends Model
{
    use BelongsToOrganization, HasUuids;

    /**
     * Field types a custom field may hold.
     *
     * @var array<int, string>
     */
    public const TYPES = ['text', 'number', 'date', 'select', 'boolean'];

    /**
     * Resource types a custom field may attach to.
     *
     * @var array<int, string>
     */
    public const RESOURCE_TYPES = ['shipment', 'claim'];

    /**
     * The most active definitions one organization may hold per
     * resource type.
     */
    public const MAX_ACTIVE_PER_RESOURCE = 20;

    protected $attributes = [
        'is_required' => false,
        'sort_order' => 0,
        'is_active' => true,
    ];

    protected $fillable = [
        'organization_id',
        'resource_type',
        'field_key',
        'label',
        'field_type',
        'is_required',
        'options',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'options' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
