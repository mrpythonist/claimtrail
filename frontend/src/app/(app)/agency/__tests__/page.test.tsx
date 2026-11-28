import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { useAuth } from "@/lib/auth";
import {
  createCommissionRule,
  createGrant,
  createPartnerLink,
  createReferralCode,
  getAgencyDashboard,
  getCommissions,
  getConsolidatedReport,
  listAgencyCarriers,
  listCommissionRules,
  listPartnerLinks,
  listReferralCodes,
} from "@/lib/agency";
import type {
  AgencyCommissions,
  AgencyDashboard,
  CommissionRule,
  ConsolidatedReport,
  Organization,
  PartnerLink,
  ReferralCode,
} from "@/lib/types";
import AgencyPage from "../page";

vi.mock("@/lib/agency", () => ({
  GRANT_SCOPES: [
    { value: "view", label: "View" },
    { value: "manage", label: "Manage" },
  ],
  PARTNER_LINK_STATUSES: [
    { value: "pending", label: "Pending" },
    { value: "approved", label: "Approved" },
    { value: "suspended", label: "Suspended" },
    { value: "rejected", label: "Rejected" },
    { value: "removed", label: "Removed" },
  ],
  assignPartnerPlan: vi.fn(),
  createCommissionRule: vi.fn(),
  createGrant: vi.fn(),
  createPartnerLink: vi.fn(),
  createReferralCode: vi.fn(),
  deactivateReferralCode: vi.fn(),
  deleteCommissionRule: vi.fn(),
  deleteGrant: vi.fn(),
  getAgencyDashboard: vi.fn(),
  getCarrierClaim: vi.fn(),
  getCommissions: vi.fn(),
  getConsolidatedReport: vi.fn(),
  getPartnerLink: vi.fn(),
  getReferralCode: vi.fn(),
  listAgencyCarriers: vi.fn(),
  listCarrierClaims: vi.fn(),
  listCommissionRules: vi.fn(),
  listGrants: vi.fn(),
  listPartnerLinks: vi.fn(),
  listReferralCodes: vi.fn(),
  reactivatePartnerLink: vi.fn(),
  removePartnerLink: vi.fn(),
  suspendPartnerLink: vi.fn(),
  updateCommissionRule: vi.fn(),
}));

vi.mock("@/lib/auth", () => ({ useAuth: vi.fn() }));

const mockedUseAuth = vi.mocked(useAuth);
const mockedDashboard = vi.mocked(getAgencyDashboard);
const mockedLinks = vi.mocked(listPartnerLinks);
const mockedCarriers = vi.mocked(listAgencyCarriers);
const mockedReport = vi.mocked(getConsolidatedReport);
const mockedCodes = vi.mocked(listReferralCodes);
const mockedCommissions = vi.mocked(getCommissions);
const mockedRules = vi.mocked(listCommissionRules);

const ORG: Organization = {
  id: "org-1",
  name: "Acme Agency",
  slug: "acme-agency",
  timezone: "UTC",
  settings: null,
  created_at: "2026-11-01T00:00:00Z",
  updated_at: "2026-11-01T00:00:00Z",
  current_user_role: "admin",
  current_user_status: "active",
};

const META = { current_page: 1, last_page: 1, per_page: 15, total: 1 };

