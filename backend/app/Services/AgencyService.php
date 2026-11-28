<?php

namespace App\Services;

use App\Billing\PlanCatalog;
use App\Models\AgencyCarrierGrant;
use App\Models\Claim;
use App\Models\ClaimPayment;
use App\Models\CommissionRule;
use App\Models\Organization;
use App\Models\PartnerLink;
use App\Models\ReferralCode;
use App\Models\User;
use App\OrganizationRole;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Agency management (v2.1.0): partner links, carrier scoping,
 * the agency dashboard, consolidated reporting, referral codes,
 * commission rules and computed earnings, and reseller workflows.
 *
 * Everything here is computed only from the organization's own
 * recorded data: claims and payments the linked carrier orgs
 * recorded themselves. There are no external benchmarks, no
 * live partner API, and no real money: billing runs on the
 * mock provider, commission is a calculated figure from
 * recorded payments, and nothing here pays a partner out.
 *
 * Money is summed per currency, never converted: there is no
 * exchange rate anywhere in the app, so a carrier with USD and
 * EUR claims reports one bucket per currency instead of a
 * single blended total.
 *
 * Access: owners and admins of the agency organization see
 * every linked carrier. Every other agency member (in
 * practice, operations managers, the only other role with
 * manage-agency) needs a per-carrier grant for each carrier
 * they may touch, with a view or manage scope. A suspended
 * link grants nothing until reactivated.
 */
class AgencyService
{
    public function __construct(
        protected BillingService $billing,
        protected EntitlementService $entitlements,
    ) {}

    // -- Access ------------------------------------------------------------

    /**
     * Whether the user is an owner or admin of the organization.
     * Owners and admins bypass per-carrier grants.
     */
    public function isAgencyAdmin(Organization $organization, User $user): bool
    {
        $membership = $user->membershipIn($organization);

        return $membership !== null
            && $membership->isActive()
            && in_array($membership->role, [OrganizationRole::Owner, OrganizationRole::Admin], true);
    }

    /**
     * Whether the user is an owner or admin of the organization.
     * Used on the carrier side of the approval flow.
     */
    public function isCarrierAdmin(Organization $organization, User $user): bool
    {
        return $this->isAgencyAdmin($organization, $user);
    }

    /**
     * The links the user may see in the agency organization:
     * every link for owners and admins, only the granted links
     * for everyone else.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PartnerLink>
     */
    public function visibleLinks(Organization $agencyOrganization, User $user)
    {
        $links = PartnerLink::forAgency($agencyOrganization->getKey())
            ->with(['carrierOrganization', 'grants'])
            ->orderBy('created_at')
            ->get();

        if ($this->isAgencyAdmin($agencyOrganization, $user)) {
            return $links;
        }

        $grantedLinkIds = AgencyCarrierGrant::query()
            ->where('user_id', $user->getKey())
            ->whereIn('partner_link_id', $links->pluck('id'))
            ->pluck('partner_link_id')
            ->all();

        return $links->whereIn('id', $grantedLinkIds)->values();
    }

