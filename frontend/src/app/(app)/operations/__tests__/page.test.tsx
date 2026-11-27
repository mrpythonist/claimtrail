import { render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { useAuth } from "@/lib/auth";
import {
  getDetentionTrends,
  getMissingEvidence,
  getRecommendations,
  getRevenueLeakage,
  listBrokerTrends,
  listFacilityPerformance,
  listRejectionReasons,
} from "@/lib/operations";
import type {
  OperationsBrokerTrendRow,
  OperationsDetentionTrends,
  OperationsFacilityRow,
  OperationsMissingEvidence,
  OperationsRecommendations,
  OperationsRejectionReasonRow,
  OperationsRevenueLeakage,
  Organization,
} from "@/lib/types";
import OperationsPage from "../page";

vi.mock("@/lib/operations", () => ({
  getDetentionTrends: vi.fn(),
  listFacilityPerformance: vi.fn(),
  listRejectionReasons: vi.fn(),
  getMissingEvidence: vi.fn(),
  listBrokerTrends: vi.fn(),
  getRevenueLeakage: vi.fn(),
  getRecommendations: vi.fn(),
}));

vi.mock("@/lib/auth", () => ({ useAuth: vi.fn() }));

const mockedUseAuth = vi.mocked(useAuth);
const mockedDetention = vi.mocked(getDetentionTrends);
const mockedFacilities = vi.mocked(listFacilityPerformance);
const mockedReasons = vi.mocked(listRejectionReasons);
const mockedMissing = vi.mocked(getMissingEvidence);
const mockedBrokerTrends = vi.mocked(listBrokerTrends);
const mockedLeakage = vi.mocked(getRevenueLeakage);
const mockedRecommendations = vi.mocked(getRecommendations);

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

const DETENTION: OperationsDetentionTrends = {
  organization_id: "org-1",
  from: "2026-01-01",
  to: "2026-01-31",
  currencies: ["USD"],
  counts: { claims: 4, submitted: 3, paid: 1, rejected: 1, accepted: 1 },
  rates: {
    recovery_rate_vs_requested: 50,
    recovery_rate_vs_approved: 70,
    rejection_rate: 33.33,
  },
  money: {
    requested_amount: "700.00",
    approved_amount: "500.00",
    paid_amount: "350.00",
    outstanding_amount: "150.00",
  },
  money_by_currency: [
    {
      currency: "USD",
      requested_amount: "700.00",
      approved_amount: "500.00",
      paid_amount: "350.00",
      outstanding_amount: "150.00",
    },
  ],
  average_submission_days: { days: 1.33, sample_size: 3 },
  average_payment_days: { days: 5, sample_size: 1 },
  monthly: [
    {
      month: "2026-01",
      claims_created: 4,
      submitted: 3,
      paid: 1,
      rejected: 1,
      requested_amount: "700.00",
      approved_amount: "500.00",
      paid_amount: "350.00",
      recovery_rate_vs_requested: 50,
    },
  ],
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
      requested_amount: "700.00",
      approved_amount: "500.00",
      paid_amount: "350.00",
      outstanding_amount: "150.00",
      recovery_rate_vs_requested: 50,
      recovery_rate_vs_approved: 70,
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
      requested_amount: "600.00",
      paid_amount: "250.00",
      recovery_rate_vs_requested_pp: -50,
      rejection_rate_pp: 33.33,
    },
  },
};

const FACILITIES: OperationsFacilityRow[] = [
  {
    facility: "Dallas DC",
    claims: 3,
    submitted: 2,
    paid: 1,
    rejected: 1,
    requested_amount: "500.00",
    approved_amount: "300.00",
    paid_amount: "300.00",
    outstanding_amount: "0.00",
    currencies: ["USD"],
    recovery_rate_vs_requested: 60,
    rejection_rate: 50,
    average_payment_days: { days: 5, sample_size: 1 },
  },
];

const REASONS: OperationsRejectionReasonRow[] = [
  {
    reason: "Insufficient evidence",
    rejected: 2,
    share_of_rejected: 66.67,
    requested_amount: "200.00",
    currencies: ["USD"],
  },
];

