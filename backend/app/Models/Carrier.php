<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Carrier extends Model
{
    use BelongsToOrganization, HasUuids;

    protected $fillable = [
        'organization_id',
        'name',
        'contact_name',
        'contact_email',
        'contact_phone',
        'dot_number',
        'notes',
    ];

    /**
     * Shipments assigned to this carrier.
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }
}
