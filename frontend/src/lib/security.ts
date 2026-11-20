import { apiFetch } from "./api";
import type {
  BackupRun,
  BackupVerification,
  PaginatedMeta,
  RetentionDays,
  RetentionSettings,
  SecurityCheckResult,
  SecurityEvent,
} from "./types";

/**
 * Typed client for the security endpoints (GET /backups,
 * GET /retention, GET /security/*). Every call is tenant-scoped:
 * apiFetch attaches the stored organization id, and each function
 * here takes the org id explicitly so pages pass the current
 * organization rather than relying on stored state. The backend
 * requires the manage-security permission (Owner and Admin) for
 * all of these; the UI gates on canViewSecurity and still handles
 * 403 responses gracefully.
 */

export async function getSecurityCheck(
  orgId: string,
): Promise<SecurityCheckResult> {
  const response = await apiFetch<{ data: SecurityCheckResult }>(
    "/security/check",
    { orgId },
  );
  return response.data;
}

export async function getBackups(
  orgId: string,
  page = 1,
  perPage = 10,
): Promise<{ data: BackupRun[]; meta: PaginatedMeta }> {
  const params = new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
  });
  return apiFetch<{ data: BackupRun[]; meta: PaginatedMeta }>(
    `/backups?${params.toString()}`,
    { orgId },
  );
}

export async function createBackup(orgId: string): Promise<BackupRun> {
  const response = await apiFetch<{ data: BackupRun }>("/backups", {
    method: "POST",
    orgId,
  });
  return response.data;
}

export async function verifyBackup(
  orgId: string,
  backupId: string,
): Promise<BackupVerification> {
  const response = await apiFetch<{ data: BackupVerification }>(
    `/backups/${encodeURIComponent(backupId)}/verify`,
    { method: "POST", orgId },
  );
  return response.data;
}

export async function getRetention(orgId: string): Promise<RetentionSettings> {
  const response = await apiFetch<{ data: RetentionSettings }>("/retention", {
    orgId,
  });
  return response.data;
}

export async function updateRetention(
  orgId: string,
  overrides: Partial<RetentionDays>,
): Promise<RetentionSettings> {
  const response = await apiFetch<{ data: RetentionSettings }>("/retention", {
    method: "PUT",
    orgId,
    body: overrides,
  });
  return response.data;
}

export async function getSecurityEvents(
  orgId: string,
  limit = 50,
): Promise<SecurityEvent[]> {
  const params = new URLSearchParams({ limit: String(limit) });
  const response = await apiFetch<{ data: SecurityEvent[] }>(
    `/security/events?${params.toString()}`,
    { orgId },
  );
  return response.data;
}

/** Badge tone for a security check status. */
export function securityCheckTone(
  status: SecurityCheckResult["overall"],
): "success" | "warning" | "danger" | "neutral" {
  switch (status) {
    case "pass":
      return "success";
    case "warn":
      return "warning";
    case "fail":
      return "danger";
    default:
      return "neutral";
  }
}

/** Human label for a security check status. */
export function securityCheckLabel(status: SecurityCheckResult["overall"]): string {
  switch (status) {
    case "pass":
      return "Pass";
    case "warn":
      return "Warning";
    case "fail":
      return "Fail";
    default:
      return "Unknown";
  }
}

/** Human label for a retention kind. */
export function retentionKindLabel(kind: keyof RetentionDays): string {
  switch (kind) {
    case "audit_logs":
      return "Audit logs";
    case "notifications":
      return "Notifications";
    case "job_executions":
      return "Job executions";
    case "failed_documents":
      return "Failed documents";
    case "security_events":
      return "Security events";
  }
}

/** Bytes as "123 B", "4.2 KB", or "1.1 MB". Null means unknown. */
export function formatBytes(bytes: number | null): string {
  if (bytes === null) return "Unknown size";
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/** Short human summary of a security event's metadata. */
export function securityEventSummary(event: SecurityEvent): string {
  const metadata = event.metadata ?? {};
  if (event.type === "login.failed" && typeof metadata.email === "string") {
    return `Failed login for ${metadata.email}`;
  }
  if (
    event.type === "authorization.denied" &&
    typeof metadata.path === "string"
  ) {
    const method =
      typeof metadata.method === "string" ? `${metadata.method} ` : "";
    return `Denied ${method}${metadata.path}`;
  }
  return event.type;
}