const DASHBOARD: AgencyDashboard = {
  agency_organization_id: "org-1",
  carriers: [
    {
      carrier: {
        organization_id: "carrier-1",
        name: "Carrier One",
        slug: "carrier-one",
        partner_link_id: "link-1",
        link_status: "approved",
        referred_by_code: "REF-ABCDEFGH",
        assigned_plan: "starter",
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
      commission: {
        rate_percent: "10.00",
        rule_id: null,
        rule_is_default: false,
        basis: "paid",
        by_currency: [{ currency: "USD", payments: "250.00", earned: "25.00", capped: false }],
        note: "Computed from recorded payments only.",
      },
    },
  ],
  totals_by_currency: [
    {
      currency: "USD",
      requested: "800.00",
      accepted: "400.00",
      recovered: "250.00",
      payments: "250.00",
      outstanding: "150.00",
    },
  ],
  link_counts: { total: 1, pending: 0, approved: 1, suspended: 0, rejected: 0, removed: 0 },
  referrals: { codes_total: 1, codes_active: 1, carriers_attributed: 1 },
  commission: {
    agency_organization_id: "org-1",
    date_from: null,
    date_to: null,
    carriers: [],
    totals_by_currency: [{ currency: "USD", payments: "250.00", earned: "25.00" }],
    note: "Computed from recorded payments only.",
  },
};

const LINK: PartnerLink = {
  id: "link-1",
  agency_organization_id: "org-1",
  carrier_organization_id: "carrier-1",
  carrier_organization_name: "Carrier One",
  status: "approved",
  referred_by_code: "REF-ABCDEFGH",
  assigned_plan: "starter",
  approved_by: 2,
  approved_at: "2026-11-28T09:00:00.000Z",
  created_by: 1,
  notes: null,
  created_at: "2026-11-28T08:00:00.000Z",
  updated_at: "2026-11-28T09:00:00.000Z",
};

const REPORT: ConsolidatedReport = {
  agency_organization_id: "org-1",
  date_from: null,
  date_to: null,
  carrier_organization_id: null,
  carriers: [
    {
      carrier: DASHBOARD.carriers[0].carrier,
      claims_total: 2,
      by_status: { paid: 1, potential: 1 },
      totals: DASHBOARD.totals_by_currency,
    },
  ],
  totals_by_currency: DASHBOARD.totals_by_currency,
  note: "Computed only from the linked carriers' own recorded claims and payments.",
};

const CODE: ReferralCode = {
  id: "code-1",
  organization_id: "org-1",
  code: "REF-ABCDEFGH",
  active: true,
  uses_count: 1,
  created_by: 1,
  notes: null,
  created_at: "2026-11-28T08:00:00.000Z",
  updated_at: "2026-11-28T08:00:00.000Z",
};

const RULE: CommissionRule = {
  id: "rule-1",
  agency_organization_id: "org-1",
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
  created_at: "2026-11-28T08:00:00.000Z",
  updated_at: "2026-11-28T08:00:00.000Z",
};

const COMMISSIONS: AgencyCommissions = {
  agency_organization_id: "org-1",
  date_from: null,
  date_to: null,
  carriers: [
    {
      carrier: DASHBOARD.carriers[0].carrier,
      rate_percent: "10.00",
      rule_id: null,
      rule_is_default: false,
      basis: "paid",
      by_currency: [{ currency: "USD", payments: "250.00", earned: "25.00", capped: false }],
      note: "Computed from recorded payments only.",
    },
  ],
  totals_by_currency: [{ currency: "USD", payments: "250.00", earned: "25.00" }],
  note: "Computed from recorded payments only.",
};

function mockLoad() {
  mockedDashboard.mockResolvedValue(DASHBOARD);
  mockedLinks.mockResolvedValue({ data: [LINK], meta: META });
  mockedCarriers.mockResolvedValue(DASHBOARD.carriers);
  mockedReport.mockResolvedValue(REPORT);
  mockedCodes.mockResolvedValue({ data: [CODE], meta: META });
  mockedCommissions.mockResolvedValue(COMMISSIONS);
  mockedRules.mockResolvedValue([RULE]);
}

beforeEach(() => {
  for (const fn of [
    vi.mocked(createCommissionRule),
    vi.mocked(createGrant),
    vi.mocked(createPartnerLink),
    vi.mocked(createReferralCode),
    mockedDashboard,
    mockedLinks,
    mockedCarriers,
    mockedReport,
    mockedCodes,
    mockedCommissions,
    mockedRules,
  ]) {
    fn.mockReset();
  }
  mockedUseAuth.mockReturnValue({ currentOrg: ORG } as ReturnType<typeof useAuth>);
  mockLoad();
});

describe("AgencyPage", () => {
  it("shows the tabs and the dashboard overview", async () => {
    render(<AgencyPage />);

    for (const label of [
      "Overview",
      "Carriers",
      "Claims",
      "Reports",
      "Referrals",
      "Commissions",
      "Reseller",
    ]) {
      expect(screen.getByRole("tab", { name: label })).toBeInTheDocument();
    }

    await waitFor(() => {
      expect(screen.getByText("Carrier One")).toBeInTheDocument();
      expect(screen.getByText("Partner links")).toBeInTheDocument();
    });
    expect(screen.getByText("Commission earned")).toBeInTheDocument();
    expect(screen.getByText(/never converted/)).toBeInTheDocument();
  });

  it("shows the partner link form and links", async () => {
    render(<AgencyPage />);
    fireEvent.click(screen.getByRole("tab", { name: "Carriers" }));

    await waitFor(() => {
      expect(screen.getByLabelText("Carrier organization id")).toBeInTheDocument();
      expect(screen.getByText("Create partner link")).toBeInTheDocument();
    });
    expect(screen.getByText("Carrier One")).toBeInTheDocument();
    expect(screen.getByText(/kept as a record/)).toBeInTheDocument();
  });

  it("shows the consolidated report form", async () => {
    render(<AgencyPage />);
    fireEvent.click(screen.getByRole("tab", { name: "Reports" }));

    await waitFor(() => {
      expect(screen.getByLabelText("From (date)")).toBeInTheDocument();
      expect(screen.getByText("Run report")).toBeInTheDocument();
    });
    expect(screen.getByText(/No external benchmarks/)).toBeInTheDocument();
  });

  it("shows referral codes and the create form", async () => {
    render(<AgencyPage />);
    fireEvent.click(screen.getByRole("tab", { name: "Referrals" }));

    await waitFor(() => {
      expect(screen.getByText("REF-ABCDEFGH")).toBeInTheDocument();
      expect(screen.getByText("Create referral code")).toBeInTheDocument();
    });
  });

  it("shows commission rules and earned commission", async () => {
    render(<AgencyPage />);
    fireEvent.click(screen.getByRole("tab", { name: "Commissions" }));

    await waitFor(() => {
      expect(screen.getByText("Commission rules")).toBeInTheDocument();
      expect(screen.getByText("Earned commission")).toBeInTheDocument();
    });
    expect(screen.getByText(/nothing here pays a partner out/)).toBeInTheDocument();
  });

  it("shows the reseller plan assignment, grants, and onboarding flow", async () => {
    render(<AgencyPage />);
    fireEvent.click(screen.getByRole("tab", { name: "Reseller" }));

    await waitFor(() => {
      expect(screen.getByText("Reseller plan assignment")).toBeInTheDocument();
      expect(screen.getByText("Carrier access grants")).toBeInTheDocument();
      expect(screen.getByText("Onboarding flow")).toBeInTheDocument();
    });
    expect(screen.getByText(/White-label branding/)).toBeInTheDocument();
  });

  it("shows an empty state when there are no carriers", async () => {
    mockedDashboard.mockResolvedValue({ ...DASHBOARD, carriers: [], totals_by_currency: [] });
    render(<AgencyPage />);

    await waitFor(() => {
      expect(screen.getByText("No active carriers")).toBeInTheDocument();
    });
  });
});
