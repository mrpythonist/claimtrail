<?php

namespace App;

enum OrganizationRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case OperationsManager = 'operations_manager';
    case Dispatcher = 'dispatcher';
    case BillingStaff = 'billing_staff';
    case Viewer = 'viewer';

    /**
     * Human readable label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Admin => 'Admin',
            self::OperationsManager => 'Operations Manager',
            self::Dispatcher => 'Dispatcher',
            self::BillingStaff => 'Billing Staff',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * Every role value, for validation rules.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }
}
