<?php

namespace Tests\Feature;

use App\ClaimStatus;
use App\Models\Claim;
use App\Models\ClaimStatusHistory;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BulkClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_transition_moves_several_claims(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);
        $first = $this->createClaim($organization, $shipment, ['status' => ClaimStatus::Potential]);
        $second = $this->createClaim($organization, $shipment, ['status' => ClaimStatus::Potential]);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/bulk-transition', [
                'ids' => [$first->getKey(), $second->getKey()],
                'to_status' => 'under_review',
            ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data.updated')
            ->assertJsonPath('data.failed', []);

        $this->assertSame(ClaimStatus::UnderReview, $first->fresh()->status);
        $this->assertSame(ClaimStatus::UnderReview, $second->fresh()->status);
        $this->assertSame(2, ClaimStatusHistory::count());
    }

    public function test_bulk_transition_reports_per_claim_failures(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);
        $movable = $this->createClaim($organization, $shipment, ['status' => ClaimStatus::Potential]);
        // Submitted claims cannot move back to under review.
        $stuck = $this->createClaim($organization, $shipment, ['status' => ClaimStatus::Submitted]);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/bulk-transition', [
                'ids' => [$movable->getKey(), $stuck->getKey()],
                'to_status' => 'under_review',
            ]);

        $response->assertOk()
            ->assertJsonCount(1, 'data.updated')
            ->assertJsonCount(1, 'data.failed');

        $this->assertSame(ClaimStatus::UnderReview, $movable->fresh()->status);
        $this->assertSame(ClaimStatus::Submitted, $stuck->fresh()->status);
    }

    public function test_bulk_transition_to_paid_is_rejected(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment, ['status' => ClaimStatus::Accepted]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/bulk-transition', [
                'ids' => [$claim->getKey()],
                'to_status' => 'paid',
            ])
            ->assertUnprocessable();

        $this->assertSame(ClaimStatus::Accepted, $claim->fresh()->status);
    }

    public function test_bulk_transition_applies_per_transition_data_rules(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment, [
            'status' => ClaimStatus::Submitted,
            'requested_amount' => 500.00,
        ]);

        // Accepting without an amount fails for that claim only.
        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/bulk-transition', [
                'ids' => [$claim->getKey()],
                'to_status' => 'accepted',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.updated', [])
            ->assertJsonCount(1, 'data.failed');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/bulk-transition', [
                'ids' => [$claim->getKey()],
                'to_status' => 'accepted',
                'accepted_amount' => 450.00,
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.updated');

        $this->assertSame(ClaimStatus::Accepted, $claim->fresh()->status);
        $this->assertSame('450.00', $claim->fresh()->accepted_amount);
    }

    public function test_bulk_delete_removes_only_pre_submission_claims(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);
        $draft = $this->createClaim($organization, $shipment, ['status' => ClaimStatus::Potential]);
        $review = $this->createClaim($organization, $shipment, ['status' => ClaimStatus::UnderReview]);
        $submitted = $this->createClaim($organization, $shipment, ['status' => ClaimStatus::Submitted]);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/bulk-delete', [
                'ids' => [$draft->getKey(), $review->getKey(), $submitted->getKey()],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.deleted', [$draft->getKey(), $review->getKey()])
            ->assertJsonCount(1, 'data.failed');

        $this->assertNull(Claim::find($draft->getKey()));
        $this->assertNull(Claim::find($review->getKey()));
        $this->assertNotNull(Claim::find($submitted->getKey()));
        // The deleted claims' history rows are gone with them.
        $this->assertSame(0, ClaimStatusHistory::where('claim_id', $draft->getKey())->count());
    }

    public function test_bulk_claim_operations_are_permission_gated_and_tenant_scoped(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        // Dispatchers hold neither review nor delete permissions.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/bulk-transition', [
                'ids' => [$claim->getKey()],
                'to_status' => 'under_review',
            ])
            ->assertOk()
            ->assertJsonPath('data.updated', [])
            ->assertJsonCount(1, 'data.failed');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/bulk-delete', [
                'ids' => [$claim->getKey()],
            ])
            ->assertForbidden();

        // A claim from another organization is reported as not found.
        $otherOrg = $this->createOrganization(['name' => 'Other Org']);
        $foreignShipment = $this->createShipment($otherOrg);
        $foreign = $this->createClaim($otherOrg, $foreignShipment);
        [$owner] = $this->actingAsMemberOf(OrganizationRole::Owner, $organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/bulk-delete', [
                'ids' => [$foreign->getKey()],
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted', [])
            ->assertJsonCount(1, 'data.failed');

        $this->assertNotNull(
            Claim::withoutGlobalScope('organization')->find($foreign->getKey())
        );
    }

    public function test_bulk_transition_is_audit_logged_once_per_claim(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment, ['status' => ClaimStatus::Potential]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims/bulk-transition', [
                'ids' => [$claim->getKey()],
                'to_status' => 'under_review',
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->getKey(),
            'action' => 'claim.status_changed',
            'entity_type' => 'Claim',
            'entity_id' => $claim->getKey(),
        ]);
    }
}
