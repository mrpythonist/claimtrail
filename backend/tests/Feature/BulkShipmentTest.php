<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Shipment;
use App\OrganizationRole;
use App\ShipmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BulkShipmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_status_moves_several_shipments_forward(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $first = $this->createShipment($organization, ['status' => ShipmentStatus::Draft]);
        $second = $this->createShipment($organization, ['status' => ShipmentStatus::Draft]);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/bulk-status', [
                'ids' => [$first->getKey(), $second->getKey()],
                'to_status' => 'planned',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.updated', [$first->getKey(), $second->getKey()])
            ->assertJsonPath('data.failed', []);

        $this->assertSame(ShipmentStatus::Planned, $first->fresh()->status);
        $this->assertSame(ShipmentStatus::Planned, $second->fresh()->status);
    }

    public function test_bulk_status_reports_failures_without_failing_the_batch(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $movable = $this->createShipment($organization, ['status' => ShipmentStatus::Draft]);
        $stuck = $this->createShipment($organization, ['status' => ShipmentStatus::Delivered]);
        $missing = '00000000-0000-0000-0000-000000000000';

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/bulk-status', [
                'ids' => [$movable->getKey(), $stuck->getKey(), $missing],
                'to_status' => 'planned',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.updated', [$movable->getKey()])
            ->assertJsonCount(2, 'data.failed');

        $this->assertSame(ShipmentStatus::Planned, $movable->fresh()->status);
        $this->assertSame(ShipmentStatus::Delivered, $stuck->fresh()->status);
        $this->assertStringContainsString(
            'Delivered shipment cannot move to Planned',
            collect($response->json('data.failed'))->firstWhere('id', $stuck->getKey())['reason']
        );
    }

    public function test_bulk_status_rejects_an_invalid_target_and_an_archived_shipment(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $archived = $this->createShipment($organization, ['status' => ShipmentStatus::Archived]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/bulk-status', [
                'ids' => [$archived->getKey()],
                'to_status' => 'bogus',
            ])
            ->assertUnprocessable();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/bulk-status', [
                'ids' => [$archived->getKey()],
                'to_status' => 'planned',
            ])
            ->assertOk()
            ->assertJsonPath('data.updated', [])
            ->assertJsonCount(1, 'data.failed');
    }

    public function test_bulk_archive_archives_several_shipments(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $first = $this->createShipment($organization, ['status' => ShipmentStatus::Completed]);
        $second = $this->createShipment($organization, ['status' => ShipmentStatus::Draft]);
        $already = $this->createShipment($organization, ['status' => ShipmentStatus::Archived]);

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/bulk-archive', [
                'ids' => [$first->getKey(), $second->getKey(), $already->getKey()],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.updated', [$first->getKey(), $second->getKey()])
            ->assertJsonCount(1, 'data.failed');

        $this->assertTrue($first->fresh()->isArchived());
        $this->assertTrue($second->fresh()->isArchived());
    }

    public function test_bulk_shipment_operations_are_permission_gated_and_tenant_scoped(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Viewer);
        $shipment = $this->createShipment($organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/bulk-status', [
                'ids' => [$shipment->getKey()],
                'to_status' => 'planned',
            ])
            ->assertForbidden();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/bulk-archive', [
                'ids' => [$shipment->getKey()],
            ])
            ->assertForbidden();

        // A dispatcher can change status but cannot archive.
        [$dispatcher] = $this->actingAsMemberOf(OrganizationRole::Dispatcher, $organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/bulk-archive', [
                'ids' => [$shipment->getKey()],
            ])
            ->assertForbidden();

        // A shipment from another organization is reported as not found.
        $otherOrg = $this->createOrganization(['name' => 'Other Org']);
        $foreign = $this->createShipment($otherOrg);
        [$owner] = $this->actingAsMemberOf(OrganizationRole::Owner, $organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/bulk-status', [
                'ids' => [$foreign->getKey()],
                'to_status' => 'planned',
            ])
            ->assertOk()
            ->assertJsonPath('data.updated', [])
            ->assertJsonCount(1, 'data.failed');

        $this->assertSame(ShipmentStatus::Draft, $foreign->fresh()->status);
    }

    public function test_bulk_status_changes_are_audit_logged(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $shipment = $this->createShipment($organization, ['status' => ShipmentStatus::Draft]);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/bulk-status', [
                'ids' => [$shipment->getKey()],
                'to_status' => 'planned',
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->getKey(),
            'entity_type' => 'Shipment',
            'entity_id' => $shipment->getKey(),
        ]);

        $this->assertSame(
            1,
            AuditLog::where('organization_id', $organization->getKey())
                ->where('entity_type', 'Shipment')
                ->where('action', 'shipment.updated')
                ->count()
        );

        $this->assertSame(0, Shipment::where('organization_id', $organization->getKey())->where('status', 'draft')->count());
    }
}
