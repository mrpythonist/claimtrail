<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EvidenceFile;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

    protected function pdfFile(string $name = 'bill-of-lading.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    public function test_shipment_create_and_update_are_audited(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments', [
                'internal_reference' => 'CT-AUDIT-001',
                'broker_or_customer_name' => 'Audit Broker Co.',
            ]);

        $response->assertCreated();
        $shipmentId = $response->json('data.id');

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson("/api/v1/shipments/{$shipmentId}", [
                'broker_or_customer_name' => 'Renamed Broker Co.',
            ])
            ->assertOk();

        $created = AuditLog::where('action', 'shipment.created')
            ->where('entity_id', $shipmentId)
            ->firstOrFail();

        $this->assertSame('Shipment', $created->entity_type);
        $this->assertSame($user->id, $created->actor_id);
        $this->assertSame('user', $created->actor_type->value);
        $this->assertSame('CT-AUDIT-001', $created->after['internal_reference']);
        $this->assertNull($created->before);

        $updated = AuditLog::where('action', 'shipment.updated')
            ->where('entity_id', $shipmentId)
            ->firstOrFail();

        $this->assertSame('Audit Broker Co.', $updated->before['broker_or_customer_name']);
        $this->assertSame('Renamed Broker Co.', $updated->after['broker_or_customer_name']);
    }

    public function test_event_creation_is_audited(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);
        $shipment = $this->createShipment($organization);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/shipments/{$shipment->getKey()}/events", [
                'event_category_id' => $this->systemCategory()->getKey(),
                'event_date' => now()->toDateString(),
                'estimated_amount' => 150.00,
            ]);

        $response->assertCreated();
        $eventId = $response->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->getKey(),
            'action' => 'shipment_event.created',
            'entity_type' => 'ShipmentEvent',
            'entity_id' => (string) $eventId,
        ]);
    }

    public function test_evidence_upload_and_delete_are_audited_without_storage_keys(): void
    {
        Storage::fake('private');
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/evidence', [
                'shipment_id' => $shipment->getKey(),
                'document_category' => 'bill_of_lading',
                'file' => $this->pdfFile(),
            ]);

        $response->assertCreated();
        $evidenceId = $response->json('data.id');

        $created = AuditLog::where('action', 'evidence_file.created')
            ->where('entity_id', (string) $evidenceId)
            ->firstOrFail();

        $this->assertArrayNotHasKey('storage_key', $created->after);
        $this->assertArrayNotHasKey('disk', $created->after);
        $this->assertSame('bill-of-lading.pdf', $created->after['original_filename']);

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson("/api/v1/evidence/{$evidenceId}")
            ->assertOk();

        $deleted = AuditLog::where('action', 'evidence_file.deleted')
            ->where('entity_id', (string) $evidenceId)
            ->firstOrFail();

        $this->assertSame('bill-of-lading.pdf', $deleted->before['original_filename']);
        $this->assertNull($deleted->after);
    }

    public function test_claim_create_transition_and_payment_are_audited_once_each(): void
    {
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organization);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/claims', [
                'shipment_id' => $shipment->getKey(),
                'requested_amount' => 250.00,
            ]);

        $response->assertCreated();
        $claimId = $response->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'claim.created',
            'entity_type' => 'Claim',
            'entity_id' => $claimId,
        ]);

        // Walk to submitted, then accept, then pay.
        foreach (['under_review', 'ready_to_submit', 'submitted'] as $step) {
            $this->withHeaders($this->orgHeaders($organization))
                ->postJson("/api/v1/claims/{$claimId}/transition", ['to_status' => $step])
                ->assertOk();
        }

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claimId}/transition", [
                'to_status' => 'accepted',
                'accepted_amount' => 250.00,
            ])
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claimId}/record-payment", [
                'recovered_amount' => 250.00,
                'payment_date' => now()->toDateString(),
                'payment_reference' => 'CHK-1001',
            ])
            ->assertOk();

        // Four transitions, one audit row each: the observer is
        // suspended during transitions, so there is no duplicate
        // claim.updated row alongside claim.status_changed.
        $this->assertSame(4, AuditLog::where('action', 'claim.status_changed')
            ->where('entity_id', $claimId)->count());
        $this->assertSame(0, AuditLog::where('action', 'claim.updated')
            ->where('entity_id', $claimId)->count());

        $accepted = AuditLog::where('action', 'claim.status_changed')
            ->where('entity_id', $claimId)
            ->whereJsonContains('after->status', 'accepted')
            ->firstOrFail();

        $this->assertSame('submitted', $accepted->before['status']);
        $this->assertSame('250.00', $accepted->after['accepted_amount']);

        $payment = AuditLog::where('action', 'claim.payment_recorded')
            ->where('entity_id', $claimId)
            ->firstOrFail();

        $this->assertSame('accepted', $payment->before['status']);
        $this->assertSame('paid', $payment->after['status']);
        $this->assertSame('250.00', $payment->after['recovered_amount']);
        $this->assertSame('CHK-1001', $payment->after['payment_reference']);
    }

    public function test_invitation_lifecycle_is_audited(): void
    {
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/invitations', [
                'email' => 'invitee@example.com',
                'role' => 'dispatcher',
            ]);

        $response->assertCreated();
        $invitationId = $response->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'organization_invitation.created',
            'entity_type' => 'OrganizationInvitation',
            'entity_id' => $invitationId,
        ]);

        // Accept with a directly created invitation carrying a known token.
        $rawToken = Str::random(48);
        $invitation = OrganizationInvitation::withoutGlobalScope('organization')->firstOrFail();
        $invitation->update(['token' => hash('sha256', $rawToken)]);

        Sanctum::actingAs($invitee);
        $this->postJson('/api/v1/invitations/accept', ['token' => $rawToken])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'invitation.accepted',
            'entity_id' => $invitationId,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'member.added',
            'entity_type' => 'OrganizationMember',
        ]);

        // Revoke a second invitation.
        Sanctum::actingAs($owner);
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/invitations', [
                'email' => 'revoked@example.com',
                'role' => 'viewer',
            ])
            ->assertCreated();

        $second = OrganizationInvitation::withoutGlobalScope('organization')
            ->where('email', 'revoked@example.com')
            ->firstOrFail();

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson("/api/v1/invitations/{$second->getKey()}")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'invitation.revoked',
            'entity_id' => (string) $second->getKey(),
        ]);
    }

    public function test_member_activation_changes_and_removal_are_audited(): void
    {
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $memberUser = User::factory()->create();
        $member = $this->addMember($organization, $memberUser, OrganizationRole::Dispatcher);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson("/api/v1/members/{$member->getKey()}", ['status' => 'inactive'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'member.deactivated',
            'entity_id' => (string) $member->getKey(),
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson("/api/v1/members/{$member->getKey()}", ['status' => 'active'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'member.activated',
            'entity_id' => (string) $member->getKey(),
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson("/api/v1/members/{$member->getKey()}")
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'member.removed',
            'entity_id' => (string) $member->getKey(),
        ]);
    }

    public function test_package_generation_is_audited(): void
    {
        Storage::fake('private');
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $shipment = $this->createShipment($organization);
        $claim = $this->createClaim($organization, $shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/claims/{$claim->getKey()}/package", [])
            ->assertAccepted();

        // The actor is the member who requested the package.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'claim_package.generated',
            'entity_type' => 'GeneratedDocument',
            'actor_type' => 'user',
            'actor_id' => $owner->id,
        ]);
    }

    public function test_audit_log_listing_is_permission_gated_and_org_scoped(): void
    {
        [$owner, $organizationA] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $shipment = $this->createShipment($organizationA);

        [, $organizationB] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->createShipment($organizationB);

        // Owner of A sees only A's rows.
        Sanctum::actingAs($owner);
        $this->withHeaders($this->orgHeaders($organizationA))
            ->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        // A viewer cannot read the audit trail at all.
        [$viewer] = $this->actingAsMemberOf(OrganizationRole::Viewer, $organizationA);
        $this->withHeaders($this->orgHeaders($organizationA))
            ->getJson('/api/v1/audit-logs')
            ->assertForbidden();
    }

    public function test_audit_log_listing_filters_work(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Admin);
        $shipment = $this->createShipment($organization);
        $this->createEvent($shipment);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/audit-logs?entity_type=Shipment')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', 'shipment.created');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/audit-logs?action=shipment_event.created')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_audit_rows_cannot_be_updated_or_deleted(): void
    {
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->createShipment($organization);

        $log = AuditLog::where('action', 'shipment.created')->firstOrFail();

        // No update or delete routes exist.
        $this->withHeaders($this->orgHeaders($organization))
            ->putJson("/api/v1/audit-logs/{$log->getKey()}", ['action' => 'forged.action'])
            ->assertNotFound();

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson("/api/v1/audit-logs/{$log->getKey()}")
            ->assertNotFound();

        // The model itself cancels updates and deletes.
        $this->assertFalse($log->update(['action' => 'forged.action']));
        $this->assertFalse($log->delete());
        $this->assertSame('shipment.created', $log->fresh()->action);
        $this->assertDatabaseHas('audit_logs', ['id' => $log->getKey()]);
    }

    public function test_correlation_id_is_recorded_on_audit_rows(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);

        $response = $this->withHeaders(array_merge(
            $this->orgHeaders($organization),
            ['X-Correlation-Id' => 'test-correlation-123'],
        ))->postJson('/api/v1/shipments', [
            'internal_reference' => 'CT-CORR-001',
            'broker_or_customer_name' => 'Correlation Broker Co.',
        ]);

        $response->assertCreated();
        $response->assertHeader('X-Correlation-Id', 'test-correlation-123');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'shipment.created',
            'correlation_id' => 'test-correlation-123',
        ]);
    }
}