const MISSING: OperationsMissingEvidence = {
  organization_id: "org-1",
  from: "2026-01-01",
  to: "2026-01-31",
  claims: 6,
  claims_with_requirements: 6,
  claims_incomplete: 4,
  average_readiness_percentage: { percentage: 50, sample_size: 6 },
  by_category: [
    {
      category: "bill_of_lading",
      category_label: "Bill of Lading",
      claims_missing: 3,
      share_of_incomplete: 75,
    },
  ],
  monthly: [
    {
      month: "2026-01",
      claims_created: 6,
      claims_with_requirements: 6,
      incomplete: 4,
      average_readiness_percentage: { percentage: 50, sample_size: 6 },
    },
  ],
};

const BROKER_TRENDS: OperationsBrokerTrendRow[] = [
  {
    broker: "Beta Freight",
    claims: 3,
    submitted: 3,
    paid: 0,
    rejected: 2,
    requested_amount: "350.00",
    approved_amount: "200.00",
    paid_amount: "50.00",
    outstanding_amount: "150.00",
    currencies: ["USD"],
    recovery_rate_vs_requested: 14.29,
    rejection_rate: 66.67,
    average_payment_days: { days: null, sample_size: 0 },
    monthly: [
      {
        month: "2026-01",
        claims_created: 3,
        submitted: 3,
        paid: 0,
        rejected: 2,
        requested_amount: "350.00",
        approved_amount: "200.00",
        paid_amount: "50.00",
        recovery_rate_vs_requested: 14.29,
      },
    ],
    change: {
      claims: 3,
      submitted: 3,
      paid: 0,
      rejected: 2,
      requested_amount: "350.00",
      paid_amount: "50.00",
      recovery_rate_vs_requested_pp: null,
      rejection_rate_pp: null,
    },
  },
];

const LEAKAGE: OperationsRevenueLeakage = {
  organization_id: "org-1",
  from: "2026-01-01",
  to: "2026-01-31",
  currencies: ["USD"],
  submitted_claims: 5,
  requested_total: "850.00",
  approved_total: "500.00",
  paid_total: "350.00",
  outstanding_total: "400.00",
  requested_vs_approved_gap: "100.00",
  claims_cut_at_approval: 1,
  overpaid_total: "0.00",
  legacy_total: "0.00",
  unsubmitted_claims: 1,
  unsubmitted_requested_total: "500.00",
  by_balance_status: [
    {
      balance_status: "unpaid",
      claims: 3,
      requested_amount: "250.00",
      approved_amount: "0.00",
      paid_amount: "0.00",
      outstanding_amount: "250.00",
    },
  ],
  money_by_currency: [
    {
      currency: "USD",
      requested_amount: "850.00",
      approved_amount: "500.00",
      paid_amount: "350.00",
      outstanding_amount: "400.00",
      requested_vs_approved_gap: "100.00",
    },
  ],
  largest_outstanding: [
    {
      claim_id: "claim-1",
      claim_reference: "CLM-TEST-1",
      broker: "Beta Freight",
      requested_amount: "200.00",
      approved_amount: "200.00",
      paid_amount: "50.00",
      outstanding_amount: "150.00",
    },
  ],
};

const RECOMMENDATIONS: OperationsRecommendations = {
  organization_id: "org-1",
  from: "2026-01-01",
  to: "2026-01-31",
  generated_from: "Recorded claims, shipments, payments, and evidence only.",
  recommendations: [
    {
      type: "highest_rejection_broker",
      title: "Review submissions to Beta Freight",
      detail: "Beta Freight rejected 2 of 3 submitted claims in this period.",
      severity: "watch",
      evidence: { broker: "Beta Freight", rejected: 2, submitted: 3, rejection_rate: 66.67 },
    },
  ],
};

function mockLoad(
  detention: OperationsDetentionTrends | null = DETENTION,
  facilities: OperationsFacilityRow[] = FACILITIES,
  reasons: OperationsRejectionReasonRow[] = REASONS,
  missing: OperationsMissingEvidence | null = MISSING,
  brokerTrends: OperationsBrokerTrendRow[] = BROKER_TRENDS,
  leakage: OperationsRevenueLeakage | null = LEAKAGE,
  recommendations: OperationsRecommendations | null = RECOMMENDATIONS,
) {
  mockedDetention.mockResolvedValue(detention as OperationsDetentionTrends);
  mockedFacilities.mockResolvedValue(facilities);
  mockedReasons.mockResolvedValue(reasons);
  mockedMissing.mockResolvedValue(missing as OperationsMissingEvidence);
  mockedBrokerTrends.mockResolvedValue(brokerTrends);
  mockedLeakage.mockResolvedValue(leakage as OperationsRevenueLeakage);
  mockedRecommendations.mockResolvedValue(
    recommendations as OperationsRecommendations,
  );
}

