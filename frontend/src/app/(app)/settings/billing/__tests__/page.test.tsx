import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type {
  BillingInvoice,
  BillingPlan,
  BillingSubscription,
  Organization,
} from "@/lib/types";
import BillingPage from "../page";

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
  return { apiFetch: vi.fn(), ApiRequestError };
});

vi.mock("@/lib/auth", () => ({ useAuth: vi.fn() }));

const mockedApiFetch = vi.mocked(apiFetch);
const mockedUseAuth = vi.mocked(useAuth);

const ADMIN_ORG: Organization = {
  id: "org-1",
  name: "Acme Trucking",
  slug: "acme",
  timezone: "UTC",
  settings: null,
  created_at: "2026-11-01T00:00:00Z",
  updated_at: "2026-11-01T00:00:00Z",
  current_user_role: "admin",
  current_user_status: "active",
};

const VIEWER_ORG: Organization = {
  ...ADMIN_ORG,
  current_user_role: "viewer",
};

const FREE_SUBSCRIPTION: BillingSubscription = {
  id: "sub-1",
  plan: "free",
  plan_name: "Free",
  status: "active",
  interval: "monthly",
  current_period_start: "2026-11-10T00:00:00Z",
  current_period_end: "2026-12-10T00:00:00Z",
  trial_ends_at: null,
  cancel_at_period_end: false,
  canceled_at: null,
  pending_plan: null,
  pending_interval: null,
  pending_extra_carriers: null,
  provider: "mock",
  payment_method_brand: null,
  payment_method_last4: null,
  extra_carriers: 0,
  credit_balance_cents: 0,
  monthly_cents: 0,
  period_cents: 0,
  success_fee_capped: false,
  usage: {
    plan: "free",
    period_start: "2026-11-10T00:00:00Z",
    period_end: "2026-12-10T00:00:00Z",
    records: [],
    recovered_cents: 0,
    accrued_fee_cents: 0,
    cap_cents: null,
    cap_remaining_cents: null,
    entitlements: {
      plan: "free",
      plan_name: "Free",
      status: "active",
      period_start: "2026-11-10T00:00:00Z",
      period_end: "2026-12-10T00:00:00Z",
      carriers: 1,
      limits: {
        plan: "free",
        plan_name: "Free",
        claims_per_month: 5,
        members: 1,
        storage_bytes: 104857600,
        pdf_packages: false,
        reports: false,
      },
      usage: {
        claims_created: 2,
        claims_carryover: 0,
        members: 1,
        pending_invitations: 0,
        storage_bytes: 10485760,
      },
      upgrade: {
        claims: { key: "starter", name: "Starter" },
        members: { key: "starter", name: "Starter" },
        storage: { key: "starter", name: "Starter" },
        pdf_packages: { key: "starter", name: "Starter" },
        reports: { key: "starter", name: "Starter" },
      },
    },
  },
};

const PLANS: BillingPlan[] = [
  {
    key: "free",
    name: "Free",
    monthly_cents: 0,
    annual_cents: 0,
    per_extra_carrier_cents: 0,
    included_carriers: 0,
    trucks: "1 truck",
    limits: { claims_per_month: 5, members: 1, storage: "100 MB" },
    description: "One-truck owner-operator keeping a record.",
    listed: true,
  },
  {
    key: "growth",
    name: "Growth",
    monthly_cents: 4900,
    annual_cents: 49000,
    per_extra_carrier_cents: 0,
    included_carriers: 0,
    trucks: "3-10 trucks",
    limits: { claims_per_month: 100, members: 10, storage: "10 GB" },
    description: "The default tier.",
    listed: true,
  },
];

const OPEN_INVOICE: BillingInvoice = {
  id: "inv-1",
  number: "INV-2026-0001",
  status: "open",
  currency: "USD",
  subtotal_cents: 4900,
  total_cents: 4900,
  amount_paid_cents: 0,
  period_start: "2026-11-10T00:00:00Z",
  period_end: "2026-12-10T00:00:00Z",
  due_date: "2026-11-10T00:00:00Z",
  paid_at: null,
  payment_attempts: 0,
  next_retry_at: null,
  failure_code: null,
  failure_message: null,
  memo: null,
  lines: [
    {
      id: "line-1",
      kind: "subscription",
      description: "Growth monthly",
      amount_cents: 4900,
    },
  ],
  created_at: "2026-11-10T00:00:00Z",
};

