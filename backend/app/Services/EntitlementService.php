<?php

namespace App\Services;

use App\Billing\PlanCatalog;
use App\Exceptions\EntitlementExceededException;
use App\Models\InboundEmailAttachment;
use App\Models\Organization;
use App\Models\Subscription;

/**
 * Plan entitlements (v1.2.1, v0.5.0 sections 8 and 9.2).
 *
 * The single service every creation endpoint consults. No endpoint
 * enforces limits by reading the plan name directly: it asks this
 * service, which resolves the organization's CURRENT plan from its
 * subscription (a trialing organization is on Growth, because the
 * trial sets plan=growth; a canceled subscription is Free, because
 * canceling resets the plan) and applies the limits from
 * config/billing.php.
 *
 * Limits, from v0.5.0 section 8 (buyer units):
 * - Claims per month: Free 5, Starter 20, Growth 100, Fleet 300,
 *   Agency 100 per carrier. Success Fee: unlimited.
 * - Members: Free 1, Starter 3, Growth 10, Fleet 25, Agency
 *   unlimited. Success Fee: unlimited.
 * - Storage: Free 100 MB, Starter 1 GB, Growth 10 GB, Fleet 50 GB,
 *   Agency 10 GB per carrier. Success Fee: unlimited. Measured on
 *   evidence files, claim packages, and unfiled email attachments
 *   (v1.3.0; an attachment counts once it is linked as evidence,
 *   never twice).
 * - PDF package exports: Free none, all paid tiers unlimited.
 *   Evidence files per claim have no hard cap; the storage limit
 *   governs them.
 * - Reports: Free gets the dashboard only; Starter and above get
 *   full reports. Agency consolidated cross-carrier reporting is a
 *   v2.1.0 feature, not a v1.2.1 gate.
 * - Shipments are NOT capped (v0.5.0 section 9.2): the claim cap
 *   is the binding limit, because capping shipments would stop a
 *   carrier recording loads they have already run.
 *
 * Downgrades never delete data: the lower limits apply from the
 * renewal, and new creation above them is refused with an upgrade
 * prompt while everything already created stays readable.
 */
class EntitlementService
{
    public function __construct(protected BillingService $billing)
    {
    }

    /**
     * The organization's effective plan key. A trialing
     * organization is on Growth (the trial sets plan=growth); a
     * canceled subscription falls back to Free.
     */
    public function effectivePlan(Organization $organization): string
    {
        $subscription = $this->billing->subscriptionFor($organization);

        if ($subscription->status === Subscription::STATUS_CANCELED) {
            return PlanCatalog::FREE;
        }

        return $subscription->plan;
    }

    /**
     * The organization's subscription (created lazily as Free).
     */
    public function subscription(Organization $organization): Subscription
    {
        return $this->billing->subscriptionFor($organization);
    }

    /**
     * The organization's carrier count. Agency limits are per
     * carrier, so this is the multiplier for that plan.
     */
    public function carrierCount(Organization $organization): int
    {
        return (int) $organization->carriers()->count();
    }

    /**
     * The resolved numeric limits for the organization's current
     * plan. A null limit means unlimited.
     *
     * @return array{plan: string, plan_name: string, claims_per_month: int|null, members: int|null, storage_bytes: int|null, pdf_packages: bool, reports: bool}
     */
    public function limits(Organization $organization): array
    {
        $plan = $this->effectivePlan($organization);

        return array_merge(
            ['plan' => $plan, 'plan_name' => PlanCatalog::name($plan)],
            $this->limitsForPlan($plan, $this->carrierCount($organization)),
        );
    }

    /**
     * The resolved numeric limits for a plan key at a carrier
     * count. Agency claims and storage scale per carrier.
     *
     * @return array{claims_per_month: int|null, members: int|null, storage_bytes: int|null, pdf_packages: bool, reports: bool, workflows: bool}
     */
    public function limitsForPlan(string $plan, int $carriers): array
    {
        $config = PlanCatalog::plan($plan)['limits'] ?? [];
        $carriers = max(1, $carriers);

        $claims = $config['claims_per_month'] ?? null;
        if ($plan === PlanCatalog::AGENCY && $claims !== null) {
            $claims *= $carriers;
        }

        $storage = isset($config['storage'])
            ? $this->parseBytes((string) $config['storage'])
            : null;
        if ($plan === PlanCatalog::AGENCY && $storage !== null) {
            $storage *= $carriers;
        }

        return [
            'claims_per_month' => $claims,
            'members' => $config['members'] ?? null,
            'storage_bytes' => $storage,
            'pdf_packages' => (bool) ($config['pdf_packages'] ?? false),
            'reports' => $plan !== PlanCatalog::FREE,
            'workflows' => $plan !== PlanCatalog::FREE,
        ];
    }

