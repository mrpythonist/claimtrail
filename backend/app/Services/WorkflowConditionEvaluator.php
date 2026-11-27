<?php

namespace App\Services;

use App\ClaimStatus;
use App\Models\Claim;
use Illuminate\Validation\ValidationException;

/**
 * Evaluates workflow conditions against a claim as recorded.
 *
 * A condition names one field, one operator, and one value.
 * All conditions on a workflow must match (AND) for the
 * workflow to run. Fields come only from the claim, its
 * shipment, its computed readiness, and its active deadline:
 * nothing is fetched from outside the organization, and no
 * condition can invent data the claim does not have.
 *
 * Supported fields:
 * - claim.status, claim.claim_category, claim.currency,
 *   claim.rejection_reason, claim.requested_amount,
 *   claim.days_since_created
 * - shipment.broker_or_customer_name, shipment.carrier_id
 * - readiness.percentage, readiness.status,
 *   readiness.missing_count
 * - deadline.status, deadline.days_until
 *
 * Operators: equals, not_equals, greater_than, less_than,
 * greater_than_or_equal, less_than_or_equal, contains, in,
 * not_in. A numeric comparison against a null field value
 * never matches; equals null matches only a null field.
 */
class WorkflowConditionEvaluator
{
    public function __construct(
        protected ReadinessService $readiness,
    ) {}

