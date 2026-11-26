import { apiFetch } from "./api";
import type {
  FailedJob,
  MonitoringCheck,
  OpsHealth,
  PaginatedMeta,
  QueueStats,
  RecentErrors,
} from "./types";

/**
 * Typed client for the operations endpoints (GET /ops/*). Every call
 * is tenant-scoped: apiFetch attaches the stored organization id,
 * and each function here takes the org id explicitly so pages pass
 * the current organization rather than relying on stored state.
 * The backend requires the view-operations permission (Owner and
 * Admin) for all of these; the UI gates on canViewOperations and
 * still handles 403 responses gracefully.
 */

export async function getOpsHealth(orgId: string): Promise<OpsHealth> {
  const response = await apiFetch<{ data: OpsHealth }>("/ops/health", {
    orgId,
  });
  return response.data;
}

export async function getQueueStats(orgId: string): Promise<QueueStats> {
  const response = await apiFetch<{ data: QueueStats }>("/ops/queue", {
    orgId,
  });
  return response.data;
}

export async function getFailedJobs(
  orgId: string,
  page = 1,
  perPage = 15,
): Promise<{ data: FailedJob[]; meta: PaginatedMeta }> {
  const params = new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
  });
  return apiFetch<{ data: FailedJob[]; meta: PaginatedMeta }>(
    `/ops/failed-jobs?${params.toString()}`,
    { orgId },
  );
}

export async function retryFailedJob(
  orgId: string,
  uuid: string,
): Promise<{ message: string }> {
  return apiFetch<{ message: string }>(
    `/ops/failed-jobs/${encodeURIComponent(uuid)}/retry`,
    { method: "POST", orgId },
  );
}

export async function forgetFailedJob(
  orgId: string,
  uuid: string,
): Promise<{ message: string }> {
  return apiFetch<{ message: string }>(
    `/ops/failed-jobs/${encodeURIComponent(uuid)}/forget`,
    { method: "POST", orgId },
  );
}

export async function retryAllFailedJobs(
  orgId: string,
): Promise<{ retried: number; failed: string[] }> {
  const response = await apiFetch<{
    data: { retried: number; failed: string[] };
  }>("/ops/failed-jobs/retry-all", { method: "POST", orgId });
  return response.data;
}

export async function flushFailedJobs(
  orgId: string,
  hours = 168,
): Promise<{ deleted: number }> {
  const response = await apiFetch<{ data: { deleted: number } }>(
    "/ops/failed-jobs/flush",
    { method: "POST", orgId, body: { hours } },
  );
  return response.data;
}

export async function getRecentErrors(
  orgId: string,
  limit = 50,
): Promise<RecentErrors> {
  const params = new URLSearchParams({ limit: String(limit) });
  const response = await apiFetch<{ data: RecentErrors }>(
    `/ops/errors?${params.toString()}`,
    { orgId },
  );
  return response.data;
}

/**
 * Production monitoring (v1.2.3): the latest hourly check run and
 * the recent history. A failing check writes a warning to the log;
 * there is no alerting channel yet, so this card is where a
 * failure is seen.
 */
export async function getMonitoring(orgId: string): Promise<{
  latest: MonitoringCheck | null;
  history: MonitoringCheck[];
}> {
  const response = await apiFetch<{
    data: { latest: MonitoringCheck | null; history: MonitoringCheck[] };
  }>("/ops/monitoring", { orgId });
  return response.data;
}

/** Run the monitoring check now and return the result. */
export async function runMonitoringCheck(orgId: string): Promise<MonitoringCheck> {
  const response = await apiFetch<{ data: MonitoringCheck }>(
    "/ops/monitoring/check",
    { method: "POST", orgId },
  );
  return response.data;
}