    /**
     * Current usage against the limits: claims created in the
     * current billing period plus any carried over from the
     * previous period when a move to a lower plan took effect at
     * this renewal (the carryover covers one period only; the
     * next renewal clears it), active members plus pending
     * invitations (an invitation is a future member, so it counts
     * against the cap before it is accepted), and storage used by
     * evidence files and claim packages.
     *
     * @return array{claims_created: int, claims_carryover: int, members: int, pending_invitations: int, storage_bytes: int}
     */
    public function usage(Organization $organization): array
    {
        $subscription = $this->billing->subscriptionFor($organization);

        $claims = $organization->claims()
            ->where('created_at', '>=', $subscription->current_period_start)
            ->where('created_at', '<', $subscription->current_period_end)
            ->count();

        $evidenceBytes = (int) $organization->evidenceFiles()->sum('file_size');
        $packageBytes = (int) $organization->generatedDocuments()->sum('file_size');

        // Unfiled email attachments also consume storage: bytes
        // stored from inbound mail count until the attachment is
        // linked as evidence, at which point the evidence row
        // counts it instead. Counting only rows without an
        // evidence link keeps every byte counted exactly once.
        $intakeBytes = (int) InboundEmailAttachment::query()
            ->whereNull('evidence_file_id')
            ->whereHas('email', fn ($query) => $query->where('organization_id', $organization->getKey()))
            ->sum('size_bytes');

        return [
            'claims_created' => (int) $claims + (int) $subscription->claims_carryover,
            'claims_carryover' => (int) $subscription->claims_carryover,
            'members' => (int) $organization->activeMembers()->count(),
            'pending_invitations' => (int) $organization->pendingInvitations()->count(),
            'storage_bytes' => $evidenceBytes + $packageBytes + $intakeBytes,
        ];
    }

    /**
     * The usage summary for the billing page: plan, period,
     * limits, usage, and the upgrade that removes each limit.
     */
    public function summary(Organization $organization): array
    {
        $subscription = $this->billing->subscriptionFor($organization);
        $plan = $this->effectivePlan($organization);
        $limits = $this->limits($organization);
        $usage = $this->usage($organization);

        return [
            'plan' => $plan,
            'plan_name' => PlanCatalog::name($plan),
            'status' => $subscription->status,
            'period_start' => $subscription->current_period_start?->toIso8601String(),
            'period_end' => $subscription->current_period_end?->toIso8601String(),
            'carriers' => $this->carrierCount($organization),
            'limits' => $limits,
            'usage' => $usage,
            'upgrade' => [
                'claims' => $this->nextPlanFor('claims', $organization),
                'members' => $this->nextPlanFor('members', $organization),
                'storage' => $this->nextPlanFor('storage', $organization),
                'pdf_packages' => $this->nextPlanFor('pdf_packages', $organization),
                'reports' => $this->nextPlanFor('reports', $organization),
            ],
        ];
    }

    /**
     * The cheapest listed plan that raises the given limit above
     * the organization's current one, or null when no listed plan
     * does (the current plan is already the highest for that
     * limit at this carrier count).
     *
     * @return array{key: string, name: string}|null
     */
    public function nextPlanFor(string $resource, Organization $organization): ?array
    {
        $plan = $this->effectivePlan($organization);
        $carriers = $this->carrierCount($organization);
        $current = $this->limitsForPlan($plan, $carriers);

        $candidates = collect(PlanCatalog::listedKeys())
            ->sortBy(fn (string $key) => PlanCatalog::monthlyCents($key))
            ->values();

        foreach ($candidates as $candidate) {
            if ($candidate === $plan) {
                continue;
            }

            $candidateLimits = $this->limitsForPlan($candidate, $carriers);
            $currentValue = $current[$this->limitKey($resource)];
            $candidateValue = $candidateLimits[$this->limitKey($resource)];

            if ($this->raises($candidateValue, $currentValue)) {
                return ['key' => $candidate, 'name' => PlanCatalog::name($candidate)];
            }
        }

        return null;
    }

