<?php

namespace Tests\Feature;

use App\ClaimCategory;
use App\ClaimStatus;
use App\EvidenceDocumentCategory;
use App\Models\Claim;
use App\Models\Shipment;
use App\OrganizationRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Operational intelligence (v1.5.2).
 *
 * Detention trends, facility performance, rejection reasons,
 * missing-evidence trends, broker-level trends, revenue
 * leakage, and deterministic operational recommendations.
 * Every number is computed from the organization's own
 * recorded claims, shipments, payments, and evidence. The
 * facility is the shipment's delivery location where one is
 * recorded, otherwise its pickup location, otherwise
 * "Unnamed facility". Rejection reasons are the reasons
 * recorded on the claims; unrecorded ones group under
 * "Not recorded". Leakage is gaps between recorded amounts
 * under the single v1.5.0 balance rule, not proven lost
 * money. Recommendations fire only when their stated
 * minimum sample is met, and each carries the numbers it
 * was derived from.
 */
class OperationalIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A claim with known money, category, status, and
     * timestamps, on the given shipment.
     */
    protected function seedClaim($organization, Shipment $shipment, array $attributes = []): Claim
    {
        $claim = $this->createClaim($organization, $shipment, $attributes);

        $claim->forceFill([
            'created_at' => $attributes['created_at'] ?? CarbonImmutable::parse('2026-01-01 09:00:00'),
            'updated_at' => $attributes['created_at'] ?? CarbonImmutable::parse('2026-01-01 09:00:00'),
        ])->save();

        return $claim->fresh();
    }

    /**
     * Record a ledger payment through the API, so the
     * payment lands in the ledger and the claim's recovered
     * amount and status move the way the real flow moves
     * them. Timestamps are backdated afterwards so the
     * averages are hand-computable.
     */
    protected function recordLedgerPayment($organization, Claim $claim, float $amount, string $paidAt): void
    {
        $this->withHeaders($this->orgHeaders($organization))
            ->postJson("/api/v1/reconciliation/claims/{$claim->getKey()}/payments", [
                'amount' => $amount,
                'payment_method' => 'wire_transfer',
                'paid_at' => $paidAt,
                'payment_reference' => 'WIRE-'.$claim->getKey(),
            ])
            ->assertCreated();
    }

    /**
     * The January fixture: six claims in January plus one
     * paid detention claim in December for the preceding
     * window. Two brokers, three facilities (one unnamed),
     * two recorded rejection reasons plus one unrecorded,
     * and a global evidence requirement for a bill of
     * lading and a proof of delivery.
     */
    protected function seedJanuary($organization): array
    {
        $alphaDallas = $this->createShipment($organization, [
            'broker_or_customer_name' => 'Alpha Brokers',
            'delivery_location' => 'Dallas DC',
            'pickup_location' => 'Houston Yard',
        ]);
        $alphaDallas2 = $this->createShipment($organization, [
            'broker_or_customer_name' => 'Alpha Brokers',
            'delivery_location' => 'Dallas DC',
            'pickup_location' => 'Houston Yard',
        ]);
        $betaFortWorth = $this->createShipment($organization, [
            'broker_or_customer_name' => 'Beta Freight',
            'delivery_location' => 'Fort Worth Hub',
            'pickup_location' => 'Austin Dock',
        ]);
        $betaUnnamed = $this->createShipment($organization, [
            'broker_or_customer_name' => 'Beta Freight',
            'delivery_location' => null,
            'pickup_location' => null,
        ]);

        $organization->evidenceRequirements()->create([
            'event_category_id' => null,
            'document_category' => EvidenceDocumentCategory::BillOfLading,
            'is_required' => true,
        ]);
        $organization->evidenceRequirements()->create([
            'event_category_id' => null,
            'document_category' => EvidenceDocumentCategory::ProofOfDelivery,
            'is_required' => true,
        ]);

        $attach = function (Claim $claim, Shipment $shipment, EvidenceDocumentCategory $category): void {
            $file = $this->createEvidence($claim->organization, $shipment, [
                'document_category' => $category,
            ]);
            $claim->evidenceFiles()->attach($file->getKey());
        };

        // D1: detention, Alpha, Dallas DC, paid in full.
        // Requested 400, approved 300, paid 300.
        $d1 = $this->seedClaim($organization, $alphaDallas, [
            'claim_category' => ClaimCategory::Detention,
            'status' => ClaimStatus::Accepted,
            'requested_amount' => 400.00,
            'accepted_amount' => 300.00,
            'created_at' => CarbonImmutable::parse('2026-01-01 09:00:00'),
            'submitted_at' => CarbonImmutable::parse('2026-01-03 09:00:00'),
            'approved_at' => CarbonImmutable::parse('2026-01-04 09:00:00'),
        ]);
        $this->recordLedgerPayment($organization, $d1, 300.00, '2026-01-09');
        $d1 = $d1->fresh();
        $d1->forceFill(['paid_at' => CarbonImmutable::parse('2026-01-09 09:00:00')])->save();
        $attach($d1, $alphaDallas, EvidenceDocumentCategory::BillOfLading);
        $attach($d1, $alphaDallas, EvidenceDocumentCategory::ProofOfDelivery);

        // D2: detention, Alpha, Dallas DC, rejected for
        // insufficient evidence. Requested 100.
        $d2 = $this->seedClaim($organization, $alphaDallas2, [
            'claim_category' => ClaimCategory::Detention,
            'status' => ClaimStatus::Rejected,
            'requested_amount' => 100.00,
            'rejection_reason' => 'Insufficient evidence',
            'created_at' => CarbonImmutable::parse('2026-01-02 09:00:00'),
            'submitted_at' => CarbonImmutable::parse('2026-01-03 09:00:00'),
            'rejected_at' => CarbonImmutable::parse('2026-01-06 09:00:00'),
        ]);
        $attach($d2, $alphaDallas2, EvidenceDocumentCategory::BillOfLading);

        // D3: detention, Beta, Fort Worth Hub, accepted with
        // a partial payment. Requested 200, approved 200,
        // paid 50.
        $d3 = $this->seedClaim($organization, $betaFortWorth, [
            'claim_category' => ClaimCategory::Detention,
            'status' => ClaimStatus::Accepted,
            'requested_amount' => 200.00,
            'accepted_amount' => 200.00,
            'created_at' => CarbonImmutable::parse('2026-01-05 09:00:00'),
            'submitted_at' => CarbonImmutable::parse('2026-01-06 09:00:00'),
            'approved_at' => CarbonImmutable::parse('2026-01-06 09:00:00'),
        ]);
        $this->recordLedgerPayment($organization, $d3, 50.00, '2026-01-08');

        // L1: layover, Beta, Fort Worth Hub, rejected for
        // insufficient evidence. Requested 100.
        $l1 = $this->seedClaim($organization, $betaFortWorth, [
            'claim_category' => ClaimCategory::Layover,
            'status' => ClaimStatus::Rejected,
            'requested_amount' => 100.00,
            'rejection_reason' => 'Insufficient evidence',
            'created_at' => CarbonImmutable::parse('2026-01-07 09:00:00'),
            'submitted_at' => CarbonImmutable::parse('2026-01-08 09:00:00'),
            'rejected_at' => CarbonImmutable::parse('2026-01-10 09:00:00'),
        ]);
        $attach($l1, $betaFortWorth, EvidenceDocumentCategory::BillOfLading);
        $attach($l1, $betaFortWorth, EvidenceDocumentCategory::ProofOfDelivery);

        // T1: tonu, Beta, unnamed facility, rejected with no
        // reason recorded. Requested 50.
        $t1 = $this->seedClaim($organization, $betaUnnamed, [
            'claim_category' => ClaimCategory::Tonu,
            'status' => ClaimStatus::Rejected,
            'requested_amount' => 50.00,
            'created_at' => CarbonImmutable::parse('2026-01-09 09:00:00'),
            'submitted_at' => CarbonImmutable::parse('2026-01-10 09:00:00'),
            'rejected_at' => CarbonImmutable::parse('2026-01-12 09:00:00'),
        ]);
        $attach($t1, $betaUnnamed, EvidenceDocumentCategory::ProofOfDelivery);

        // P1: detention, Alpha, Dallas DC, still potential.
        // Requested 500; never entered the submitted
        // population.
        $p1 = $this->seedClaim($organization, $alphaDallas, [
            'claim_category' => ClaimCategory::Detention,
            'status' => ClaimStatus::Potential,
            'requested_amount' => 500.00,
            'created_at' => CarbonImmutable::parse('2026-01-12 09:00:00'),
        ]);

        // D0: detention, Alpha, Dallas DC, paid in December,
        // for the preceding window. Requested 100, approved
        // 100, paid 100.
        $d0 = $this->seedClaim($organization, $alphaDallas, [
            'claim_category' => ClaimCategory::Detention,
            'status' => ClaimStatus::Accepted,
            'requested_amount' => 100.00,
            'accepted_amount' => 100.00,
            'created_at' => CarbonImmutable::parse('2025-12-15 09:00:00'),
            'submitted_at' => CarbonImmutable::parse('2025-12-16 09:00:00'),
            'approved_at' => CarbonImmutable::parse('2025-12-17 09:00:00'),
        ]);
        $this->recordLedgerPayment($organization, $d0, 100.00, '2025-12-20');
        $d0 = $d0->fresh();
        $d0->forceFill(['paid_at' => CarbonImmutable::parse('2025-12-20 09:00:00')])->save();

        return compact('d1', 'd2', 'd3', 'l1', 't1', 'p1', 'd0');
    }

    protected function operationsGet($organization, string $path): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->orgHeaders($organization))->getJson($path);
    }

    protected function range(): string
    {
        return '?from=2026-01-01&to=2026-01-31';
    }

    public function test_detention_trends_are_detention_only(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $this->seedJanuary($organization);

        $data = $this->operationsGet($organization, '/api/v1/operations/detention-trends'.$this->range())
            ->assertOk()
            ->json('data');

        // Four detention claims in January (D1, D2, D3, P1);
        // three submitted, one paid, one rejected.
        $this->assertSame(4, $data['counts']['claims']);
        $this->assertSame(3, $data['counts']['submitted']);
        $this->assertSame(1, $data['counts']['paid']);
        $this->assertSame(1, $data['counts']['rejected']);

        // Requested over the submitted population: 400 + 100
        // + 200. Paid: 300 + 50. Approved: 300 + 200.
        // Outstanding follows the analytics convention: the
        // amount due is the total approved (500) because an
        // approved total exists, so 500 minus 350 paid is 150.
        // The per-claim balance rule (v1.5.0) would give 250
        // for these claims; the leakage endpoint reports that
        // one instead.
        $this->assertSame('700.00', $data['money']['requested_amount']);
        $this->assertSame('500.00', $data['money']['approved_amount']);
        $this->assertSame('350.00', $data['money']['paid_amount']);
        $this->assertSame('150.00', $data['money']['outstanding_amount']);
        $this->assertEquals(50.0, $data['rates']['recovery_rate_vs_requested']);
        $this->assertEquals(70.0, $data['rates']['recovery_rate_vs_approved']);
        $this->assertEquals(33.33, $data['rates']['rejection_rate']);

        // Submission times: 2 days, 1 day, 1 day. Mean 1.33.
        $this->assertEquals(1.33, $data['average_submission_days']['days']);
        $this->assertSame(3, $data['average_submission_days']['sample_size']);

        // Payment time: only D1, approved to paid is 5 days.
        $this->assertEquals(5.0, $data['average_payment_days']['days']);
        $this->assertSame(1, $data['average_payment_days']['sample_size']);

        // The monthly series has the one January point.
        $this->assertCount(1, $data['monthly']);
        $this->assertSame('2026-01', $data['monthly'][0]['month']);
        $this->assertSame(4, $data['monthly'][0]['claims_created']);
        $this->assertEquals(50.0, $data['monthly'][0]['recovery_rate_vs_requested']);

        // December had one detention claim, paid in full.
        $this->assertSame(1, $data['comparison']['previous']['claims']);
        $this->assertEquals(100.0, $data['comparison']['previous']['recovery_rate_vs_requested']);
        $this->assertSame(3, $data['comparison']['change']['claims']);
        $this->assertEquals(-50.0, $data['comparison']['change']['recovery_rate_vs_requested_pp']);
    }

    public function test_facilities_group_by_delivery_location_then_pickup(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $this->seedJanuary($organization);

        $rows = $this->operationsGet($organization, '/api/v1/operations/facilities'.$this->range())
            ->assertOk()
            ->json('data');

        $this->assertCount(3, $rows);

        // Dallas DC: D1, D2, P1. Two submitted, one paid,
        // one rejected; 300 recovered of 500 requested.
        $dallas = collect($rows)->firstWhere('facility', 'Dallas DC');
        $this->assertSame(3, $dallas['claims']);
        $this->assertSame(2, $dallas['submitted']);
        $this->assertEquals(60.0, $dallas['recovery_rate_vs_requested']);
        $this->assertEquals(50.0, $dallas['rejection_rate']);

        // Fort Worth Hub: D3, L1. Two submitted, one
        // rejected; 50 recovered of 300 requested.
        $fortWorth = collect($rows)->firstWhere('facility', 'Fort Worth Hub');
        $this->assertSame(2, $fortWorth['claims']);
        $this->assertSame(2, $fortWorth['submitted']);
        $this->assertEquals(16.67, $fortWorth['recovery_rate_vs_requested']);

        // The shipment with no locations groups under
        // "Unnamed facility".
        $unnamed = collect($rows)->firstWhere('facility', 'Unnamed facility');
        $this->assertSame(1, $unnamed['claims']);
        $this->assertSame(1, $unnamed['submitted']);
        $this->assertEquals(0.0, $unnamed['recovery_rate_vs_requested']);
        $this->assertEquals(100.0, $unnamed['rejection_rate']);
    }

    public function test_rejection_reasons_group_unrecorded(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $this->seedJanuary($organization);

        $rows = $this->operationsGet($organization, '/api/v1/operations/rejection-reasons'.$this->range())
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $rows);

        // Two of the three rejections cite insufficient
        // evidence; one carries no reason at all.
        $insufficient = collect($rows)->firstWhere('reason', 'Insufficient evidence');
        $this->assertSame(2, $insufficient['rejected']);
        $this->assertEquals(66.67, $insufficient['share_of_rejected']);
        $this->assertSame('200.00', $insufficient['requested_amount']);

        $notRecorded = collect($rows)->firstWhere('reason', 'Not recorded');
        $this->assertSame(1, $notRecorded['rejected']);
        $this->assertEquals(33.33, $notRecorded['share_of_rejected']);
        $this->assertSame('50.00', $notRecorded['requested_amount']);
    }

    public function test_missing_evidence_trends(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $this->seedJanuary($organization);

        $data = $this->operationsGet($organization, '/api/v1/operations/missing-evidence'.$this->range())
            ->assertOk()
            ->json('data');

        // Six claims in January, all with requirements. D1
        // and L1 are ready; D2, D3, T1, and P1 are not.
        $this->assertSame(6, $data['claims']);
        $this->assertSame(6, $data['claims_with_requirements']);
        $this->assertSame(4, $data['claims_incomplete']);

        // Readiness: 100, 50, 0, 100, 50, 0. Mean 50.
        $this->assertEquals(50.0, $data['average_readiness_percentage']['percentage']);
        $this->assertSame(6, $data['average_readiness_percentage']['sample_size']);

        // Bill of lading is missing on D3, T1, P1; proof of
        // delivery on D2, D3, P1. Both are missing on three
        // of the four incomplete claims.
        $this->assertCount(2, $data['by_category']);

        $bol = collect($data['by_category'])->firstWhere('category', 'bill_of_lading');
        $this->assertSame('Bill of Lading', $bol['category_label']);
        $this->assertSame(3, $bol['claims_missing']);
        $this->assertEquals(75.0, $bol['share_of_incomplete']);

        $pod = collect($data['by_category'])->firstWhere('category', 'proof_of_delivery');
        $this->assertSame('Proof of Delivery', $pod['category_label']);
        $this->assertSame(3, $pod['claims_missing']);
        $this->assertEquals(75.0, $pod['share_of_incomplete']);

        // The monthly series has the one January point.
        $this->assertCount(1, $data['monthly']);
        $this->assertSame('2026-01', $data['monthly'][0]['month']);
        $this->assertSame(6, $data['monthly'][0]['claims_created']);
        $this->assertSame(4, $data['monthly'][0]['incomplete']);
        $this->assertEquals(50.0, $data['monthly'][0]['average_readiness_percentage']['percentage']);
    }

    public function test_broker_trends_carry_monthly_series_and_change(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $this->seedJanuary($organization);

        $rows = $this->operationsGet($organization, '/api/v1/operations/broker-trends'.$this->range())
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $rows);

        // Alpha: D1, D2, P1. Two submitted, recovery 300
        // over 500. December had one Alpha claim, paid in
        // full, so recovery fell 40 points.
        $alpha = collect($rows)->firstWhere('broker', 'Alpha Brokers');
        $this->assertSame(3, $alpha['claims']);
        $this->assertSame(2, $alpha['submitted']);
        $this->assertEquals(60.0, $alpha['recovery_rate_vs_requested']);
        $this->assertEquals(50.0, $alpha['rejection_rate']);
        $this->assertCount(1, $alpha['monthly']);
        $this->assertSame(3, $alpha['monthly'][0]['claims_created']);
        $this->assertSame(2, $alpha['change']['claims']);
        $this->assertEquals(-40.0, $alpha['change']['recovery_rate_vs_requested_pp']);
        $this->assertEquals(50.0, $alpha['change']['rejection_rate_pp']);

        // Beta: D3, L1, T1. Three submitted, recovery 50
        // over 350, rejection two of three. No Beta claims
        // in December, so the rate changes are null.
        $beta = collect($rows)->firstWhere('broker', 'Beta Freight');
        $this->assertSame(3, $beta['claims']);
        $this->assertSame(3, $beta['submitted']);
        $this->assertEquals(14.29, $beta['recovery_rate_vs_requested']);
        $this->assertEquals(66.67, $beta['rejection_rate']);
        $this->assertSame(3, $beta['change']['claims']);
        $this->assertNull($beta['change']['recovery_rate_vs_requested_pp']);
        $this->assertNull($beta['change']['rejection_rate_pp']);
    }

    public function test_revenue_leakage_is_gaps_between_recorded_amounts(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $this->seedJanuary($organization);

        $data = $this->operationsGet($organization, '/api/v1/operations/revenue-leakage'.$this->range())
            ->assertOk()
            ->json('data');

        // Five submitted claims. Requested 850, approved
        // 500, paid 350, outstanding 400.
        $this->assertSame(5, $data['submitted_claims']);
        $this->assertSame('850.00', $data['requested_total']);
        $this->assertSame('500.00', $data['approved_total']);
        $this->assertSame('350.00', $data['paid_total']);
        $this->assertSame('400.00', $data['outstanding_total']);

        // Only D1 was cut at approval: 400 requested, 300
        // approved.
        $this->assertSame('100.00', $data['requested_vs_approved_gap']);
        $this->assertSame(1, $data['claims_cut_at_approval']);

        // Every paid dollar sits in the ledger, and nothing
        // was overpaid.
        $this->assertSame('0.00', $data['overpaid_total']);
        $this->assertSame('0.00', $data['legacy_total']);

        // The one potential claim is pipeline, not leakage.
        $this->assertSame(1, $data['unsubmitted_claims']);
        $this->assertSame('500.00', $data['unsubmitted_requested_total']);

        // By balance status: three unpaid, one partial, one
        // paid, none overpaid.
        $unpaid = collect($data['by_balance_status'])->firstWhere('balance_status', 'unpaid');
        $this->assertSame(3, $unpaid['claims']);
        $this->assertSame('250.00', $unpaid['outstanding_amount']);

        $partial = collect($data['by_balance_status'])->firstWhere('balance_status', 'partial');
        $this->assertSame(1, $partial['claims']);
        $this->assertSame('150.00', $partial['outstanding_amount']);

        $paid = collect($data['by_balance_status'])->firstWhere('balance_status', 'paid');
        $this->assertSame(1, $paid['claims']);
        $this->assertSame('0.00', $paid['outstanding_amount']);

        $this->assertNull(collect($data['by_balance_status'])->firstWhere('balance_status', 'overpaid'));

        // The largest outstanding balance is D3 at 150.
        $this->assertCount(4, $data['largest_outstanding']);
        $this->assertSame('150.00', $data['largest_outstanding'][0]['outstanding_amount']);
        $this->assertSame('Beta Freight', $data['largest_outstanding'][0]['broker']);

        // One currency row, no conversion.
        $this->assertCount(1, $data['money_by_currency']);
        $this->assertSame('USD', $data['money_by_currency'][0]['currency']);
        $this->assertSame('400.00', $data['money_by_currency'][0]['outstanding_amount']);
    }

    public function test_recommendations_fire_only_on_their_minimum_samples(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $this->seedJanuary($organization);

        $data = $this->operationsGet($organization, '/api/v1/operations/recommendations'.$this->range())
            ->assertOk()
            ->json('data');

        $recommendations = $data['recommendations'];

        // Five rules fire: worst broker, top reason, most
        // missing evidence, outstanding balances, rising
        // detention claims. The facility rule does not fire
        // (no facility has three submitted claims), the
        // detention-rejection rule does not (33.33% is below
        // the 60% overall rate), and the broker-fall rule
        // does not (Alpha fell 40 points but has only two
        // submitted claims).
        $this->assertCount(5, $recommendations);
        $types = collect($recommendations)->pluck('type')->all();
        $this->assertSame([
            'highest_rejection_broker',
            'top_rejection_reason',
            'most_missing_evidence',
            'largest_outstanding',
            'detention_trend_rising',
        ], $types);

        // Beta Freight rejected two of three submitted claims.
        $broker = collect($recommendations)->firstWhere('type', 'highest_rejection_broker');
        $this->assertSame('watch', $broker['severity']);
        $this->assertSame('Beta Freight', $broker['evidence']['broker']);
        $this->assertEquals(66.67, $broker['evidence']['rejection_rate']);

        // Two of the three rejections cite insufficient
        // evidence.
        $reason = collect($recommendations)->firstWhere('type', 'top_rejection_reason');
        $this->assertSame('Insufficient evidence', $reason['evidence']['reason']);
        $this->assertSame(2, $reason['evidence']['rejected']);
        $this->assertEquals(66.67, $reason['evidence']['share_of_rejected']);

        // Bill of lading is missing on three of the four
        // incomplete claims.
        $missing = collect($recommendations)->firstWhere('type', 'most_missing_evidence');
        $this->assertSame('bill_of_lading', $missing['evidence']['category']);
        $this->assertSame(3, $missing['evidence']['claims_missing']);

        // 400 outstanding, largest single balance 150.
        $outstanding = collect($recommendations)->firstWhere('type', 'largest_outstanding');
        $this->assertSame('watch', $outstanding['severity']);
        $this->assertSame('400.00', $outstanding['evidence']['outstanding_total']);
        $this->assertSame('150.00', $outstanding['evidence']['largest_outstanding']['outstanding_amount']);

        // Detention claims rose from one to four.
        $rising = collect($recommendations)->firstWhere('type', 'detention_trend_rising');
        $this->assertSame(4, $rising['evidence']['current_claims']);
        $this->assertSame(1, $rising['evidence']['previous_claims']);
    }

    public function test_empty_organization_returns_empty_operations(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');

        $this->operationsGet($organization, '/api/v1/operations/detention-trends'.$this->range())
            ->assertOk()
            ->assertJsonPath('data.counts.claims', 0)
            ->assertJsonPath('data.rates.recovery_rate_vs_requested', null)
            ->assertJsonPath('data.comparison.change.claims', 0);

        $this->operationsGet($organization, '/api/v1/operations/facilities'.$this->range())
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->operationsGet($organization, '/api/v1/operations/rejection-reasons'.$this->range())
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->operationsGet($organization, '/api/v1/operations/missing-evidence'.$this->range())
            ->assertOk()
            ->assertJsonPath('data.claims_incomplete', 0)
            ->assertJsonPath('data.average_readiness_percentage.percentage', null)
            ->assertJsonPath('data.by_category', []);

        $this->operationsGet($organization, '/api/v1/operations/broker-trends'.$this->range())
            ->assertOk()
            ->assertJsonPath('data', []);

        $this->operationsGet($organization, '/api/v1/operations/revenue-leakage'.$this->range())
            ->assertOk()
            ->assertJsonPath('data.submitted_claims', 0)
            ->assertJsonPath('data.outstanding_total', '0.00')
            ->assertJsonPath('data.largest_outstanding', []);

        // No rule has a minimum sample, so nothing is
        // recommended for an empty organization.
        $this->operationsGet($organization, '/api/v1/operations/recommendations'.$this->range())
            ->assertOk()
            ->assertJsonPath('data.recommendations', []);
    }

    public function test_viewer_may_read_operations(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $this->seedJanuary($organization);

        $this->actingAsMemberOf(OrganizationRole::Viewer, $organization);

        $this->operationsGet($organization, '/api/v1/operations/detention-trends'.$this->range())
            ->assertOk();
        $this->operationsGet($organization, '/api/v1/operations/recommendations'.$this->range())
            ->assertOk();
    }

    public function test_free_plan_is_refused_with_402(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->seedJanuary($organization);

        $this->operationsGet($organization, '/api/v1/operations/detention-trends')
            ->assertStatus(402);
    }

    public function test_other_organization_sees_nothing(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');
        $this->seedJanuary($organization);

        [, $other] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($other, 'starter');

        $this->operationsGet($other, '/api/v1/operations/detention-trends'.$this->range())
            ->assertOk()
            ->assertJsonPath('data.counts.claims', 0);

        $this->operationsGet($other, '/api/v1/operations/recommendations'.$this->range())
            ->assertOk()
            ->assertJsonPath('data.recommendations', []);
    }

    public function test_invalid_range_is_rejected(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'starter');

        $this->operationsGet($organization, '/api/v1/operations/detention-trends?from=2026-02-01&to=2026-01-01')
            ->assertUnprocessable();
    }
}
