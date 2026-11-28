import { apiDownload, apiFetch, ApiRequestError } from "./api";
import { downloadBlob } from "./shipments";
import type {
  AlertEvaluation,
  AlertMetric,
  AlertOperator,
  AlertRule,
  AlertSeverity,
  AssessmentFinding,
  AuditLog,
  DisasterRecoveryDrill,
  DisasterRecoveryDrillKind,
  DisasterRecoveryStatus,
  EnterpriseAlert,
  EnterpriseAlertStatus,
  IpCheckResult,
  LoadTestRun,
  LoadTestTarget,
  PaginatedMeta,
  PasswordCheckResult,
  RetentionKind,
  RetentionPreview,
  RetentionPurgeResult,
  SecurityAssessment,
  SecurityPolicy,
  SloKey,
  SloStatus,
} from "./types";

/**
 * Typed client for the enterprise readiness surface (v2.0.3):
 * audit-log export, security policies, retention preview and
 * purge, disaster recovery, load tests, alert rules and alerts,
 * SLOs, and security assessments.
 *
 * Every call is tenant-scoped under /api/v1 and needs the
 * manage-enterprise permission and the Growth plan or better:
 * the UI gates on canManageEnterprise and still handles 403
 * and 402 responses gracefully. The audit trail is append-only;
 * the retention purge is the only deletion path. Password rules
 * are enforced on password change; IP ranges are check-only,
 * not enforced at the edge; two-factor is recorded policy
 * only, no 2FA exists yet. There is no real failover: disaster
 * recovery drills verify or dry-run restore a backup only.
 */

export const ALERT_METRICS: { value: AlertMetric; label: string }[] = [
  { value: "queue_depth", label: "Queue depth" },
  { value: "failed_jobs", label: "Failed jobs" },
  { value: "error_rate", label: "Error rate" },
  { value: "disk_usage_percent", label: "Disk usage (%)" },
  { value: "backup_age_hours", label: "Backup age (hours)" },
];

export const ALERT_OPERATORS: { value: AlertOperator; label: string }[] = [
  { value: "gt", label: "Greater than (>)" },
  { value: "gte", label: "Greater than or equal (>=)" },
];

export const ALERT_SEVERITIES: { value: AlertSeverity; label: string }[] = [
  { value: "warning", label: "Warning" },
  { value: "critical", label: "Critical" },
];

export const SLO_KEYS: SloKey[] = ["availability", "error_rate", "latency_p95"];

export const RETENTION_KINDS: RetentionKind[] = [
  "audit_logs",
  "notifications",
  "job_executions",
  "failed_documents",
  "security_events",
];

export const RETENTION_KIND_LABELS: Record<RetentionKind, string> = {
  audit_logs: "Audit logs",
  notifications: "Notifications",
  job_executions: "Job executions",
  failed_documents: "Failed documents",
  security_events: "Security events",
};

export const LOAD_TEST_TARGETS: { value: LoadTestTarget; label: string }[] = [
  { value: "health", label: "Health endpoint" },
  { value: "shipments", label: "Shipments" },
];

export const DRILL_KINDS: { value: DisasterRecoveryDrillKind; label: string }[] = [
  { value: "verify", label: "Verify backup" },
  { value: "restore_dry_run", label: "Restore dry run" },
];

/** The policy the backend returns when no policy has been saved yet. */
export const SECURITY_POLICY_DEFAULTS: SecurityPolicy = {
  password_min_length: 8,
  password_require_numbers: false,
  password_require_symbols: false,
  session_timeout_minutes: 120,
  max_concurrent_sessions: null,
  allowed_ip_ranges: [],
  require_two_factor: false,
  is_default: true,
};

export const ASSESSMENT_FINDING_STATUSES: AssessmentFinding["status"][] = [
  "pass",
  "warn",
  "fail",
  "open",
];

export type Paginated<T> = { data: T[]; meta: PaginatedMeta };

export interface AuditExportFilters {
  format: "csv" | "json";
  entity_type?: string;
  action?: string;
  actor_id?: string;
  date_from?: string;
  date_to?: string;
}