    /**
     * Refuse claim creation when the organization is at its
     * monthly claim cap. Claims already created are never
     * deleted by a cap; only new creation is refused.
     *
     * @throws EntitlementExceededException
     */
    public function authorizeClaimCreation(Organization $organization): void
    {
        $limits = $this->limits($organization);
        $usage = $this->usage($organization);
        $limit = $limits['claims_per_month'];

        if ($limit === null || $usage['claims_created'] < $limit) {
            return;
        }

        $upgrade = $this->nextPlanFor('claims', $organization);

        throw new EntitlementExceededException(
            'claims',
            $limit,
            $usage['claims_created'],
            $limits['plan'],
            $upgrade['key'] ?? null,
            $upgrade['name'] ?? null,
            $this->claimMessage($limits, $usage['claims_created'], $upgrade),
        );
    }

    /**
     * Refuse an invitation when active members plus pending
     * invitations are already at the member cap.
     *
     * @throws EntitlementExceededException
     */
    public function authorizeInvitation(Organization $organization): void
    {
        $limits = $this->limits($organization);
        $usage = $this->usage($organization);
        $limit = $limits['members'];

        if ($limit === null) {
            return;
        }

        $used = $usage['members'] + $usage['pending_invitations'];

        if ($used < $limit) {
            return;
        }

        $upgrade = $this->nextPlanFor('members', $organization);

        throw new EntitlementExceededException(
            'members',
            $limit,
            $used,
            $limits['plan'],
            $upgrade['key'] ?? null,
            $upgrade['name'] ?? null,
            $this->memberMessage($limits, $used, $upgrade),
        );
    }

    /**
     * Refuse an upload when the file would take the organization
     * past its storage cap.
     *
     * @throws EntitlementExceededException
     */
    public function authorizeUpload(Organization $organization, int $bytes): void
    {
        $limits = $this->limits($organization);
        $usage = $this->usage($organization);
        $limit = $limits['storage_bytes'];

        if ($limit === null || $usage['storage_bytes'] + $bytes <= $limit) {
            return;
        }

        $upgrade = $this->nextPlanFor('storage', $organization);

        throw new EntitlementExceededException(
            'storage',
            $limit,
            $usage['storage_bytes'],
            $limits['plan'],
            $upgrade['key'] ?? null,
            $upgrade['name'] ?? null,
            $this->storageMessage($limits, $usage['storage_bytes'], $upgrade),
        );
    }

    /**
     * Refuse PDF package generation on plans without it (Free).
     *
     * @throws EntitlementExceededException
     */
    public function authorizePackageGeneration(Organization $organization): void
    {
        $limits = $this->limits($organization);

        if ($limits['pdf_packages']) {
            return;
        }

        $upgrade = $this->nextPlanFor('pdf_packages', $organization);

        throw new EntitlementExceededException(
            'pdf_packages',
            null,
            0,
            $limits['plan'],
            $upgrade['key'] ?? null,
            $upgrade['name'] ?? null,
            'PDF package exports are not included in the '.$limits['plan_name'].' plan.'
                .($upgrade !== null
                    ? ' Upgrade to '.$upgrade['name'].' to generate claim packages.'
                    : ''),
        );
    }

    /**
     * Refuse full reports on the Free plan. The dashboard is a
     * separate screen and stays available to every plan.
     *
     * @throws EntitlementExceededException
     */
    public function authorizeReports(Organization $organization): void
    {
        $limits = $this->limits($organization);

        if ($limits['reports']) {
            return;
        }

        $upgrade = $this->nextPlanFor('reports', $organization);

        throw new EntitlementExceededException(
            'reports',
            null,
            0,
            $limits['plan'],
            $upgrade['key'] ?? null,
            $upgrade['name'] ?? null,
            'Full reports are not included in the '.$limits['plan_name'].' plan.'
                .($upgrade !== null
                    ? ' Upgrade to '.$upgrade['name'].' to view reports.'
                    : ''),
        );
    }

