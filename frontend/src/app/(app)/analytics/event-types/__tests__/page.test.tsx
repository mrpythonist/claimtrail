import { render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { AnalyticsEventTypeRow, Organization } from "@/lib/types";
import EventTypePerformancePage from "../page";

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

const ROWS: AnalyticsEventTypeRow[] = [
  {
    category: "detention",
    category_label: "Detention",
    claims: 2,
    submitted: 1,
    paid: 1,
    rejected: 0,
    requested_amount: "200.00",
    approved_amount: "200.00",
    paid_amount: "200.00",
    outstanding_amount: "0.00",
    currencies: ["USD"],
    recovery_rate_vs_requested: 100,
    rejection_rate: 0,
    average_payment_days: { days: 5, sample_size: 1 },
  },
  {
    category: "tonu",
    category_label: "Truck Ordered Not Used (TONU)",
    claims: 0,
    submitted: 0,
    paid: 0,
    rejected: 0,
    requested_amount: "0.00",
    approved_amount: "0.00",
    paid_amount: "0.00",
    outstanding_amount: "0.00",
    currencies: [],
    recovery_rate_vs_requested: null,
    rejection_rate: null,
    average_payment_days: { days: null, sample_size: 0 },
  },
];

beforeEach(() => {
  mockedApiFetch.mockReset();
  mockedUseAuth.mockReturnValue({ currentOrg: ORG } as ReturnType<
    typeof useAuth
  >);
});

describe("EventTypePerformancePage", () => {
  it("shows event-type rows including zero rows", async () => {
    mockedApiFetch.mockResolvedValue({ data: ROWS });
    render(<EventTypePerformancePage />);

    await waitFor(() => {
      expect(screen.getByText("Detention")).toBeInTheDocument();
      expect(
        screen.getByText("Truck Ordered Not Used (TONU)"),
      ).toBeInTheDocument();
    });

    expect(screen.getByText("100%")).toBeInTheDocument();
    expect(screen.getByText("Export event types CSV")).toBeInTheDocument();
  });

  it("shows an empty state when there are no claims", async () => {
    mockedApiFetch.mockResolvedValue({ data: [] });
    render(<EventTypePerformancePage />);

    await waitFor(() => {
      expect(screen.getByText("No claims in this range")).toBeInTheDocument();
    });
  });
});
