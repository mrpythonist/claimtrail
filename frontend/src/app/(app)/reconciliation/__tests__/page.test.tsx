import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type {
  ClaimPayment,
  Organization,
  ReconciliationReport,
  ReconciliationSummary,
} from "@/lib/types";
import ReconciliationPage from "../page";

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

const ORG: Organization = {
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

const SUMMARY: ReconciliationSummary = {
  organization_id: "org-1",
  claims_count: 1,
  requested_amount: "250.00",
  approved_amount: "200.00",
  paid_amount: "120.00",
  outstanding_amount: "80.00",
  by_balance_status: { unpaid: 0, partial: 1, paid: 0, overpaid: 0 },
  currencies: ["USD"],
};

const PAYMENT: ClaimPayment = {
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

function mockLoad(
  summary: ReconciliationSummary | null = SUMMARY,
  payments: ClaimPayment[] = [PAYMENT],
  reports: ReconciliationReport[] = [REPORT],
) {
  mockedApiFetch.mockImplementation((url: string, options?: { method?: string }) => {
    if (url.startsWith("/reconciliation/summary")) {
      return Promise.resolve({ data: summary });
    }
    if (url.startsWith("/reconciliation/ledger")) {
      return Promise.resolve({ data: payments });
    }
    if (url.startsWith("/reconciliation/reports") && options?.method === "POST") {
      return Promise.resolve({ data: REPORT });
    }
    if (url.startsWith("/reconciliation/reports")) {
      return Promise.resolve({ data: reports });
    }
    return Promise.reject(new Error(`Unexpected URL ${url}`));
  });
}

beforeEach(() => {
  mockedApiFetch.mockReset();
  mockedUseAuth.mockReturnValue({ currentOrg: ORG } as ReturnType<typeof useAuth>);
});

describe("ReconciliationPage", () => {
  it("shows totals, the ledger, and reports", async () => {
    mockLoad();
    render(<ReconciliationPage />);

    await waitFor(() => {
      expect(screen.getByText("WIRE-1001", { exact: false })).toBeInTheDocument();
      expect(screen.getByText("2026-11-01 to 2026-11-30")).toBeInTheDocument();
    });

    expect(screen.getByText("80.00")).toBeInTheDocument();
    expect(screen.getByText("Clean")).toBeInTheDocument();
    expect(screen.getByText("Generate report")).toBeInTheDocument();
  });

  it("shows empty states when nothing exists", async () => {
    mockLoad(
      { ...SUMMARY, claims_count: 0, requested_amount: "0.00", approved_amount: "0.00", paid_amount: "0.00", outstanding_amount: "0.00", by_balance_status: { unpaid: 0, partial: 0, paid: 0, overpaid: 0 } },
      [],
      [],
    );
    render(<ReconciliationPage />);

    await waitFor(() => {
      expect(screen.getByText("No payments recorded yet")).toBeInTheDocument();
      expect(screen.getByText("No reports generated yet")).toBeInTheDocument();
    });
  });

  it("generates a report from the form", async () => {
    mockLoad(SUMMARY, [PAYMENT], []);
    const user = userEvent.setup();
    render(<ReconciliationPage />);

    await waitFor(() => {
      expect(screen.getByText("Generate report")).toBeInTheDocument();
    });

    await user.click(screen.getByRole("button", { name: "Generate report" }));

    await waitFor(() => {
      expect(screen.getByText("2026-11-01 to 2026-11-30")).toBeInTheDocument();
    });
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/reconciliation/reports",
      expect.objectContaining({ method: "POST" }),
    );
  });
});
