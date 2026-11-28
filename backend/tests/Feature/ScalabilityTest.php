<?php

namespace Tests\Feature;

use App\EvidenceDocumentCategory;
use App\OrganizationRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Scalability improvements (v2.0.3).
 *
 * The missing-evidence endpoint used to compute readiness per
 * claim with three queries each — the requirements lookup, the
 * attached-evidence pluck, and the snapshot read — so its query
 * count grew with the claim count: 26 queries for 5 claims,
 * 41 for 10. ReadinessService now caches the requirements per
 * event category and reuses the eager-loaded evidence and
 * snapshots, so the count is constant: 12 queries for 5 claims
 * and 12 for 10. This test pins the constant count with a
 * small margin, and pins that the numbers it returns are
 * unchanged by the fix.
 */
class ScalabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function seedClaims($organization, int $count): void
    {
        $category = $this->systemCategory('detention');

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

        for ($i = 0; $i < $count; $i++) {
            $shipment = $this->createShipment($organization, [
                'broker_or_customer_name' => 'Alpha Brokers',
                'delivery_location' => 'Dallas DC',
            ]);
            $event = $this->createEvent($shipment, ['event_category_id' => $category->id]);
            $claim = $this->createClaim($organization, $shipment, [
                'shipment_event_id' => $event->getKey(),
                'created_at' => CarbonImmutable::parse('2026-01-01 09:00:00'),
            ]);
            $claim->forceFill(['created_at' => CarbonImmutable::parse('2026-01-01 09:00:00')])->save();
        }
    }

    /**
     * Count the queries one request issues.
     */
    protected function countQueries(callable $run): int
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $run();

        return count($queries);
    }

    public function test_missing_evidence_query_count_is_constant_as_claims_grow(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'growth');
        $this->seedClaims($organization, 5);

        $five = $this->countQueries(function () use ($organization) {
            $this->withHeaders($this->orgHeaders($organization))
                ->getJson('/api/v1/operations/missing-evidence?from=2026-01-01&to=2026-01-31')
                ->assertOk()
                ->assertJsonPath('data.claims', 5)
                ->assertJsonPath('data.claims_incomplete', 5);
        });

        $this->seedClaims($organization, 5);

        $ten = $this->countQueries(function () use ($organization) {
            $this->withHeaders($this->orgHeaders($organization))
                ->getJson('/api/v1/operations/missing-evidence?from=2026-01-01&to=2026-01-31')
                ->assertOk()
                ->assertJsonPath('data.claims', 10)
                ->assertJsonPath('data.claims_incomplete', 10);
        });

        // Measured 12 queries at both sizes (was 26 at 5 claims
        // and 41 at 10 before the fix). The bound leaves a small
        // margin for unrelated queries, but doubling the claims
        // must not add more than 3.
        $this->assertLessThanOrEqual(15, $five);
        $this->assertLessThanOrEqual(15, $ten);
        $this->assertLessThanOrEqual($five + 3, $ten);
    }

    public function test_missing_evidence_numbers_are_unchanged_by_the_fix(): void
    {
        [, $organization] = $this->actingAsMemberOf(OrganizationRole::Owner);
        $this->subscribe($organization, 'growth');
        $this->seedClaims($organization, 3);

        $this->withHeaders($this->orgHeaders($organization))
            ->getJson('/api/v1/operations/missing-evidence?from=2026-01-01&to=2026-01-31')
            ->assertOk()
            ->assertJsonPath('data.claims', 3)
            ->assertJsonPath('data.claims_with_requirements', 3)
            ->assertJsonPath('data.claims_incomplete', 3)
            ->assertJsonPath('data.average_readiness_percentage.percentage', 0)
            ->assertJsonPath('data.by_category.0.category', 'bill_of_lading')
            ->assertJsonPath('data.by_category.0.claims_missing', 3)
            ->assertJsonPath('data.by_category.1.category', 'proof_of_delivery')
            ->assertJsonPath('data.by_category.1.claims_missing', 3);
    }
}
