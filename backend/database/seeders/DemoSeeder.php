<?php

namespace Database\Seeders;

use App\MembershipStatus;
use App\Models\EventCategory;
use App\Models\Organization;
use App\Models\User;
use App\OrganizationRole;
use App\ShipmentStatus;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Development-only demo data: one organization with one user per role,
 * two carriers, three sample shipments, and two sample events.
 *
 * All demo users share the password "password". These credentials are
 * documented in docs/DEVELOPMENT.md and must never exist in production.
 * The seeder is idempotent: running it twice does not duplicate users,
 * memberships, carriers, shipments, or events. No evidence rows are
 * seeded: an evidence record without a real file behind it would be
 * a broken promise.
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

        $owner = User::where('email', 'owner@demo.claimtrail.test')->firstOrFail();
        $dispatcher = User::where('email', 'dispatcher@demo.claimtrail.test')->firstOrFail();

        $carrier = $organization->carriers()->firstOrCreate(
            ['name' => 'Acme Lines LLC'],
            [
                'contact_name' => 'Rosa Reyes',
                'contact_email' => 'dispatch@acmelin.es',
                'contact_phone' => '(555) 010-4400',
                'dot_number' => 'DOT 1234567',
            ]
        );

        $organization->carriers()->firstOrCreate(
            ['name' => 'Prairie Haul Inc.'],
            [
                'contact_name' => 'Tom Baker',
                'contact_email' => 'ops@prairiehaul.test',
                'dot_number' => 'DOT 7654321',
            ]
        );

        $shipment = $organization->shipments()->firstOrCreate(
            ['internal_reference' => 'CT-DEMO-001'],
            [
                'external_load_reference' => 'BRK-88412',
                'broker_or_customer_name' => 'Heartland Foods',
                'carrier_id' => $carrier->getKey(),
                'driver_name' => 'Joe Parker',
                'driver_contact' => '(555) 010-2233',
                'pickup_location' => 'Dallas, TX',
                'delivery_location' => 'Kansas City, MO',
                'pickup_appointment' => now()->subDays(5)->setTime(9, 0),
                'delivery_appointment' => now()->subDays(4)->setTime(14, 0),
                'actual_arrival_at' => now()->subDays(4)->setTime(13, 40),
                'actual_departure_at' => now()->subDays(4)->setTime(18, 15),
                'base_rate' => 1450.00,
                'currency' => 'USD',
                'status' => ShipmentStatus::Delivered,
                'created_by' => $dispatcher->id,
            ]
        );

        $organization->shipments()->firstOrCreate(
            ['internal_reference' => 'CT-DEMO-002'],
            [
                'external_load_reference' => 'BRK-88455',
                'broker_or_customer_name' => 'Ozark Building Supply',
                'carrier_id' => $carrier->getKey(),
                'driver_name' => 'Maria Lopez',
                'pickup_location' => 'Memphis, TN',
                'delivery_location' => 'St. Louis, MO',
                'pickup_appointment' => now()->addDays(1)->setTime(8, 0),
                'delivery_appointment' => now()->addDays(2)->setTime(11, 0),
                'base_rate' => 980.00,
                'currency' => 'USD',
                'status' => ShipmentStatus::Planned,
                'created_by' => $dispatcher->id,
            ]
        );

        $organization->shipments()->firstOrCreate(
            ['internal_reference' => 'CT-DEMO-003'],
            [
                'broker_or_customer_name' => 'Blue River Logistics',
                'pickup_location' => 'Oklahoma City, OK',
                'delivery_location' => 'Little Rock, AR',
                'base_rate' => 720.00,
                'currency' => 'USD',
                'status' => ShipmentStatus::InTransit,
                'created_by' => $owner->id,
            ]
        );

        $detention = EventCategory::where('slug', 'detention')
            ->whereNull('organization_id')
            ->first();
        $lumper = EventCategory::where('slug', 'lumper')
            ->whereNull('organization_id')
            ->first();

        if ($detention !== null) {
            $shipment->events()->firstOrCreate(
                [
                    'event_category_id' => $detention->getKey(),
                    'event_date' => now()->subDays(4)->toDateString(),
                ],
                [
                    'organization_id' => $organization->getKey(),
                    'start_time' => '14:00',
                    'end_time' => '18:15',
                    'duration_minutes' => 255,
                    'location' => 'Kansas City, MO',
                    'description' => 'Held at the receiver for four hours past the appointment.',
                    'estimated_amount' => 250.00,
                    'currency' => 'USD',
                    'reported_by' => $dispatcher->id,
                ]
            );
        }

        if ($lumper !== null) {
            $shipment->events()->firstOrCreate(
                [
                    'event_category_id' => $lumper->getKey(),
                    'event_date' => now()->subDays(4)->toDateString(),
                ],
                [
                    'organization_id' => $organization->getKey(),
                    'location' => 'Kansas City, MO',
                    'description' => 'Lumper fee paid at the receiver.',
                    'estimated_amount' => 180.00,
                    'currency' => 'USD',
                    'reported_by' => $dispatcher->id,
                ]
            );
        }
    }
}
