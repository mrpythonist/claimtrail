import { apiFetch } from "./api";
import type {
  TmsConnectionTest,
  TmsIntegration,
  TmsProvider,
  TmsSettings,
  TmsSyncKind,
  TmsSyncRun,
  TmsSyncRunStatus,
} from "./types";

/**
 * Typed client for the TMS integration endpoints (v1.3.2).
 *
 * A generic provider adapter with a mock provider and
 * Trimble TMW.Suite modelled schema-only. No live TMS is
 * contacted: the mock syncs dummy data and the Trimble
 * provider fails every fetch with a live-unavailable
 * message. Any member may list integrations, providers,
 * and sync runs; creating, updating, deleting, testing,
 * and syncing need the manage-tms-integrations permission
 * (owner, admin, operations manager, dispatcher); the UI
 * gates on canManageTmsIntegrations and still handles 403
 * responses gracefully. Credentials are never returned by
 * the API.
 */

export const TMS_SYNC_KINDS: {
  value: TmsSyncKind;
  label: string;
}[] = [
  { value: "full", label: "Full sync" },
  { value: "shipments", label: "Shipments" },
  { value: "status", label: "Status" },
  { value: "appointments", label: "Appointments" },
  { value: "documents", label: "Documents" },
];

export function tmsSyncKindLabel(kind: TmsSyncKind): string {
  return TMS_SYNC_KINDS.find((k) => k.value === kind)?.label ?? kind;
}

export const TMS_SYNC_RUN_STATUSES: {
  value: TmsSyncRunStatus;
  label: string;
}[] = [
  { value: "pending", label: "Pending" },
  { value: "running", label: "Running" },
  { value: "completed", label: "Completed" },
  { value: "failed", label: "Failed" },
];

export function tmsSyncRunStatusLabel(status: TmsSyncRunStatus): string {
  return TMS_SYNC_RUN_STATUSES.find((s) => s.value === status)?.label ?? status;
}

export async function listTmsProviders(orgId: string): Promise<TmsProvider[]> {
  const response = await apiFetch<{ data: TmsProvider[] }>("/tms/providers", {
    orgId,
  });
  return response.data;
}

export async function listTmsIntegrations(orgId: string): Promise<TmsIntegration[]> {
  const response = await apiFetch<{ data: TmsIntegration[] }>(
    "/tms/integrations",
    { orgId },
  );
  return response.data;
}

export async function createTmsIntegration(
  orgId: string,
  input: {
    provider: string;
    name: string;
    credentials?: Record<string, string>;
    settings?: Partial<TmsSettings>;
  },
): Promise<TmsIntegration> {
  const response = await apiFetch<{ data: TmsIntegration }>(
    "/tms/integrations",
    { method: "POST", orgId, body: input },
  );
  return response.data;
}

export async function getTmsIntegration(
  orgId: string,
  integrationId: string,
): Promise<TmsIntegration> {
  const response = await apiFetch<{ data: TmsIntegration }>(
    `/tms/integrations/${integrationId}`,
    { orgId },
  );
  return response.data;
}

export async function updateTmsIntegration(
  orgId: string,
  integrationId: string,
  input: {
    name?: string;
    status?: "active" | "disabled";
    credentials?: Record<string, string>;
    settings?: Partial<TmsSettings>;
  },
): Promise<TmsIntegration> {
  const response = await apiFetch<{ data: TmsIntegration }>(
    `/tms/integrations/${integrationId}`,
    { method: "PUT", orgId, body: input },
  );
  return response.data;
}

export async function deleteTmsIntegration(
  orgId: string,
  integrationId: string,
): Promise<void> {
  await apiFetch(`/tms/integrations/${integrationId}`, {
    method: "DELETE",
    orgId,
  });
}

export async function testTmsConnection(
  orgId: string,
  integrationId: string,
): Promise<TmsConnectionTest> {
  const response = await apiFetch<{ data: TmsConnectionTest }>(
    `/tms/integrations/${integrationId}/test`,
    { method: "POST", orgId },
  );
  return response.data;
}

export async function runTmsSync(
  orgId: string,
  integrationId: string,
  kind: TmsSyncKind = "full",
): Promise<TmsSyncRun> {
  const response = await apiFetch<{ data: TmsSyncRun }>(
    `/tms/integrations/${integrationId}/sync`,
    { method: "POST", orgId, body: { kind } },
  );
  return response.data;
}

export async function listTmsSyncRuns(
  orgId: string,
  filters?: { tms_integration_id?: string; kind?: TmsSyncKind; status?: TmsSyncRunStatus },
): Promise<TmsSyncRun[]> {
  const query = new URLSearchParams();
  if (filters?.tms_integration_id) {
    query.set("tms_integration_id", filters.tms_integration_id);
  }
  if (filters?.kind) {
    query.set("kind", filters.kind);
  }
  if (filters?.status) {
    query.set("status", filters.status);
  }
  const suffix = query.size > 0 ? `?${query.toString()}` : "";
  const response = await apiFetch<{ data: TmsSyncRun[] }>(
    `/tms/sync-runs${suffix}`,
    { orgId },
  );
  return response.data;
}

export async function getTmsSyncRun(
  orgId: string,
  syncRunId: string,
): Promise<TmsSyncRun> {
  const response = await apiFetch<{ data: TmsSyncRun }>(
    `/tms/sync-runs/${syncRunId}`,
    { orgId },
  );
  return response.data;
}