function mockAuth(org: Organization | null) {
  mockedUseAuth.mockReturnValue({
    user: null,
    organizations: org ? [org] : [],
    currentOrg: org,
    loading: false,
    login: vi.fn(),
    register: vi.fn(),
    logout: vi.fn(),
    refreshUser: vi.fn(),
    selectOrganization: vi.fn(),
    createOrganization: vi.fn(),
  });
}

beforeEach(() => {
  mockedApiFetch.mockReset();
  mockedApiFetch.mockImplementation(async (path: string) => {
    if (path === "/billing/plans") return { data: PLANS };
    if (path === "/billing/subscription") return { data: FREE_SUBSCRIPTION };
    if (path.startsWith("/billing/invoices?")) {
      return {
        data: [OPEN_INVOICE],
        meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
      };
    }
    throw new Error(`Unexpected call: ${path}`);
  });
  mockAuth(ADMIN_ORG);
});

describe("BillingPage", () => {
  it("renders the subscription, plans, and invoices", async () => {
    render(<BillingPage />);

    expect(await screen.findByText("Current subscription")).toBeInTheDocument();
    expect(screen.getByText("Free")).toBeInTheDocument();
    expect(screen.getByText("Active")).toBeInTheDocument();

    expect(screen.getByText("Plans")).toBeInTheDocument();
    expect(screen.getByText("Growth")).toBeInTheDocument();
    expect(screen.getByText("$49.00/month")).toBeInTheDocument();
    expect(screen.getByText("3-10 trucks · 100 claims/month · 10 members · 10 GB storage")).toBeInTheDocument();

    expect(screen.getByText("Invoices")).toBeInTheDocument();
    expect(screen.getByText("INV-2026-0001")).toBeInTheDocument();
    expect(screen.getByText("Open")).toBeInTheDocument();
    expect(screen.getByText("Subscription: $49.00")).toBeInTheDocument();

    expect(screen.getByText("Try Growth free for 30 days")).toBeInTheDocument();
    expect(
      screen.getByText(
        "The card charged for renewals and invoice payments. Mock billing only: pick a Stripe test card.",
      ),
    ).toBeInTheDocument();
    expect(screen.getByText("Billing portal")).toBeInTheDocument();
  });

  it("shows plan usage against the plan limits with upgrade prompts", async () => {
    render(<BillingPage />);

    expect(await screen.findByText("Plan usage")).toBeInTheDocument();
    expect(screen.getByText("2 of 5")).toBeInTheDocument();
    expect(screen.getByText("1 of 1")).toBeInTheDocument();
    expect(screen.getByText("10 MB of 100 MB")).toBeInTheDocument();
    expect(screen.getByText("Not included on this plan")).toBeInTheDocument();
    expect(screen.getByText("Dashboard only")).toBeInTheDocument();
    expect(
      screen.getByText("At the limit. Upgrade to Starter for more members."),
    ).toBeInTheDocument();
    expect(
      screen.getByText(
        "Upgrade to Starter to generate PDF claim packages.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText("Upgrade to Starter for full reports."),
    ).toBeInTheDocument();
  });

  it("shows an access-denied alert for viewers", async () => {
    mockAuth(VIEWER_ORG);
    render(<BillingPage />);

    expect(
      await screen.findByText(
        "Billing settings are not available for your role in this organization.",
      ),
    ).toBeInTheDocument();
    expect(mockedApiFetch).not.toHaveBeenCalled();
  });

  it("starts a 30-day trial and shows the result", async () => {
    const user = userEvent.setup();
    mockedApiFetch.mockImplementation(async (path: string) => {
      if (path === "/billing/plans") return { data: PLANS };
      if (path === "/billing/subscription") return { data: FREE_SUBSCRIPTION };
      if (path.startsWith("/billing/invoices?")) {
        return {
          data: [OPEN_INVOICE],
          meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
        };
      }
      if (path === "/billing/trials") {
        return { data: { ...FREE_SUBSCRIPTION, status: "trialing", plan: "growth" } };
      }
      throw new Error(`Unexpected call: ${path}`);
    });
    render(<BillingPage />);

    await screen.findByText("Try Growth free for 30 days");
    await user.click(
      screen.getByRole("button", { name: "Start 30-day free trial" }),
    );

    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith("/billing/trials", {
        method: "POST",
        orgId: "org-1",
        body: { payment_method: "pm_mock_visa" },
      });
    });
    expect(
      await screen.findByText(/30-day Growth trial started/),
    ).toBeInTheDocument();
  });

  it("subscribes to a plan through checkout and shows the result", async () => {
    const user = userEvent.setup();
    mockedApiFetch.mockImplementation(async (path: string, options?: unknown) => {
      if (path === "/billing/plans") return { data: PLANS };
      if (path === "/billing/subscription") return { data: FREE_SUBSCRIPTION };
      if (path.startsWith("/billing/invoices?")) {
        return {
          data: [OPEN_INVOICE],
          meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
        };
      }
      if (path === "/billing/checkout") {
        return {
          data: {
            id: "cs-1",
            provider_session_id: "cs_mock_1",
            plan: "growth",
            interval: "monthly",
            extra_carriers: 0,
            status: "open",
            expires_at: "2026-11-25T13:00:00Z",
          },
        };
      }
      if (path === "/billing/checkout/cs-1/complete") {
        return { data: { ...FREE_SUBSCRIPTION, plan: "growth" } };
      }
      throw new Error(`Unexpected call: ${path} ${JSON.stringify(options)}`);
    });
    render(<BillingPage />);

    await screen.findByText("Plans");
    await user.click(
      screen.getByRole("button", { name: "Subscribe to Growth" }),
    );

    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith("/billing/checkout", {
        method: "POST",
        orgId: "org-1",
        body: { plan: "growth", interval: "monthly", extra_carriers: 0 },
      });
      expect(mockedApiFetch).toHaveBeenCalledWith(
        "/billing/checkout/cs-1/complete",
        {
          method: "POST",
          orgId: "org-1",
          body: { payment_method: "pm_mock_visa" },
        },
      );
    });
    expect(
      await screen.findByText("Subscribed to Growth."),
    ).toBeInTheDocument();
  });

  it("pays an open invoice and shows the result", async () => {
    const user = userEvent.setup();
    mockedApiFetch.mockImplementation(async (path: string) => {
      if (path === "/billing/plans") return { data: PLANS };
      if (path === "/billing/subscription") return { data: FREE_SUBSCRIPTION };
      if (path.startsWith("/billing/invoices?")) {
        return {
          data: [OPEN_INVOICE],
          meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
        };
      }
      if (path === "/billing/invoices/inv-1/pay") {
        return { data: { ...OPEN_INVOICE, status: "paid" } };
      }
      throw new Error(`Unexpected call: ${path}`);
    });
    render(<BillingPage />);

    await screen.findByText("Invoices");
    await user.click(screen.getByRole("button", { name: "Pay now" }));

    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith(
        "/billing/invoices/inv-1/pay",
        {
          method: "POST",
          orgId: "org-1",
          body: { payment_method: "pm_mock_visa" },
        },
      );
    });
    expect(
      await screen.findByText("Invoice INV-2026-0001 paid."),
    ).toBeInTheDocument();
  });

  it("opens the billing portal and shows the link", async () => {
    const user = userEvent.setup();
    mockedApiFetch.mockImplementation(async (path: string) => {
      if (path === "/billing/plans") return { data: PLANS };
      if (path === "/billing/subscription") return { data: FREE_SUBSCRIPTION };
      if (path.startsWith("/billing/invoices?")) {
        return {
          data: [OPEN_INVOICE],
          meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
        };
      }
      if (path === "/billing/portal") {
        return {
          data: {
            url: "https://mock-billing.example/portal/ps_mock_1",
            token: "ps_mock_1",
            expires_at: "2026-11-25T14:00:00Z",
          },
        };
      }
      throw new Error(`Unexpected call: ${path}`);
    });
    render(<BillingPage />);

    await screen.findByText("Billing portal");
    await user.click(
      screen.getByRole("button", { name: "Open billing portal" }),
    );

    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith("/billing/portal", {
        method: "POST",
        orgId: "org-1",
      });
    });
    expect(
      await screen.findByText("https://mock-billing.example/portal/ps_mock_1"),
    ).toBeInTheDocument();
  });
});
