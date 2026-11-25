import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import {
  cancelSubscription,
  changePlan,
  completeCheckout,
  createPortalSession,
  formatCents,
  getInvoices,
  getPlans,
  getSubscription,
  getUsage,
  intervalLabel,
  invoiceLineKindLabel,
  invoiceStatusLabel,
  invoiceStatusTone,
  payInvoice,
  previewPlanChange,
  resumeSubscription,
  startCheckout,
  startTrial,
  subscriptionStatusLabel,
  subscriptionStatusTone,
  updatePaymentMethod,
} from "../billing";
import type {
  BillingInvoice,
  BillingPlan,
  BillingSubscription,
  BillingUsageSummary,
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
  return { apiFetch: vi.fn(), ApiRequestError };
});

const mockedApiFetch = vi.mocked(apiFetch);

beforeEach(() => {
  mockedApiFetch.mockReset();
});

const PLAN: BillingPlan = {
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
};

const SUBSCRIPTION: BillingSubscription = {
  id: "sub-1",
  plan: "growth",
  plan_name: "Growth",
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
  payment_method_brand: "visa",
  payment_method_last4: "4242",
  extra_carriers: 0,
  credit_balance_cents: 0,
  monthly_cents: 4900,
  period_cents: 4900,
  success_fee_capped: false,
};

const INVOICE: BillingInvoice = {
  id: "inv-1",
  number: "INV-2026-0001",
  status: "paid",
  currency: "USD",
  subtotal_cents: 4900,
  total_cents: 4900,
  amount_paid_cents: 4900,
  period_start: "2026-11-10T00:00:00Z",
  period_end: "2026-12-10T00:00:00Z",
  due_date: "2026-11-10T00:00:00Z",
  paid_at: "2026-11-10T00:00:00Z",
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

describe("getPlans", () => {
  it("calls /billing/plans with the org id and unwraps data", async () => {
    mockedApiFetch.mockResolvedValue({ data: [PLAN] });
    await expect(getPlans("org-1")).resolves.toEqual([PLAN]);
    expect(mockedApiFetch).toHaveBeenCalledWith("/billing/plans", {
      orgId: "org-1",
    });
  });
});

describe("getSubscription", () => {
  it("calls /billing/subscription and unwraps data", async () => {
    mockedApiFetch.mockResolvedValue({ data: SUBSCRIPTION });
    await expect(getSubscription("org-1")).resolves.toEqual(SUBSCRIPTION);
    expect(mockedApiFetch).toHaveBeenCalledWith("/billing/subscription", {
      orgId: "org-1",
    });
  });
});

describe("startCheckout", () => {
  it("posts the plan, interval, and extra carriers", async () => {
    const session = {
      id: "cs-1",
      provider_session_id: "cs_mock_1",
      plan: "growth",
      interval: "monthly",
      extra_carriers: 0,
      status: "open",
      expires_at: "2026-11-25T13:00:00Z",
    };
    mockedApiFetch.mockResolvedValue({ data: session });
    await expect(
      startCheckout("org-1", "growth", "monthly"),
    ).resolves.toEqual(session);
    expect(mockedApiFetch).toHaveBeenCalledWith("/billing/checkout", {
      method: "POST",
      orgId: "org-1",
      body: { plan: "growth", interval: "monthly", extra_carriers: 0 },
    });
  });
});

describe("completeCheckout", () => {
  it("posts the payment method to the session complete endpoint", async () => {
    mockedApiFetch.mockResolvedValue({ data: SUBSCRIPTION });
    await expect(
      completeCheckout("org-1", "cs-1", "pm_mock_visa"),
    ).resolves.toEqual(SUBSCRIPTION);
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/billing/checkout/cs-1/complete",
      {
        method: "POST",
        orgId: "org-1",
        body: { payment_method: "pm_mock_visa" },
      },
    );
  });
});

