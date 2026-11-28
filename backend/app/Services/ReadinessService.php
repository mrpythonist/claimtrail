<?php

namespace App\Services;

use App\EvidenceDocumentCategory;
use App\Models\Claim;
use App\Models\ClaimRequirement;
use App\Models\ClaimStatusHistory;
use App\Models\EvidenceRequirement;
use App\Models\User;
use App\ReadinessStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Computes evidence readiness for a claim.
 *
 * Readiness counts required document categories against the evidence
 * attached to the claim. It is informational only: a ready claim is
 * not a valid claim, and readiness never guarantees a broker will
 * accept it. The UI must carry that wording wherever readiness shows.
 *
 * Required categories come from the organization's evidence
 * requirements: global requirements (no event category) plus the ones
 * matching the claim event's category. A claim with no event sees
 * global requirements only.
 */
class ReadinessService
{
    /**
     * Applicable requirements, cached per organization and
     * event category for the life of this service instance.
     * The operational-intelligence endpoints compute readiness
     * for every claim in a window, and the requirements for
     * claims sharing a category are identical — without the
     * cache that is one query per claim (v2.0.3).
     *
     * @var array<string, \Illuminate\Support\Collection<int, EvidenceRequirement>>
     */
    protected array $requirementsCache = [];

    /**
     * Compute the live readiness breakdown for a claim.
     *
     * When the caller already loaded the claim's evidence files
     * and requirement snapshots (the operations endpoints do),
     * the loaded relations are used instead of re-querying:
     * compute() used to issue three queries per claim — the
     * requirements lookup, the attached-evidence pluck, and the
     * snapshot read — which made the missing-evidence endpoint
     * scale with the claim count (v2.0.3).
     *
     * @return array<string, mixed>
     */
    public function compute(Claim $claim): array
    {
        $requirements = $this->applicableRequirements($claim);

        $required = $requirements->where('is_required', true)
            ->map(fn (EvidenceRequirement $requirement) => $requirement->document_category->value)
            ->unique()
            ->values()
            ->all();

        $optional = $requirements->where('is_required', false)
            ->map(fn (EvidenceRequirement $requirement) => $requirement->document_category->value)
            ->unique()
            ->values()
            ->all();

        $attached = $this->attachedCategories($claim);

        $collected = array_values(array_intersect($required, $attached));
        $missing = array_values(array_diff($required, $attached));
        $optionalCollected = array_values(array_intersect($optional, $attached));

        $total = count($required);
        $percentage = $total === 0
            ? 100
            : (int) round((count($collected) / $total) * 100);

        $status = match (true) {
            $total === 0 => ReadinessStatus::Ready,
            count($missing) === 0 => ReadinessStatus::Ready,
            count($collected) === 0 => ReadinessStatus::NotStarted,
            default => ReadinessStatus::Partial,
        };

        $snapshots = $this->requirementSnapshots($claim);
        $overridden = $snapshots->contains(fn (ClaimRequirement $row) => $row->isOverridden());

        return [
            'claim_id' => $claim->getKey(),
            'required' => $this->describeCategories($required),
            'optional' => $this->describeCategories($optional),
            'collected' => $this->describeCategories($collected),
            'missing' => $this->describeCategories($missing),
            'optional_collected' => $this->describeCategories($optionalCollected),
            'readiness_percentage' => $percentage,
            'readiness_status' => $status->value,
            'readiness_status_label' => $status->label(),
            'has_requirements' => $total > 0,
            'is_overridden' => $overridden,
            'disclaimer' => 'Readiness counts attached evidence against configured requirements. It does not certify that the claim is valid or will be accepted.',
        ];
    }

