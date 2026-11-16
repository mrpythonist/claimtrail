<?php

namespace App\Policies;

use App\Models\Carrier;
use App\Models\User;
use App\Permission;

class CarrierPolicy
{
    /**
     * Carriers are viewed with the shipment view permission: anyone who
     * can see shipments can see the carriers on them.
     */
    public function view(User $user, Carrier $carrier): bool
    {
        return $user->hasPermissionIn($carrier->organization_id, Permission::ViewShipments);
    }

    /**
     * Carriers are edited with the shipment edit permission.
     */
    public function update(User $user, Carrier $carrier): bool
    {
        return $user->hasPermissionIn($carrier->organization_id, Permission::EditShipments);
    }
}