    /**
     * Whether every condition matches the claim. An empty or
     * missing condition list matches everything.
     *
     * @param  array<int, array<string, mixed>>|null  $conditions
     */
    public function matches(Claim $claim, ?array $conditions): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (! $this->matchesOne($claim, $condition)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The value of one condition field for the claim, or null
     * when the claim has no such value (no deadline, no
     * carrier, no rejection reason).
     */
    public function fieldValue(Claim $claim, string $field): mixed
    {
        return match ($field) {
            'claim.status' => $claim->status->value,
            'claim.claim_category' => $claim->claim_category->value,
            'claim.currency' => $claim->currency,
            'claim.rejection_reason' => $claim->rejection_reason,
            'claim.requested_amount' => $claim->requested_amount !== null ? (float) $claim->requested_amount : null,
            'claim.days_since_created' => (int) $claim->created_at->diffInDays(now()),
            'shipment.broker_or_customer_name' => $claim->shipment?->broker_or_customer_name,
            'shipment.carrier_id' => $claim->shipment?->carrier_id,
            'readiness.percentage' => $this->readinessFor($claim)['readiness_percentage'],
            'readiness.status' => $this->readinessFor($claim)['readiness_status'],
            'readiness.missing_count' => count($this->readinessFor($claim)['missing']),
            'deadline.status' => $this->deadlineStatus($claim),
            'deadline.days_until' => $this->deadlineDaysUntil($claim),
            default => throw ValidationException::withMessages([
                'field' => 'Unknown condition field: '.$field,
            ]),
        };
    }

    /**
     * All condition field names, for validation and docs.
     *
     * @return array<int, string>
     */
    public static function fields(): array
    {
        return [
            'claim.status',
            'claim.claim_category',
            'claim.currency',
            'claim.rejection_reason',
            'claim.requested_amount',
            'claim.days_since_created',
            'shipment.broker_or_customer_name',
            'shipment.carrier_id',
            'readiness.percentage',
            'readiness.status',
            'readiness.missing_count',
            'deadline.status',
            'deadline.days_until',
        ];
    }

    /**
     * All condition operators, for validation and docs.
     *
     * @return array<int, string>
     */
    public static function operators(): array
    {
        return [
            'equals',
            'not_equals',
            'greater_than',
            'less_than',
            'greater_than_or_equal',
            'less_than_or_equal',
            'contains',
            'in',
            'not_in',
        ];
    }

    /**
     * Validate a conditions array the way the API validates
     * it, so the engine never runs a malformed rule.
     *
     * @param  array<int, array<string, mixed>>|null  $conditions
     *
     * @throws ValidationException
     */
    public static function validateConditions(?array $conditions): void
    {
        if ($conditions === null) {
            return;
        }

        foreach ($conditions as $index => $condition) {
            $field = $condition['field'] ?? null;
            $operator = $condition['operator'] ?? null;

            if (! in_array($field, self::fields(), true)) {
                throw ValidationException::withMessages([
                    "conditions.{$index}.field" => 'Unknown condition field: '.(string) $field,
                ]);
            }

            if (! in_array($operator, self::operators(), true)) {
                throw ValidationException::withMessages([
                    "conditions.{$index}.operator" => 'Unknown condition operator: '.(string) $operator,
                ]);
            }

            if (in_array($operator, ['in', 'not_in'], true) && ! is_array($condition['value'] ?? null)) {
                throw ValidationException::withMessages([
                    "conditions.{$index}.value" => 'The in and not_in operators need an array value.',
                ]);
            }

            if (in_array($operator, ['greater_than', 'less_than', 'greater_than_or_equal', 'less_than_or_equal'], true)
                && ! is_numeric($condition['value'] ?? null)) {
                throw ValidationException::withMessages([
                    "conditions.{$index}.value" => 'Numeric operators need a numeric value.',
                ]);
            }
        }
    }

    /**
     * Whether one condition matches the claim.
     *
     * @param  array<string, mixed>  $condition
     */
    protected function matchesOne(Claim $claim, array $condition): bool
    {
        $field = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? null;
        $value = $condition['value'] ?? null;

        if (! in_array($field, self::fields(), true) || ! in_array($operator, self::operators(), true)) {
            throw ValidationException::withMessages([
                'condition' => 'Unknown condition field or operator.',
            ]);
        }

        $actual = $this->fieldValue($claim, $field);

        return match ($operator) {
            'equals' => $this->looseEquals($actual, $value),
            'not_equals' => ! $this->looseEquals($actual, $value),
            'greater_than' => $actual !== null && is_numeric($actual) && is_numeric($value) && (float) $actual > (float) $value,
            'less_than' => $actual !== null && is_numeric($actual) && is_numeric($value) && (float) $actual < (float) $value,
            'greater_than_or_equal' => $actual !== null && is_numeric($actual) && is_numeric($value) && (float) $actual >= (float) $value,
            'less_than_or_equal' => $actual !== null && is_numeric($actual) && is_numeric($value) && (float) $actual <= (float) $value,
            'contains' => $actual !== null && is_string($actual) && is_string($value) && str_contains(strtolower($actual), strtolower((string) $value)),
            'in' => is_array($value) && $this->looseIn($actual, $value),
            'not_in' => is_array($value) && ! $this->looseIn($actual, $value),
        };
    }

    /**
     * Loose equality: numbers compare numerically, everything
     * else compares as strings, so a JSON 250 and a stored
     * "250.00" still match.
     */
    protected function looseEquals(mixed $actual, mixed $expected): bool
    {
        if ($actual === null || $expected === null) {
            return $actual === $expected;
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual === (float) $expected;
        }

        return (string) $actual === (string) $expected;
    }

    /**
     * Loose membership, with the same numeric/string rules as
     * loose equality.
     *
     * @param  array<int, mixed>  $values
     */
    protected function looseIn(mixed $actual, array $values): bool
    {
        foreach ($values as $value) {
            if ($this->looseEquals($actual, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The computed readiness for the claim. Computed fresh on
     * every call: readiness is read inside long-running queue
     * workers, where a cached value could outlive the evidence
     * it was computed from.
     *
     * @return array<string, mixed>
     */
    protected function readinessFor(Claim $claim): array
    {
        return $this->readiness->compute($claim);
    }

    /**
     * The status of the claim's active deadline, or 'none'
     * when the claim has no deadline at all. Submitted and
     * closed claims report their recorded outcome (met or
     * missed) rather than the live clock.
     */
    protected function deadlineStatus(Claim $claim): string
    {
        $deadline = $claim->activeDeadline()->first();

        if ($deadline === null) {
            return $claim->submission_deadline_at === null ? 'none' : $this->fallbackDeadlineStatus($claim);
        }

        return $deadline->status->value;
    }

    /**
     * Days until the claim's active deadline, negative when
     * overdue, null when the claim has no deadline.
     */
    protected function deadlineDaysUntil(Claim $claim): ?int
    {
        $at = $claim->activeDeadline()->first()?->deadline_at
            ?? $claim->submission_deadline_at;

        if ($at === null) {
            return null;
        }

        return (int) now()->diffInDays($at, false);
    }

    /**
     * Deadline status for a claim that only has a
     * submission_deadline_at and no deadline row: met when
     * submitted on time, missed when submitted late or closed
     * unsubmitted past the date, overdue when past the date
     * and still open, upcoming otherwise.
     */
    protected function fallbackDeadlineStatus(Claim $claim): string
    {
        $at = $claim->submission_deadline_at;

        if ($claim->submitted_at !== null) {
            return $claim->submitted_at->lessThanOrEqualTo($at) ? 'met' : 'missed';
        }

        if ($claim->status === ClaimStatus::Closed && now()->greaterThan($at)) {
            return 'missed';
        }

        return now()->greaterThan($at) ? 'overdue' : 'upcoming';
    }
}
