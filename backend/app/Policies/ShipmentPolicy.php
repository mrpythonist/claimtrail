<?php

namespace App\Policies;

use App\Models\Shipment;
use App\Models\User;
use App\Permission;

class ShipmentPolicy
{
    /**
     * Any member with the shipment view permission can read a shipment
     * in their organization.
     */
    public function view(User $user, Shipment $shipment): bool
    {
        return $user->hasPermissionIn($shipment->organization_id, Permission::ViewShipments);
    }

    /**
     * Editing needs the edit permission, and archived shipments are
     * read-only: no edit of any kind is allowed once archived.
     */
    public function update(User $user, Shipment $shipment): bool
    {
        return ! $shipment->isArchived()
            && $user->hasPermissionIn($shipment->organization_id, Permission::EditShipments);
    }

    /**
     * Archiving needs the archive permission, and an already archived
     * shipment cannot be archived again.
     */
    public function archive(User $user, Shipment $shipment): bool
    {
        return ! $shipment->isArchived()
            && $user->hasPermissionIn($shipment->organization_id, Permission::ArchiveShipments);
    }
}
