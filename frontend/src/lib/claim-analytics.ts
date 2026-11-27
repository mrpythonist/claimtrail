import { apiDownload, apiFetch } from "./api";
import { downloadBlob } from "./shipments";
import type {
  AnalyticsBrokerRow,
  AnalyticsCarrierRow,
  AnalyticsEventTypeRow,
  AnalyticsExportType,
  AnalyticsMonthlyPoint,
  AnalyticsOverview,
  AnalyticsYearlyPoint,
} from "./types";

/**
 * Typed client for the claim analytics endpoints (v1.5.1).
 *
 * (Not the marketing-site tracker in analytics.ts.) Every
 * metric is computed from the organization's own recorded
 * claims, shipments, and payments: recovery and rejection
 * rates, average submission and payment times, broker, carrier,
 * and event-type performance, and monthly/yearly comparisons.
 * Money is summed as recorded, with no currency conversion,
 * and every average carries its sample size. Rates are null
 * when their denominator is zero. Reading analytics needs
 * view-analytics; exporting a CSV needs export-reports, and
 * the Free plan is refused with a 402, the same as reports.
 * The UI gates on canViewAnalytics / canExportReports and
 * still handles 403 and 402 responses gracefully.
 */

export const ANALYTICS_EXPORT_TYPES: {
  value: AnalyticsExportType;
  label: string;
}[] = [
  { value: "overview", label: "Overview" },
  { value: "brokers", label: "Brokers" },
  { value: "carriers", label: "Carriers" },
  { value: "event-types", label: "Event types" },
  { value: "monthly", label: "Monthly series" },
];

export function analyticsRangeQuery(query?: {
  from?: string;
  to?: string;
}): string {
  const params = new URLSearchParams();
  if (query?.from) params.set("from", query.from);
  if (query?.to) params.set("to", query.to);
  return params.size > 0 ? `?${params.toString()}` : "";
}

/** A rate as a percent, or an em dash when there was nothing to measure. */
export function formatAnalyticsRate(rate: number | null): string {
  if (rate === null || rate === undefined) return "—";
  return `${rate}%`;
}

/** An average in days with its sample size, or an em dash when empty. */
export function formatAnalyticsAverage(average: {
  days: number | null;
  sample_size: number;
}): string {
  if (average.days === null || average.days === undefined) return "—";
  return `${average.days} days (n=${average.sample_size})`;
}

export async function getAnalyticsOverview(query?: {
  from?: string;
  to?: string;
}): Promise<AnalyticsOverview> {
  const res = await apiFetch<{ data: AnalyticsOverview }>(
    `/analytics/overview${analyticsRangeQuery(query)}`,
  );
  return res.data;
}

export async function listBrokerPerformance(query?: {
  from?: string;
  to?: string;
}): Promise<AnalyticsBrokerRow[]> {
  const res = await apiFetch<{ data: AnalyticsBrokerRow[] }>(
    `/analytics/brokers${analyticsRangeQuery(query)}`,
  );
  return res.data;
}

export async function listCarrierPerformance(query?: {
  from?: string;
  to?: string;
}): Promise<AnalyticsCarrierRow[]> {
  const res = await apiFetch<{ data: AnalyticsCarrierRow[] }>(
    `/analytics/carriers${analyticsRangeQuery(query)}`,
  );
  return res.data;
}

export async function listEventTypePerformance(query?: {
  from?: string;
  to?: string;
}): Promise<AnalyticsEventTypeRow[]> {
  const res = await apiFetch<{ data: AnalyticsEventTypeRow[] }>(
    `/analytics/event-types${analyticsRangeQuery(query)}`,
  );
  return res.data;
}

export async function getMonthlySeries(query?: {
  from?: string;
  to?: string;
}): Promise<AnalyticsMonthlyPoint[]> {
  const res = await apiFetch<{ data: AnalyticsMonthlyPoint[] }>(
    `/analytics/monthly${analyticsRangeQuery(query)}`,
  );
  return res.data;
}

export async function getYearlySeries(query?: {
  from?: string;
  to?: string;
}): Promise<AnalyticsYearlyPoint[]> {
  const res = await apiFetch<{ data: AnalyticsYearlyPoint[] }>(
    `/analytics/yearly${analyticsRangeQuery(query)}`,
  );
  return res.data;
}

export async function exportAnalytics(
  type: AnalyticsExportType,
  query?: { from?: string; to?: string; orgId?: string },
): Promise<void> {
  const params = new URLSearchParams();
  params.set("type", type);
  if (query?.from) params.set("from", query.from);
  if (query?.to) params.set("to", query.to);
  const blob = await apiDownload(`/analytics/export?${params.toString()}`, {
    orgId: query?.orgId,
  });
  const today = new Date().toISOString().slice(0, 10);
  downloadBlob(blob, `claimtrail-analytics-${type}-${today}.csv`);
}
