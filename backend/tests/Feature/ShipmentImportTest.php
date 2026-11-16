<?php

namespace Tests\Feature;

use App\Models\Shipment;
use App\OrganizationRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ShipmentImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a CSV upload from a string.
     */
    protected function csvFile(string $content, string $name = 'shipments.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    public function test_preview_validates_rows_without_creating_shipments(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::OperationsManager);
        $carrier = $this->createCarrier($organization, ['name' => 'Acme Lines LLC']);

        $csv = "internal_reference,broker_or_customer_name,carrier,base_rate,status\n"
            ."CT-IMP-1,Heartland Foods,Acme Lines LLC,1200.00,planned\n"
            ."CT-IMP-2,Ozark Supply,,850.50,draft\n";

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/shipments/import/preview', [
                'file' => $this->csvFile($csv),
            ]);

        $response->assertOk()
            ->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.valid', 2)
            ->assertJsonPath('data.summary.invalid', 0)
            ->assertJsonPath('data.summary.duplicate', 0)
            ->assertJsonStructure(['data' => ['preview_token', 'rows']])
            ->assertJsonPath('data.rows.0.data.carrier_id', $carrier->getKey());

        $this->assertSame(0, Shipment::count());
    }

    public function test_preview_flags_row_errors_and_unknown_carriers(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $csv = "internal_reference,broker_or_customer_name,carrier,base_rate\n"
            ."CT-IMP-1,,Ghost Carrier,-5\n";

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/shipments/import/preview', [
                'file' => $this->csvFile($csv),
            ]);

        $response->assertOk()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.valid', 0)
            ->assertJsonPath('data.summary.invalid', 1);

        $errors = $response->json('data.rows.0.errors');
        $this->assertArrayHasKey('broker_or_customer_name', $errors);
        $this->assertArrayHasKey('carrier', $errors);
        $this->assertArrayHasKey('base_rate', $errors);
    }

    public function test_preview_detects_duplicates_against_the_database_and_within_the_file(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->createShipment($organization, ['internal_reference' => 'CT-EXISTS']);

        $csv = "internal_reference,broker_or_customer_name\n"
            ."CT-EXISTS,Heartland Foods\n"
            ."CT-NEW,Ozark Supply\n"
            ."CT-NEW,Blue River Logistics\n";

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/shipments/import/preview', [
                'file' => $this->csvFile($csv),
            ]);

        $response->assertOk()
            ->assertJsonPath('data.summary.total', 3)
            ->assertJsonPath('data.summary.valid', 1)
            ->assertJsonPath('data.summary.duplicate', 2)
            ->assertJsonPath('data.rows.0.is_duplicate', true)
            ->assertJsonPath('data.rows.1.is_duplicate', false)
            ->assertJsonPath('data.rows.2.is_duplicate', true);
    }

    public function test_preview_rejects_a_file_without_required_columns(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $csv = "notes,driver_name\nJust a note,Joe\n";

        $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/shipments/import/preview', [
                'file' => $this->csvFile($csv),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_preview_rejects_a_non_csv_file(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $path = tempnam(sys_get_temp_dir(), 'bin');
        file_put_contents($path, random_bytes(64));
        $file = new UploadedFile($path, 'shipments.exe', 'application/octet-stream', null, true);

        $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/shipments/import/preview', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_confirm_imports_valid_rows_only(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->createShipment($organization, ['internal_reference' => 'CT-EXISTS']);

        $csv = "internal_reference,broker_or_customer_name\n"
            ."CT-NEW-1,Heartland Foods\n"
            ."CT-EXISTS,Ozark Supply\n"
            .",Missing Reference\n";

        $preview = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/shipments/import/preview', [
                'file' => $this->csvFile($csv),
            ])
            ->assertOk()
            ->json('data');

        $confirm = $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/import/confirm', [
                'preview_token' => $preview['preview_token'],
            ]);

        $confirm->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.skipped', 2);

        $this->assertDatabaseHas('shipments', [
            'organization_id' => $organization->getKey(),
            'internal_reference' => 'CT-NEW-1',
            'created_by' => $user->id,
        ]);
        $this->assertSame(2, Shipment::count());
    }

    public function test_confirm_with_row_indexes_imports_only_the_selected_rows(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $csv = "internal_reference,broker_or_customer_name\n"
            ."CT-SEL-1,Heartland Foods\n"
            ."CT-SEL-2,Ozark Supply\n";

        $preview = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/shipments/import/preview', [
                'file' => $this->csvFile($csv),
            ])
            ->assertOk()
            ->json('data');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/import/confirm', [
                'preview_token' => $preview['preview_token'],
                'row_indexes' => [2],
            ])
            ->assertOk()
            ->assertJsonPath('data.imported', 1);

        $this->assertDatabaseMissing('shipments', ['internal_reference' => 'CT-SEL-1']);
        $this->assertDatabaseHas('shipments', ['internal_reference' => 'CT-SEL-2']);
    }

    public function test_confirm_rejects_an_unknown_or_expired_token(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/import/confirm', [
                'preview_token' => 'no-such-token',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['preview_token']);
    }

    public function test_a_preview_token_cannot_be_used_twice(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $csv = "internal_reference,broker_or_customer_name\nCT-ONCE,Heartland Foods\n";

        $preview = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/shipments/import/preview', [
                'file' => $this->csvFile($csv),
            ])
            ->assertOk()
            ->json('data');

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/import/confirm', [
                'preview_token' => $preview['preview_token'],
            ])
            ->assertOk();

        $this->withHeaders($this->orgHeaders($organization))
            ->postJson('/api/v1/shipments/import/confirm', [
                'preview_token' => $preview['preview_token'],
            ])
            ->assertUnprocessable();
    }

    public function test_a_mapping_override_renames_an_unrecognized_header(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);

        $csv = "Load ID,Customer\nCT-MAP-1,Heartland Foods\n";

        $response = $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/shipments/import/preview', [
                'file' => $this->csvFile($csv),
                'mapping' => json_encode(['Load ID' => 'internal_reference']),
            ]);

        $response->assertOk()
            ->assertJsonPath('data.summary.valid', 1)
            ->assertJsonPath('data.rows.0.data.internal_reference', 'CT-MAP-1');
    }

    public function test_a_dispatcher_cannot_import_shipments(): void
    {
        [$user, $organization] = $this->actingAsMemberOf(OrganizationRole::Dispatcher);

        $csv = "internal_reference,broker_or_customer_name\nCT-X,Someone\n";

        $this->withHeaders($this->orgHeaders($organization))
            ->post('/api/v1/shipments/import/preview', [
                'file' => $this->csvFile($csv),
            ])
            ->assertForbidden();
    }
}