export function auditExportQuery(filters: AuditExportFilters): string {
  const params = new URLSearchParams();
  params.set("format", filters.format);
  if (filters.entity_type) params.set("entity_type", filters.entity_type);
  if (filters.action) params.set("action", filters.action);
  if (filters.actor_id) params.set("actor_id", filters.actor_id);
  if (filters.date_from) params.set("date_from", filters.date_from);
  if (filters.date_to) params.set("date_to", filters.date_to);
  return params.toString();
}

/**
 * Exports the audit trail. CSV downloads as a file; JSON returns
 * the rows. The trail is append-only: exporting never deletes
 * anything, and the retention purge is the only deletion path.
 */
export async function exportAuditLogs(filters: AuditExportFilters): Promise<AuditLog[]> {
  if (filters.format === "csv") {
    const blob = await apiDownload(`/audit-logs/export?${auditExportQuery(filters)}`);
    const today = new Date().toISOString().slice(0, 10);
    downloadBlob(blob, `claimtrail-audit-logs-${today}.csv`);
    return [];
  }
  const body = await apiFetch<{ data: AuditLog[] }>(
    `/audit-logs/export?${auditExportQuery(filters)}`,
  );
  return body.data;
}

export async function getSecurityPolicy(): Promise<SecurityPolicy> {
  const body = await apiFetch<{ data: SecurityPolicy }>("/security/policies");
  return body.data;
}

export async function updateSecurityPolicy(
  input: Partial<SecurityPolicy>,
): Promise<SecurityPolicy> {
  const body = await apiFetch<{ data: SecurityPolicy }>("/security/policies", {
    method: "PUT",
    body: input,
  });
  return body.data;
}

export async function checkPassword(password: string): Promise<PasswordCheckResult> {
  const body = await apiFetch<{ data: PasswordCheckResult }>(
    "/security/policies/check-password",
    { method: "POST", body: { password } },
  );
  return body.data;
}

export async function checkIp(ip: string): Promise<IpCheckResult> {
  const body = await apiFetch<{ data: IpCheckResult }>("/security/policies/check-ip", {
    method: "POST",
    body: { ip },
  });
  return body.data;
}

export async function getRetentionPreview(): Promise<RetentionPreview> {
  const body = await apiFetch<{ data: RetentionPreview }>("/retention/preview");
  return body.data;
}

export async function purgeRetention(dryRun = true): Promise<RetentionPurgeResult> {
  const body = await apiFetch<{ data: RetentionPurgeResult }>("/retention/purge", {
    method: "POST",
    body: { dry_run: dryRun },
  });
  return body.data;
}

export async function getDisasterRecovery(): Promise<DisasterRecoveryStatus> {
  const body = await apiFetch<{ data: DisasterRecoveryStatus }>(
    "/enterprise/disaster-recovery",
  );
  return body.data;
}

export async function listDrills(
  page = 1,
  perPage = 15,
): Promise<Paginated<DisasterRecoveryDrill>> {
  const params = new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
  });
  return apiFetch<Paginated<DisasterRecoveryDrill>>(
    `/enterprise/disaster-recovery/drills?${params.toString()}`,
  );
}

export async function createDrill(input: {
  kind: DisasterRecoveryDrillKind;
  backup_run_id?: string;
}): Promise<DisasterRecoveryDrill> {
  const body = await apiFetch<{ data: DisasterRecoveryDrill }>(
    "/enterprise/disaster-recovery/drills",
    { method: "POST", body: input },
  );
  return body.data;
}

export async function listLoadTests(
  page = 1,
  perPage = 15,
): Promise<Paginated<LoadTestRun>> {
  const params = new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
  });
  return apiFetch<Paginated<LoadTestRun>>(
    `/enterprise/load-tests?${params.toString()}`,
  );
}

export async function createLoadTest(input: {
  name?: string;
  requests: number;
  target?: LoadTestTarget;
}): Promise<LoadTestRun> {
  const body = await apiFetch<{ data: LoadTestRun }>("/enterprise/load-tests", {
    method: "POST",
    body: input,
  });
  return body.data;
}

