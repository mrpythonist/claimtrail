<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's named, saved filter set for a list (shipments or
 * claims) inside one organization.
 *
 * Views are personal: a member only sees their own. The filter
 * payload is a flat map of list query parameter names to string
 * values, applied client-side as query parameters. The backend
 * never interprets the payload as anything other than data.
 */
class SavedView extends Model
{
    use BelongsToOrganization, HasUuids;

    /**
     * Resource types a view can be saved for.
     */
    public const RESOURCE_SHIPMENT = 'shipment';

    public const RESOURCE_CLAIM = 'claim';

    /**
     * @return array<int, string>
     */
    public static function resourceTypes(): array
    {
        return [self::RESOURCE_SHIPMENT, self::RESOURCE_CLAIM];
    }

    /**
     * Maximum saved views per member, per organization, per resource
     * type. Keeps the list a short set of real shortcuts, not an
     * unbounded collection.
     */
    public const MAX_PER_USER_PER_RESOURCE = 25;

    protected $fillable = [
        'organization_id',
        'user_id',
        'resource_type',
        'name',
        'filters',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
        ];
    }

    /**
     * The member who saved this view.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
