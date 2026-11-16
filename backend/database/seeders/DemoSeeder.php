<?php

namespace Database\Seeders;

use App\MembershipStatus;
use App\Models\Organization;
use App\Models\User;
use App\OrganizationRole;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Development-only demo data: one organization with one user per role.
 *
 * All demo users share the password "password". These credentials are
 * documented in docs/DEVELOPMENT.md and must never exist in production.
 * The seeder is idempotent: running it twice does not duplicate users
 * or memberships.
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $organization = Organization::firstOrCreate(
            ['slug' => 'acme-trucking-demo'],
            [
                'name' => 'Acme Trucking Co.',
                'timezone' => 'America/Chicago',
            ]
        );

        $users = [
            ['name' => 'Olivia Owner', 'email' => 'owner@demo.claimtrail.test', 'role' => OrganizationRole::Owner],
            ['name' => 'Adam Admin', 'email' => 'admin@demo.claimtrail.test', 'role' => OrganizationRole::Admin],
            ['name' => 'Maria Manager', 'email' => 'ops@demo.claimtrail.test', 'role' => OrganizationRole::OperationsManager],
            ['name' => 'Dan Dispatcher', 'email' => 'dispatcher@demo.claimtrail.test', 'role' => OrganizationRole::Dispatcher],
            ['name' => 'Beth Billing', 'email' => 'billing@demo.claimtrail.test', 'role' => OrganizationRole::BillingStaff],
            ['name' => 'Victor Viewer', 'email' => 'viewer@demo.claimtrail.test', 'role' => OrganizationRole::Viewer],
        ];

        foreach ($users as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );

            $organization->members()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'role' => $data['role'],
                    'status' => MembershipStatus::Active,
                    'joined_at' => now(),
                ]
            );
        }
    }
}