export async function listAlertRules(): Promise<AlertRule[]> {
  const body = await apiFetch<{ data: AlertRule[] }>("/enterprise/alert-rules");
  return body.data;
}

export async function createAlertRule(input: {
  name: string;
  metric: AlertMetric;
  operator: AlertOperator;
  threshold: number;
  window_minutes: number;
  enabled?: boolean;
  severity?: AlertSeverity;
}): Promise<AlertRule> {
  const body = await apiFetch<{ data: AlertRule }>("/enterprise/alert-rules", {
    method: "POST",
    body: input,
  });
  return body.data;
}

export async function getAlertRule(id: string): Promise<AlertRule> {
  const body = await apiFetch<{ data: AlertRule }>(
    `/enterprise/alert-rules/${encodeURIComponent(id)}`,
  );
  return body.data;
}

export async function updateAlertRule(
  id: string,
  input: Partial<AlertRule>,
): Promise<AlertRule> {
  const body = await apiFetch<{ data: AlertRule }>(
    `/enterprise/alert-rules/${encodeURIComponent(id)}`,
    { method: "PUT", body: input },
  );
  return body.data;
}

export async function deleteAlertRule(id: string): Promise<string> {
  const body = await apiFetch<{ message: string }>(
    `/enterprise/alert-rules/${encodeURIComponent(id)}`,
    { method: "DELETE" },
  );
  return body.message;
}

export async function evaluateAlertRule(id: string): Promise<AlertEvaluation> {
  const body = await apiFetch<{ data: AlertEvaluation }>(
    `/enterprise/alert-rules/${encodeURIComponent(id)}/evaluate`,
    { method: "POST" },
  );
  return body.data;
}

export async function listAlerts(
  query?: { status?: EnterpriseAlertStatus | ""; metric?: string; page?: number; perPage?: number },
): Promise<Paginated<EnterpriseAlert>> {
  const params = new URLSearchParams();
  if (query?.status) params.set("status", query.status);
  if (query?.metric) params.set("metric", query.metric);
  params.set("page", String(query?.page ?? 1));
  params.set("per_page", String(query?.perPage ?? 15));
  return apiFetch<Paginated<EnterpriseAlert>>(
    `/enterprise/alerts?${params.toString()}`,
  );
}

export async function acknowledgeAlert(id: string): Promise<EnterpriseAlert> {
  const body = await apiFetch<{ data: EnterpriseAlert }>(
    `/enterprise/alerts/${encodeURIComponent(id)}/acknowledge`,
    { method: "POST" },
  );
  return body.data;
}

export async function resolveAlert(id: string): Promise<EnterpriseAlert> {
  const body = await apiFetch<{ data: EnterpriseAlert }>(
    `/enterprise/alerts/${encodeURIComponent(id)}/resolve`,
    { method: "POST" },
  );
  return body.data;
}

export async function listSlos(): Promise<SloStatus[]> {
  const body = await apiFetch<{ data: SloStatus[] }>("/enterprise/slos");
  return body.data;
}

export async function updateSlo(
  key: SloKey,
  input: { target_value?: number; window_days?: number; enabled?: boolean },
): Promise<SloStatus> {
  const body = await apiFetch<{ data: SloStatus }>(`/enterprise/slos/${key}`, {
    method: "PUT",
    body: input,
  });
  return body.data;
}

export async function listAssessments(
  page = 1,
  perPage = 15,
): Promise<Paginated<SecurityAssessment>> {
  const params = new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
  });
  return apiFetch<Paginated<SecurityAssessment>>(
    `/enterprise/assessments?${params.toString()}`,
  );
}

export async function createAssessment(): Promise<SecurityAssessment> {
  const body = await apiFetch<{ data: SecurityAssessment }>("/enterprise/assessments", {
    method: "POST",
  });
  return body.data;
}

/** Returns null when no assessment has ever been generated (404). */
export async function getLatestAssessment(): Promise<SecurityAssessment | null> {
  try {
    const body = await apiFetch<{ data: SecurityAssessment }>(
      "/enterprise/assessments/latest",
    );
    return body.data;
  } catch (err) {
    if (err instanceof ApiRequestError && err.status === 404) return null;
    throw err;
  }
}