beforeEach(() => {
  vi.mocked(mockedDetention).mockReset();
  vi.mocked(mockedFacilities).mockReset();
  vi.mocked(mockedReasons).mockReset();
  vi.mocked(mockedMissing).mockReset();
  vi.mocked(mockedBrokerTrends).mockReset();
  vi.mocked(mockedLeakage).mockReset();
  vi.mocked(mockedRecommendations).mockReset();
  mockedUseAuth.mockReturnValue({ currentOrg: ORG } as ReturnType<
    typeof useAuth
  >);
});

describe("OperationsPage", () => {
  it("shows detention trends, facilities, reasons, missing evidence, broker trends, leakage, and recommendations", async () => {
    mockLoad();
    render(<OperationsPage />);

    await waitFor(() => {
      expect(screen.getByText("Detention trends")).toBeInTheDocument();
      expect(screen.getByText("Review submissions to Beta Freight")).toBeInTheDocument();
    });

    expect(screen.getByText("Dallas DC")).toBeInTheDocument();
    expect(screen.getByText("Insufficient evidence")).toBeInTheDocument();
    expect(screen.getByText("Bill of Lading")).toBeInTheDocument();
    expect(screen.getAllByText("Beta Freight").length).toBe(2);
    expect(screen.getByText("CLM-TEST-1")).toBeInTheDocument();
    expect(screen.getAllByText("50%").length).toBeGreaterThanOrEqual(2);
    expect(screen.getByText(/1\.33 days \(n=3\)/)).toBeInTheDocument();
    expect(screen.getByText(/\+3 claims/)).toBeInTheDocument();
  });

  it("shows empty states when nothing exists", async () => {
    mockLoad(
      {
        ...DETENTION,
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
        monthly: [],
        comparison: {
          ...DETENTION.comparison,
          current: {
            claims: 0,
            submitted: 0,
            paid: 0,
            rejected: 0,
            requested_amount: "0.00",
            approved_amount: "0.00",
            paid_amount: "0.00",
            outstanding_amount: "0.00",
            recovery_rate_vs_requested: null,
            recovery_rate_vs_approved: null,
            rejection_rate: null,
          },
          previous: {
            claims: 0,
            submitted: 0,
            paid: 0,
            rejected: 0,
            requested_amount: "0.00",
            approved_amount: "0.00",
            paid_amount: "0.00",
            outstanding_amount: "0.00",
            recovery_rate_vs_requested: null,
            recovery_rate_vs_approved: null,
            rejection_rate: null,
          },
          change: {
            claims: 0,
            submitted: 0,
            paid: 0,
            rejected: 0,
            requested_amount: "0.00",
            paid_amount: "0.00",
            recovery_rate_vs_requested_pp: null,
            rejection_rate_pp: null,
          },
        },
      },
      [],
      [],
      {
        ...MISSING,
        claims: 0,
        claims_with_requirements: 0,
        claims_incomplete: 0,
        average_readiness_percentage: { percentage: null, sample_size: 0 },
        by_category: [],
        monthly: [],
      },
      [],
      {
        ...LEAKAGE,
        submitted_claims: 0,
        requested_total: "0.00",
        approved_total: "0.00",
        paid_total: "0.00",
        outstanding_total: "0.00",
        requested_vs_approved_gap: "0.00",
        claims_cut_at_approval: 0,
        overpaid_total: "0.00",
        legacy_total: "0.00",
        unsubmitted_claims: 0,
        unsubmitted_requested_total: "0.00",
        by_balance_status: [],
        money_by_currency: [],
        largest_outstanding: [],
      },
      { ...RECOMMENDATIONS, recommendations: [] },
    );
    render(<OperationsPage />);

    await waitFor(() => {
      expect(screen.getByText("Nothing to flag in this range")).toBeInTheDocument();
      expect(screen.getByText("No rejections in this range")).toBeInTheDocument();
      expect(screen.getByText("No detention claims in this range")).toBeInTheDocument();
    });
  });
});
