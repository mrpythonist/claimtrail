<?php

namespace Tests\Unit;

use App\Tms\MockTmsProvider;
use App\Tms\TmsProviderException;
use App\Tms\TmsProviderRegistry;
use App\Tms\TrimbleTmwMapper;
use App\Tms\TrimbleTmwProvider;
use Tests\TestCase;

/**
 * TMS providers (v1.3.2).
 *
 * The mock provider's deterministic dummy data, the
 * Trimble provider's schema-only behaviour (credential
 * shape validation, request descriptors from the
 * published endpoints, live_unavailable on every fetch),
 * and the mapper from provider records onto ClaimTrail
 * records. No HTTP is performed anywhere here.
 */
class TmsProviderTest extends TestCase
{
    public function test_mock_provider_reports_itself_as_a_simulation(): void
    {
        $provider = new MockTmsProvider();

        $this->assertSame('mock', $provider->name());
        $this->assertSame('Mock TMS', $provider->label());
        $this->assertFalse($provider->liveAvailable());
        $this->assertSame([
            'shipments' => true,
            'status' => true,
            'appointments' => true,
            'documents' => true,
            'webhooks' => false,
        ], $provider->capabilities());
    }

    public function test_mock_authenticate_succeeds_without_a_live_system(): void
    {
        $result = (new MockTmsProvider())->authenticate([]);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['live_available']);
        $this->assertNotNull($result['token']);
        $this->assertStringContainsString('No live TMS', $result['message']);
    }

    public function test_mock_returns_three_fixed_loads(): void
    {
        $loads = (new MockTmsProvider())->fetchShipments([]);

        $this->assertCount(3, $loads);
        $this->assertSame(
            ['TMW-MOCK-1001', 'TMW-MOCK-1002', 'TMW-MOCK-1003'],
            array_column($loads, 'external_reference')
        );
        $this->assertSame(
            ['in_transit', 'delivered', 'planned'],
            array_column($loads, 'status')
        );
    }

    public function test_mock_fetch_shipments_respects_limit(): void
    {
        $loads = (new MockTmsProvider())->fetchShipments([], ['limit' => 2]);

        $this->assertCount(2, $loads);
    }

    public function test_mock_status_and_appointments_for_one_load(): void
    {
        $provider = new MockTmsProvider();

        $status = $provider->fetchShipmentStatus([], 'TMW-MOCK-1001');
        $this->assertSame('in_transit', $status['status']);
        $this->assertSame('TMW-MOCK-1001', $status['external_reference']);

        $appointments = $provider->fetchAppointments([], 'TMW-MOCK-1002');
        $this->assertCount(1, $appointments);
        $this->assertSame('2026-11-25T08:00:00Z', $appointments[0]['pickup_appointment']);
        $this->assertSame('2026-11-26T10:47:00Z', $appointments[0]['actual_arrival_at']);
    }

    public function test_mock_documents_carry_content_for_storage(): void
    {
        $documents = (new MockTmsProvider())->fetchDocuments([], 'TMW-MOCK-1001');

        $this->assertCount(2, $documents);
        $this->assertSame(['proof_of_delivery', 'bill_of_lading'], array_column($documents, 'type'));

        foreach ($documents as $document) {
            $this->assertSame(strlen($document['content']), $document['size_bytes']);
            $this->assertStringContainsString('TMW-MOCK-1001', $document['content']);
        }

        $this->assertSame([], (new MockTmsProvider())->fetchDocuments([], 'TMW-MOCK-1003'));
    }

    public function test_mock_unknown_load_throws_provider_error(): void
    {
        $this->expectException(TmsProviderException::class);
        $this->expectExceptionMessage('no load with external reference');

        (new MockTmsProvider())->fetchShipmentStatus([], 'TMW-NOPE');
    }

    public function test_trimble_authenticate_rejects_incomplete_credentials(): void
    {
        $result = (new TrimbleTmwProvider())->authenticate(['client_id' => 'abc']);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['live_available']);
        $this->assertNull($result['token']);
        $this->assertStringContainsString('client_secret', $result['message']);
        $this->assertStringContainsString('account_id', $result['message']);
    }

    public function test_trimble_authenticate_accepts_well_formed_credentials_but_reports_no_live_access(): void
    {
        $result = (new TrimbleTmwProvider())->authenticate([
            'client_id' => 'cid',
            'client_secret' => 'secret',
            'account_id' => 'acct',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['live_available']);
        $this->assertNull($result['token']);
        $this->assertStringContainsString('no live TMW.Suite system is reachable', $result['message']);
    }

    public function test_trimble_fetches_always_throw_live_unavailable(): void
    {
        $provider = new TrimbleTmwProvider();
        $credentials = ['client_id' => 'cid', 'client_secret' => 'secret', 'account_id' => 'acct'];

        foreach ([
            fn () => $provider->fetchShipments($credentials),
            fn () => $provider->fetchShipmentStatus($credentials, 'TMW-1'),
            fn () => $provider->fetchAppointments($credentials, 'TMW-1'),
            fn () => $provider->fetchDocuments($credentials, 'TMW-1'),
        ] as $call) {
            try {
                $call();
                $this->fail('Expected a live_unavailable exception.');
            } catch (TmsProviderException $exception) {
                $this->assertSame(TmsProviderException::CODE_LIVE_UNAVAILABLE, $exception->providerCode);
                $this->assertStringContainsString('on-prem Cloud Hub Connector', $exception->getMessage());
            }
        }
    }

    public function test_trimble_request_descriptors_use_the_published_endpoints(): void
    {
        $provider = new TrimbleTmwProvider();

        $token = $provider->requestDescriptor('token');
        $this->assertSame('POST', $token['method']);
        $this->assertSame(
            'https://cloud.api.trimblecloud.com/transportation/api/identity/v2/oauth2/token',
            $token['url']
        );
        $this->assertSame('sas_token', $token['body']['scope']);

        $shipments = $provider->requestDescriptor('shipments');
        $this->assertSame('GET', $shipments['method']);
        $this->assertSame(
            'https://cloud.api.trimblecloud.com/transportation/tte-tmwsuite/v0/odata/Orders',
            $shipments['url']
        );
        $this->assertStringContainsString('Bearer <access_token>', $shipments['headers']['Authorization']);

        $status = $provider->requestDescriptor('shipment_status', ['external_reference' => 'TMW-1']);
        $this->assertStringContainsString('Orders(TMW-1)', $status['url']);
    }

    public function test_registry_resolves_both_drivers_and_rejects_unknown_ones(): void
    {
        $this->assertSame(['mock', 'trimble-tmw'], TmsProviderRegistry::drivers());
        $this->assertInstanceOf(MockTmsProvider::class, TmsProviderRegistry::resolve('mock'));
        $this->assertInstanceOf(TrimbleTmwProvider::class, TmsProviderRegistry::resolve('trimble-tmw'));

        $this->expectException(TmsProviderException::class);
        TmsProviderRegistry::resolve('acme-tms');
    }

    public function test_registry_catalogue_marks_both_providers_as_not_live(): void
    {
        $catalogue = TmsProviderRegistry::catalogue();

        $this->assertCount(2, $catalogue);
        $this->assertSame(['mock', 'trimble-tmw'], array_column($catalogue, 'name'));

        foreach ($catalogue as $entry) {
            $this->assertFalse($entry['live_available']);
        }

        $trimble = $catalogue[1];
        $this->assertSame(['client_id', 'client_secret', 'account_id'], $trimble['credential_fields']);
        $this->assertStringContainsString('Schema-only', $trimble['live_note']);
    }

    public function test_mapper_translates_provider_statuses(): void
    {
        $this->assertSame(\App\ShipmentStatus::Planned, TrimbleTmwMapper::toShipmentStatus('planned'));
        $this->assertSame(\App\ShipmentStatus::InTransit, TrimbleTmwMapper::toShipmentStatus('dispatched'));
        $this->assertSame(\App\ShipmentStatus::InTransit, TrimbleTmwMapper::toShipmentStatus('in_transit'));
        $this->assertSame(\App\ShipmentStatus::Delivered, TrimbleTmwMapper::toShipmentStatus('delivered'));
        $this->assertSame(\App\ShipmentStatus::Completed, TrimbleTmwMapper::toShipmentStatus('completed'));
        $this->assertNull(TrimbleTmwMapper::toShipmentStatus('bogus'));
    }

    public function test_mapper_translates_document_types(): void
    {
        $this->assertSame(\App\EvidenceDocumentCategory::ProofOfDelivery, TrimbleTmwMapper::toEvidenceCategory('proof_of_delivery'));
        $this->assertSame(\App\EvidenceDocumentCategory::BillOfLading, TrimbleTmwMapper::toEvidenceCategory('bol'));
        $this->assertSame(\App\EvidenceDocumentCategory::RateConfirmation, TrimbleTmwMapper::toEvidenceCategory('rate_confirmation'));
        $this->assertSame(\App\EvidenceDocumentCategory::Other, TrimbleTmwMapper::toEvidenceCategory('something-else'));
    }

    public function test_mapper_drops_null_shipment_fields(): void
    {
        $attributes = TrimbleTmwMapper::toShipmentAttributes([
            'external_reference' => 'TMW-1',
            'internal_reference' => null,
            'broker_or_customer_name' => 'Acme',
            'pickup_appointment' => null,
        ]);

        $this->assertSame('TMW-1', $attributes['external_load_reference']);
        $this->assertSame('Acme', $attributes['broker_or_customer_name']);
        $this->assertArrayNotHasKey('internal_reference', $attributes);
        $this->assertArrayNotHasKey('pickup_appointment', $attributes);
    }
}
