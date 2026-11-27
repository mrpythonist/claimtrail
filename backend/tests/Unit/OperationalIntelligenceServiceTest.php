<?php

namespace Tests\Unit;

use App\Models\Claim;
use App\Models\Shipment;
use App\Services\OperationalIntelligenceService;
use Tests\TestCase;

/**
 * Pure helpers of the operational intelligence service
 * (v1.5.2): the facility, broker, and rejection-reason
 * fallbacks, which decide how a claim groups when the
 * recorded field is empty.
 */
class OperationalIntelligenceServiceTest extends TestCase
{
    protected function claimWithShipment(array $shipmentAttributes, array $claimAttributes = []): Claim
    {
        $shipment = new Shipment($shipmentAttributes);
        $claim = new Claim($claimAttributes);
        $claim->setRelation('shipment', $shipment);

        return $claim;
    }

    public function test_facility_prefers_delivery_location(): void
    {
        $claim = $this->claimWithShipment([
            'delivery_location' => 'Dallas DC',
            'pickup_location' => 'Houston Yard',
        ]);

        $this->assertSame('Dallas DC', OperationalIntelligenceService::facilityOf($claim));
    }

    public function test_facility_falls_back_to_pickup_location(): void
    {
        $claim = $this->claimWithShipment([
            'delivery_location' => null,
            'pickup_location' => 'Houston Yard',
        ]);

        $this->assertSame('Houston Yard', OperationalIntelligenceService::facilityOf($claim));
    }

    public function test_facility_is_unnamed_when_nothing_is_recorded(): void
    {
        $claim = $this->claimWithShipment([
            'delivery_location' => '   ',
            'pickup_location' => null,
        ]);

        $this->assertSame('Unnamed facility', OperationalIntelligenceService::facilityOf($claim));
    }

    public function test_facility_is_unnamed_without_a_shipment(): void
    {
        $claim = new Claim();

        $this->assertSame('Unnamed facility', OperationalIntelligenceService::facilityOf($claim));
    }

    public function test_broker_falls_back_to_unnamed(): void
    {
        $named = $this->claimWithShipment(['broker_or_customer_name' => 'Alpha Brokers']);
        $blank = $this->claimWithShipment(['broker_or_customer_name' => '  ']);
        $none = new Claim();

        $this->assertSame('Alpha Brokers', OperationalIntelligenceService::brokerOf($named));
        $this->assertSame('Unnamed broker', OperationalIntelligenceService::brokerOf($blank));
        $this->assertSame('Unnamed broker', OperationalIntelligenceService::brokerOf($none));
    }

    public function test_rejection_reason_falls_back_to_not_recorded(): void
    {
        $recorded = $this->claimWithShipment([], ['rejection_reason' => 'Insufficient evidence']);
        $blank = $this->claimWithShipment([], ['rejection_reason' => '  ']);
        $none = new Claim();

        $this->assertSame('Insufficient evidence', OperationalIntelligenceService::rejectionReasonOf($recorded));
        $this->assertSame('Not recorded', OperationalIntelligenceService::rejectionReasonOf($blank));
        $this->assertSame('Not recorded', OperationalIntelligenceService::rejectionReasonOf($none));
    }
}