    /**
     * Refuse workflow configuration on the Free plan. Reading
     * workflows and runs stays available: an organization that
     * downgrades keeps its history, it just cannot create or
     * change workflows, and its existing workflows stop firing.
     *
     * @throws EntitlementExceededException
     */
    public function authorizeWorkflows(Organization $organization): void
    {
        $limits = $this->limits($organization);

        if ($limits['workflows']) {
            return;
        }

        $upgrade = $this->nextPlanFor('workflows', $organization);

        throw new EntitlementExceededException(
            'workflows',
            null,
            0,
            $limits['plan'],
            $upgrade['key'] ?? null,
            $upgrade['name'] ?? null,
            'Workflows are not included in the '.$limits['plan_name'].' plan.'
                .($upgrade !== null
                    ? ' Upgrade to '.$upgrade['name'].' to use workflows.'
                    : ''),
        );
    }

    /**
     * Whether a candidate limit raises the current one. A null
     * limit is unlimited and always raises a finite one; a bool
     * raises only false to true.
     */
    protected function raises(mixed $candidate, mixed $current): bool
    {
        if (is_bool($current)) {
            return $candidate === true && $current === false;
        }

        if ($candidate === null) {
            return $current !== null;
        }

        return $current === null ? false : $candidate > $current;
    }

    protected function limitKey(string $resource): string
    {
        return match ($resource) {
            'claims' => 'claims_per_month',
            'members' => 'members',
            'storage' => 'storage_bytes',
            'pdf_packages' => 'pdf_packages',
            'reports' => 'reports',
            default => $resource,
        };
    }

    protected function claimMessage(array $limits, int $used, ?array $upgrade): string
    {
        $base = 'Your '.$limits['plan_name'].' plan includes '.$limits['claims_per_month'].' claims per month. You have used '.$used.'.';

        if ($upgrade !== null) {
            return $base.' Upgrade to '.$upgrade['name'].' to create more claims.';
        }

        return $base.' No higher plan raises the monthly claim limit for your organization.';
    }

    protected function memberMessage(array $limits, int $used, ?array $upgrade): string
    {
        $base = 'Your '.$limits['plan_name'].' plan includes '.$limits['members'].' members. Your organization has '.$used.' members and pending invitations.';

        if ($upgrade !== null) {
            return $base.' Upgrade to '.$upgrade['name'].' to invite more people.';
        }

        return $base.' No higher plan raises the member limit.';
    }

    protected function storageMessage(array $limits, int $usedBytes, ?array $upgrade): string
    {
        $base = 'Your '.$limits['plan_name'].' plan includes '.$this->formatBytes((int) $limits['storage_bytes']).' of storage. Your organization is using '.$this->formatBytes($usedBytes).'.';

        if ($upgrade !== null) {
            return $base.' Upgrade to '.$upgrade['name'].' to upload more files.';
        }

        return $base.' No higher plan raises the storage limit for your organization.';
    }

    /**
     * Parse a config size such as "100 MB", "1 GB" or "512 KB"
     * into bytes.
     */
    public function parseBytes(string $value): int
    {
        if (! preg_match('/^([\d.]+)\s*([KMGT]?B)?$/i', trim($value), $matches)) {
            return 0;
        }

        $number = (float) $matches[1];
        $unit = strtoupper($matches[2] ?? 'B');

        return (int) round($number * match ($unit) {
            'KB' => 1024,
            'MB' => 1024 ** 2,
            'GB' => 1024 ** 3,
            'TB' => 1024 ** 4,
            default => 1,
        });
    }

    /**
     * Format bytes for a human-readable message.
     */
    public function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 ** 3) {
            return rtrim(rtrim(number_format($bytes / (1024 ** 3), 2), '0'), '.').' GB';
        }

        if ($bytes >= 1024 ** 2) {
            return rtrim(rtrim(number_format($bytes / (1024 ** 2), 2), '0'), '.').' MB';
        }

        if ($bytes >= 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 2), '0'), '.').' KB';
        }

        return $bytes.' B';
    }
}
