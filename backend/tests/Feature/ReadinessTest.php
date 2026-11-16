<?php

namespace Tests\Feature;

use App\Models\ClaimRequirement;
use App\Models\ClaimStatusHistory;
use App\Models\EvidenceRequirement;
use App\Models\User;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_requirements_can_be_managed(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);
        $detention = $this->systemCategory('detention');

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/evidence-requirements', [
                'event_category_id' => $detention->getKey(),
                'document_category' => 'appointment_confirmation',
                'is_required' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.document_category', 'appointment_confirmation')
            ->assertJsonPath('data.event_category.slug', 'detention');

        $id = $response->json('data.id');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/evidence-requirements')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson("/api/v1/evidence-requirements/{$id}", [
                'is_required' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_required', false);

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson("/api/v1/evidence-requirements/{$id}")
            ->assertOk();

        $this->assertSame(0, EvidenceRequirement::count());
    }

    public function test_a_viewer_cannot_create_a_requirement(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/evidence-requirements', [
                'document_category' => 'receipt',
            ])
            ->assertForbidden();
    }

    public function test_duplicate_requirements_are_rejected(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);

        $payload = ['document_category' => 'receipt', 'is_required' => true];

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/evidence-requirements', $payload)
            ->assertCreated();

        // Same global requirement twice: the database unique index
        // cannot see null categories, so the API must catch it.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/evidence-requirements', $payload)
            ->assertUnprocessable();
    }

    public function test_readiness_counts_required_against_attached_evidence(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $detention = $this->systemCategory('detention');
        $shipment = $this->createShipment($organization);
        $event = $this->createEvent($shipment, ['event_category_id' => $detention->getKey()]);
        $claim = $this->createClaim($organization, $shipment, ['shipment_event_id' => $event->getKey()]);

        foreach (['appointment_confirmation', 'arrival_departure_record'] as $category) {
            $organization->evidenceRequirements()->create([
                'event_category_id' => $detention->getKey(),
                'document_category' => $category,
                'is_required' => true,
            ]);
        }

        // Nothing attached yet: 0 percent, not started.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson("/api/v1/claims/{$claim->getKey()}/readiness")
            ->assertOk()
            ->assertJsonPath('data.readiness_percentage', 0)
            ->assertJsonPath('data.readiness_status', 'not_started')
            ->assertJsonPath('data.missing.0.value', 'appointment_confirmation');

        // Attach one of the two required categories: 50 percent, partial.
        $evidence = $this->createEvidence($organization, $shipment, [
            'document_category' => 'appointment_confirmation',
        ]);
        $claim->evidenceFiles()->attach($evidence->getKey());

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson("/api/v1/claims/{$claim->getKey()}/readiness")
            ->assertOk()
            ->assertJsonPath('data.readiness_percentage', 50)
            ->assertJsonPath('data.readiness_status', 'partial')
            ->assertJsonPath('data.missing.0.value', 'arrival_departure_record')
            ->assertJsonPath('data.collected.0.value', 'appointment_confirmation');
    }

    public function test_readiness_is_100_with_no_requirements_configured(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson("/api/v1/claims/{$claim->getKey()}/readiness")
            ->assertOk()
            ->assertJsonPath('data.readiness_percentage', 100)
            ->assertJsonPath('data.readiness_status', 'ready')
            ->assertJsonPath('data.has_requirements', false);
    }

    public function test_optional_requirements_do_not_block_readiness(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $organization->evidenceRequirements()->create([
            'event_category_id' => null,
            'document_category' => 'receipt',
            'is_required' => true,
        ]);
        $organization->evidenceRequirements()->create([
            'event_category_id' => null,
            'document_category' => 'driver_photo',
            'is_required' => false,
        ]);

        $evidence = $this->createEvidence($organization, $shipment, [
            'document_category' => 'receipt',
        ]);
        $claim->evidenceFiles()->attach($evidence->getKey());

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson("/api/v1/claims/{$claim->getKey()}/readiness")
            ->assertOk()
            ->assertJsonPath('data.readiness_percentage', 100)
            ->assertJsonPath('data.readiness_status', 'ready')
            ->assertJsonPath('data.optional.0.value', 'driver_photo');
    }

    public function test_a_readiness_snapshot_is_written_when_marking_ready(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $organization->evidenceRequirements()->create([
            'event_category_id' => null,
            'document_category' => 'receipt',
            'is_required' => true,
        ]);

        foreach (['under_review', 'ready_to_submit'] as $step) {
            $this->withHeaders($this->orgHeaders($organization))
                ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                    'to_status' => $step,
                ])
                ->assertOk();
        }

        $rows = ClaimRequirement::where('claim_id', $claim->getKey())->get();

        $this->assertSame(1, $rows->count());
        $this->assertSame('receipt', $rows->first()->document_category->value);
        $this->assertFalse($rows->first()->is_fulfilled);
        $this->assertNotNull($rows->first()->snapshot_at);
    }

    public function test_override_requires_a_reason_and_review_permission(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $organization->evidenceRequirements()->create([
            'event_category_id' => null,
            'document_category' => 'receipt',
            'is_required' => true,
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/readiness/override", [
                'reason' => '',
            ])
            ->assertUnprocessable();

        $billing = User::factory()->create();
        $this->addMember($organization, $billing, OrganizationRole::BillingStaff);
        Sanctum::actingAs($billing);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/readiness/override", [
                'reason' => 'Broker confirmed by phone.',
            ])
            ->assertForbidden();
    }

    public function test_override_records_the_reviewer_and_reason(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $organization->evidenceRequirements()->create([
            'event_category_id' => null,
            'document_category' => 'receipt',
            'is_required' => true,
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/readiness/override", [
                'reason' => 'Broker confirmed receipt by phone; receipt to follow.',
            ])
            ->assertOk()
            ->assertJsonPath('data.is_overridden', true);

        $row = ClaimRequirement::where('claim_id', $claim->getKey())->firstOrFail();

        $this->assertSame($user->id, $row->overridden_by);
        $this->assertSame('Broker confirmed receipt by phone; receipt to follow.', $row->override_reason);
        $this->assertNotNull($row->overridden_at);

        $this->assertSame(1, ClaimStatusHistory::where('claim_id', $claim->getKey())
            ->where('metadata->action', 'readiness_override')
            ->count());
    }
}
