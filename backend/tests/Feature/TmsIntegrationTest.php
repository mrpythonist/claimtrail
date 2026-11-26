<?php

namespace Tests\Feature;

use App\Models\EvidenceFile;
use App\Models\Shipment;
use App\Models\TmsIntegration;
use App\Models\TmsSyncRun;
use App\OrganizationRole;
use App\Services\TmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TMS integrations (v1.3.2).
 *
 * A generic provider adapter with a mock provider and
 * Trimble TMW.Suite modelled schema-only. Syncs apply
 * provider records to shipments, statuses, appointments,
 * and evidence; re-runs dedupe. No live TMS is involved
 * anywhere: the mock returns dummy data and the Trimble
 * provider fails every fetch with live_unavailable.
 */
class TmsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    protected function createMockIntegration($organization, array $overrides = []): array
    {
        return $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations', array_merge([
                'provider' => 'mock',
                'name' => 'Mock TMS',
            ], $overrides))
            ->assertCreated()
            ->json('data');
    }

    public function test_providers_catalogue_lists_mock_and_trimble_as_not_live(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $providers = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/tms/providers')
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $providers);
        $this->assertSame(['mock', 'trimble-tmw'], array_column($providers, 'name'));

        foreach ($providers as $provider) {
            $this->assertFalse($provider['live_available']);
        }
    }

    public function test_mock_integration_can_be_created_listed_and_read(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $created = $this->createMockIntegration($organization);

        $this->assertSame('mock', $created['provider']);
        $this->assertSame('Mock TMS', $created['provider_label']);
        $this->assertFalse($created['live_available']);
        $this->assertFalse($created['has_credentials']);
        $this->assertArrayNotHasKey('credentials', $created);
        $this->assertTrue($created['settings']['sync_shipments']);
        $this->assertFalse($created['settings']['auto_sync']);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/tms/integrations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mock TMS');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/tms/integrations/'.$created['id'])
            ->assertOk()
            ->assertJsonPath('data.id', $created['id']);
    }

    public function test_unknown_provider_is_rejected(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations', [
                'provider' => 'acme-tms',
                'name' => 'Acme',
            ])
            ->assertUnprocessable();
    }

    public function test_trimble_integration_needs_credentials(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations', [
                'provider' => 'trimble-tmw',
                'name' => 'TMW Production',
            ])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations', [
                'provider' => 'trimble-tmw',
                'name' => 'TMW Production',
                'credentials' => ['client_id' => 'cid'],
            ])
            ->assertUnprocessable();

        $created = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations', [
                'provider' => 'trimble-tmw',
                'name' => 'TMW Production',
                'credentials' => [
                    'client_id' => 'cid',
                    'client_secret' => 'secret',
                    'account_id' => 'acct',
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame(['client_id', 'client_secret', 'account_id'], $created['credential_fields_present']);
        $this->assertArrayNotHasKey('credentials', $created);
    }

    public function test_integration_names_are_unique_per_organization(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->createMockIntegration($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations', [
                'provider' => 'mock',
                'name' => 'Mock TMS',
            ])
            ->assertUnprocessable();
    }

    public function test_member_without_manage_permission_cannot_create_integrations_but_can_list(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::BillingStaff);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations', [
                'provider' => 'mock',
                'name' => 'Mock TMS',
            ])
            ->assertForbidden();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/tms/integrations')
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/tms/providers')
            ->assertOk();
    }

    public function test_integration_can_be_disabled_and_deleted(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $created = $this->createMockIntegration($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/tms/integrations/'.$created['id'], ['status' => 'disabled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'disabled');

        $this->withHeaders($this->orgHeaders($organization))
            ->deleteJson('/api/v1/tms/integrations/'.$created['id'])
            ->assertOk();

        $this->assertDatabaseMissing('tms_integrations', ['id' => $created['id']]);
    }

    public function test_test_connection_reports_mock_as_connected_but_not_live(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $created = $this->createMockIntegration($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations/'.$created['id'].'/test')
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.live_available', false);
    }

    public function test_test_connection_reports_trimble_as_well_formed_but_not_live(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $created = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations', [
                'provider' => 'trimble-tmw',
                'name' => 'TMW Production',
                'credentials' => [
                    'client_id' => 'cid',
                    'client_secret' => 'secret',
                    'account_id' => 'acct',
                ],
            ])
            ->assertCreated()
            ->json('data');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations/'.$created['id'].'/test')
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.live_available', false)
            ->assertJsonPath('data.token', null);
    }

    public function test_full_mock_sync_creates_shipments_and_documents(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $admin = \App\Models\User::factory()->create();
        $this->addMember($organization, $admin, OrganizationRole::Admin);

        $created = $this->createMockIntegration($organization);

        $run = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations/'.$created['id'].'/sync', ['kind' => 'full'])
            ->assertOk()
            ->json('data');

        $this->assertSame('completed', $run['status']);
        $this->assertSame(3, $run['summary']['fetched']);
        $this->assertSame(3, $run['summary']['created']);
        $this->assertSame(3, $run['summary']['documents_created']);

        $this->assertDatabaseHas('shipments', [
            'organization_id' => $organization->getKey(),
            'external_load_reference' => 'TMW-MOCK-1001',
            'internal_reference' => 'CT-TMS-1',
            'broker_or_customer_name' => 'Heartland Foods',
            'status' => 'in_transit',
        ]);
        $this->assertDatabaseHas('shipments', [
            'external_load_reference' => 'TMW-MOCK-1002',
            'status' => 'delivered',
        ]);
        $this->assertDatabaseHas('shipments', [
            'external_load_reference' => 'TMW-MOCK-1003',
            'status' => 'planned',
        ]);

        $shipment = Shipment::forOrganization($organization)
            ->where('external_load_reference', 'TMW-MOCK-1001')
            ->firstOrFail();
        $this->assertSame('2026-11-27 09:00:00', $shipment->pickup_appointment->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-28 14:00:00', $shipment->delivery_appointment->format('Y-m-d H:i:s'));

        $this->assertSame(3, EvidenceFile::forOrganization($organization)->count());
        $this->assertDatabaseHas('evidence_files', [
            'shipment_id' => $shipment->getKey(),
            'original_filename' => 'pod-1001.pdf',
            'document_category' => 'proof_of_delivery',
        ]);

        // The other member who can manage integrations is notified; the actor is not.
        $this->assertDatabaseHas('notifications', [
            'organization_id' => $organization->getKey(),
            'user_id' => $admin->id,
            'type' => 'tms.sync_completed',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'organization_id' => $organization->getKey(),
            'user_id' => $user->id,
            'type' => 'tms.sync_completed',
        ]);
    }

    public function test_repeated_mock_sync_dedupes_and_creates_nothing_new(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $created = $this->createMockIntegration($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations/'.$created['id'].'/sync', ['kind' => 'full'])
            ->assertOk();

        $second = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations/'.$created['id'].'/sync', ['kind' => 'full'])
            ->assertOk()
            ->json('data');

        $this->assertSame('completed', $second['status']);
        $this->assertSame(0, $second['summary']['created']);
        $this->assertSame(0, $second['summary']['updated']);
        $this->assertSame(0, $second['summary']['documents_created']);
        $this->assertSame(3, Shipment::forOrganization($organization)->count());
        $this->assertSame(3, EvidenceFile::forOrganization($organization)->count());
    }

    public function test_status_sync_moves_a_shipment_forward_only(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $created = $this->createMockIntegration($organization);

        $shipment = $this->createShipment($organization, [
            'internal_reference' => 'CT-TMS-3',
            'external_load_reference' => 'TMW-MOCK-1003',
            'status' => \App\ShipmentStatus::Draft,
        ]);

        $run = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations/'.$created['id'].'/sync', ['kind' => 'status'])
            ->assertOk()
            ->json('data');

        $this->assertSame('completed', $run['status']);
        $this->assertSame(1, $run['summary']['updated']);
        $this->assertSame('planned', $shipment->fresh()->status->value);
    }

    public function test_appointments_sync_fills_appointment_times(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $created = $this->createMockIntegration($organization);

        $shipment = $this->createShipment($organization, [
            'internal_reference' => 'CT-TMS-1',
            'external_load_reference' => 'TMW-MOCK-1001',
            'status' => \App\ShipmentStatus::Draft,
            'pickup_appointment' => null,
            'delivery_appointment' => null,
        ]);

        $run = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations/'.$created['id'].'/sync', ['kind' => 'appointments'])
            ->assertOk()
            ->json('data');

        $this->assertSame('completed', $run['status']);
        $this->assertSame(1, $run['summary']['updated']);
        $this->assertSame('2026-11-27 09:00:00', $shipment->fresh()->pickup_appointment->format('Y-m-d H:i:s'));
    }

    public function test_documents_sync_stores_evidence_without_duplicates(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $created = $this->createMockIntegration($organization);

        $this->createShipment($organization, [
            'internal_reference' => 'CT-TMS-1',
            'external_load_reference' => 'TMW-MOCK-1001',
        ]);

        $first = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations/'.$created['id'].'/sync', ['kind' => 'documents'])
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $first['summary']['documents_created']);

        $second = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations/'.$created['id'].'/sync', ['kind' => 'documents'])
            ->assertOk()
            ->json('data');

        $this->assertSame(0, $second['summary']['documents_created']);
        $this->assertSame(2, EvidenceFile::forOrganization($organization)->count());
    }

    public function test_trimble_sync_fails_with_live_unavailable_and_creates_nothing(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $admin = \App\Models\User::factory()->create();
        $this->addMember($organization, $admin, OrganizationRole::Admin);

        $created = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations', [
                'provider' => 'trimble-tmw',
                'name' => 'TMW Production',
                'credentials' => [
                    'client_id' => 'cid',
                    'client_secret' => 'secret',
                    'account_id' => 'acct',
                ],
            ])
            ->assertCreated()
            ->json('data');

        $run = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations/'.$created['id'].'/sync', ['kind' => 'full'])
            ->assertOk()
            ->json('data');

        $this->assertSame('failed', $run['status']);
        $this->assertStringContainsString('no live TMW system is reachable', $run['error_message']);
        $this->assertSame(0, Shipment::forOrganization($organization)->count());

        $this->assertDatabaseHas('notifications', [
            'organization_id' => $organization->getKey(),
            'user_id' => $admin->id,
            'type' => 'tms.sync_failed',
        ]);

        $this->assertDatabaseHas('tms_integrations', [
            'id' => $created['id'],
            'last_error' => $run['error_message'],
        ]);
    }

    public function test_disabled_integration_sync_fails_fast(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $created = $this->createMockIntegration($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->putJson('/api/v1/tms/integrations/'.$created['id'], ['status' => 'disabled'])
            ->assertOk();

        $run = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations/'.$created['id'].'/sync', ['kind' => 'full'])
            ->assertOk()
            ->json('data');

        $this->assertSame('failed', $run['status']);
        $this->assertStringContainsString('disabled', $run['error_message']);
        $this->assertSame(0, Shipment::forOrganization($organization)->count());
    }

    public function test_sync_runs_can_be_listed_and_filtered(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $created = $this->createMockIntegration($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/tms/integrations/'.$created['id'].'/sync', ['kind' => 'shipments'])
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/tms/sync-runs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'shipments')
            ->assertJsonPath('data.0.status', 'completed');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/tms/sync-runs?kind=status')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/tms/sync-runs?status=failed')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_integrations_are_tenant_scoped(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $other = $this->createOrganization();

        $created = $this->createMockIntegration($organization);

        [$otherUser] = $this->actingAsMemberOf(OrganizationRole::Owner, $other);

        $this->withHeaders($this->orgHeaders($other))
            ->getJson('/api/v1/tms/integrations/'.$created['id'])
            ->assertNotFound();

        $this->withHeaders($this->orgHeaders($other))
            ->getJson('/api/v1/tms/integrations')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_tms_list_command_shows_integrations(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->createMockIntegration($organization);

        Artisan::call('tms:list');
        $output = Artisan::output();

        $this->assertStringContainsString('Mock TMS', $output);
        $this->assertStringContainsString('mock', $output);
    }

    public function test_tms_sync_command_runs_a_sync(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $created = $this->createMockIntegration($organization);

        $this->assertSame(0, Artisan::call('tms:sync', [
            'integration' => $created['id'],
            '--kind' => 'shipments',
        ]));
        $this->assertStringContainsString('finished', Artisan::output());
        $this->assertSame(3, Shipment::forOrganization($organization)->count());
    }

    public function test_tms_run_due_command_runs_auto_sync_integrations_only(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $manual = $this->createMockIntegration($organization, ['name' => 'Manual Mock']);
        $auto = $this->createMockIntegration($organization, [
            'name' => 'Auto Mock',
            'settings' => ['auto_sync' => true],
        ]);

        $this->assertSame(0, Artisan::call('tms:run-due'));

        $this->assertSame(3, Shipment::forOrganization($organization)->count());
        $this->assertSame(1, TmsSyncRun::forOrganization($organization)->count());
        $this->assertDatabaseHas('tms_sync_runs', [
            'tms_integration_id' => $auto['id'],
            'status' => 'completed',
        ]);
        $this->assertDatabaseMissing('tms_sync_runs', [
            'tms_integration_id' => $manual['id'],
        ]);

        // A second run-due finds nothing due: the auto
        // integration synced within its interval.
        $this->assertSame(0, Artisan::call('tms:run-due'));
        $this->assertSame(1, TmsSyncRun::forOrganization($organization)->count());
    }
}
