<?php

namespace App\Http\Controllers\Api\V1;

use App\ClaimStatus;
use App\Http\Controllers\Controller;
use App\Models\AgencyCarrierGrant;
use App\Models\Claim;
use App\Models\CommissionRule;
use App\Models\Organization;
use App\Models\PartnerLink;
use App\Models\ReferralCode;
use App\Models\User;
use App\Permission;
use App\Services\AgencyService;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Agency management (v2.1.0): partner links, carrier
 * management, the agency dashboard, consolidated reporting,
 * referral codes, commission rules and computed earnings, and
 * reseller workflows.
 *
 * Every endpoint needs manage-agency (owner, admin, operations
 * manager) and the Agency plan: every other plan is refused
 * with a 402. All of it is tenant-scoped to the agency
 * organization resolved by the middleware.
 *
 * Carrier access is per-carrier, not just org-level: owners
 * and admins of the agency see every linked carrier, while
 * other members need a grant for each carrier they may touch.
 * Carrier claims are read-only from the agency side: writing
 * a claim stays with the carrier organization itself.
 */
class AgencyController extends Controller
{
    public function __construct(
        protected AgencyService $agency,
        protected EntitlementService $entitlements,
    ) {}

    // -- Dashboard ---------------------------------------------------------

    /**
     * The agency dashboard: per-carrier stats for the active
     * links the caller may see, consolidated totals merged by
     * currency, link counts, and the referral and commission
     * summaries.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);

        return response()->json([
            'data' => $this->agency->dashboard($organization, $request->user()),
        ]);
    }

    /**
     * Linked carriers with per-carrier stats. Stats are present
     * only for active links: a pending or suspended carrier has
     * no numbers to show yet.
     */
    public function listCarriers(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);

        $links = $this->agency->visibleLinks($organization, $request->user());

        $carriers = $links->map(function (PartnerLink $link) {
            $carrierOrganization = $link->carrierOrganization;

            if ($carrierOrganization === null) {
                return null;
            }

            $stats = $link->isActive()
                ? $this->agency->carrierStats($carrierOrganization)
                : null;

            return array_merge(
                ['carrier' => $this->agency->presentCarrier($carrierOrganization, $link)],
                $stats ?? ['claims_total' => null, 'by_status' => null, 'totals' => null],
                ['commission' => $link->isActive() ? $this->agency->commissionForLink($link) : null],
            );
        })->filter()->values()->all();

