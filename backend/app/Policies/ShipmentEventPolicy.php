<?php

namespace App\Policies;

use App\Models\ShipmentEvent;
use App\Models\User;
use App\Permission;

class ShipmentEventPolicy
{
    /**
     * Any member with the event view permission can read an event in
     * their organization.
     */
    public function view(User $user, ShipmentEvent $event): bool
    {
        return $user->hasPermissionIn($event->organization_id, Permission::ViewEvents);
    }

    /**
     * Editing needs the edit permission, and events on an archived
     * shipment are read-only along with the shipment.
     */
    public function update(User $user, ShipmentEvent $event): bool
    {
        return ! $event->shipment->isArchived()
            && $user->hasPermissionIn($event->organization_id, Permission::EditEvents);
    }

    /**
     * Deleting needs the delete permission, and events on an archived
     * shipment cannot be deleted.
     */
    public function delete(User $user, ShipmentEvent $event): bool
    {
        return ! $event->shipment->isArchived()
            && $user->hasPermissionIn($event->organization_id, Permission::DeleteEvents);
    }
}
