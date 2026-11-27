import { render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { AnalyticsCarrierRow, Organization } from "@/lib/types";
import CarrierPerformancePage from "../page";

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
  return { apiFetch: vi.fn(), apiDownload: vi.fn(), ApiRequestError };
});

vi.mock("@/lib/shipments", () => ({ downloadBlob: vi.fn() }));

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

const ROWS: AnalyticsCarrierRow[] = [
  {
    carrier_id: "carrier-1",
    carrier: "Acme Carriers",
    claims: 2,
    submitted: 1,
    paid: 0,
    rejected: 1,
    requested_amount: "100.00",
    approved_amount: "0.00",
    paid_amount: "0.00",
    outstanding_amount: "100.00",
    currencies: ["USD"],
    recovery_rate_vs_requested: 0,
    rejection_rate: 100,
    average_payment_days: { days: null, sample_size: 0 },
  },
  {
    carrier_id: null,
    carrier: "No carrier assigned",
    claims: 2,
    submitted: 2,
    paid: 1,
    rejected: 0,
    requested_amount: "300.00",
    approved_amount: "300.00",
    paid_amount: "250.00",
    outstanding_amount: "50.00",
    currencies: ["USD"],
    recovery_rate_vs_requested: 83.33,
    rejection_rate: 0,
    average_payment_days: { days: 5, sample_size: 1 },
  },
];

beforeEach(() => {
  mockedApiFetch.mockReset();
  mockedUseAuth.mockReturnValue({ currentOrg: ORG } as ReturnType<
    typeof useAuth
  >);
});

describe("CarrierPerformancePage", () => {
  it("shows carrier rows including the unassigned group", async () => {
    mockedApiFetch.mockResolvedValue({ data: ROWS });
    render(<CarrierPerformancePage />);

    await waitFor(() => {
      expect(screen.getByText("Acme Carriers")).toBeInTheDocument();
      expect(screen.getByText("No carrier assigned")).toBeInTheDocument();
    });

    expect(screen.getByText("83.33%")).toBeInTheDocument();
    expect(screen.getByText("Export carriers CSV")).toBeInTheDocument();
  });

  it("shows an empty state when there are no carriers", async () => {
    mockedApiFetch.mockResolvedValue({ data: [] });
    render(<CarrierPerformancePage />);

    await waitFor(() => {
      expect(screen.getByText("No carriers in this range")).toBeInTheDocument();
    });
  });
});
