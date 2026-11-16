<?php

namespace Tests\Feature;

use App\Models\Claim;
use App\Models\ClaimStatusHistory;
use App\Models\User;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function pdfFile(string $name = 'evidence.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    /**
     * Walk a claim to the given status through the API, as the owner.
     */
    protected function walkClaimTo(Claim $claim, string $status, array $extra = []): void
    {
        $path = [
            'under_review' => ['under_review'],
            'ready_to_submit' => ['under_review', 'ready_to_submit'],
            'submitted' => ['under_review', 'ready_to_submit', 'submitted'],
        ][$status] ?? [];

        foreach ($path as $step) {
            $this->withHeaders($this->orgHeaders($claim->organization))
                ->postJson("/api/v1/claims/{$claim->getKey()}/transition", array_merge([
                    'to_status' => $step,
                ], $step === $status ? $extra : []))
                ->assertOk();
        }
    }

    public function test_a_claim_can_be_created_for_a_shipment(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);
        $event = $this->createEvent($shipment);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'shipment_event_id' => $event->getKey(),
                'requested_amount' => 250.00,
                'description' => 'Four hours detention at the receiver.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'potential')
            ->assertJsonPath('data.claim_category', 'detention')
            ->assertJsonPath('data.requested_amount', '250.00')
            ->assertJsonPath('data.accepted_amount', null)
            ->assertJsonPath('data.recovered_amount', null);

        $this->assertMatchesRegularExpression(
            '/^CLM-\d{4}-\d{4}$/',
            $response->json('data.claim_reference')
        );
    }

    public function test_claim_references_increment_within_an_organization(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);
        $shipment = $this->createShipment($organization);

        $first = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 100.00,
            ])->json('data.claim_reference');

        $second = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 200.00,
            ])->json('data.claim_reference');

        $this->assertNotSame($first, $second);
        $this->assertSame((int) substr($first, -4) + 1, (int) substr($second, -4));
    }

    public function test_claim_creation_rejects_an_event_from_another_shipment(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);
        $shipment = $this->createShipment($organization);
        $otherShipment = $this->createShipment($organization);
        $event = $this->createEvent($otherShipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'shipment_event_id' => $event->getKey(),
                'requested_amount' => 100.00,
            ])
            ->assertUnprocessable();

        $this->assertSame(0, Claim::count());
    }

    public function test_a_viewer_cannot_create_a_claim(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);
        $shipment = $this->createShipment($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 100.00,
            ])
            ->assertForbidden();
    }

    public function test_a_dispatcher_cannot_create_a_claim(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 100.00,
            ])
            ->assertForbidden();
    }

    public function test_claims_are_listed_with_status_and_search_filters(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $this->createClaim($organization, $shipment, ['claim_reference' => 'CLM-2026-0001']);
        $this->createClaim($organization, $shipment, [
            'claim_reference' => 'CLM-2026-0002',
            'status' => 'under_review',
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/claims?status=under_review')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.claim_reference', 'CLM-2026-0002');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/claims?search=CLM-2026-0001')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_a_user_cannot_read_another_organizations_claim(): void
    {
        [, $organizationA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organizationA);
        $claim = $this->createClaim($organizationA, $shipment);

        [, $organizationB] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organizationB))
            ->getJson("/api/v1/claims/{$claim->getKey()}")
            ->assertNotFound();
    }

    public function test_a_claim_moves_through_review_and_records_history(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $this->walkClaimTo($claim, 'ready_to_submit');

        $this->assertSame('ready_to_submit', $claim->fresh()->status->value);
        $this->assertSame(2, ClaimStatusHistory::where('claim_id', $claim->getKey())->count());
        $this->assertSame(
            'potential',
            ClaimStatusHistory::where('claim_id', $claim->getKey())->oldest()->first()->from_status
        );
    }

    public function test_an_invalid_transition_is_rejected(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'submitted',
            ])
            ->assertUnprocessable();

        $this->assertSame('potential', $claim->fresh()->status->value);
    }

    public function test_submitting_a_claim_stamps_submitted_at(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $this->walkClaimTo($claim, 'submitted');

        $this->assertSame('submitted', $claim->fresh()->status->value);
        $this->assertNotNull($claim->fresh()->submitted_at);
    }

    public function test_accepting_requires_an_explicit_accepted_amount(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);
        $this->walkClaimTo($claim, 'submitted');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'accepted',
            ])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'accepted',
                'accepted_amount' => 250.00,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.accepted_amount', '250.00');

        $this->assertNotNull($claim->fresh()->approved_at);
        $this->assertNull($claim->fresh()->recovered_amount);
    }

    public function test_partially_accepted_requires_less_than_requested(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment, ['requested_amount' => 250.00]);
        $this->walkClaimTo($claim, 'submitted');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'partially_accepted',
                'accepted_amount' => 250.00,
            ])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'partially_accepted',
                'accepted_amount' => 150.00,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'partially_accepted');
    }

    public function test_rejecting_requires_a_reason(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);
        $this->walkClaimTo($claim, 'submitted');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'rejected',
            ])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'rejected',
                'rejection_reason' => 'Broker says detention was not in the rate confirmation.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertNotNull($claim->fresh()->rejected_at);
    }

    public function test_paid_is_unreachable_through_the_transition_endpoint(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);
        $this->walkClaimTo($claim, 'submitted');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'accepted',
                'accepted_amount' => 250.00,
            ])
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'paid',
            ])
            ->assertForbidden();

        $this->assertSame('accepted', $claim->fresh()->status->value);
    }

    public function test_a_rejected_claim_can_return_to_review(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);
        $this->walkClaimTo($claim, 'submitted');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'rejected',
                'rejection_reason' => 'Missing paperwork.',
            ])
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'under_review',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'under_review');
    }

    public function test_payment_recording_moves_an_accepted_claim_to_paid(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);
        $this->walkClaimTo($claim, 'submitted');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'accepted',
                'accepted_amount' => 250.00,
            ])
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/record-payment", [
                'recovered_amount' => 250.00,
                'payment_date' => now()->toDateString(),
                'payment_reference' => 'ACH-9981',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.recovered_amount', '250.00')
            ->assertJsonPath('data.payment_reference', 'ACH-9981');

        $this->assertNotNull($claim->fresh()->paid_at);
    }

    public function test_payment_recording_rejects_unaccepted_claims(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/record-payment", [
                'recovered_amount' => 250.00,
                'payment_date' => now()->toDateString(),
            ])
            ->assertForbidden();
    }

    public function test_payment_recording_requires_a_positive_amount(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);
        $this->walkClaimTo($claim, 'submitted');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'accepted',
                'accepted_amount' => 250.00,
            ])
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/record-payment", [
                'recovered_amount' => 0,
                'payment_date' => now()->toDateString(),
            ])
            ->assertUnprocessable();
    }

    public function test_closing_a_pre_submission_claim_requires_a_reason(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'closed',
            ])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'closed',
                'reason' => 'Broker paid the detention directly.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');
    }

    public function test_a_closed_claim_is_terminal(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment, ['status' => 'closed']);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'under_review',
            ])
            ->assertUnprocessable();
    }

    public function test_evidence_can_be_attached_and_detached(): void
    {
        Storage::fake('private');
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);
        $evidence = $this->createEvidence($organization, $shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/evidence", [
                'evidence_file_id' => $evidence->getKey(),
            ])
            ->assertCreated();

        $this->assertSame(1, $claim->fresh()->evidenceFiles()->count());

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson("/api/v1/claims/{$claim->getKey()}/evidence")
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson("/api/v1/claims/{$claim->getKey()}/evidence/{$evidence->getKey()}")
            ->assertOk();

        $this->assertSame(0, $claim->fresh()->evidenceFiles()->count());
        $this->assertNotNull($evidence->fresh());
    }

    public function test_evidence_from_another_shipment_cannot_be_attached(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $otherShipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);
        $evidence = $this->createEvidence($organization, $otherShipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/evidence", [
                'evidence_file_id' => $evidence->getKey(),
            ])
            ->assertUnprocessable();
    }

    public function test_cross_org_evidence_cannot_be_attached(): void
    {
        [, $organizationA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipmentA = $this->createShipment($organizationA);
        $claim = $this->createClaim($organizationA, $shipmentA);

        $organizationB = $this->createOrganization();
        $shipmentB = $this->createShipment($organizationB);
        $evidence = $this->createEvidence($organizationB, $shipmentB);

        $this->withHeaders($this->orgHeaders($organizationA))
            ->postJson("/api/v1/claims/{$claim->getKey()}/evidence", [
                'evidence_file_id' => $evidence->getKey(),
            ])
            ->assertUnprocessable();
    }

    public function test_a_submitted_claim_freezes_amount_but_allows_notes(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);
        $this->walkClaimTo($claim, 'submitted');

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson("/api/v1/claims/{$claim->getKey()}", [
                'requested_amount' => 999.00,
            ])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson("/api/v1/claims/{$claim->getKey()}", [
                'internal_notes' => 'Broker called to confirm receipt.',
            ])
            ->assertOk()
            ->assertJsonPath('data.internal_notes', 'Broker called to confirm receipt.');
    }

    public function test_billing_staff_can_submit_but_cannot_review(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $billing = User::factory()->create();
        $this->addMember($organization, $billing, OrganizationRole::BillingStaff);

        Sanctum::actingAs($billing);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'under_review',
            ])
            ->assertOk();

        // Marking ready is a review decision: billing staff may not do it.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'ready_to_submit',
            ])
            ->assertForbidden();

        // But once a reviewer marks the claim ready, billing staff
        // can submit it: submission is theirs to do.
        $claim->update(['status' => 'ready_to_submit']);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/transition", [
                'to_status' => 'submitted',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');
    }

    public function test_an_operations_manager_can_review_and_submit(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $this->walkClaimTo($claim, 'submitted');

        $this->assertSame('submitted', $claim->fresh()->status->value);
    }
}