        return response()->json(['data' => $carriers]);
    }

    // -- Partner links -----------------------------------------------------

    /**
     * Partner links, newest first, with a status filter.
     */
    public function listLinks(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:'.implode(',', PartnerLink::statuses())],
        ]);

        $links = $this->agency->listLinks(
            $organization,
            $validated['status'] ?? null,
            $this->perPage($request, 25),
        );

        return response()->json([
            'data' => $links->getCollection()
                ->map(fn (PartnerLink $link) => $this->agency->presentLink($link->loadMissing(['carrierOrganization', 'approver', 'creator'])))
                ->all(),
            'meta' => $this->paginationMeta($links),
        ]);
    }

    /**
     * Create a partner link. The link starts pending and grants
     * nothing until the carrier organization approves it.
     */
    public function storeLink(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);

        $validated = $request->validate([
            'carrier_organization_id' => ['required', 'string', 'uuid', 'exists:organizations,id'],
            'referral_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $link = $this->agency->createLink(
            $organization,
            $validated['carrier_organization_id'],
            $validated['referral_code'] ?? null,
            $validated['notes'] ?? null,
            $request->user(),
        );

        return response()->json([
            'data' => $this->agency->presentLink($link->fresh()),
        ], 201);
    }

    /**
     * One partner link of this agency.
     */
    public function showLink(Request $request, PartnerLink $partnerLink): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);
        $this->assertAgencyLink($organization, $partnerLink);

        return response()->json([
            'data' => $this->agency->presentLink($partnerLink->loadMissing(['carrierOrganization', 'approver', 'creator'])),
        ]);
    }

    /**
     * Suspend an approved link. Suspended links keep their
     * history but grant no access until reactivated.
     */
    public function suspendLink(Request $request, PartnerLink $partnerLink): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);
        $this->assertAgencyLink($organization, $partnerLink);

        return response()->json([
            'data' => $this->agency->presentLink($this->agency->suspendLink($partnerLink)),
        ]);
    }

    /**
     * Reactivate a suspended link.
     */
    public function reactivateLink(Request $request, PartnerLink $partnerLink): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);
        $this->assertAgencyLink($organization, $partnerLink);

        return response()->json([
            'data' => $this->agency->presentLink($this->agency->reactivateLink($partnerLink)),
        ]);
    }

    /**
     * Remove a link. The row is kept with status removed, never
     * deleted, so referral attribution survives.
     */
    public function removeLink(Request $request, PartnerLink $partnerLink): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);
        $this->assertAgencyLink($organization, $partnerLink);

        return response()->json([
            'data' => $this->agency->presentLink($this->agency->removeLink($partnerLink)),
        ]);
    }

    /**
     * Assign a plan to the carrier organization (reseller plan
     * assignment). Runs through the normal billing change-plan
     * flow on the mock provider; no real money moves.
     */
    public function assignPlan(Request $request, PartnerLink $partnerLink): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);
        $this->assertAgencyLink($organization, $partnerLink);
        $this->agency->assertAgencyAdmin($organization, $request->user());

        $validated = $request->validate([
            'plan' => ['required', 'string', 'in:'.implode(',', \App\Billing\PlanCatalog::keys())],
        ]);

        $link = $this->agency->assignPlan($partnerLink, $validated['plan'], $request->user());

        return response()->json([
            'data' => $this->agency->presentLink($link),
        ]);
    }

    // -- Carrier claims (scoped read) --------------------------------------

    /**
     * A linked carrier's claims, as the agency sees them.
     * Read-only: writing a claim stays with the carrier
     * organization itself.
     */
    public function listCarrierClaims(Request $request, string $carrierOrganizationId): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:'.implode(',', ClaimStatus::values())],
        ]);

        $claims = $this->agency->listCarrierClaims(
            $organization,
            $request->user(),
            $carrierOrganizationId,
            $validated['status'] ?? null,
            $this->perPage($request, 25),
        );

        return response()->json([
            'data' => $claims->getCollection()
                ->map(fn (Claim $claim) => $this->agency->presentClaim($claim))
                ->all(),
            'meta' => $this->paginationMeta($claims),
        ]);
    }

    /**
     * One linked carrier's claim, as the agency sees it.
     */
    public function showCarrierClaim(Request $request, string $carrierOrganizationId, string $claim): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);

        $found = $this->agency->showCarrierClaim(
            $organization,
            $request->user(),
            $carrierOrganizationId,
            $claim,
        );

        return response()->json([
            'data' => $this->agency->presentClaim($found),
        ]);
    }

    // -- Consolidated reporting --------------------------------------------

    /**
     * The consolidated cross-carrier report: one row per carrier
     * the caller may see, plus totals merged by currency.
     * Computed only from the linked carriers' own recorded
     * claims and payments.
     */
    public function consolidatedReport(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);

        $validated = $request->validate([
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'carrier_organization_id' => ['sometimes', 'nullable', 'string', 'uuid'],
        ]);

        return response()->json([
            'data' => $this->agency->consolidatedReport(
                $organization,
                $request->user(),
                $validated['date_from'] ?? null,
                $validated['date_to'] ?? null,
                $validated['carrier_organization_id'] ?? null,
            ),
        ]);
    }

    // -- Referral codes ----------------------------------------------------

    /**
     * The agency's referral codes, newest first.
     */
    public function listCodes(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);

        $codes = $this->agency->listCodes($organization, $this->perPage($request, 25));

        return response()->json([
            'data' => $codes->getCollection()
                ->map(fn (ReferralCode $code) => $this->agency->presentCode($code))
                ->all(),
            'meta' => $this->paginationMeta($codes),
        ]);
    }

    /**
     * Create a referral code.
     */
    public function storeCode(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);

        $validated = $request->validate([
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $code = $this->agency->createCode(
            $organization,
            $validated['notes'] ?? null,
            $request->user(),
        );

        return response()->json([
            'data' => $this->agency->presentCode($code),
        ], 201);
    }

    /**
     * One referral code with the carriers it brought in.
     */
    public function showCode(Request $request, ReferralCode $referralCode): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);
        $this->assertAgencyCode($organization, $referralCode);

        return response()->json([
            'data' => array_merge(
                $this->agency->presentCode($referralCode),
                ['attributed_links' => $referralCode->attributedLinks()
                    ->map(fn (PartnerLink $link) => $this->agency->presentLink($link))
                    ->all()],
            ),
        ]);
    }

    /**
     * Deactivate a code. Links that already cite it keep their
     * attribution.
     */
    public function deactivateCode(Request $request, ReferralCode $referralCode): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);
        $this->assertAgencyCode($organization, $referralCode);

        return response()->json([
            'data' => $this->agency->presentCode($this->agency->deactivateCode($referralCode)),
        ]);
    }

    // -- Commission rules and earnings -------------------------------------

    /**
     * The agency's commission rules, default first.
     */
    public function listRules(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);

        return response()->json([
            'data' => $this->agency->listRules($organization)
                ->map(fn (CommissionRule $rule) => $this->agency->presentRule($rule))
                ->all(),
        ]);
    }

    /**
     * Create a commission rule: a default for the whole agency,
     * or a per-carrier override for one link.
     */
    public function storeRule(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);

        $validated = $request->validate([
            'partner_link_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'cap_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'effective_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $rule = $this->agency->createRule(
            $organization,
            $request->user(),
            $validated['partner_link_id'] ?? null,
            (float) $validated['rate_percent'],
            $validated['cap_cents'] ?? null,
            $validated['effective_from'] ?? null,
        );

        return response()->json([
            'data' => $this->agency->presentRule($rule),
        ], 201);
    }

    /**
     * Update a commission rule's rate, cap, active flag, or
     * effective date. The rule's carrier never changes.
     */
    public function updateRule(Request $request, CommissionRule $commissionRule): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);
        $this->assertAgencyRule($organization, $commissionRule);
        $this->assertRuleAccess($organization, $request->user(), $commissionRule);

        $validated = $request->validate([
            'rate_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'cap_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
            'effective_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json([
            'data' => $this->agency->presentRule($this->agency->updateRule($commissionRule, $validated)),
        ]);
    }

    /**
     * Delete a commission rule. Earnings computed afterwards
     * fall back to the default rule or the configured default
     * rate.
     */
    public function destroyRule(Request $request, CommissionRule $commissionRule): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);
        $this->assertAgencyRule($organization, $commissionRule);
        $this->assertRuleAccess($organization, $request->user(), $commissionRule);

        $this->agency->deleteRule($commissionRule);

        return response()->json(['message' => 'Commission rule deleted.']);
    }

    /**
     * Earned commission for the whole agency, computed only
     * from recorded payments. An optional date window filters
     * payments by paid date.
     */
    public function commissions(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);

        $validated = $request->validate([
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json([
            'data' => $this->agency->commissions(
                $organization,
                $request->user(),
                $validated['date_from'] ?? null,
                $validated['date_to'] ?? null,
            ),
        ]);
    }

    // -- Grants ------------------------------------------------------------

    /**
     * Grants under one link, with the member each covers.
     */
    public function listGrants(Request $request, PartnerLink $partnerLink): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);
        $this->assertAgencyLink($organization, $partnerLink);
        $this->agency->assertAgencyAdmin($organization, $request->user());

        return response()->json([
            'data' => $this->agency->listGrants($partnerLink)
                ->map(fn (AgencyCarrierGrant $grant) => $this->agency->presentGrant($grant))
                ->all(),
        ]);
    }

    /**
     * Grant a member access to a carrier. Granting again
     * replaces the scope.
     */
    public function storeGrant(Request $request, PartnerLink $partnerLink): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);
        $this->assertAgencyLink($organization, $partnerLink);
        $this->agency->assertAgencyAdmin($organization, $request->user());

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'scope' => ['required', 'string', 'in:'.implode(',', AgencyCarrierGrant::scopes())],
        ]);

        $member = User::query()->whereKey($validated['user_id'])->firstOrFail();

        $grant = $this->agency->createGrant(
            $partnerLink,
            $member,
            $validated['scope'],
            $request->user(),
        );

        return response()->json([
            'data' => $this->agency->presentGrant($grant->fresh()),
        ], 201);
    }

    /**
     * Remove a grant. The member loses access to that carrier
     * immediately.
     */
    public function destroyGrant(Request $request, PartnerLink $partnerLink, AgencyCarrierGrant $grant): JsonResponse
    {
        $organization = $this->organization($request);
        $this->authorizeAgency($organization);
        $this->assertAgencyLink($organization, $partnerLink);
        $this->agency->assertAgencyAdmin($organization, $request->user());

        if ($grant->partner_link_id !== $partnerLink->getKey()) {
            abort(404);
        }

        $this->agency->deleteGrant($grant);

        return response()->json(['message' => 'Grant removed.']);
    }

    // -- Helpers -----------------------------------------------------------

    /**
     * The permission and the plan gate, in one place: every
     * agency endpoint calls this first.
     */
    protected function authorizeAgency(Organization $organization): void
    {
        Gate::authorize(Permission::ManageAgency->value, $organization);
        $this->entitlements->authorizeAgency($organization);
    }

    /**
     * A link belongs to this agency, or it is a 404. Route
     * model binding runs before the organization middleware,
     * so the check is explicit, like the other controllers.
     */
    protected function assertAgencyLink(Organization $organization, PartnerLink $link): void
    {
        if ($link->agency_organization_id !== $organization->getKey()) {
            abort(404);
        }
    }

    /**
     * A referral code belongs to this agency, or it is a 404.
     */
    protected function assertAgencyCode(Organization $organization, ReferralCode $code): void
    {
        if ($code->organization_id !== $organization->getKey()) {
            abort(404);
        }
    }

    /**
     * A commission rule belongs to this agency, or it is a 404.
     */
    protected function assertAgencyRule(Organization $organization, CommissionRule $rule): void
    {
        if ($rule->agency_organization_id !== $organization->getKey()) {
            abort(404);
        }
    }

    /**
     * Who may change a rule: the default rule is admin-only; a
     * per-carrier rule needs the manage scope on that carrier
     * (owners and admins pass by bypass).
     */
    protected function assertRuleAccess(Organization $organization, $user, CommissionRule $rule): void
    {
        if ($rule->partner_link_id === null) {
            $this->agency->assertAgencyAdmin($organization, $user);

            return;
        }

        $link = PartnerLink::query()->whereKey($rule->partner_link_id)->first();

        if ($link === null) {
            abort(404);
        }

        $this->agency->assertCarrierAccess($organization, $user, $link, AgencyCarrierGrant::SCOPE_MANAGE);
    }

    /**
     * The organization resolved by the ResolveOrganization middleware.
     */
    protected function organization(Request $request): Organization
    {
        return $request->attributes->get('organization');
    }

    /**
     * Page size from the request, clamped to the allowed range.
     */
    protected function perPage(Request $request, int $default): int
    {
        return max(1, min(100, (int) $request->input('per_page', $default)));
    }

    /**
     * Pagination metadata in the standard list shape.
     */
    protected function paginationMeta($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