    /**
     * (Re)write the readiness snapshot rows for a claim.
     *
     * Old rows are removed and fresh ones written, so the snapshot
     * always reflects the latest check. Override information is
     * preserved across re-snapshots: an accepted gap stays accepted
     * until the evidence arrives.
     */
    public function snapshot(Claim $claim): void
    {
        $computed = $this->compute($claim);
        $now = now();

        DB::transaction(function () use ($claim, $computed, $now) {
            $previous = $claim->requirementSnapshots()
                ->get()
                ->keyBy(fn (ClaimRequirement $row) => $row->document_category->value);

            $claim->requirementSnapshots()->delete();

            $attachedByCategory = $claim->evidenceFiles()
                ->get()
                ->groupBy(fn ($file) => $file->document_category instanceof EvidenceDocumentCategory
                    ? $file->document_category->value
                    : (string) $file->document_category);

            foreach ($computed['required'] as $item) {
                $category = $item['value'];
                $fulfilled = in_array($category, array_column($computed['collected'], 'value'), true);
                $prior = $previous->get($category);

                ClaimRequirement::create([
                    'organization_id' => $claim->organization_id,
                    'claim_id' => $claim->getKey(),
                    'document_category' => $category,
                    'is_required' => true,
                    'is_fulfilled' => $fulfilled,
                    'evidence_file_id' => $fulfilled
                        ? $attachedByCategory->get($category)?->first()?->getKey()
                        : null,
                    'snapshot_at' => $now,
                    'overridden_by' => $prior?->overridden_by,
                    'override_reason' => $prior?->override_reason,
                    'overridden_at' => $prior?->overridden_at,
                ]);
            }
        });
    }

    /**
     * Override the readiness assessment for a claim.
     *
     * The override does not change the computed numbers; it records
     * that a reviewer accepted the gap, with a mandatory explanation.
     * A history entry is written so the decision is visible in the
     * claim timeline. The full audit trail lands in Milestone 4.
     *
     * @throws ValidationException when the reason is missing
     */
    public function override(Claim $claim, User $user, ?string $reason): array
    {
        if ($reason === null || trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'An explanation is required to override the readiness assessment.',
            ]);
        }

        return DB::transaction(function () use ($claim, $user, $reason) {
            $this->snapshot($claim);

            $claim->requirementSnapshots()
                ->where('is_fulfilled', false)
                ->update([
                    'overridden_by' => $user->id,
                    'override_reason' => $reason,
                    'overridden_at' => now(),
                ]);

            ClaimStatusHistory::create([
                'organization_id' => $claim->organization_id,
                'claim_id' => $claim->getKey(),
                'from_status' => $claim->status->value,
                'to_status' => $claim->status->value,
                'changed_by' => $user->id,
                'reason' => 'Readiness overridden: '.$reason,
                'metadata' => ['action' => 'readiness_override'],
            ]);

            return $this->compute($claim->fresh());
        });
    }

    /**
     * Evidence requirements that apply to a claim: global rows plus
     * rows matching the claim event's category.
     *
     * @return \Illuminate\Support\Collection<int, EvidenceRequirement>
     */
    protected function applicableRequirements(Claim $claim)
    {
        $eventCategoryId = $claim->event?->event_category_id;
        $cacheKey = $claim->organization_id.'|'.($eventCategoryId ?? 'null');

        if (! isset($this->requirementsCache[$cacheKey])) {
            $this->requirementsCache[$cacheKey] = EvidenceRequirement::forOrganization($claim->organization_id)
                ->where(function ($query) use ($eventCategoryId) {
                    $query->whereNull('event_category_id');

                    if ($eventCategoryId !== null) {
                        $query->orWhere('event_category_id', $eventCategoryId);
                    }
                })
                ->get();
        }

        return $this->requirementsCache[$cacheKey];
    }

    /**
     * The document categories attached to the claim as
     * evidence. Uses the loaded relation when the caller
     * eager-loaded it, so a bulk readiness pass does not
     * re-query per claim.
     *
     * @return array<int, string>
     */
    protected function attachedCategories(Claim $claim): array
    {
        $files = $claim->relationLoaded('evidenceFiles')
            ? $claim->evidenceFiles
            : $claim->evidenceFiles()->get();

        return $files
            ->pluck('document_category')
            ->map(fn ($category) => $category instanceof EvidenceDocumentCategory
                ? $category->value
                : (string) $category)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The claim's requirement snapshots. Uses the loaded
     * relation when the caller eager-loaded it.
     *
     * @return \Illuminate\Support\Collection<int, ClaimRequirement>
     */
    protected function requirementSnapshots(Claim $claim)
    {
        return $claim->relationLoaded('requirementSnapshots')
            ? $claim->requirementSnapshots
            : $claim->requirementSnapshots()->get();
    }

    /**
     * Turn category values into value/label pairs for the API.
     *
     * @param  array<int, string>  $categories
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function describeCategories(array $categories): array
    {
        return array_map(function (string $category) {
            $enum = EvidenceDocumentCategory::tryFrom($category);

            return [
                'value' => $category,
                'label' => $enum?->label() ?? $category,
            ];
        }, $categories);
    }
}
