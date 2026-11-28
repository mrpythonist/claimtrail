import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import {
  GRANT_SCOPES,
  PARTNER_LINK_STATUSES,
  assignPartnerPlan,
  createCommissionRule,
  createGrant,
  createPartnerLink,
  createReferralCode,
  deactivateReferralCode,
  deleteCommissionRule,
  deleteGrant,
  getAgencyDashboard,
  getCarrierClaim,
  getCommissions,
  getConsolidatedReport,
  getPartnerLink,
  getReferralCode,
  listAgencyCarriers,
  listCarrierClaims,
  listCommissionRules,
  listGrants,
  listPartnerLinks,
  listReferralCodes,
  reactivatePartnerLink,
  removePartnerLink,
  suspendPartnerLink,
  updateCommissionRule,
} from "../agency";
import { canManageAgency } from "../permissions";
import type {
  AgencyCommissions,
  AgencyDashboard,
  AgencyCarrierStats,
  CommissionRule,
  ConsolidatedReport,
  PartnerLink,
  ReferralCode,
} from "@/lib/types";

vi.mock("@/lib/api", () => {
  class ApiRequestError extends Error {
    status: number;
    fields: Record<string, string[]>;
    constructor(status: number, body: { message: string }) {
      super(body.message);
      this.status = status;
      this.fields = {};
    }
  }
  return { apiFetch: vi.fn(), apiDownload: vi.fn(), apiUpload: vi.fn(), ApiRequestError };
});

const mockedApiFetch = vi.mocked(apiFetch);

beforeEach(() => {
  mockedApiFetch.mockReset();
});

const LINK: PartnerLink = {
  id: "link-1",
  agency_organization_id: "agency-1",
  carrier_organization_id: "carrier-1",
  carrier_organization_name: "Carrier One",
  status: "pending",
  referred_by_code: null,
  assigned_plan: null,
  approved_by: null,
  approved_at: null,
  created_by: 1,
  notes: null,
  created_at: "2026-11-28T10:00:00.000Z",
  updated_at: "2026-11-28T10:00:00.000Z",
};

const DASHBOARD: AgencyDashboard = {
  agency_organization_id: "agency-1",
  carriers: [],
  totals_by_currency: [],
  link_counts: { total: 1, pending: 1, approved: 0, suspended: 0, rejected: 0, removed: 0 },
  referrals: { codes_total: 1, codes_active: 1, carriers_attributed: 0 },
  commission: {
    agency_organization_id: "agency-1",
    date_from: null,
    date_to: null,
    carriers: [],
    totals_by_currency: [],
    note: "Computed from recorded payments only.",
  },
};

const CARRIER_STATS: AgencyCarrierStats = {
  carrier: {
    organization_id: "carrier-1",
    name: "Carrier One",
    slug: "carrier-one",
    partner_link_id: "link-1",
    link_status: "approved",
    referred_by_code: null,
    assigned_plan: null,
  },
  claims_total: 2,
  by_status: { paid: 1, potential: 1 },
  totals: [
    {
      currency: "USD",
      requested: "800.00",
      accepted: "400.00",
      recovered: "250.00",
      payments: "250.00",
      outstanding: "150.00",
    },
  ],
  commission: null,
};

const REPORT: ConsolidatedReport = {
  agency_organization_id: "agency-1",
  date_from: null,
  date_to: null,
  carrier_organization_id: null,
  carriers: [
    {
      carrier: CARRIER_STATS.carrier,
      claims_total: 2,
      by_status: { paid: 1, potential: 1 },
      totals: CARRIER_STATS.totals,
    },
  ],
  totals_by_currency: CARRIER_STATS.totals,
  note: "Computed only from the linked carriers' own recorded claims and payments.",
};

const CODE: ReferralCode = {
  id: "code-1",
  organization_id: "agency-1",
  code: "REF-ABCDEFGH",
  active: true,
  uses_count: 1,
  created_by: 1,
  notes: null,
  created_at: "2026-11-28T10:00:00.000Z",
  updated_at: "2026-11-28T10:00:00.000Z",
};

const RULE: CommissionRule = {
  id: "rule-1",
  agency_organization_id: "agency-1",
  partner_link_id: null,
  carrier_organization_id: null,
  carrier_organization_name: null,
  is_default: true,
  rate_percent: "20.00",
  cap_cents: null,
  basis: "paid",
  active: true,
  effective_from: null,
  created_by: 1,
  created_at: "2026-11-28T10:00:00.000Z",
  updated_at: "2026-11-28T10:00:00.000Z",
};

const COMMISSIONS: AgencyCommissions = {
  agency_organization_id: "agency-1",
  date_from: null,
  date_to: null,
  carriers: [
    {
      carrier: CARRIER_STATS.carrier,
      rate_percent: "10.00",
      rule_id: null,
      rule_is_default: false,
      basis: "paid",
      by_currency: [{ currency: "USD", payments: "500.00", earned: "50.00", capped: false }],
      note: "Computed from recorded payments only.",
    },
  ],
  totals_by_currency: [{ currency: "USD", payments: "500.00", earned: "50.00" }],
  note: "Computed from recorded payments only.",
};

