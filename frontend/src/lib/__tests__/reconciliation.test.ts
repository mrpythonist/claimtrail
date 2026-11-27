import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import {
  RECONCILIATION_PAYMENT_METHODS,
  balanceStatusLabel,
  generateReconciliationReport,
  getClaimBalance,
  getReconciliationReport,
  getReconciliationSummary,
  listLedgerPayments,
  listReconciliationReports,
  recordClaimPayment,
  reconciliationPaymentMethodLabel,
  voidClaimPayment,
} from "../reconciliation";
import type { ClaimBalance, ReconciliationReport } from "@/lib/types";

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
  return { apiFetch: vi.fn(), apiUpload: vi.fn(), ApiRequestError };
});

const mockedApiFetch = vi.mocked(apiFetch);

beforeEach(() => {
  mockedApiFetch.mockReset();
});

const BALANCE: ClaimBalance = {
  claim_id: "claim-1",
  claim_reference: "CLM-2026-0001",
  status: "accepted",
  currency: "USD",
  requested_amount: "250.00",
  approved_amount: "200.00",
  due_amount: "200.00",
  paid_amount: "120.00",
  outstanding_amount: "80.00",
  ledger_amount: "120.00",
  legacy_amount: "0.00",
  balance_status: "partial",
  payments: [
    {
      id: "pay-1",
      organization_id: "org-1",
      claim_id: "claim-1",
      amount: "120.00",
      currency: "USD",
      payment_method: "wire_transfer",
      payment_method_label: "Wire transfer",
      payment_reference: "WIRE-1001",
      paid_at: "2026-11-27",
      notes: null,
      recorded_by: "user-1",
      created_at: "2026-11-27T10:00:00Z",
    },
  ],
};

const REPORT: ReconciliationReport = {
  id: "rep-1",
  organization_id: "org-1",
  period_start: "2026-11-01",
  period_end: "2026-11-30",
  totals: {
    claims_count: 1,
    payments_count: 1,
    requested_amount: "250.00",
    approved_amount: "200.00",
    paid_amount: "120.00",
    outstanding_amount: "80.00",
    discrepancies_count: 0,
  },
  discrepancies: [],
  generated_by: "user-1",
  generated_at: "2026-11-27T10:00:00Z",
  created_at: "2026-11-27T10:00:00Z",
};

describe("reconciliationPaymentMethodLabel", () => {
  it("labels every method", () => {
    expect(reconciliationPaymentMethodLabel("wire_transfer")).toBe("Wire transfer");
    expect(reconciliationPaymentMethodLabel("ach")).toBe("ACH");
    expect(reconciliationPaymentMethodLabel("cheque")).toBe("Cheque");
    expect(reconciliationPaymentMethodLabel("cash")).toBe("Cash");
    expect(reconciliationPaymentMethodLabel("card")).toBe("Card");
    expect(reconciliationPaymentMethodLabel("other")).toBe("Other");
  });
});

describe("RECONCILIATION_PAYMENT_METHODS", () => {
  it("covers the six methods", () => {
    expect(RECONCILIATION_PAYMENT_METHODS.map((m) => m.value)).toEqual([
      "wire_transfer",
      "ach",
      "cheque",
      "cash",
      "card",
      "other",
    ]);
  });
});

describe("balanceStatusLabel", () => {
  it("labels every balance status", () => {
    expect(balanceStatusLabel("unpaid")).toBe("Unpaid");
    expect(balanceStatusLabel("partial")).toBe("Partially paid");
    expect(balanceStatusLabel("paid")).toBe("Paid");
    expect(balanceStatusLabel("overpaid")).toBe("Overpaid");
  });
});

describe("getReconciliationSummary", () => {
  it("calls the summary endpoint", async () => {
    mockedApiFetch.mockResolvedValueOnce({
      data: {
        organization_id: "org-1",
        claims_count: 1,
        requested_amount: "250.00",
        approved_amount: "200.00",
        paid_amount: "120.00",
        outstanding_amount: "80.00",
        by_balance_status: { unpaid: 0, partial: 1, paid: 0, overpaid: 0 },
        currencies: ["USD"],
      },
    });
    const summary = await getReconciliationSummary();
    expect(mockedApiFetch).toHaveBeenCalledWith("/reconciliation/summary");
    expect(summary.outstanding_amount).toBe("80.00");
  });
});

describe("listLedgerPayments", () => {
  it("calls the ledger endpoint with filters", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: BALANCE.payments });
    const payments = await listLedgerPayments({
      claim_id: "claim-1",
      payment_method: "wire_transfer",
    });
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/reconciliation/ledger?claim_id=claim-1&payment_method=wire_transfer",
    );
    expect(payments).toHaveLength(1);
    expect(payments[0].payment_reference).toBe("WIRE-1001");
  });

  it("calls the ledger endpoint without a query string when unfiltered", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: [] });
    await listLedgerPayments();
    expect(mockedApiFetch).toHaveBeenCalledWith("/reconciliation/ledger");
  });
});

describe("getClaimBalance", () => {
  it("calls the claim balance endpoint", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: BALANCE });
    const balance = await getClaimBalance("claim-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/reconciliation/claims/claim-1");
    expect(balance.balance_status).toBe("partial");
    expect(balance.payments).toHaveLength(1);
  });
});

describe("recordClaimPayment", () => {
  it("posts the payment and returns the payment plus balance", async () => {
    mockedApiFetch.mockResolvedValueOnce({
      data: BALANCE.payments[0],
      balance: { ...BALANCE, payments: undefined },
    });
    const result = await recordClaimPayment("claim-1", {
      amount: 120,
      payment_method: "wire_transfer",
      paid_at: "2026-11-27",
      payment_reference: "WIRE-1001",
    });
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/reconciliation/claims/claim-1/payments",
      {
        method: "POST",
        body: {
          amount: 120,
          payment_method: "wire_transfer",
          paid_at: "2026-11-27",
          payment_reference: "WIRE-1001",
        },
      },
    );
    expect(result.payment.amount).toBe("120.00");
    expect(result.balance.outstanding_amount).toBe("80.00");
  });
});

describe("voidClaimPayment", () => {
  it("deletes the ledger payment", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: { voided: true } });
    await voidClaimPayment("pay-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/reconciliation/ledger/pay-1", {
      method: "DELETE",
    });
  });
});

describe("reconciliation reports", () => {
  it("lists, reads, and generates reports", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: [REPORT] });
    const reports = await listReconciliationReports();
    expect(mockedApiFetch).toHaveBeenCalledWith("/reconciliation/reports");
    expect(reports).toHaveLength(1);

    mockedApiFetch.mockResolvedValueOnce({ data: REPORT });
    const report = await getReconciliationReport("rep-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/reconciliation/reports/rep-1");
    expect(report.totals.discrepancies_count).toBe(0);

    mockedApiFetch.mockResolvedValueOnce({ data: REPORT });
    const generated = await generateReconciliationReport({
      period_start: "2026-11-01",
      period_end: "2026-11-30",
    });
    expect(mockedApiFetch).toHaveBeenCalledWith("/reconciliation/reports", {
      method: "POST",
      body: { period_start: "2026-11-01", period_end: "2026-11-30" },
    });
    expect(generated.id).toBe("rep-1");
  });
});
