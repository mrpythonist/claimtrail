import { apiFetch } from "./api";
import type {
  ImportFrequency,
  ImportMapping,
  ImportRow,
  ImportRowStatus,
  ImportRun,
  ImportRunSource,
  ImportRunStatus,
  ImportSchedule,
  PaginatedMeta,
  SyncRun,
  SyncRunStatus,
} from "./types";

/**
 * Typed client for the advanced import endpoints (v1.3.1).
 *
 * Saved CSV mappings, import history with row-level error
 * correction, scheduled imports, and synchronization workflows.
 * Imports target shipments only. Any member may list and read;
 * creating, updating, deleting, running, and correcting need
 * the manage-imports permission (owner, admin, operations
 * manager, dispatcher); the UI gates on canManageImports and
 * still handles 403 responses gracefully.
 */

export const IMPORT_RUN_STATUSES: {
  value: ImportRunStatus;
  label: string;
}[] = [
  { value: "pending", label: "Pending" },
  { value: "running", label: "Running" },
  { value: "completed", label: "Completed" },
  { value: "partial", label: "Partial" },
  { value: "failed", label: "Failed" },
];

export function importRunStatusLabel(status: ImportRunStatus): string {
  return IMPORT_RUN_STATUSES.find((s) => s.value === status)?.label ?? status;
}

export const IMPORT_RUN_SOURCES: {
  value: ImportRunSource;
  label: string;
}[] = [
  { value: "upload", label: "Upload" },
  { value: "schedule", label: "Scheduled" },
  { value: "sync", label: "Sync" },
];

export function importRunSourceLabel(source: ImportRunSource): string {
  return IMPORT_RUN_SOURCES.find((s) => s.value === source)?.label ?? source;
}

export const IMPORT_ROW_STATUSES: {
  value: ImportRowStatus;
  label: string;
}[] = [
  { value: "imported", label: "Imported" },
  { value: "skipped", label: "Skipped" },
  { value: "duplicate", label: "Duplicate" },
  { value: "failed", label: "Failed" },
  { value: "corrected", label: "Corrected" },
];

export function importRowStatusLabel(status: ImportRowStatus): string {
  return IMPORT_ROW_STATUSES.find((s) => s.value === status)?.label ?? status;
}

export const SYNC_RUN_STATUSES: {
  value: SyncRunStatus;
  label: string;
}[] = [
  { value: "pending", label: "Pending" },
  { value: "running", label: "Running" },
  { value: "completed", label: "Completed" },
  { value: "failed", label: "Failed" },
];

export function syncRunStatusLabel(status: SyncRunStatus): string {
  return SYNC_RUN_STATUSES.find((s) => s.value === status)?.label ?? status;
}

export const IMPORT_FREQUENCIES: {
  value: ImportFrequency;
  label: string;
}[] = [
  { value: "daily", label: "Daily" },
  { value: "weekly", label: "Weekly" },
  { value: "manual", label: "Manual" },
];

export function importFrequencyLabel(frequency: ImportFrequency): string {
  return IMPORT_FREQUENCIES.find((f) => f.value === frequency)?.label ?? frequency;
}

export async function listImportMappings(
  orgId: string,
): Promise<ImportMapping[]> {
  const response = await apiFetch<{ data: ImportMapping[] }>(
    "/imports/mappings",
    { orgId },
  );
  return response.data;
}

export async function createImportMapping(
  orgId: string,
  input: {
    name: string;
    mapping: Record<string, string>;
    is_default?: boolean;
  },
): Promise<ImportMapping> {
  const response = await apiFetch<{ data: ImportMapping }>(
    "/imports/mappings",
    { method: "POST", orgId, body: input },
  );
  return response.data;
}

