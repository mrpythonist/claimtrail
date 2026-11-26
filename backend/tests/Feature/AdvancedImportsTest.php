<?php

namespace Tests\Feature;

use App\Models\ImportMapping;
use App\Models\ImportRow;
use App\Models\ImportRun;
use App\Models\ImportSchedule;
use App\Models\Notification;
use App\Models\Shipment;
use App\Models\SyncRun;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Advanced imports (v1.3.1).
 *
 * Saved CSV mappings, import history with row-level error
 * correction, scheduled imports, synchronization workflows,
 * and import notifications. No external service is involved:
 * schedules and sync runs import files the organization
 * already uploaded.
 */
class AdvancedImportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        Queue::fake();
    }

    /**
     * Build a CSV upload from a string.
     */
    protected function csvFile(string $content, string $name = 'shipments.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    protected function validCsv(): string
    {
        return "internal_reference,broker_or_customer_name\n"
            ."CT-IMP-1,Heartland Foods\n"
            ."CT-IMP-2,Ozark Supply\n";
    }

    protected function mixedCsv(): string
    {
        return "internal_reference,broker_or_customer_name\n"
            ."CT-IMP-1,Heartland Foods\n"
            ."CT-EXISTS,Ozark Supply\n"
            .",Missing Reference\n";
    }

    public function test_mappings_can_be_saved_listed_and_made_default(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $first = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/imports/mappings', [
                'name' => 'Carrier export',
                'mapping' => ['Load ID' => 'internal_reference', 'Customer' => 'broker_or_customer_name'],
                'is_default' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Carrier export')
            ->assertJsonPath('data.is_default', true)
            ->json('data');

        $second = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/imports/mappings', [
                'name' => 'Broker export',
                'mapping' => ['Load ID' => 'internal_reference', 'Customer' => 'broker_or_customer_name'],
                'is_default' => true,
            ])
            ->assertCreated()
            ->json('data');

        // The new default unsets the previous one.
        $this->assertDatabaseHas('import_mappings', ['id' => $first['id'], 'is_default' => false]);
        $this->assertDatabaseHas('import_mappings', ['id' => $second['id'], 'is_default' => true]);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/imports/mappings')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_mapping_names_are_unique_per_organization_and_fields_are_validated(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $payload = [
            'name' => 'Carrier export',
            'mapping' => ['Load ID' => 'internal_reference'],
        ];

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/imports/mappings', $payload)
            ->assertCreated();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/imports/mappings', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/imports/mappings', [
                'name' => 'Bad mapping',
                'mapping' => ['Load ID' => 'not_a_field'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mapping']);
    }

    public function test_a_viewer_cannot_manage_mappings_but_can_list_them(): void
    {
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        ImportMapping::create([
            'organization_id' => $organization->getKey(),
            'name' => 'Carrier export',
            'target' => 'shipments',
            'mapping' => ['Load ID' => 'internal_reference'],
            'created_by' => $owner->id,
        ]);

        [$viewer] = $this->actingAsMemberOf(OrganizationRole::Viewer, $organization);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/imports/mappings')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/imports/mappings', [
                'name' => 'Another',
                'mapping' => ['Load ID' => 'internal_reference'],
            ])
            ->assertForbidden();
    }

    public function test_mappings_are_tenant_scoped(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $other = $this->createOrganization();

        $mapping = ImportMapping::create([
            'organization_id' => $other->getKey(),
            'name' => 'Other org mapping',
            'target' => 'shipments',
            'mapping' => ['Load ID' => 'internal_reference'],
            'created_by' => $user->id,
        ]);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/imports/mappings/'.$mapping->getKey())
            ->assertNotFound();

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/imports/mappings')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_preview_with_a_saved_mapping_renames_unrecognized_headers(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $mapping = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/imports/mappings', [
                'name' => 'Carrier export',
                'mapping' => ['Load ID' => 'internal_reference', 'Customer' => 'broker_or_customer_name'],
            ])
            ->assertCreated()
            ->json('data');

        $csv = "Load ID,Customer\nCT-MAP-1,Heartland Foods\n";

        $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/shipments/import/preview', [
                'file' => $this->csvFile($csv),
                'mapping_id' => $mapping['id'],
            ])
            ->assertOk()
            ->assertJsonPath('data.summary.valid', 1)
            ->assertJsonPath('data.rows.0.data.internal_reference', 'CT-MAP-1');
    }

    public function test_confirm_records_an_import_run_with_rows_and_history(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->createShipment($organization, ['internal_reference' => 'CT-EXISTS']);

        $preview = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/shipments/import/preview', [
                'file' => $this->csvFile($this->mixedCsv()),
            ])
            ->assertOk()
            ->json('data');

        $confirm = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/import/confirm', [
                'preview_token' => $preview['preview_token'],
            ])
            ->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.skipped', 2)
            ->json('data');

        $this->assertNotEmpty($confirm['import_run_id']);

        $run = ImportRun::findOrFail($confirm['import_run_id']);
        $this->assertSame('upload', $run->source);
        $this->assertSame('partial', $run->status);
        $this->assertSame(3, $run->total_rows);
        $this->assertSame(1, $run->imported_rows);
        $this->assertSame(1, $run->duplicate_rows);
        $this->assertSame(1, $run->error_rows);
        $this->assertSame(3, $run->rows()->count());
        $this->assertSame(1, $run->rows()->where('status', ImportRow::STATUS_DUPLICATE)->count());

        // History lists the run and filters by status and source.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/imports/runs')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'partial');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/imports/runs?status=completed')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/imports/runs?source=upload')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Rows filter to duplicates only.
        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/imports/runs/'.$run->getKey().'/rows?status=duplicate')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.data.internal_reference', 'CT-EXISTS');
    }

    public function test_error_correction_fixes_a_row_and_refreshes_the_run(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->createShipment($organization, ['internal_reference' => 'CT-EXISTS']);

        $preview = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/shipments/import/preview', [
                'file' => $this->csvFile($this->mixedCsv()),
            ])
            ->assertOk()
            ->json('data');

        $confirm = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/import/confirm', [
                'preview_token' => $preview['preview_token'],
            ])
            ->assertOk()
            ->json('data');

        $run = ImportRun::findOrFail($confirm['import_run_id']);
        $badRow = $run->rows()->where('status', ImportRow::STATUS_SKIPPED)->firstOrFail();

        // A correction that still fails validation stays failed.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/imports/runs/'.$run->getKey().'/rows/'.$badRow->getKey().'/correct', [
                'data' => ['broker_or_customer_name' => ''],
            ])
            ->assertUnprocessable();

        // A valid correction creates the shipment.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/imports/runs/'.$run->getKey().'/rows/'.$badRow->getKey().'/correct', [
                'data' => ['internal_reference' => 'CT-FIXED-1', 'broker_or_customer_name' => 'Heartland Foods'],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'corrected');

        $this->assertDatabaseHas('shipments', [
            'organization_id' => $organization->getKey(),
            'internal_reference' => 'CT-FIXED-1',
        ]);

        $run->refresh();
        $this->assertSame(2, $run->imported_rows);
        $this->assertSame(1, $run->duplicate_rows);
        $this->assertSame(0, $run->error_rows);
        $this->assertSame('partial', $run->status);

        // A corrected row cannot be corrected again.
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/imports/runs/'.$run->getKey().'/rows/'.$badRow->getKey().'/correct', [
                'data' => ['internal_reference' => 'CT-FIXED-2'],
            ])
            ->assertUnprocessable();
    }

    public function test_duplicate_correction_with_a_taken_reference_fails(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->createShipment($organization, ['internal_reference' => 'CT-EXISTS']);

        $preview = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/shipments/import/preview', [
                'file' => $this->csvFile($this->mixedCsv()),
            ])
            ->assertOk()
            ->json('data');

        $confirm = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/import/confirm', [
                'preview_token' => $preview['preview_token'],
            ])
            ->assertOk()
            ->json('data');

        $run = ImportRun::findOrFail($confirm['import_run_id']);
        $dupRow = $run->rows()->where('status', ImportRow::STATUS_DUPLICATE)->firstOrFail();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/imports/runs/'.$run->getKey().'/rows/'.$dupRow->getKey().'/correct', [
                'data' => ['internal_reference' => 'CT-EXISTS'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['internal_reference']);
    }

    public function test_schedule_can_be_created_and_run_now(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $schedule = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/imports/schedules', [
                'name' => 'Daily carrier file',
                'frequency' => 'daily',
                'file' => $this->csvFile($this->validCsv()),
            ])
            ->assertCreated()
            ->assertJsonPath('data.frequency', 'daily')
            ->assertJsonPath('data.active', true)
            ->json('data');

        $this->assertNotNull($schedule['next_run_at']);

        $run = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/imports/schedules/'.$schedule['id'].'/run')
            ->assertOk()
            ->assertJsonPath('data.source', 'schedule')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.imported_rows', 2)
            ->json('data');

        $this->assertSame(2, Shipment::forOrganization($organization)->count());

        $updated = $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/imports/schedules/'.$schedule['id'])
            ->assertOk()
            ->json('data');

        $this->assertSame($run['id'], $updated['last_import_run_id']);
        $this->assertNotNull($updated['last_run_at']);
        $this->assertNotNull($updated['next_run_at']);
        $this->assertGreaterThan(time(), strtotime($updated['next_run_at']));
    }

    public function test_run_scheduled_command_runs_due_schedules_only(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $due = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/imports/schedules', [
                'name' => 'Due schedule',
                'frequency' => 'daily',
                'file' => $this->csvFile($this->validCsv()),
            ])
            ->assertCreated()
            ->json('data');

        ImportSchedule::whereKey($due['id'])->update(['next_run_at' => now()->subHour()]);

        $future = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/imports/schedules', [
                'name' => 'Future schedule',
                'frequency' => 'daily',
                'file' => $this->csvFile($this->validCsv()),
            ])
            ->assertCreated()
            ->json('data');

        $this->artisan('imports:run-scheduled')
            ->assertSuccessful();

        $this->assertSame(2, Shipment::forOrganization($organization)->count());
        $this->assertSame(1, ImportRun::where('source', 'schedule')->count());
        $this->assertNotNull(ImportSchedule::find($due['id'])->last_import_run_id);
        $this->assertNull(ImportSchedule::find($future['id'])->last_import_run_id);
    }

    public function test_sync_workflow_moves_through_its_states(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $sync = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/imports/sync-runs', [
                'name' => 'Nightly broker sync',
                'file' => $this->csvFile($this->validCsv()),
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data');

        $finished = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/imports/sync-runs/'.$sync['id'].'/run')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->json('data');

        $this->assertNotNull($finished['import_run_id']);
        $this->assertSame(2, $finished['summary']['imported']);
        $this->assertSame(1, ImportRun::where('source', 'sync')->count());
        $this->assertSame(2, Shipment::forOrganization($organization)->count());
    }

    public function test_sync_workflow_fails_when_its_file_is_gone(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $sync = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/imports/sync-runs', [
                'name' => 'Broken sync',
                'file' => $this->csvFile($this->validCsv()),
            ])
            ->assertCreated()
            ->json('data');

        $record = SyncRun::findOrFail($sync['id']);
        Storage::disk('private')->delete($record->source_file_path);

        $finished = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/imports/sync-runs/'.$sync['id'].'/run')
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->json('data');

        $this->assertNotNull($finished['error_message']);
        $this->assertSame(1, ImportRun::where('source', 'sync')->where('status', 'failed')->count());
    }

    public function test_completed_import_notifies_other_members_who_can_manage_imports(): void
    {
        [$owner, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $admin = \App\Models\User::factory()->create();
        $this->addMember($organization, $admin, OrganizationRole::Admin);

        $preview = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/shipments/import/preview', [
                'file' => $this->csvFile($this->validCsv()),
            ])
            ->assertOk()
            ->json('data');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/import/confirm', [
                'preview_token' => $preview['preview_token'],
            ])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'organization_id' => $organization->getKey(),
            'user_id' => $admin->id,
            'type' => 'import.completed',
        ]);

        // The actor is not notified about their own import.
        $this->assertDatabaseMissing('notifications', [
            'organization_id' => $organization->getKey(),
            'user_id' => $owner->id,
            'type' => 'import.completed',
        ]);
    }

    public function test_import_history_and_runs_are_tenant_scoped(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $other = $this->createOrganization();

        $preview = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/shipments/import/preview', [
                'file' => $this->csvFile($this->validCsv()),
            ])
            ->assertOk()
            ->json('data');

        $confirm = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/import/confirm', [
                'preview_token' => $preview['preview_token'],
            ])
            ->assertOk()
            ->json('data');

        [$otherUser] = $this->actingAsMemberOf(OrganizationRole::Owner, $other);

        $this->withHeaders($this->orgHeaders($other))
            ->getJson('/api/v1/imports/runs/'.$confirm['import_run_id'])
            ->assertNotFound();

        $this->withHeaders($this->orgHeaders($other))
            ->getJson('/api/v1/imports/runs')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
