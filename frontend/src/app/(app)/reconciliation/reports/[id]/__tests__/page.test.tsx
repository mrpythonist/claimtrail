import { render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { Organization, ReconciliationReport } from "@/lib/types";
import ReconciliationReportPage from "../page";

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

vi.mock("next/navigation", () => ({
  useParams: () => ({ id: "rep-1" }),
}));

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
    discrepancies_count: 1,
  },
  discrepancies: [
    {
      kind: "legacy_payment",
      claim_id: "claim-1",
      claim_reference: "CLM-2026-0001",
      detail: "USD 250.00 was recorded before the payment ledger existed and has no ledger row.",
    },
  ],
  generated_by: "user-1",
  generated_at: "2026-11-27T10:00:00Z",
  created_at: "2026-11-27T10:00:00Z",
};

beforeEach(() => {
  mockedApiFetch.mockReset();
  mockedUseAuth.mockReturnValue({ currentOrg: ORG } as ReturnType<typeof useAuth>);
});

describe("ReconciliationReportPage", () => {
  it("shows the period totals and the flagged discrepancies", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: REPORT });
    render(<ReconciliationReportPage />);

    await waitFor(() => {
      expect(screen.getByText("2026-11-01 to 2026-11-30")).toBeInTheDocument();
      expect(screen.getByText("CLM-2026-0001", { exact: false })).toBeInTheDocument();
    });

    expect(screen.getByText("1 flagged")).toBeInTheDocument();
    expect(screen.getByText("legacy payment", { exact: false })).toBeInTheDocument();
  });

  it("shows a clean state when there are no discrepancies", async () => {
    mockedApiFetch.mockResolvedValueOnce({
      data: { ...REPORT, totals: { ...REPORT.totals, discrepancies_count: 0 }, discrepancies: [] },
    });
    render(<ReconciliationReportPage />);

    await waitFor(() => {
      expect(screen.getByText("No discrepancies")).toBeInTheDocument();
    });
  });
});
