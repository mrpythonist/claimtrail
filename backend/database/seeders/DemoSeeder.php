<?php

namespace Database\Seeders;

use App\ClaimStatus;
use App\MembershipStatus;
use App\Models\EventCategory;
use App\Models\Organization;
use App\Models\User;
use App\NotificationType;
use App\OrganizationRole;
use App\Services\ClaimReferenceService;
use App\ShipmentStatus;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Development-only demo data: one organization with one user per role,
 * two carriers, three sample shipments, two sample events, default
 * evidence requirements, two sample claims, and one sample deadline.
 *
 * All demo users share the password "password". These credentials are
 * documented in docs/DEVELOPMENT.md and must never exist in production.
 * The seeder is idempotent: running it twice does not duplicate users,
 * memberships, carriers, shipments, events, requirements, claims,
 * deadlines, or notifications. No evidence rows are seeded: an
 * evidence record without a real file behind it would be a broken
 * promise. No generated documents are seeded either: a document row
 * without a PDF behind it would be the same broken promise. No audit
 * rows are seeded: the audit trail records only actions that really
 * happened, and seeding is not one of them. Two notifications are
 * seeded for the demo owner, both about the sample detention claim
 * (created, and missing evidence): they describe the seeded claim,
 * which genuinely has no evidence attached.
 *
 * The default evidence requirements are illustrative starting points
 * the customer must verify against their own contracts. They are not
 * universal rules and never imply a claim is valid.
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

        $tonu = EventCategory::where('slug', 'tonu')
            ->whereNull('organization_id')
            ->first();

        // Illustrative default requirements. A real organization
        // reviews and changes these in organization settings.
        $defaultRequirements = [
            [$detention, 'appointment_confirmation'],
            [$detention, 'arrival_departure_record'],
            [$detention, 'broker_communication'],
            [$lumper, 'lumper_receipt'],
            [$tonu, 'broker_communication'],
        ];

        foreach ($defaultRequirements as [$category, $documentCategory]) {
            if ($category === null) {
                continue;
            }

            $organization->evidenceRequirements()->firstOrCreate(
                [
                    'event_category_id' => $category->getKey(),
                    'document_category' => $documentCategory,
                ],
                [
                    'is_required' => true,
                    'notes' => 'Seeded default. Verify against your contracts.',
                    'created_by' => $owner->id,
                ]
            );
        }

        $references = app(ClaimReferenceService::class);
        $billing = User::where('email', 'billing@demo.claimtrail.test')->firstOrFail();

        $detentionEvent = $detention !== null
            ? $shipment->events()->where('event_category_id', $detention->getKey())->first()
            : null;

        $detentionClaim = null;

        if ($detentionEvent !== null) {
            $detentionClaim = $organization->claims()
                ->where('shipment_event_id', $detentionEvent->getKey())
                ->first();

            if ($detentionClaim === null) {
                $detentionClaim = $organization->claims()->create([
                    'claim_reference' => $references->nextReference($organization),
                    'shipment_id' => $shipment->getKey(),
                    'shipment_event_id' => $detentionEvent->getKey(),
                    'claim_category' => 'detention',
                    'description' => 'Detention at the Kansas City receiver, four hours past the appointment.',
                    'requested_amount' => 250.00,
                    'currency' => 'USD',
                    'status' => ClaimStatus::UnderReview,
                    'created_by' => $billing->id,
                    'updated_by' => $billing->id,
                ]);
            }
        }

        $lumperEvent = $lumper !== null
            ? $shipment->events()->where('event_category_id', $lumper->getKey())->first()
            : null;

        if ($lumperEvent !== null && $organization->claims()
            ->where('shipment_event_id', $lumperEvent->getKey())
            ->doesntExist()) {
            $organization->claims()->create([
                'claim_reference' => $references->nextReference($organization),
                'shipment_id' => $shipment->getKey(),
                'shipment_event_id' => $lumperEvent->getKey(),
                'claim_category' => 'lumper',
                'description' => 'Lumper fee paid at the receiver.',
                'requested_amount' => 180.00,
                'currency' => 'USD',
                'status' => ClaimStatus::Potential,
                'created_by' => $billing->id,
                'updated_by' => $billing->id,
            ]);
        }

        if ($detentionClaim !== null && $detentionClaim->deadlines()->doesntExist()) {
            $detentionClaim->deadlines()->create([
                'organization_id' => $organization->getKey(),
                'deadline_at' => now()->addDays(14),
                'timezone' => 'America/Chicago',
                'source_reference' => 'Rate confirmation BRK-88412, section 4.',
                'reminder_days_before' => [7, 3, 1],
                'status' => 'upcoming',
            ]);
        }

        // Two notifications for the demo owner, both about the sample
        // detention claim. The dedupe keys are stable (no date), so
        // re-running the seeder does not duplicate them. No audit rows
        // are seeded: the trail records only actions that happened.
        if ($detentionClaim !== null) {
            $reference = $detentionClaim->claim_reference;

            $organization->notifications()->firstOrCreate(
                [
                    'user_id' => $owner->id,
                    'dedupe_key' => 'demo:claim.created:'.$detentionClaim->getKey(),
                ],
                [
                    'type' => NotificationType::ClaimCreated,
                    'title' => 'New claim created: '.$reference,
                    'body' => 'Claim '.$reference.' was created for shipment CT-DEMO-001.',
                    'data' => [
                        'claim_id' => (string) $detentionClaim->getKey(),
                        'claim_reference' => $reference,
                        'shipment_id' => (string) $shipment->getKey(),
                    ],
                ]
            );

            $organization->notifications()->firstOrCreate(
                [
                    'user_id' => $owner->id,
                    'dedupe_key' => 'demo:claim.evidence_missing:'.$detentionClaim->getKey(),
                ],
                [
                    'type' => NotificationType::ClaimEvidenceMissing,
                    'title' => 'Claim '.$reference.' is missing evidence',
                    'body' => 'Claim '.$reference.' still needs: Appointment confirmation, Arrival/departure record, Broker communication.',
                    'data' => [
                        'claim_id' => (string) $detentionClaim->getKey(),
                        'claim_reference' => $reference,
                        'shipment_id' => (string) $shipment->getKey(),
                    ],
                ]
            );
        }
    }
}
