<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shipment event category.
 *
 * System categories have a null organization_id and are visible to
 * every organization. Organization categories belong to one
 * organization. This model deliberately does not use the
 * BelongsToOrganization trait: its global scope would hide the system
 * rows. Controllers scope visibility explicitly instead.
 */
class EventCategory extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'slug',
        'label',
        'description',
        'is_active',
        'default_estimated_amount',
        'default_currency',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'default_estimated_amount' => 'decimal:2',
        ];
    }

    /**
     * The owning organization, or null for a system category.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Events recorded under this category.
     */
    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class);
    }

    /**
     * Whether this is a system category shared by all organizations.
     */
    public function isSystem(): bool
    {
        return $this->organization_id === null;
    }

    /**
     * Categories visible to an organization: system rows plus its own.
     */
    public function scopeVisibleTo($query, Organization|string $organization)
    {
        $id = $organization instanceof Organization ? $organization->getKey() : $organization;

        return $query->where(function ($query) use ($id) {
            $query->whereNull('organization_id')->orWhere('organization_id', $id);
        });
    }
}