export async function updateImportMapping(
  orgId: string,
  mappingId: string,
  input: {
    name?: string;
    mapping?: Record<string, string>;
    is_default?: boolean;
  },
): Promise<ImportMapping> {
  const response = await apiFetch<{ data: ImportMapping }>(
    `/imports/mappings/${mappingId}`,
    { method: "PUT", orgId, body: input },
  );
  return response.data;
}

export async function deleteImportMapping(
  orgId: string,
  mappingId: string,
): Promise<void> {
  await apiFetch(`/imports/mappings/${mappingId}`, {
    method: "DELETE",
    orgId,
  });
}

export async function listImportRuns(
  orgId: string,
  query: {
    status?: ImportRunStatus | "all";
    source?: ImportRunSource | "all";
    page?: number;
  } = {},
): Promise<{ data: ImportRun[]; meta: PaginatedMeta }> {
  const params = new URLSearchParams();
  if (query.status && query.status !== "all") params.set("status", query.status);
  if (query.source && query.source !== "all") params.set("source", query.source);
  if (query.page) params.set("page", String(query.page));
  const suffix = params.toString() === "" ? "" : `?${params.toString()}`;
  return apiFetch<{ data: ImportRun[]; meta: PaginatedMeta }>(
    `/imports/runs${suffix}`,
    { orgId },
  );
}

export async function getImportRun(
  orgId: string,
  runId: string,
): Promise<ImportRun> {
  const response = await apiFetch<{ data: ImportRun }>(
    `/imports/runs/${runId}`,
    { orgId },
  );
  return response.data;
}

export async function listImportRunRows(
  orgId: string,
  runId: string,
  query: { status?: ImportRowStatus | "all"; page?: number } = {},
): Promise<{ data: ImportRow[]; meta: PaginatedMeta }> {
  const params = new URLSearchParams();
  if (query.status && query.status !== "all") params.set("status", query.status);
  if (query.page) params.set("page", String(query.page));
  const suffix = params.toString() === "" ? "" : `?${params.toString()}`;
  return apiFetch<{ data: ImportRow[]; meta: PaginatedMeta }>(
    `/imports/runs/${runId}/rows${suffix}`,
    { orgId },
  );
}

export async function correctImportRow(
  orgId: string,
  runId: string,
  rowId: string,
  data: Record<string, unknown>,
): Promise<ImportRow> {
  const response = await apiFetch<{ data: ImportRow }>(
    `/imports/runs/${runId}/rows/${rowId}/correct`,
    { method: "POST", orgId, body: { data } },
  );
  return response.data;
}

export async function listImportSchedules(
  orgId: string,
): Promise<ImportSchedule[]> {
  const response = await apiFetch<{ data: ImportSchedule[] }>(
    "/imports/schedules",
    { orgId },
  );
  return response.data;
}

export async function runImportSchedule(
  orgId: string,
  scheduleId: string,
): Promise<ImportRun> {
  const response = await apiFetch<{ data: ImportRun }>(
    `/imports/schedules/${scheduleId}/run`,
    { method: "POST", orgId },
  );
  return response.data;
}

export async function deleteImportSchedule(
  orgId: string,
  scheduleId: string,
): Promise<void> {
  await apiFetch(`/imports/schedules/${scheduleId}`, {
    method: "DELETE",
    orgId,
  });
}

export async function listSyncRuns(orgId: string): Promise<SyncRun[]> {
  const response = await apiFetch<{ data: SyncRun[] }>(
    "/imports/sync-runs",
    { orgId },
  );
  return response.data;
}

export async function runSyncRun(
  orgId: string,
  syncRunId: string,
): Promise<SyncRun> {
  const response = await apiFetch<{ data: SyncRun }>(
    `/imports/sync-runs/${syncRunId}/run`,
    { method: "POST", orgId },
  );
  return response.data;
}

export async function deleteSyncRun(
  orgId: string,
  syncRunId: string,
): Promise<void> {
  await apiFetch(`/imports/sync-runs/${syncRunId}`, {
    method: "DELETE",
    orgId,
  });
}
