<?php

namespace Tests\Unit;

use App\ClaimCategory;
use App\ClaimStatus;
use App\Models\EvidenceRequirement;
use App\Services\WorkflowConditionEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Workflow condition evaluation (v2.0.0).
 *
 * Conditions read the claim, its shipment, its computed
 * readiness, and its active deadline as recorded. All
 * conditions on a workflow must match (AND). A numeric
 * comparison against a null field never matches; equals null
 * matches only a null field. Malformed conditions are refused
 * at validation time, before a workflow can be saved.
 */
class WorkflowConditionEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    protected function evaluator(): WorkflowConditionEvaluator
    {
        return app(WorkflowConditionEvaluator::class);
    }

    protected function claimWithShipment(array $shipmentAttributes = [], array $claimAttributes = [])
    {
        [$user, $organization] = $this->actingAsMemberOf(\App\OrganizationRole::Owner);
        $shipment = $this->createShipment($organization, $shipmentAttributes);
        $claim = $this->createClaim($organization, $shipment, $claimAttributes);

        return [$organization, $shipment, $claim];
    }

    public function test_empty_conditions_match_everything(): void
    {
        [, , $claim] = $this->claimWithShipment();

        $this->assertTrue($this->evaluator()->matches($claim, []));
        $this->assertTrue($this->evaluator()->matches($claim, null));
    }

    public function test_status_and_category_equals(): void
    {
        [, , $claim] = $this->claimWithShipment([], [
            'status' => ClaimStatus::Potential,
            'claim_category' => ClaimCategory::Detention,
        ]);

        $this->assertTrue($this->evaluator()->matches($claim, [
            ['field' => 'claim.status', 'operator' => 'equals', 'value' => 'potential'],
            ['field' => 'claim.claim_category', 'operator' => 'equals', 'value' => 'detention'],
        ]));
        $this->assertFalse($this->evaluator()->matches($claim, [
            ['field' => 'claim.status', 'operator' => 'equals', 'value' => 'submitted'],
        ]));
    }

    public function test_numeric_comparison_on_requested_amount(): void
    {
        [, , $claim] = $this->claimWithShipment([], ['requested_amount' => 250.00]);

        $this->assertTrue($this->evaluator()->matches($claim, [
            ['field' => 'claim.requested_amount', 'operator' => 'greater_than', 'value' => 100],
        ]));
        $this->assertTrue($this->evaluator()->matches($claim, [
            ['field' => 'claim.requested_amount', 'operator' => 'less_than_or_equal', 'value' => 250],
        ]));
        $this->assertFalse($this->evaluator()->matches($claim, [
            ['field' => 'claim.requested_amount', 'operator' => 'greater_than', 'value' => 250],
        ]));
    }

    public function test_contains_matches_broker_name_case_insensitively(): void
    {
        [, , $claim] = $this->claimWithShipment(['broker_or_customer_name' => 'Alpha Brokers LLC']);

        $this->assertTrue($this->evaluator()->matches($claim, [
            ['field' => 'shipment.broker_or_customer_name', 'operator' => 'contains', 'value' => 'alpha'],
        ]));
        $this->assertFalse($this->evaluator()->matches($claim, [
            ['field' => 'shipment.broker_or_customer_name', 'operator' => 'contains', 'value' => 'beta'],
        ]));
    }

    public function test_in_operator_matches_a_list(): void
    {
        [, , $claim] = $this->claimWithShipment([], ['status' => ClaimStatus::Potential]);

        $this->assertTrue($this->evaluator()->matches($claim, [
            ['field' => 'claim.status', 'operator' => 'in', 'value' => ['potential', 'submitted']],
        ]));
        $this->assertFalse($this->evaluator()->matches($claim, [
            ['field' => 'claim.status', 'operator' => 'not_in', 'value' => ['potential', 'submitted']],
        ]));
    }

    public function test_equals_null_matches_only_a_null_rejection_reason(): void
    {
        [, , $claim] = $this->claimWithShipment([], ['rejection_reason' => null]);

        $this->assertTrue($this->evaluator()->matches($claim, [
            ['field' => 'claim.rejection_reason', 'operator' => 'equals', 'value' => null],
        ]));
        $this->assertFalse($this->evaluator()->matches($claim, [
            ['field' => 'claim.rejection_reason', 'operator' => 'greater_than', 'value' => 1],
        ]));
    }

    public function test_readiness_fields_come_from_the_computed_readiness(): void
    {
        [$organization, $shipment, $claim] = $this->claimWithShipment();

        EvidenceRequirement::create([
            'organization_id' => $organization->getKey(),
            'event_category_id' => null,
            'document_category' => 'bill_of_lading',
            'is_required' => true,
        ]);

        $this->assertTrue($this->evaluator()->matches($claim, [
            ['field' => 'readiness.percentage', 'operator' => 'equals', 'value' => 0],
            ['field' => 'readiness.missing_count', 'operator' => 'equals', 'value' => 1],
        ]));

        $file = $this->createEvidence($organization, $shipment, ['document_category' => 'bill_of_lading']);
        $claim->evidenceFiles()->syncWithoutDetaching([
            $file->getKey() => ['attached_by' => null],
        ]);

        $this->assertTrue($this->evaluator()->matches($claim->fresh(), [
            ['field' => 'readiness.percentage', 'operator' => 'equals', 'value' => 100],
            ['field' => 'readiness.status', 'operator' => 'equals', 'value' => 'ready'],
        ]));
    }

    public function test_deadline_fields_use_the_submission_deadline(): void
    {
        [, , $claim] = $this->claimWithShipment([], [
            'submission_deadline_at' => now()->addDays(3),
        ]);

        $this->assertTrue($this->evaluator()->matches($claim, [
            ['field' => 'deadline.status', 'operator' => 'equals', 'value' => 'upcoming'],
            ['field' => 'deadline.days_until', 'operator' => 'less_than_or_equal', 'value' => 7],
        ]));
        $this->assertFalse($this->evaluator()->matches($claim, [
            ['field' => 'deadline.days_until', 'operator' => 'less_than', 'value' => 1],
        ]));
    }

    public function test_deadline_fields_are_null_without_a_deadline(): void
    {
        [, , $claim] = $this->claimWithShipment();

        $this->assertTrue($this->evaluator()->matches($claim, [
            ['field' => 'deadline.status', 'operator' => 'equals', 'value' => 'none'],
            ['field' => 'deadline.days_until', 'operator' => 'equals', 'value' => null],
        ]));
    }

    public function test_unknown_field_throws(): void
    {
        [, , $claim] = $this->claimWithShipment();

        $this->expectException(ValidationException::class);

        $this->evaluator()->matches($claim, [
            ['field' => 'claim.secret', 'operator' => 'equals', 'value' => 'x'],
        ]);
    }

    public function test_validate_conditions_rejects_bad_operator_and_bad_in_value(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowConditionEvaluator::validateConditions([
            ['field' => 'claim.status', 'operator' => 'in', 'value' => 'potential'],
        ]);
    }

    public function test_validate_conditions_rejects_non_numeric_value_for_numeric_operator(): void
    {
        $this->expectException(ValidationException::class);

        WorkflowConditionEvaluator::validateConditions([
            ['field' => 'claim.requested_amount', 'operator' => 'greater_than', 'value' => 'a lot'],
        ]);
    }
}
