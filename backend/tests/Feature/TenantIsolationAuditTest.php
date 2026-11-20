<?php

namespace Tests\Feature;

use App\EvidenceDocumentCategory;
use App\GeneratedDocumentStatus;
use App\Models\ClaimDeadline;
use App\Models\CustomField;
use App\Models\DeadlineSetting;
use App\Models\EventCategory;
use App\Models\EvidenceRequirement;
use App\Models\EvidenceTemplate;
use App\Models\ExportTemplate;
use App\Models\GeneratedDocument;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\SavedView;
use App\Models\User;
use App\NotificationType;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tenant isolation audit (v1.1.3).
 *
 * For every API resource, a member of organization A requesting a
 * record of organization B by direct id must get a 404, for
 * reads, writes, and deletes, including nested resources (events
 * and evidence under another org's shipment, evidence attached
 * across organizations). A 404, not a 403, so ids cannot be
 * probed. This test is the executable form of the audit: the
 * code review findings are in docs/SECURITY.md.
 */
class TenantIsolationAuditTest extends TestCase
{
    use RefreshDatabase;

    protected User $userA;

    protected Organization $orgA;

    protected Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->userA, $this->orgA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->orgB = $this->createOrganization(['name' => 'Other Carriers Inc.']);
        $userB = User::factory()->create();
        $this->addMember($this->orgB, $userB, OrganizationRole::Owner);

