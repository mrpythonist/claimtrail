import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import {
  analyticsRangeQuery,
  exportAnalytics,
  formatAnalyticsAverage,
  formatAnalyticsRate,
  getAnalyticsOverview,
  getMonthlySeries,
  getYearlySeries,
  listBrokerPerformance,
  listCarrierPerformance,
  listEventTypePerformance,
} from "../claim-analytics";
import type {
  AnalyticsBrokerRow,
  AnalyticsMonthlyPoint,
  AnalyticsOverview,
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
  return { apiFetch: vi.fn(), apiDownload: vi.fn(), ApiRequestError };
});

vi.mock("@/lib/shipments", () => ({ downloadBlob: vi.fn() }));

const mockedApiFetch = vi.mocked(apiFetch);

beforeEach(() => {
  mockedApiFetch.mockReset();
});

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

describe("formatAnalyticsRate", () => {
  it("formats a rate as a percent and a null rate as an em dash", () => {
    expect(formatAnalyticsRate(62.5)).toBe("62.5%");
    expect(formatAnalyticsRate(null)).toBe("—");
  });
});

describe("formatAnalyticsAverage", () => {
  it("shows days with the sample size and an em dash when empty", () => {
    expect(
      formatAnalyticsAverage({ days: 1.67, sample_size: 3 }),
    ).toBe("1.67 days (n=3)");
    expect(formatAnalyticsAverage({ days: null, sample_size: 0 })).toBe("—");
  });
});

describe("analyticsRangeQuery", () => {
  it("builds an empty query when no dates are given", () => {
    expect(analyticsRangeQuery()).toBe("");
    expect(analyticsRangeQuery({})).toBe("");
  });

  it("includes only the dates that are set", () => {
    expect(analyticsRangeQuery({ from: "2026-01-01" })).toBe(
      "?from=2026-01-01",
    );
    expect(
      analyticsRangeQuery({ from: "2026-01-01", to: "2026-01-31" }),
    ).toBe("?from=2026-01-01&to=2026-01-31");
  });
});

describe("analytics client", () => {
  it("fetches the overview with the range query", async () => {
    mockedApiFetch.mockResolvedValue({ data: OVERVIEW });
    const result = await getAnalyticsOverview({
      from: "2026-01-01",
      to: "2026-01-31",
    });
    expect(result).toEqual(OVERVIEW);
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/analytics/overview?from=2026-01-01&to=2026-01-31",
    );
  });

  it("fetches broker, carrier, and event-type tables", async () => {
    const brokerRow: AnalyticsBrokerRow = {
      broker: "Alpha Brokers",
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
    };
    mockedApiFetch.mockResolvedValue({ data: [brokerRow] });
    expect(await listBrokerPerformance()).toEqual([brokerRow]);
    expect(mockedApiFetch).toHaveBeenCalledWith("/analytics/brokers");

    mockedApiFetch.mockResolvedValue({ data: [] });
    expect(await listCarrierPerformance()).toEqual([]);
    expect(mockedApiFetch).toHaveBeenCalledWith("/analytics/carriers");

    mockedApiFetch.mockResolvedValue({ data: [] });
    expect(await listEventTypePerformance()).toEqual([]);
    expect(mockedApiFetch).toHaveBeenCalledWith("/analytics/event-types");
  });

  it("fetches monthly and yearly series", async () => {
    const monthly: AnalyticsMonthlyPoint[] = [
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
    mockedApiFetch.mockResolvedValue({ data: monthly });
    expect(await getMonthlySeries()).toEqual(monthly);
    expect(mockedApiFetch).toHaveBeenCalledWith("/analytics/monthly");

    mockedApiFetch.mockResolvedValue({ data: [] });
    expect(await getYearlySeries()).toEqual([]);
    expect(mockedApiFetch).toHaveBeenCalledWith("/analytics/yearly");
  });

  it("exports a CSV through the download helper", async () => {
    const { apiDownload } = await import("@/lib/api");
    const { downloadBlob } = await import("@/lib/shipments");
    const mockedDownload = vi.mocked(apiDownload);
    const mockedBlob = vi.mocked(downloadBlob);
    const blob = new Blob(["a,b"], { type: "text/csv" });
    mockedDownload.mockResolvedValue(blob);

    await exportAnalytics("brokers", { orgId: "org-1" });

    expect(mockedDownload).toHaveBeenCalledWith("/analytics/export?type=brokers", {
      orgId: "org-1",
    });
    expect(mockedBlob).toHaveBeenCalledWith(
      blob,
      expect.stringMatching(/^claimtrail-analytics-brokers-\d{4}-\d{2}-\d{2}\.csv$/),
    );
  });
});
