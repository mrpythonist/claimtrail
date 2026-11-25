<?php

namespace Tests;

use App\ClaimCategory;
use App\ClaimStatus;
use App\EvidenceDocumentCategory;
use App\MembershipStatus;
use App\Models\Carrier;
use App\Models\Claim;
use App\Models\EventCategory;
use App\Models\EvidenceFile;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\OrganizationRole;
use App\ShipmentStatus;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create an organization with a random slug.
     */
    protected function createOrganization(array $attributes = []): Organization
    {
        return Organization::create(array_merge([
            'name' => 'Test Trucking Co.',
            'slug' => 'test-trucking-'.Str::lower(Str::random(8)),
            'timezone' => 'UTC',
        ], $attributes));
    }

    /**
     * Add a user to an organization with the given role.
     */
    protected function addMember(
        Organization $organization,
        User $user,
        OrganizationRole $role,
        MembershipStatus $status = MembershipStatus::Active,
    ): OrganizationMember {
        return $organization->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            'status' => $status,
            'joined_at' => now(),
        ]);
    }

    /**
     * Create a user, an organization, and a membership, then authenticate
     * as that user with Sanctum.
     *
     * @return array{User, Organization, OrganizationMember}
     */
    protected function actingAsMemberOf(
        OrganizationRole $role,
        ?Organization $organization = null,
    ): array {
        $organization ??= $this->createOrganization();
        $user = User::factory()->create();
        $member = $this->addMember($organization, $user, $role);

        Sanctum::actingAs($user);

        return [$user, $organization, $member];
    }

    /**
     * Headers that select an organization for tenant-scoped requests.
     *
     * @return array<string, string>
     */
    protected function orgHeaders(Organization $organization): array
    {
        return ['X-Organization-Id' => (string) $organization->getKey()];
    }

    /**
     * Create a carrier in an organization.
     */
    protected function createCarrier(Organization $organization, array $attributes = []): Carrier
    {
        return $organization->carriers()->create(array_merge([
            'name' => 'Test Carrier '.Str::random(6),
        ], $attributes));
    }

    /**
     * Create a shipment in an organization.
     */
    protected function createShipment(Organization $organization, array $attributes = []): Shipment
    {
        return $organization->shipments()->create(array_merge([
            'internal_reference' => 'CT-TEST-'.Str::upper(Str::random(8)),
            'broker_or_customer_name' => 'Test Broker Co.',
            'status' => ShipmentStatus::Draft,
        ], $attributes));
    }

    /**
     * Find or create a system event category by slug.
     */
    protected function systemCategory(string $slug = 'detention'): EventCategory
    {
        return EventCategory::firstOrCreate(
            ['slug' => $slug, 'organization_id' => null],
            ['label' => Str::headline($slug), 'description' => null]
        );
    }

    /**
     * Create an event on a shipment.
     */
    protected function createEvent(Shipment $shipment, array $attributes = []): ShipmentEvent
    {
        return $shipment->events()->create(array_merge([
            'organization_id' => $shipment->organization_id,
            'event_category_id' => $this->systemCategory()->getKey(),
            'event_date' => now()->toDateString(),
        ], $attributes));
    }

    /**
     * Put the organization's subscription on a plan with a stored
     * payment method, as if it had checked out. Shared by billing
     * and entitlement tests.
     */
    protected function subscribe(Organization $organization, string $plan, string $interval = 'monthly', ?string $paymentMethod = 'pm_mock_visa', int $extraCarriers = 0): \App\Models\Subscription
    {
        $service = app(\App\Services\BillingService::class);

        $sub = $service->subscriptionFor($organization);
        $now = now();

        $method = $paymentMethod !== null
            ? \App\Billing\MockBillingProvider::resolvePaymentMethod($paymentMethod)
            : null;

        $sub->update([
            'plan' => $plan,
            'status' => \App\Models\Subscription::STATUS_ACTIVE,
            'interval' => $interval,
            'current_period_start' => $now->copy()->subDays(15),
            'current_period_end' => $now->copy()->addDays(15),
            'plan_started_at' => $now->copy()->subDays(15),
            'success_fee_cap_until' => $plan === \App\Billing\PlanCatalog::SUCCESS_FEE
                ? $now->copy()->addMonths(12)
                : null,
            'payment_method_token' => $method['token'] ?? null,
            'payment_method_brand' => $method['brand'] ?? null,
            'payment_method_last4' => $method['last4'] ?? null,
            'extra_carriers' => $extraCarriers,
        ]);

        return $sub->fresh();
    }

    /**
     * Create a claim for a shipment.
     */
    protected function createClaim(Organization $organization, Shipment $shipment, array $attributes = []): Claim
    {
        return $organization->claims()->create(array_merge([
            'claim_reference' => 'CLM-TEST-'.Str::upper(Str::random(8)),
            'shipment_id' => $shipment->getKey(),
            'claim_category' => ClaimCategory::Detention,
            'requested_amount' => 250.00,
            'currency' => 'USD',
            'status' => ClaimStatus::Potential,
        ], $attributes));
    }

    /**
     * Create an evidence file record without touching storage.
     *
     * Only the metadata row is created; tests that download the file
     * must put bytes on the (fake) disk themselves.
     */
    protected function createEvidence(Organization $organization, Shipment $shipment, array $attributes = []): EvidenceFile
    {
        return $organization->evidenceFiles()->create(array_merge([
            'shipment_id' => $shipment->getKey(),
            'document_category' => EvidenceDocumentCategory::BillOfLading,
            'original_filename' => 'test.pdf',
            'storage_key' => 'evidence/test/'.Str::uuid().'.pdf',
            'disk' => 'private',
            'mime_type' => 'application/pdf',
            'file_size' => 1234,
        ], $attributes));
    }
}