describe("agency client", () => {
  it("fetches the dashboard", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: DASHBOARD });
    await expect(getAgencyDashboard()).resolves.toEqual(DASHBOARD);
    expect(mockedApiFetch).toHaveBeenCalledWith("/agency/dashboard");
  });

  it("lists carriers", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: [CARRIER_STATS] });
    await expect(listAgencyCarriers()).resolves.toEqual([CARRIER_STATS]);
    expect(mockedApiFetch).toHaveBeenCalledWith("/agency/carriers");
  });

  it("lists partner links with and without a status filter", async () => {
    mockedApiFetch.mockResolvedValueOnce({
      data: [LINK],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    });
    const result = await listPartnerLinks();
    expect(result.data).toEqual([LINK]);
    expect(mockedApiFetch).toHaveBeenCalledWith("/agency/partners");

    mockedApiFetch.mockResolvedValueOnce({
      data: [LINK],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    });
    await listPartnerLinks("approved");
    expect(mockedApiFetch).toHaveBeenCalledWith("/agency/partners?status=approved");
  });

  it("creates a partner link with a referral code", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: LINK });
    await expect(
      createPartnerLink({
        carrier_organization_id: "carrier-1",
        referral_code: "REF-ABCDEFGH",
      }),
    ).resolves.toEqual(LINK);
    expect(mockedApiFetch).toHaveBeenCalledWith("/agency/partners", {
      method: "POST",
      body: { carrier_organization_id: "carrier-1", referral_code: "REF-ABCDEFGH" },
    });
  });

  it("suspends, reactivates, removes, and assigns a plan to a link", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: { ...LINK, status: "suspended" } });
    await expect(suspendPartnerLink("link-1")).resolves.toMatchObject({ status: "suspended" });
    expect(mockedApiFetch).toHaveBeenCalledWith("/agency/partners/link-1/suspend", {
      method: "POST",
    });

    mockedApiFetch.mockResolvedValueOnce({ data: { ...LINK, status: "approved" } });
    await expect(reactivatePartnerLink("link-1")).resolves.toMatchObject({ status: "approved" });

    mockedApiFetch.mockResolvedValueOnce({ data: { ...LINK, status: "removed" } });
    await expect(removePartnerLink("link-1")).resolves.toMatchObject({ status: "removed" });

    mockedApiFetch.mockResolvedValueOnce({ data: { ...LINK, assigned_plan: "starter" } });
    await expect(assignPartnerPlan("link-1", "starter")).resolves.toMatchObject({
      assigned_plan: "starter",
    });
    expect(mockedApiFetch).toHaveBeenCalledWith("/agency/partners/link-1/plan", {
      method: "POST",
      body: { plan: "starter" },
    });
  });

  it("fetches one partner link", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: LINK });
    await expect(getPartnerLink("link-1")).resolves.toEqual(LINK);
    expect(mockedApiFetch).toHaveBeenCalledWith("/agency/partners/link-1");
  });

  it("lists carrier claims and fetches one claim", async () => {
    const claim = {
      id: "claim-1",
      claim_reference: "CLM-1",
      organization_id: "carrier-1",
      shipment_id: "ship-1",
      shipment_reference: "CT-1",
      carrier_id: "carrier-row-1",
      claim_category: "detention",
      status: "paid",
      currency: "USD",
      requested_amount: "500.00",
      accepted_amount: "500.00",
      recovered_amount: "500.00",
      submitted_at: null,
      paid_at: "2026-11-28",
      created_at: "2026-11-28T10:00:00.000Z",
      updated_at: "2026-11-28T10:00:00.000Z",
    };
    mockedApiFetch.mockResolvedValueOnce({
      data: [claim],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    });
    const result = await listCarrierClaims("carrier-1");
    expect(result.data).toEqual([claim]);
    expect(mockedApiFetch).toHaveBeenCalledWith("/agency/carriers/carrier-1/claims");

    mockedApiFetch.mockResolvedValueOnce({ data: claim });
    await expect(getCarrierClaim("carrier-1", "claim-1")).resolves.toEqual(claim);
  });

  it("fetches the consolidated report with filters", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: REPORT });
    await expect(getConsolidatedReport()).resolves.toEqual(REPORT);
    expect(mockedApiFetch).toHaveBeenCalledWith("/agency/reports/consolidated");

    mockedApiFetch.mockResolvedValueOnce({ data: REPORT });
    await getConsolidatedReport({
      date_from: "2026-11-01",
      date_to: "2026-11-30",
      carrier_organization_id: "carrier-1",
    });
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/agency/reports/consolidated?date_from=2026-11-01&date_to=2026-11-30&carrier_organization_id=carrier-1",
    );
  });

  it("creates, fetches, and deactivates referral codes", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: CODE });
    await expect(createReferralCode({ notes: "Conference" })).resolves.toEqual(CODE);
    expect(mockedApiFetch).toHaveBeenCalledWith("/agency/referrals", {
      method: "POST",
      body: { notes: "Conference" },
    });

    mockedApiFetch.mockResolvedValueOnce({
      data: [CODE],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    });
    const result = await listReferralCodes();
    expect(result.data).toEqual([CODE]);

    mockedApiFetch.mockResolvedValueOnce({ data: { ...CODE, attributed_links: [LINK] } });
    const detail = await getReferralCode("code-1");
    expect(detail.attributed_links).toEqual([LINK]);

    mockedApiFetch.mockResolvedValueOnce({ data: { ...CODE, active: false } });
    await expect(deactivateReferralCode("code-1")).resolves.toMatchObject({ active: false });
    expect(mockedApiFetch).toHaveBeenCalledWith("/agency/referrals/code-1/deactivate", {
      method: "POST",
    });
  });

  it("creates, updates, and deletes commission rules", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: RULE });
    await expect(createCommissionRule({ rate_percent: 20 })).resolves.toEqual(RULE);
    expect(mockedApiFetch).toHaveBeenCalledWith("/agency/commission-rules", {
      method: "POST",
      body: { rate_percent: 20 },
    });

    mockedApiFetch.mockResolvedValueOnce({ data: [RULE] });
    await expect(listCommissionRules()).resolves.toEqual([RULE]);

    mockedApiFetch.mockResolvedValueOnce({ data: { ...RULE, rate_percent: "15.00" } });
    await expect(updateCommissionRule("rule-1", { rate_percent: 15 })).resolves.toMatchObject({
      rate_percent: "15.00",
    });

    mockedApiFetch.mockResolvedValueOnce({});
    await expect(deleteCommissionRule("rule-1")).resolves.toBeUndefined();
    expect(mockedApiFetch).toHaveBeenCalledWith("/agency/commission-rules/rule-1", {
      method: "DELETE",
    });
  });

  it("fetches commissions with a date window", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: COMMISSIONS });
    await expect(getCommissions()).resolves.toEqual(COMMISSIONS);
    expect(mockedApiFetch).toHaveBeenCalledWith("/agency/commissions");

    mockedApiFetch.mockResolvedValueOnce({ data: COMMISSIONS });
    await getCommissions({ date_from: "2026-11-01", date_to: "2026-11-30" });
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/agency/commissions?date_from=2026-11-01&date_to=2026-11-30",
    );
  });

  it("creates, lists, and deletes grants", async () => {
    const grant = {
      id: "grant-1",
      partner_link_id: "link-1",
      user_id: 7,
      user_name: "Ops Manager",
      user_email: "ops@example.com",
      scope: "view" as const,
      granted_by: 1,
      created_at: "2026-11-28T10:00:00.000Z",
      updated_at: "2026-11-28T10:00:00.000Z",
    };
    mockedApiFetch.mockResolvedValueOnce({ data: grant });
    await expect(createGrant("link-1", { user_id: 7, scope: "view" })).resolves.toEqual(grant);
    expect(mockedApiFetch).toHaveBeenCalledWith("/agency/partners/link-1/grants", {
      method: "POST",
      body: { user_id: 7, scope: "view" },
    });

    mockedApiFetch.mockResolvedValueOnce({ data: [grant] });
    await expect(listGrants("link-1")).resolves.toEqual([grant]);

    mockedApiFetch.mockResolvedValueOnce({});
    await expect(deleteGrant("link-1", "grant-1")).resolves.toBeUndefined();
    expect(mockedApiFetch).toHaveBeenCalledWith("/agency/partners/link-1/grants/grant-1", {
      method: "DELETE",
    });
  });

  it("exposes the partner link statuses and grant scopes", () => {
    expect(PARTNER_LINK_STATUSES.map((status) => status.value)).toEqual([
      "pending",
      "approved",
      "suspended",
      "rejected",
      "removed",
    ]);
    expect(GRANT_SCOPES.map((scope) => scope.value)).toEqual(["view", "manage"]);
  });
});

describe("canManageAgency", () => {
  it("allows owner, admin, and operations manager", () => {
    expect(canManageAgency("owner")).toBe(true);
    expect(canManageAgency("admin")).toBe(true);
    expect(canManageAgency("operations_manager")).toBe(true);
  });

  it("refuses dispatcher, billing staff, and viewer", () => {
    expect(canManageAgency("dispatcher")).toBe(false);
    expect(canManageAgency("billing_staff")).toBe(false);
    expect(canManageAgency("viewer")).toBe(false);
    expect(canManageAgency(null)).toBe(false);
    expect(canManageAgency(undefined)).toBe(false);
  });
});
