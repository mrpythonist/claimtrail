import { render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type {
  AnalyticsMonthlyPoint,
  AnalyticsOverview,
  AnalyticsYearlyPoint,
  Organization,
} from "@/lib/types";
import AnalyticsPage from "../page";

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

const OVERVIEW: AnalyticsOverview = {
  organization_id: "org-1",
  from: "2026-01-01",
  to: "2026-01-31",
  currencies: ["USD"],
  counts: { claims: 4, submitted: 3, paid: 1, rejected: 1, accepted: 1 },
  rates: {
    recovery_rate_vs_requested: 62.5,
    recovery_rate_vs_approved: 83.33,
    rejection_rate: 33.33,
  },
  money: {
    requested_amount: "400.00",
    approved_amount: "300.00",
    paid_amount: "250.00",
    outstanding_amount: "50.00",
  },
  money_by_currency: [
    {
      currency: "USD",
      requested_amount: "400.00",
      approved_amount: "300.00",
      paid_amount: "250.00",
      outstanding_amount: "50.00",
    },
  ],
  average_submission_days: { days: 1.67, sample_size: 3 },
  average_payment_days: { days: 5, sample_size: 1 },
  comparison: {
    from: "2026-01-01",
    to: "2026-01-31",
    previous_from: "2025-12-01",
    previous_to: "2025-12-31",
    current: {
      claims: 4,
      submitted: 3,
      paid: 1,
      rejected: 1,
      requested_amount: "400.00",
      approved_amount: "300.00",
      paid_amount: "250.00",
      outstanding_amount: "50.00",
      recovery_rate_vs_requested: 62.5,
      recovery_rate_vs_approved: 83.33,
      rejection_rate: 33.33,
    },
    previous: {
      claims: 1,
      submitted: 1,
      paid: 1,
      rejected: 0,
      requested_amount: "100.00",
      approved_amount: "100.00",
      paid_amount: "100.00",
      outstanding_amount: "0.00",
      recovery_rate_vs_requested: 100,
      recovery_rate_vs_approved: 100,
      rejection_rate: 0,
    },
    change: {
      claims: 3,
      submitted: 2,
      paid: 0,
      rejected: 1,
      requested_amount: "300.00",
      paid_amount: "150.00",
      recovery_rate_vs_requested_pp: -37.5,
      rejection_rate_pp: 33.33,
    },
  },
};

const MONTHLY: AnalyticsMonthlyPoint[] = [
  {
    month: "2026-01",
    claims_created: 4,
    submitted: 3,
    paid: 1,
    rejected: 1,
    requested_amount: "400.00",
    approved_amount: "300.00",
    paid_amount: "250.00",
    recovery_rate_vs_requested: 62.5,
  },
];

const YEARLY: AnalyticsYearlyPoint[] = [
  {
    year: "2026",
    claims_created: 4,
    submitted: 3,
    paid: 1,
    rejected: 1,
    requested_amount: "400.00",
    approved_amount: "300.00",
    paid_amount: "250.00",
    recovery_rate_vs_requested: 62.5,
  },
];

function mockLoad(
  overview: AnalyticsOverview | null = OVERVIEW,
  monthly: AnalyticsMonthlyPoint[] = MONTHLY,
  yearly: AnalyticsYearlyPoint[] = YEARLY,
) {
  mockedApiFetch.mockImplementation((url: string) => {
    if (url.startsWith("/analytics/overview")) {
      return Promise.resolve({ data: overview });
    }
    if (url.startsWith("/analytics/monthly")) {
      return Promise.resolve({ data: monthly });
    }
    if (url.startsWith("/analytics/yearly")) {
      return Promise.resolve({ data: yearly });
    }
    return Promise.reject(new Error(`Unexpected URL ${url}`));
  });
}

beforeEach(() => {
  mockedApiFetch.mockReset();
  mockedUseAuth.mockReturnValue({ currentOrg: ORG } as ReturnType<
    typeof useAuth
  >);
});

describe("AnalyticsPage", () => {
  it("shows rates, averages, monthly and yearly comparisons", async () => {
    mockLoad();
    render(<AnalyticsPage />);

    await waitFor(() => {
      expect(screen.getByText("Recovery rate (vs requested)")).toBeInTheDocument();
      expect(screen.getAllByText("Jan 2026").length).toBeGreaterThan(0);
    });

    expect(screen.getAllByText("62.5%").length).toBeGreaterThan(0);
    expect(screen.getByText("33.33%")).toBeInTheDocument();
    expect(screen.getByText(/1\.67 days \(n=3\)/)).toBeInTheDocument();
    expect(screen.getByText(/5 days \(n=1\)/)).toBeInTheDocument();
    expect(screen.getByText(/\+3 claims/)).toBeInTheDocument();
    expect(screen.getByText("Export overview CSV")).toBeInTheDocument();
    expect(screen.getByText("Broker performance")).toBeInTheDocument();
  });

  it("shows empty states when nothing exists", async () => {
    mockLoad(
      {
        ...OVERVIEW,
        counts: { claims: 0, submitted: 0, paid: 0, rejected: 0, accepted: 0 },
        rates: {
          recovery_rate_vs_requested: null,
          recovery_rate_vs_approved: null,
          rejection_rate: null,
        },
        money: {
          requested_amount: "0.00",
          approved_amount: "0.00",
          paid_amount: "0.00",
          outstanding_amount: "0.00",
        },
        money_by_currency: [],
        average_submission_days: { days: null, sample_size: 0 },
        average_payment_days: { days: null, sample_size: 0 },
      },
      [],
      [],
    );
    render(<AnalyticsPage />);

    await waitFor(() => {
      expect(
        screen.getAllByText("No claims in this range").length,
      ).toBeGreaterThan(0);
    });
  });
});