    /**
     * Refuse access to a carrier unless the link is active and
     * the user may touch it: owners and admins of the agency
     * pass, everyone else needs a grant covering the scope.
     * A grant tied to a suspended or removed link grants
     * nothing, because the link itself is not active.
     */
    public function assertCarrierAccess(
        Organization $agencyOrganization,
        User $user,
        PartnerLink $link,
        string $scope = AgencyCarrierGrant::SCOPE_VIEW,
    ): void {
        if (! $link->isActive()) {
            abort(403, 'This partner link is not active.');
        }

        if ($this->isAgencyAdmin($agencyOrganization, $user)) {
            return;
        }

        $grant = AgencyCarrierGrant::query()
            ->where('partner_link_id', $link->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if ($grant === null || ! $grant->covers($scope)) {
            abort(403, 'You do not have access to this carrier.');
        }
    }

    /**
     * Refuse unless the user is an owner or admin of the agency
     * organization. Grant administration and plan assignment are
     * admin actions: they decide who sees a carrier and what the
     * carrier is billed, so they are not delegated to operations
     * managers.
     */
    public function assertAgencyAdmin(Organization $agencyOrganization, User $user): void
    {
        if (! $this->isAgencyAdmin($agencyOrganization, $user)) {
            abort(403, 'Only an owner or admin of the agency can do this.');
        }
    }

    // -- Partner links -----------------------------------------------------

    /**
     * Links for the agency, newest first, with a status filter.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function listLinks(Organization $agencyOrganization, ?string $status, int $perPage = 25)
    {
        return PartnerLink::forAgency($agencyOrganization->getKey())
            ->with(['carrierOrganization', 'approver', 'creator'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Create a partner link. The link starts pending: it grants
     * nothing until an owner or admin of the CARRIER organization
     * approves it, so an agency cannot claim a carrier
     * unilaterally. A referral code, when given, must be an
     * active code of this agency; the code is recorded on the
     * link as the attribution.
     */
    public function createLink(
        Organization $agencyOrganization,
        string $carrierOrganizationId,
        ?string $referralCode,
        ?string $notes,
        User $creator,
    ): PartnerLink {
        $carrierOrganization = Organization::query()->whereKey($carrierOrganizationId)->first();

        if ($carrierOrganization === null) {
            abort(404, 'Carrier organization not found.');
        }

        if ($carrierOrganization->getKey() === $agencyOrganization->getKey()) {
            throw ValidationException::withMessages([
                'carrier_organization_id' => 'An organization cannot partner with itself.',
            ]);
        }

        $existing = PartnerLink::query()
            ->where('agency_organization_id', $agencyOrganization->getKey())
            ->where('carrier_organization_id', $carrierOrganization->getKey())
            ->where('status', '!=', PartnerLink::STATUS_REMOVED)
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'carrier_organization_id' => 'A partner link to this carrier already exists (status: '.$existing->status.').',
            ]);
        }

        $code = null;

        if ($referralCode !== null && $referralCode !== '') {
            $code = ReferralCode::query()
                ->where('organization_id', $agencyOrganization->getKey())
                ->where('code', $referralCode)
                ->first();

            if ($code === null) {
                throw ValidationException::withMessages([
                    'referral_code' => 'No such referral code exists for this agency.',
                ]);
            }

            if (! $code->active) {
                throw ValidationException::withMessages([
                    'referral_code' => 'This referral code is deactivated.',
                ]);
            }
        }

        return PartnerLink::create([
            'agency_organization_id' => $agencyOrganization->getKey(),
            'carrier_organization_id' => $carrierOrganization->getKey(),
            'status' => PartnerLink::STATUS_PENDING,
            'referred_by_code' => $code?->code,
            'created_by' => $creator->getKey(),
            'notes' => $notes,
        ]);
    }

    /**
     * Approve a pending link, from the carrier side: the caller
     * must be an owner or admin of the CARRIER organization.
     * Agency members cannot approve their own link.
     */
    public function approveLink(PartnerLink $link, User $user): PartnerLink
    {
        $carrierOrganization = $link->carrierOrganization;

        if ($carrierOrganization === null || ! $this->isCarrierAdmin($carrierOrganization, $user)) {
            abort(403, 'Only an owner or admin of the carrier organization can approve this link.');
        }

        if ($link->status !== PartnerLink::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'Only a pending link can be approved (current status: '.$link->status.').',
            ]);
        }

        $link->update([
            'status' => PartnerLink::STATUS_APPROVED,
            'approved_by' => $user->getKey(),
            'approved_at' => now(),
        ]);

        return $link->fresh();
    }

    /**
     * Reject a pending link, from the carrier side.
     */
    public function rejectLink(PartnerLink $link, User $user): PartnerLink
    {
        $carrierOrganization = $link->carrierOrganization;

        if ($carrierOrganization === null || ! $this->isCarrierAdmin($carrierOrganization, $user)) {
            abort(403, 'Only an owner or admin of the carrier organization can reject this link.');
        }

        if ($link->status !== PartnerLink::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'Only a pending link can be rejected (current status: '.$link->status.').',
            ]);
        }

        $link->update(['status' => PartnerLink::STATUS_REJECTED]);

        return $link->fresh();
    }

    /**
     * Suspend an approved link. Suspended links keep their
     * history but grant no access until reactivated.
     */
    public function suspendLink(PartnerLink $link): PartnerLink
    {
        if ($link->status !== PartnerLink::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'status' => 'Only an approved link can be suspended (current status: '.$link->status.').',
            ]);
        }

        $link->update(['status' => PartnerLink::STATUS_SUSPENDED]);

        return $link->fresh();
    }

    /**
     * Reactivate a suspended link.
     */
    public function reactivateLink(PartnerLink $link): PartnerLink
    {
        if ($link->status !== PartnerLink::STATUS_SUSPENDED) {
            throw ValidationException::withMessages([
                'status' => 'Only a suspended link can be reactivated (current status: '.$link->status.').',
            ]);
        }

        $link->update(['status' => PartnerLink::STATUS_APPROVED]);

        return $link->fresh();
    }

    /**
     * Remove a link. The row is kept with status removed, never
     * deleted, so referral attribution and commission history
     * survive the removal.
     */
    public function removeLink(PartnerLink $link): PartnerLink
    {
        if ($link->status === PartnerLink::STATUS_REMOVED) {
            throw ValidationException::withMessages([
                'status' => 'This link is already removed.',
            ]);
        }

        $link->update(['status' => PartnerLink::STATUS_REMOVED]);

        return $link->fresh();
    }

    /**
     * Assign a plan to the carrier organization (reseller plan
     * assignment). The change runs through the normal billing
     * change-plan flow on the mock provider: an upgrade takes
     * effect immediately, a downgrade is scheduled for the next
     * renewal, and the plan is recorded on the link. No real
     * money moves: billing runs on the mock provider.
     */
    public function assignPlan(PartnerLink $link, string $plan, User $actor): PartnerLink
    {
        if (! PlanCatalog::exists($plan)) {
            throw ValidationException::withMessages([
                'plan' => 'Unknown plan: '.$plan.'.',
            ]);
        }

        if (! $link->isActive()) {
            throw ValidationException::withMessages([
                'plan' => 'A plan can only be assigned to an active partner link.',
            ]);
        }

        $carrierOrganization = $link->carrierOrganization;

        if ($carrierOrganization === null) {
            abort(404, 'Carrier organization not found.');
        }

        $this->billing->changePlan($carrierOrganization, $plan, 'monthly', 0, $actor);

        $link->update(['assigned_plan' => $plan]);

        return $link->fresh();
    }

    // -- Dashboard and reporting -------------------------------------------

    /**
     * Claim totals for one carrier organization, grouped by
     * currency. Claims carry their own currency, and there is
     * no exchange rate anywhere, so totals are one bucket per
     * currency instead of a single blended number.
     *
     * @return array{claims_total: int, by_status: array<string, int>, totals: array<int, array{currency: string, requested: string, accepted: string, recovered: string, payments: string, outstanding: string}>}
     */
    public function carrierStats(Organization $carrierOrganization): array
    {
        // forOrganization bypasses the tenant global scope: the
        // resolved organization here is the agency, but these
        // rows belong to the carrier. The access check happened
        // in the caller (assertCarrierAccess / visibleLinks).
        $byStatus = Claim::forOrganization($carrierOrganization)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $totals = Claim::forOrganization($carrierOrganization)
            ->selectRaw('currency, SUM(requested_amount) as requested, SUM(accepted_amount) as accepted, SUM(recovered_amount) as recovered, COUNT(*) as claims')
            ->groupBy('currency')
            ->get();

        $payments = ClaimPayment::forOrganization($carrierOrganization)
            ->selectRaw('currency, SUM(amount) as paid')
            ->groupBy('currency')
            ->pluck('paid', 'currency')
            ->map(fn ($amount) => (string) $amount)
            ->all();

        $rows = [];

        foreach ($totals as $row) {
            $requested = (string) ($row->requested ?? '0');
            $accepted = (string) ($row->accepted ?? '0');
            $recovered = (string) ($row->recovered ?? '0');
            $paid = $payments[$row->currency] ?? '0';
            // Outstanding is what is still owed: the amount due
            // (accepted when set, otherwise requested) minus what
            // was recovered. It never goes below zero.
            $due = bccomp($accepted, '0', 2) > 0 ? $accepted : $requested;
            $outstanding = bccomp($recovered, $due, 2) >= 0 ? '0.00' : bcsub($due, $recovered, 2);

            $rows[] = [
                'currency' => $row->currency,
                'requested' => number_format((float) $requested, 2, '.', ''),
                'accepted' => number_format((float) $accepted, 2, '.', ''),
                'recovered' => number_format((float) $recovered, 2, '.', ''),
                'payments' => number_format((float) $paid, 2, '.', ''),
                'outstanding' => number_format((float) $outstanding, 2, '.', ''),
            ];
        }

        // A carrier with payments but no claims in the currency
        // cannot exist (payments attach to claims), so the rows
        // above cover every currency with recorded money.

        return [
            'claims_total' => (int) array_sum(array_values($byStatus)),
            'by_status' => $byStatus,
            'totals' => $rows,
        ];
    }

    /**
     * The agency dashboard: per-carrier stats for the active
     * links the user may see, consolidated totals merged by
     * currency, link counts by status, and the referral and
     * commission summaries.
     */
    public function dashboard(Organization $agencyOrganization, User $user): array
    {
        $links = $this->visibleLinks($agencyOrganization, $user)
            ->where('status', PartnerLink::STATUS_APPROVED);

        $carriers = [];
        $totalsByCurrency = [];

        foreach ($links as $link) {
            $carrierOrganization = $link->carrierOrganization;

            if ($carrierOrganization === null) {
                continue;
            }

            $stats = $this->carrierStats($carrierOrganization);
            $commission = $this->commissionForLink($link);

            $carriers[] = array_merge(
                ['carrier' => $this->presentCarrier($carrierOrganization, $link)],
                $stats,
                ['commission' => $commission],
            );

            foreach ($stats['totals'] as $bucket) {
                $currency = $bucket['currency'];
                $totalsByCurrency[$currency] ??= [
                    'currency' => $currency,
                    'requested' => '0.00',
                    'accepted' => '0.00',
                    'recovered' => '0.00',
                    'payments' => '0.00',
                    'outstanding' => '0.00',
                ];

                foreach (['requested', 'accepted', 'recovered', 'payments', 'outstanding'] as $key) {
                    $totalsByCurrency[$currency][$key] = bcadd($totalsByCurrency[$currency][$key], $bucket[$key], 2);
                }
            }
        }

        $allLinks = PartnerLink::forAgency($agencyOrganization->getKey())->get();

        return [
            'agency_organization_id' => $agencyOrganization->getKey(),
            'carriers' => $carriers,
            'totals_by_currency' => array_values($totalsByCurrency),
            'link_counts' => [
                'total' => $allLinks->count(),
                'pending' => $allLinks->where('status', PartnerLink::STATUS_PENDING)->count(),
                'approved' => $allLinks->where('status', PartnerLink::STATUS_APPROVED)->count(),
                'suspended' => $allLinks->where('status', PartnerLink::STATUS_SUSPENDED)->count(),
                'rejected' => $allLinks->where('status', PartnerLink::STATUS_REJECTED)->count(),
                'removed' => $allLinks->where('status', PartnerLink::STATUS_REMOVED)->count(),
            ],
            'referrals' => $this->referralSummary($agencyOrganization),
            'commission' => $this->commissions($agencyOrganization, $user),
        ];
    }

    /**
     * The consolidated cross-carrier report: one row per
     * carrier the user may see, plus totals merged by currency.
     * Claims are filtered by created date and payments by paid
     * date, so the report always states the window it covers.
     * Computed only from the orgs' own recorded claims and
     * payments: no external benchmarks exist here.
     */
    public function consolidatedReport(
        Organization $agencyOrganization,
        User $user,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $carrierOrganizationId,
    ): array {
        $links = $this->visibleLinks($agencyOrganization, $user)
            ->where('status', PartnerLink::STATUS_APPROVED);

        if ($carrierOrganizationId !== null) {
            $links = $links->where('carrier_organization_id', $carrierOrganizationId)->values();

            if ($links->isEmpty()) {
                abort(404, 'No active partner link for this carrier.');
            }
        }

        $rows = [];
        $totalsByCurrency = [];

        foreach ($links as $link) {
            $carrierOrganization = $link->carrierOrganization;

            if ($carrierOrganization === null) {
                continue;
            }

            // forOrganization bypasses the tenant global scope:
            // the resolved organization is the agency, but these
            // rows belong to the carrier.
            $claims = Claim::forOrganization($carrierOrganization)
                ->when($dateFrom !== null, fn ($query) => $query->whereDate('created_at', '>=', $dateFrom))
                ->when($dateTo !== null, fn ($query) => $query->whereDate('created_at', '<=', $dateTo));

            $byStatus = (clone $claims)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->map(fn ($count) => (int) $count)
                ->all();

            $buckets = (clone $claims)
                ->selectRaw('currency, SUM(requested_amount) as requested, SUM(accepted_amount) as accepted, SUM(recovered_amount) as recovered, COUNT(*) as claims')
                ->groupBy('currency')
                ->get();

            $payments = ClaimPayment::forOrganization($carrierOrganization)
                ->when($dateFrom !== null, fn ($query) => $query->whereDate('paid_at', '>=', $dateFrom))
                ->when($dateTo !== null, fn ($query) => $query->whereDate('paid_at', '<=', $dateTo))
                ->selectRaw('currency, SUM(amount) as paid')
                ->groupBy('currency')
                ->pluck('paid', 'currency')
                ->map(fn ($amount) => (string) $amount)
                ->all();

            $totals = [];

            foreach ($buckets as $bucket) {
                $requested = (string) ($bucket->requested ?? '0');
                $accepted = (string) ($bucket->accepted ?? '0');
                $recovered = (string) ($bucket->recovered ?? '0');
                $paid = $payments[$bucket->currency] ?? '0';
                $due = bccomp($accepted, '0', 2) > 0 ? $accepted : $requested;
                $outstanding = bccomp($recovered, $due, 2) >= 0 ? '0.00' : bcsub($due, $recovered, 2);

                $totals[] = [
                    'currency' => $bucket->currency,
                    'requested' => number_format((float) $requested, 2, '.', ''),
                    'accepted' => number_format((float) $accepted, 2, '.', ''),
                    'recovered' => number_format((float) $recovered, 2, '.', ''),
                    'payments' => number_format((float) $paid, 2, '.', ''),
                    'outstanding' => number_format((float) $outstanding, 2, '.', ''),
                ];

                $currency = $bucket->currency;
                $totalsByCurrency[$currency] ??= [
                    'currency' => $currency,
                    'requested' => '0.00',
                    'accepted' => '0.00',
                    'recovered' => '0.00',
                    'payments' => '0.00',
                    'outstanding' => '0.00',
                ];

                foreach (['requested', 'accepted', 'recovered', 'payments', 'outstanding'] as $key) {
                    $totalsByCurrency[$currency][$key] = bcadd($totalsByCurrency[$currency][$key], $totals[count($totals) - 1][$key], 2);
                }
            }

            $rows[] = [
                'carrier' => $this->presentCarrier($carrierOrganization, $link),
                'claims_total' => (int) array_sum(array_values($byStatus)),
                'by_status' => $byStatus,
                'totals' => $totals,
            ];
        }

        return [
            'agency_organization_id' => $agencyOrganization->getKey(),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'carrier_organization_id' => $carrierOrganizationId,
            'carriers' => $rows,
            'totals_by_currency' => array_values($totalsByCurrency),
            'note' => 'Computed only from the linked carriers\' own recorded claims and payments. No external benchmarks.',
        ];
    }

    // -- Carrier claims (scoped read) --------------------------------------

    /**
     * A carrier's claims, as the agency sees them: read-only.
     * Writing a claim stays with the carrier organization
     * itself; the agency manages the portfolio, not the
     * carrier's own records.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function listCarrierClaims(
        Organization $agencyOrganization,
        User $user,
        string $carrierOrganizationId,
        ?string $status,
        int $perPage = 25,
    ) {
        $link = $this->linkForCarrier($agencyOrganization, $carrierOrganizationId);
        $this->assertCarrierAccess($agencyOrganization, $user, $link);

        // forOrganization bypasses the tenant global scope: the
        // resolved organization is the agency, but these rows
        // belong to the carrier.
        return Claim::forOrganization($carrierOrganizationId)
            ->with(['shipment'])
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * One carrier claim, as the agency sees it.
     */
    public function showCarrierClaim(
        Organization $agencyOrganization,
        User $user,
        string $carrierOrganizationId,
        string $claimId,
    ): Claim {
        $link = $this->linkForCarrier($agencyOrganization, $carrierOrganizationId);
        $this->assertCarrierAccess($agencyOrganization, $user, $link);

        $claim = Claim::forOrganization($carrierOrganizationId)
            ->whereKey($claimId)
            ->with(['shipment', 'payments'])
            ->first();

        if ($claim === null) {
            abort(404, 'Claim not found.');
        }

        return $claim;
    }

    /**
     * The link to a carrier, or 404 when the agency has no link
     * to it at all. A missing link is a 404, not a 403: the
     * carrier is simply not one of this agency's carriers.
     */
    public function linkForCarrier(Organization $agencyOrganization, string $carrierOrganizationId): PartnerLink
    {
        $link = PartnerLink::query()
            ->where('agency_organization_id', $agencyOrganization->getKey())
            ->where('carrier_organization_id', $carrierOrganizationId)
            ->first();

        if ($link === null) {
            abort(404, 'No partner link for this carrier.');
        }

        return $link;
    }

    // -- Referral codes ----------------------------------------------------

    /**
     * The agency's referral codes, newest first.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function listCodes(Organization $agencyOrganization, int $perPage = 25)
    {
        return ReferralCode::query()
            ->where('organization_id', $agencyOrganization->getKey())
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Create a referral code. The code is the configured prefix
     * plus a random part, and is unique across all agencies.
     */
    public function createCode(Organization $agencyOrganization, ?string $notes, User $creator): ReferralCode
    {
        $prefix = (string) config('agency.referral_code_prefix', 'REF');
        $length = (int) config('agency.referral_code_length', 8);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = $prefix.'-'.Str::upper(Str::random($length));

            if (! ReferralCode::query()->where('code', $code)->exists()) {
                return ReferralCode::create([
                    'organization_id' => $agencyOrganization->getKey(),
                    'code' => $code,
                    'active' => true,
                    'created_by' => $creator->getKey(),
                    'notes' => $notes,
                ]);
            }
        }

        throw ValidationException::withMessages([
            'code' => 'Could not generate a unique referral code. Try again.',
        ]);
    }

    /**
     * Deactivate a code. Deactivation never rewrites history:
     * links that already cite the code keep their attribution.
     */
    public function deactivateCode(ReferralCode $code): ReferralCode
    {
        $code->update(['active' => false]);

        return $code->fresh();
    }

    /**
     * Referral summary for the dashboard: codes issued, active
     * codes, and carriers attributed to this agency's codes.
     */
    public function referralSummary(Organization $agencyOrganization): array
    {
        $codes = ReferralCode::query()
            ->where('organization_id', $agencyOrganization->getKey())
            ->get();

        $attributed = PartnerLink::query()
            ->where('agency_organization_id', $agencyOrganization->getKey())
            ->whereNotNull('referred_by_code')
            ->count();

        return [
            'codes_total' => $codes->count(),
            'codes_active' => $codes->where('active', true)->count(),
            'carriers_attributed' => $attributed,
        ];
    }

    // -- Commission rules and earnings -------------------------------------

    /**
     * The agency's commission rules, default first, then
     * per-carrier rules.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CommissionRule>
     */
    public function listRules(Organization $agencyOrganization)
    {
        return CommissionRule::query()
            ->where('agency_organization_id', $agencyOrganization->getKey())
            ->with(['partnerLink.carrierOrganization'])
            ->orderByRaw('partner_link_id IS NOT NULL')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Create a commission rule. A default rule (no carrier)
     * replaces the configured default rate for the whole agency,
     * and there can be only one active default at a time. A
     * per-carrier rule needs an active link to that carrier.
     */
    public function createRule(
        Organization $agencyOrganization,
        User $user,
        ?string $partnerLinkId,
        float $ratePercent,
        ?int $capCents,
        ?string $effectiveFrom,
    ): CommissionRule {
        if ($partnerLinkId === null) {
            $this->assertAgencyAdmin($agencyOrganization, $user);

            $existing = CommissionRule::query()
                ->where('agency_organization_id', $agencyOrganization->getKey())
                ->whereNull('partner_link_id')
                ->where('active', true)
                ->first();

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'partner_link_id' => 'An active default commission rule already exists. Update or deactivate it first.',
                ]);
            }

            return CommissionRule::create([
                'agency_organization_id' => $agencyOrganization->getKey(),
                'partner_link_id' => null,
                'rate_percent' => $ratePercent,
                'cap_cents' => $capCents,
                'basis' => CommissionRule::BASIS_PAID,
                'active' => true,
                'effective_from' => $effectiveFrom,
                'created_by' => $user->getKey(),
            ]);
        }

        $link = PartnerLink::query()
            ->where('agency_organization_id', $agencyOrganization->getKey())
            ->whereKey($partnerLinkId)
            ->first();

        if ($link === null) {
            abort(404, 'Partner link not found.');
        }

        $this->assertCarrierAccess($agencyOrganization, $user, $link, AgencyCarrierGrant::SCOPE_MANAGE);

        $existing = CommissionRule::query()
            ->where('partner_link_id', $link->getKey())
            ->where('active', true)
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'partner_link_id' => 'An active commission rule already exists for this carrier. Update or deactivate it first.',
            ]);
        }

        return CommissionRule::create([
            'agency_organization_id' => $agencyOrganization->getKey(),
            'partner_link_id' => $link->getKey(),
            'rate_percent' => $ratePercent,
            'cap_cents' => $capCents,
            'basis' => CommissionRule::BASIS_PAID,
            'active' => true,
            'effective_from' => $effectiveFrom,
            'created_by' => $user->getKey(),
        ]);
    }

    /**
     * Update a commission rule's rate, cap, active flag, or
     * effective date. The rule's carrier never changes: a rule
     * for a different carrier is a new rule.
     */
    public function updateRule(CommissionRule $rule, array $validated): CommissionRule
    {
        $rule->update($validated);

        return $rule->fresh();
    }

    /**
     * Delete a commission rule. Earnings computed afterwards
     * fall back to the default rule or the configured default
     * rate, so deleting a rule changes future computations and
     * never rewrites a number already reported.
     */
    public function deleteRule(CommissionRule $rule): void
    {
        $rule->delete();
    }

    /**
     * The rule that applies to a link: the active per-carrier
     * rule, else the active default rule, else null (the
     * configured default rate then applies).
     */
    public function ruleForLink(PartnerLink $link): ?CommissionRule
    {
        $rule = CommissionRule::query()
            ->where('partner_link_id', $link->getKey())
            ->where('active', true)
            ->first();

        if ($rule !== null) {
            return $rule;
        }

        return CommissionRule::query()
            ->where('agency_organization_id', $link->agency_organization_id)
            ->whereNull('partner_link_id')
            ->where('active', true)
            ->first();
    }

    /**
     * Earned commission for one link, computed only from the
     * carrier's recorded ClaimPayment rows: payments in the
     * window times the applicable rate, capped when the rule
     * sets a cap. The cap applies per currency, in that
     * currency's minor units. Commission is a calculated
     * figure, never money owed or paid.
     */
    public function commissionForLink(PartnerLink $link, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $rule = $this->ruleForLink($link);
        $rate = $rule !== null ? (string) $rule->rate_percent : (string) config('agency.default_commission_rate_percent', 10);

        // forOrganization bypasses the tenant global scope: the
        // resolved organization is the agency, but these rows
        // belong to the carrier.
        $payments = ClaimPayment::forOrganization($link->carrier_organization_id)
            ->when($dateFrom !== null, fn ($query) => $query->whereDate('paid_at', '>=', $dateFrom))
            ->when($dateTo !== null, fn ($query) => $query->whereDate('paid_at', '<=', $dateTo))
            ->selectRaw('currency, SUM(amount) as paid')
            ->groupBy('currency')
            ->pluck('paid', 'currency')
            ->map(fn ($amount) => (string) $amount)
            ->all();

        $earned = [];

        foreach ($payments as $currency => $paid) {
            // rate is a percent: earned = paid * rate / 100.
            $amount = bcdiv(bcmul($paid, $rate, 4), '100', 2);
            $capped = false;

            if ($rule !== null && $rule->cap_cents !== null) {
                $cap = bcdiv((string) $rule->cap_cents, '100', 2);

                if (bccomp($amount, $cap, 2) > 0) {
                    $amount = $cap;
                    $capped = true;
                }
            }

            $earned[] = [
                'currency' => $currency,
                'payments' => number_format((float) $paid, 2, '.', ''),
                'earned' => number_format((float) $amount, 2, '.', ''),
                'capped' => $capped,
            ];
        }

        return [
            'rate_percent' => number_format((float) $rate, 2, '.', ''),
            'rule_id' => $rule?->getKey(),
            'rule_is_default' => $rule !== null && $rule->isDefault(),
            'basis' => CommissionRule::BASIS_PAID,
            'by_currency' => $earned,
            'note' => 'Computed from recorded payments only. Commission is a calculated figure; nothing here pays a partner out.',
        ];
    }

    /**
     * Earned commission for the whole agency: one row per
     * active link the user may see, plus totals merged by
     * currency.
     */
    public function commissions(
        Organization $agencyOrganization,
        User $user,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): array {
        $links = $this->visibleLinks($agencyOrganization, $user)
            ->where('status', PartnerLink::STATUS_APPROVED);

        return $this->commissionsForLinks($agencyOrganization, $links, $dateFrom, $dateTo);
    }

    /**
     * Earned commission for the whole agency, without user
     * scoping: every active link. Used by the console command,
     * which runs as the organization itself.
     */
    public function commissionsForReport(
        Organization $agencyOrganization,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): array {
        $links = PartnerLink::forAgency($agencyOrganization->getKey())
            ->active()
            ->with(['carrierOrganization'])
            ->orderBy('created_at')
            ->get();

        return $this->commissionsForLinks($agencyOrganization, $links, $dateFrom, $dateTo);
    }

    /**
     * Earned commission for a set of links, plus totals merged
     * by currency.
     */
    protected function commissionsForLinks(
        Organization $agencyOrganization,
        $links,
        ?string $dateFrom,
        ?string $dateTo,
    ): array {
        $rows = [];
        $totalsByCurrency = [];

        foreach ($links as $link) {
            $carrierOrganization = $link->carrierOrganization;

            if ($carrierOrganization === null) {
                continue;
            }

            $commission = $this->commissionForLink($link, $dateFrom, $dateTo);

            $rows[] = array_merge(
                ['carrier' => $this->presentCarrier($carrierOrganization, $link)],
                $commission,
            );

            foreach ($commission['by_currency'] as $bucket) {
                $currency = $bucket['currency'];
                $totalsByCurrency[$currency] ??= [
                    'currency' => $currency,
                    'payments' => '0.00',
                    'earned' => '0.00',
                ];
                $totalsByCurrency[$currency]['payments'] = bcadd($totalsByCurrency[$currency]['payments'], $bucket['payments'], 2);
                $totalsByCurrency[$currency]['earned'] = bcadd($totalsByCurrency[$currency]['earned'], $bucket['earned'], 2);
            }
        }

        return [
            'agency_organization_id' => $agencyOrganization->getKey(),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'carriers' => $rows,
            'totals_by_currency' => array_values($totalsByCurrency),
            'note' => 'Computed from recorded payments only. Commission is a calculated figure; nothing here pays a partner out.',
        ];
    }

    // -- Grants ------------------------------------------------------------

    /**
     * Grants under one link, with the member.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, AgencyCarrierGrant>
     */
    public function listGrants(PartnerLink $link)
    {
        return AgencyCarrierGrant::query()
            ->where('partner_link_id', $link->getKey())
            ->with(['user'])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Grant a member access to a carrier. The member must be an
     * active member of the agency organization. One grant per
     * member per link: granting again replaces the scope.
     */
    public function createGrant(PartnerLink $link, User $member, string $scope, User $granter): AgencyCarrierGrant
    {
        $membership = $member->membershipIn($link->agency_organization_id);

        if ($membership === null || ! $membership->isActive()) {
            throw ValidationException::withMessages([
                'user_id' => 'The user is not an active member of the agency organization.',
            ]);
        }

        return AgencyCarrierGrant::updateOrCreate(
            ['partner_link_id' => $link->getKey(), 'user_id' => $member->getKey()],
            ['scope' => $scope, 'granted_by' => $granter->getKey()],
        );
    }

    /**
     * Remove a grant. The member loses access to that carrier
     * immediately; their agency membership is untouched.
     */
    public function deleteGrant(AgencyCarrierGrant $grant): void
    {
        $grant->delete();
    }

    // -- Presenters ----------------------------------------------------------

    public function presentLink(PartnerLink $link): array
    {
        return [
            'id' => $link->id,
            'agency_organization_id' => $link->agency_organization_id,
            'carrier_organization_id' => $link->carrier_organization_id,
            'carrier_organization_name' => $link->carrierOrganization?->name,
            'status' => $link->status,
            'referred_by_code' => $link->referred_by_code,
            'assigned_plan' => $link->assigned_plan,
            'approved_by' => $link->approved_by,
            'approved_at' => $link->approved_at?->toIso8601String(),
            'created_by' => $link->created_by,
            'notes' => $link->notes,
            'created_at' => $link->created_at?->toIso8601String(),
            'updated_at' => $link->updated_at?->toIso8601String(),
        ];
    }

    public function presentCarrier(Organization $carrierOrganization, PartnerLink $link): array
    {
        return [
            'organization_id' => $carrierOrganization->getKey(),
            'name' => $carrierOrganization->name,
            'slug' => $carrierOrganization->slug,
            'partner_link_id' => $link->getKey(),
            'link_status' => $link->status,
            'referred_by_code' => $link->referred_by_code,
            'assigned_plan' => $link->assigned_plan,
        ];
    }

    public function presentCode(ReferralCode $code): array
    {
        return [
            'id' => $code->id,
            'organization_id' => $code->organization_id,
            'code' => $code->code,
            'active' => (bool) $code->active,
            'uses_count' => $code->usesCount(),
            'created_by' => $code->created_by,
            'notes' => $code->notes,
            'created_at' => $code->created_at?->toIso8601String(),
            'updated_at' => $code->updated_at?->toIso8601String(),
        ];
    }

    public function presentRule(CommissionRule $rule): array
    {
        return [
            'id' => $rule->id,
            'agency_organization_id' => $rule->agency_organization_id,
            'partner_link_id' => $rule->partner_link_id,
            'carrier_organization_id' => $rule->partnerLink?->carrier_organization_id,
            'carrier_organization_name' => $rule->partnerLink?->carrierOrganization?->name,
            'is_default' => $rule->isDefault(),
            'rate_percent' => number_format((float) $rule->rate_percent, 2, '.', ''),
            'cap_cents' => $rule->cap_cents,
            'basis' => $rule->basis,
            'active' => (bool) $rule->active,
            'effective_from' => $rule->effective_from?->toDateString(),
            'created_by' => $rule->created_by,
            'created_at' => $rule->created_at?->toIso8601String(),
            'updated_at' => $rule->updated_at?->toIso8601String(),
        ];
    }

    public function presentGrant(AgencyCarrierGrant $grant): array
    {
        return [
            'id' => $grant->id,
            'partner_link_id' => $grant->partner_link_id,
            'user_id' => $grant->user_id,
            'user_name' => $grant->user?->name,
            'user_email' => $grant->user?->email,
            'scope' => $grant->scope,
            'granted_by' => $grant->granted_by,
            'created_at' => $grant->created_at?->toIso8601String(),
            'updated_at' => $grant->updated_at?->toIso8601String(),
        ];
    }

    public function presentClaim(Claim $claim): array
    {
        return [
            'id' => $claim->id,
            'claim_reference' => $claim->claim_reference,
            'organization_id' => $claim->organization_id,
            'shipment_id' => $claim->shipment_id,
            'shipment_reference' => $claim->shipment?->internal_reference,
            'carrier_id' => $claim->shipment?->carrier_id,
            'claim_category' => $claim->claim_category->value,
            'status' => $claim->status->value,
            'currency' => $claim->currency,
            'requested_amount' => number_format((float) $claim->requested_amount, 2, '.', ''),
            'accepted_amount' => $claim->accepted_amount !== null ? number_format((float) $claim->accepted_amount, 2, '.', '') : null,
            'recovered_amount' => number_format((float) $claim->recovered_amount, 2, '.', ''),
            'submitted_at' => $claim->submitted_at?->toIso8601String(),
            'paid_at' => $claim->paid_at?->toIso8601String(),
            'created_at' => $claim->created_at?->toIso8601String(),
            'updated_at' => $claim->updated_at?->toIso8601String(),
        ];
    }
}
