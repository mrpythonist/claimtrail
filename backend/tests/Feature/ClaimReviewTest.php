<?php

namespace Tests\Feature;

use App\ClaimStatus;
use App\Models\ClaimReviewNote;
use App\Models\ClaimStatusHistory;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaimReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reviewer_can_add_and_list_notes_on_a_claim(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment, ['status' => ClaimStatus::UnderReview]);

        $created = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/'.$claim->getKey().'/review-notes', [
                'note' => 'Rate confirmation matches the detention hours.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.note', 'Rate confirmation matches the detention hours.')
            ->assertJsonPath('data.author.name', $user->name)
            ->json('data.id');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/claims/'.$claim->getKey().'/review-notes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $created);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->getKey(),
            'action' => 'claim.review_note_added',
            'entity_id' => $claim->getKey(),
        ]);
    }

    public function test_notes_are_review_gated_and_closed_claims_take_none(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment, ['status' => ClaimStatus::UnderReview]);

        // Dispatchers can view claims but cannot review them.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/'.$claim->getKey().'/review-notes', [
                'note' => 'Not my call.',
            ])
            ->assertForbidden();

        [$manager] = $this->actingAsMemberOf(OrganizationRole::OperationsManager, $organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/'.$claim->getKey().'/review-notes', [
                'note' => '',
            ])
            ->assertUnprocessable();

        $closed = $this->createClaim($organization, $shipment, ['status' => ClaimStatus::Closed]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/'.$closed->getKey().'/review-notes', [
                'note' => 'Too late.',
            ])
            ->assertUnprocessable();
    }

    public function test_approve_moves_a_claim_to_ready_to_submit_and_records_the_comment(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment, ['status' => ClaimStatus::UnderReview]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/'.$claim->getKey().'/review', [
                'decision' => 'approve',
                'comment' => 'Evidence checks out, ready to send.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready_to_submit');

        $this->assertSame(ClaimStatus::ReadyToSubmit, $claim->fresh()->status);
        $this->assertSame(1, ClaimReviewNote::count());
        $this->assertSame('Evidence checks out, ready to send.', ClaimReviewNote::first()->note);

        $history = ClaimStatusHistory::where('claim_id', $claim->getKey())->first();
        $this->assertSame('under_review', $history->from_status);
        $this->assertSame('ready_to_submit', $history->to_status);
        $this->assertSame('Evidence checks out, ready to send.', $history->reason);
    }

    public function test_request_changes_moves_a_claim_back_and_requires_a_comment(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment, ['status' => ClaimStatus::UnderReview]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/'.$claim->getKey().'/review', [
                'decision' => 'request_changes',
            ])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/'.$claim->getKey().'/review', [
                'decision' => 'request_changes',
                'comment' => 'Missing the signed proof of delivery.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'evidence_required');

        $this->assertSame(ClaimStatus::EvidenceRequired, $claim->fresh()->status);
        $this->assertSame('Missing the signed proof of delivery.', ClaimReviewNote::first()->note);
    }

    public function test_review_rejects_claims_not_under_review_and_bad_decisions(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment, ['status' => ClaimStatus::Potential]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/'.$claim->getKey().'/review', [
                'decision' => 'approve',
                'comment' => 'Looks fine.',
            ])
            ->assertUnprocessable();

        $underReview = $this->createClaim($organization, $shipment, ['status' => ClaimStatus::UnderReview]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/'.$underReview->getKey().'/review', [
                'decision' => 'maybe',
            ])
            ->assertUnprocessable();

        $this->assertSame(ClaimStatus::UnderReview, $underReview->fresh()->status);
        $this->assertSame(0, ClaimReviewNote::count());
    }

    public function test_review_notes_are_tenant_scoped(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment, ['status' => ClaimStatus::UnderReview]);

        $orgB = $this->createOrganization(['name' => 'Other Org']);
        $this->addMember($orgB, $user, OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($orgB))
            ->getJson('/api/v1/claims/'.$claim->getKey().'/review-notes')
            ->assertNotFound();

        $this->withHeaders($this->orgHeaders($orgB))
            ->postJson('/api/v1/claims/'.$claim->getKey().'/review-notes', [
                'note' => 'Cross-org note.',
            ])
            ->assertNotFound();

        $this->assertSame(0, ClaimReviewNote::count());
    }
}
