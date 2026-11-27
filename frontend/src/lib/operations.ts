import { apiFetch } from "./api";
import { analyticsRangeQuery } from "./claim-analytics";
import type {
  OperationsBrokerTrendRow,
  OperationsDetentionTrends,
  OperationsFacilityRow,
  OperationsMissingEvidence,
  OperationsRecommendations,
  OperationsRejectionReasonRow,
  OperationsRevenueLeakage,
} from "./types";

/**
 * Typed client for the operational intelligence endpoints
 * (v1.5.2): detention trends, facility performance,
 * rejection reasons, missing-evidence trends, broker-level
 * trends, revenue leakage, and operational recommendations.
 *
 * Every number is computed from the organization's own
 * recorded claims, shipments, payments, and evidence: there
 * is no external benchmark and no industry data. Money is
 * summed as recorded, with no currency conversion, and every
 * average carries its sample size. Rates are null when their
 * denominator was zero. Reading needs view-analytics; the
 * Free plan is refused with a 402, the same as analytics.
 * The UI gates on canViewAnalytics and still handles 403
 * and 402 responses gracefully.
 */

export async function getDetentionTrends(query?: {
  from?: string;
  to?: string;
}): Promise<OperationsDetentionTrends> {
  const res = await apiFetch<{ data: OperationsDetentionTrends }>(
    `/operations/detention-trends${analyticsRangeQuery(query)}`,
  );
  return res.data;
}

export async function listFacilityPerformance(query?: {
  from?: string;
  to?: string;
}): Promise<OperationsFacilityRow[]> {
  const res = await apiFetch<{ data: OperationsFacilityRow[] }>(
    `/operations/facilities${analyticsRangeQuery(query)}`,
  );
  return res.data;
}

export async function listRejectionReasons(query?: {
  from?: string;
  to?: string;
}): Promise<OperationsRejectionReasonRow[]> {
  const res = await apiFetch<{ data: OperationsRejectionReasonRow[] }>(
    `/operations/rejection-reasons${analyticsRangeQuery(query)}`,
  );
  return res.data;
}

export async function getMissingEvidence(query?: {
  from?: string;
  to?: string;
}): Promise<OperationsMissingEvidence> {
  const res = await apiFetch<{ data: OperationsMissingEvidence }>(
    `/operations/missing-evidence${analyticsRangeQuery(query)}`,
  );
  return res.data;
}

export async function listBrokerTrends(query?: {
  from?: string;
  to?: string;
}): Promise<OperationsBrokerTrendRow[]> {
  const res = await apiFetch<{ data: OperationsBrokerTrendRow[] }>(
    `/operations/broker-trends${analyticsRangeQuery(query)}`,
  );
  return res.data;
}

export async function getRevenueLeakage(query?: {
  from?: string;
  to?: string;
}): Promise<OperationsRevenueLeakage> {
  const res = await apiFetch<{ data: OperationsRevenueLeakage }>(
    `/operations/revenue-leakage${analyticsRangeQuery(query)}`,
  );
  return res.data;
}

export async function getRecommendations(query?: {
  from?: string;
  to?: string;
}): Promise<OperationsRecommendations> {
  const res = await apiFetch<{ data: OperationsRecommendations }>(
    `/operations/recommendations${analyticsRangeQuery(query)}`,
  );
  return res.data;
}