describe("startTrial", () => {
  it("posts to /billing/trials with a null payment method for no-card trials", async () => {
    mockedApiFetch.mockResolvedValue({ data: SUBSCRIPTION });
    await expect(startTrial("org-1", null)).resolves.toEqual(SUBSCRIPTION);
    expect(mockedApiFetch).toHaveBeenCalledWith("/billing/trials", {
      method: "POST",
      orgId: "org-1",
      body: { payment_method: null },
    });
  });
});

describe("previewPlanChange", () => {
  it("posts with preview true and unwraps data", async () => {
    const preview = {
      proration: {
        credit_cents: 2450,
        charge_cents: 4950,
        net_cents: 2500,
        remaining_days: 15,
        period_days: 30,
        is_upgrade: true,
      },
      effective: "immediately" as const,
      current_plan: "growth",
      current_period_end: "2026-12-10T00:00:00Z",
    };
    mockedApiFetch.mockResolvedValue({ data: preview });
    await expect(
      previewPlanChange("org-1", "fleet", "monthly"),
    ).resolves.toEqual(preview);
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/billing/subscription/change-plan",
      {
        method: "POST",
        orgId: "org-1",
        body: {
          plan: "fleet",
          interval: "monthly",
          extra_carriers: 0,
          preview: true,
        },
      },
    );
  });
});

describe("changePlan", () => {
  it("posts without preview and unwraps the result", async () => {
    const result = {
      subscription: SUBSCRIPTION,
      invoice: INVOICE,
      proration: {
        credit_cents: 2450,
        charge_cents: 4950,
        net_cents: 2500,
        remaining_days: 15,
        period_days: 30,
        is_upgrade: true,
      },
      effective: "immediately" as const,
    };
    mockedApiFetch.mockResolvedValue({ data: result });
    await expect(changePlan("org-1", "fleet", "monthly")).resolves.toEqual(
      result,
    );
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/billing/subscription/change-plan",
      {
        method: "POST",
        orgId: "org-1",
        body: { plan: "fleet", interval: "monthly", extra_carriers: 0 },
      },
    );
  });
});

describe("cancelSubscription", () => {
  it("posts the immediate flag", async () => {
    mockedApiFetch.mockResolvedValue({ data: SUBSCRIPTION });
    await expect(cancelSubscription("org-1", false)).resolves.toEqual(
      SUBSCRIPTION,
    );
    expect(mockedApiFetch).toHaveBeenCalledWith("/billing/subscription/cancel", {
      method: "POST",
      orgId: "org-1",
      body: { immediate: false },
    });
  });
});

describe("resumeSubscription", () => {
  it("posts to /billing/resume", async () => {
    mockedApiFetch.mockResolvedValue({ data: SUBSCRIPTION });
    await expect(resumeSubscription("org-1")).resolves.toEqual(SUBSCRIPTION);
    expect(mockedApiFetch).toHaveBeenCalledWith("/billing/subscription/resume", {
      method: "POST",
      orgId: "org-1",
    });
  });
});

describe("getInvoices", () => {
  it("calls /billing/invoices with paging and unwraps data and meta", async () => {
    const meta = { current_page: 1, last_page: 1, per_page: 15, total: 1 };
    mockedApiFetch.mockResolvedValue({ data: [INVOICE], meta });
    await expect(getInvoices("org-1")).resolves.toEqual({
      data: [INVOICE],
      meta,
    });
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/billing/invoices?page=1&per_page=15",
      { orgId: "org-1" },
    );
  });
});

describe("payInvoice", () => {
  it("posts the payment method to the pay endpoint", async () => {
    mockedApiFetch.mockResolvedValue({ data: INVOICE });
    await expect(payInvoice("org-1", "inv-1", "pm_mock_visa")).resolves.toEqual(
      INVOICE,
    );
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/billing/invoices/inv-1/pay",
      {
        method: "POST",
        orgId: "org-1",
        body: { payment_method: "pm_mock_visa" },
      },
    );
  });
});

describe("updatePaymentMethod", () => {
  it("posts the new payment method", async () => {
    mockedApiFetch.mockResolvedValue({ data: SUBSCRIPTION });
    await expect(updatePaymentMethod("org-1", "pm_mock_visa")).resolves.toEqual(
      SUBSCRIPTION,
    );
    expect(mockedApiFetch).toHaveBeenCalledWith("/billing/payment-method", {
      method: "POST",
      orgId: "org-1",
      body: { payment_method: "pm_mock_visa" },
    });
  });
});