        Storage::fake('private');
    }

    /**
     * Headers for organization A, the organization the test user
     * belongs to.
     *
     * @return array<string, string>
     */
    protected function headersA(): array
    {
        return $this->orgHeaders($this->orgA);
    }

    public function test_cross_tenant_shipment_access_returns_404(): void
    {
        $shipmentB = $this->createShipment($this->orgB);

        $this->withHeaders($this->headersA())->getJson('/api/v1/shipments/'.$shipmentB->getKey())->assertNotFound();
        $this->withHeaders($this->headersA())->putJson('/api/v1/shipments/'.$shipmentB->getKey(), [
            'broker_or_customer_name' => 'Hijacked Broker',
        ])->assertNotFound();
        $this->withHeaders($this->headersA())->postJson('/api/v1/shipments/'.$shipmentB->getKey().'/archive')->assertNotFound();

        $this->assertSame('Test Broker Co.', $shipmentB->fresh()->broker_or_customer_name);
    }

    public function test_cross_tenant_event_access_returns_404(): void
    {
        $shipmentB = $this->createShipment($this->orgB);
        $eventB = $this->createEvent($shipmentB);

        $this->withHeaders($this->headersA())->getJson('/api/v1/events/'.$eventB->getKey())->assertNotFound();
        $this->withHeaders($this->headersA())->putJson('/api/v1/events/'.$eventB->getKey(), [
            'notes' => 'Hijacked note',
        ])->assertNotFound();
        $this->withHeaders($this->headersA())->deleteJson('/api/v1/events/'.$eventB->getKey())->assertNotFound();

        // Nested: creating an event under another org's shipment.
        $this->withHeaders($this->headersA())->postJson('/api/v1/shipments/'.$shipmentB->getKey().'/events', [
            'event_category_id' => $this->systemCategory()->getKey(),
            'event_date' => now()->toDateString(),
        ])->assertNotFound();

        // The relation query is scoped to the organization
        // resolved by the requests above (org A), so the count
        // of org B's events needs the scope lifted.
        $this->assertSame(1, $shipmentB->events()->withoutGlobalScope('organization')->count());
    }

    public function test_cross_tenant_evidence_access_returns_404(): void
    {
        $shipmentB = $this->createShipment($this->orgB);
        $evidenceB = $this->createEvidence($this->orgB, $shipmentB);
        Storage::disk('private')->put($evidenceB->storage_key, 'fake-pdf-bytes');

        $this->withHeaders($this->headersA())->getJson('/api/v1/evidence/'.$evidenceB->getKey())->assertNotFound();
        $this->withHeaders($this->headersA())->getJson('/api/v1/evidence/'.$evidenceB->getKey().'/download')->assertNotFound();
        $this->withHeaders($this->headersA())->getJson('/api/v1/evidence/'.$evidenceB->getKey().'/preview')->assertNotFound();
        $this->withHeaders($this->headersA())->putJson('/api/v1/evidence/'.$evidenceB->getKey().'/verification', [
            'verification_status' => 'verified',
        ])->assertNotFound();
        $this->withHeaders($this->headersA())->deleteJson('/api/v1/evidence/'.$evidenceB->getKey())->assertNotFound();

        $this->assertNotNull($evidenceB->fresh());
    }

    public function test_cross_tenant_claim_access_returns_404(): void
    {
        $shipmentB = $this->createShipment($this->orgB);
        $claimB = $this->createClaim($this->orgB, $shipmentB);

        $this->withHeaders($this->headersA())->getJson('/api/v1/claims/'.$claimB->getKey())->assertNotFound();
        $this->withHeaders($this->headersA())->putJson('/api/v1/claims/'.$claimB->getKey(), [
            'requested_amount' => 99999,
        ])->assertNotFound();
        $this->withHeaders($this->headersA())->postJson('/api/v1/claims/'.$claimB->getKey().'/transition', [
            'to_status' => 'under_review',
        ])->assertNotFound();
        $this->withHeaders($this->headersA())->getJson('/api/v1/claims/'.$claimB->getKey().'/readiness')->assertNotFound();
        $this->withHeaders($this->headersA())->getJson('/api/v1/claims/'.$claimB->getKey().'/history')->assertNotFound();
        $this->withHeaders($this->headersA())->getJson('/api/v1/claims/'.$claimB->getKey().'/evidence')->assertNotFound();

        $this->assertSame(250.00, (float) $claimB->fresh()->requested_amount);
    }

    public function test_cross_tenant_evidence_cannot_be_attached_to_a_claim(): void
    {
        $shipmentA = $this->createShipment($this->orgA);
        $claimA = $this->createClaim($this->orgA, $shipmentA);
        $shipmentB = $this->createShipment($this->orgB);
        $evidenceB = $this->createEvidence($this->orgB, $shipmentB);

        // Evidence from another organization is not even a valid
        // choice for this organization: 422, and nothing attaches.
        $this->withHeaders($this->headersA())->postJson('/api/v1/claims/'.$claimA->getKey().'/evidence', [
            'evidence_file_id' => $evidenceB->getKey(),
        ])->assertUnprocessable();

        $this->assertSame(0, $claimA->evidenceFiles()->count());

        // Detaching another org's evidence from your own claim.
        $this->withHeaders($this->headersA())
            ->deleteJson('/api/v1/claims/'.$claimA->getKey().'/evidence/'.$evidenceB->getKey())
            ->assertNotFound();
    }

    public function test_cross_tenant_deadline_access_returns_404(): void
    {
        $shipmentB = $this->createShipment($this->orgB);
        $claimB = $this->createClaim($this->orgB, $shipmentB);
        $deadlineB = ClaimDeadline::create([
            'organization_id' => $this->orgB->getKey(),
            'claim_id' => $claimB->getKey(),
            'deadline_at' => now()->addDays(5),
            'timezone' => 'UTC',
            'source_reference' => 'Test deadline',
        ]);

        $this->withHeaders($this->headersA())->getJson('/api/v1/deadlines/'.$deadlineB->getKey())->assertNotFound();
        $this->withHeaders($this->headersA())->putJson('/api/v1/deadlines/'.$deadlineB->getKey(), [
            'deadline_at' => now()->addDays(99)->toIso8601String(),
        ])->assertNotFound();
    }

    public function test_cross_tenant_package_download_returns_404(): void
    {
        $shipmentB = $this->createShipment($this->orgB);
        $claimB = $this->createClaim($this->orgB, $shipmentB);
        $documentB = GeneratedDocument::create([
            'organization_id' => $this->orgB->getKey(),
            'claim_id' => $claimB->getKey(),
            'storage_key' => 'packages/'.$this->orgB->getKey().'/'.$claimB->getKey().'/test.pdf',
            'disk' => 'private',
            'file_size' => 10,
            'status' => GeneratedDocumentStatus::Ready,
            'generated_at' => now(),
        ]);
        Storage::disk('private')->put($documentB->storage_key, 'fake-pdf-bytes');

        $this->withHeaders($this->headersA())
            ->getJson('/api/v1/documents/'.$documentB->getKey().'/download')
            ->assertNotFound();
    }

    public function test_cross_tenant_supporting_records_return_404(): void
    {
        $categoryB = EventCategory::create([
            'organization_id' => $this->orgB->getKey(),
            'slug' => 'org-b-category',
            'label' => 'Org B Category',
        ]);
        $requirementB = EvidenceRequirement::create([
            'organization_id' => $this->orgB->getKey(),
            'event_category_id' => $categoryB->getKey(),
            'document_category' => EvidenceDocumentCategory::BillOfLading,
            'is_required' => true,
            'created_by' => $this->userA->id,
        ]);
        $carrierB = $this->createCarrier($this->orgB);
        $fieldB = CustomField::create([
            'organization_id' => $this->orgB->getKey(),
            'resource_type' => 'shipment',
            'field_key' => 'custom_b',
            'label' => 'Org B field',
            'field_type' => 'text',
        ]);
        $settingB = DeadlineSetting::create([
            'organization_id' => $this->orgB->getKey(),
            'scope_type' => 'global',
            'scope_value' => null,
            'submit_within_days' => 30,
            'reminder_days_before' => [7],
        ]);
        $templateB = ExportTemplate::create([
            'organization_id' => $this->orgB->getKey(),
            'resource_type' => 'shipments',
            'name' => 'Org B template',
            'columns' => ['internal_reference'],
        ]);
        $evidenceTemplateB = EvidenceTemplate::create([
            'organization_id' => $this->orgB->getKey(),
            'name' => 'Org B evidence template',
        ]);

        $this->withHeaders($this->headersA())->putJson('/api/v1/event-categories/'.$categoryB->getKey(), [
            'label' => 'Hijacked',
        ])->assertNotFound();
        $this->withHeaders($this->headersA())->putJson('/api/v1/evidence-requirements/'.$requirementB->getKey(), [
            'is_required' => false,
        ])->assertNotFound();
        $this->withHeaders($this->headersA())->deleteJson('/api/v1/evidence-requirements/'.$requirementB->getKey())->assertNotFound();
        $this->withHeaders($this->headersA())->putJson('/api/v1/carriers/'.$carrierB->getKey(), [
            'name' => 'Hijacked Carrier',
        ])->assertNotFound();
        $this->withHeaders($this->headersA())->getJson('/api/v1/custom-fields/'.$fieldB->getKey())->assertNotFound();
        $this->withHeaders($this->headersA())->getJson('/api/v1/deadline-settings/'.$settingB->getKey())->assertNotFound();
        $this->withHeaders($this->headersA())->getJson('/api/v1/export-templates/'.$templateB->getKey())->assertNotFound();
        $this->withHeaders($this->headersA())->getJson('/api/v1/evidence-templates/'.$evidenceTemplateB->getKey())->assertNotFound();
    }

    public function test_cross_tenant_saved_views_and_notifications_return_404(): void
    {
        $userB = User::factory()->create();
        $this->addMember($this->orgB, $userB, OrganizationRole::Viewer);

        $viewB = SavedView::create([
            'organization_id' => $this->orgB->getKey(),
            'user_id' => $userB->id,
            'resource_type' => 'shipments',
            'name' => 'Org B view',
            'filters' => [],
        ]);
        $notificationB = Notification::create([
            'organization_id' => $this->orgB->getKey(),
            'user_id' => $userB->id,
            'type' => NotificationType::ClaimCreated,
            'title' => 'Org B notification',
            'body' => 'Body',
        ]);

        $this->withHeaders($this->headersA())->putJson('/api/v1/saved-views/'.$viewB->getKey(), [
            'name' => 'Hijacked view',
        ])->assertNotFound();
        $this->withHeaders($this->headersA())->deleteJson('/api/v1/saved-views/'.$viewB->getKey())->assertNotFound();
        $this->withHeaders($this->headersA())->postJson('/api/v1/notifications/'.$notificationB->getKey().'/read')->assertNotFound();
    }

    public function test_a_non_member_cannot_view_an_organization(): void
    {
        $this->withHeaders($this->headersA())
            ->getJson('/api/v1/organizations/'.$this->orgB->getKey())
            ->assertForbidden();
    }
}