describe("createPortalSession", () => {
  it("posts to /billing/portal-sessions and unwraps data", async () => {
    const session = {
      url: "https://mock-billing.example/portal/ps_mock_1",
      token: "ps_mock_1",
      expires_at: "2026-11-25T14:00:00Z",
    };
    mockedApiFetch.mockResolvedValue({ data: session });
    await expect(createPortalSession("org-1")).resolves.toEqual(session);
    expect(mockedApiFetch).toHaveBeenCalledWith("/billing/portal", {
      method: "POST",
      orgId: "org-1",
    });
  });
});

describe("getUsage", () => {
  it("calls /billing/usage and unwraps data", async () => {
    const usage: BillingUsageSummary = {
      plan: "success_fee",
      period_start: "2026-11-10T00:00:00Z",
      period_end: "2026-12-10T00:00:00Z",
      records: [],
      recovered_cents: 0,
      accrued_fee_cents: 0,
      cap_cents: 4900,
      cap_remaining_cents: 4900,
    };
    mockedApiFetch.mockResolvedValue({ data: usage });
    await expect(getUsage("org-1")).resolves.toEqual(usage);
    expect(mockedApiFetch).toHaveBeenCalledWith("/billing/usage", {
      orgId: "org-1",
    });
  });
});

describe("formatCents", () => {
  it("formats dollars and keeps the minus sign on negatives", () => {
    expect(formatCents(4900)).toBe("$49.00");
    expect(formatCents(0)).toBe("$0.00");
    expect(formatCents(-2450)).toBe("-$24.50");
    expect(formatCents(199)).toBe("$1.99");
  });
});

describe("subscriptionStatusLabel and subscriptionStatusTone", () => {
  it("labels every status", () => {
    expect(subscriptionStatusLabel("trialing")).toBe("Trial");
    expect(subscriptionStatusLabel("active")).toBe("Active");
    expect(subscriptionStatusLabel("past_due")).toBe("Past due");
    expect(subscriptionStatusLabel("canceled")).toBe("Canceled");
    expect(subscriptionStatusTone("active")).toBe("success");
    expect(subscriptionStatusTone("past_due")).toBe("danger");
  });
});

describe("invoiceStatusLabel and invoiceStatusTone", () => {
  it("labels every status", () => {
    expect(invoiceStatusLabel("open")).toBe("Open");
    expect(invoiceStatusLabel("paid")).toBe("Paid");
    expect(invoiceStatusLabel("past_due")).toBe("Past due");
    expect(invoiceStatusLabel("void")).toBe("Void");
    expect(invoiceStatusLabel("uncollectible")).toBe("Uncollectible");
    expect(invoiceStatusLabel("draft")).toBe("Draft");
    expect(invoiceStatusTone("paid")).toBe("success");
    expect(invoiceStatusTone("open")).toBe("warning");
  });
});

describe("invoiceLineKindLabel", () => {
  it("labels every line kind and passes unknown kinds through", () => {
    expect(invoiceLineKindLabel("subscription")).toBe("Subscription");
    expect(invoiceLineKindLabel("proration_credit")).toBe("Proration credit");
    expect(invoiceLineKindLabel("proration_charge")).toBe("Proration charge");
    expect(invoiceLineKindLabel("success_fee")).toBe("Success fee");
    expect(invoiceLineKindLabel("cap_adjustment")).toBe(
      "First-year cap adjustment",
    );
    expect(invoiceLineKindLabel("credit_applied")).toBe("Credit applied");
    expect(invoiceLineKindLabel("something_new")).toBe("something_new");
  });
});

describe("intervalLabel", () => {
  it("labels monthly and annual", () => {
    expect(intervalLabel("monthly")).toBe("Monthly");
    expect(intervalLabel("annual")).toBe("Annual");
  });
});
