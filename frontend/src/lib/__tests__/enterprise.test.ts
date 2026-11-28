import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiDownload, apiFetch, ApiRequestError } from "@/lib/api";
import { downloadBlob } from "@/lib/shipments";
import {
  ALERT_METRICS,
  RETENTION_KINDS,
  SECURITY_POLICY_DEFAULTS,
  SLO_KEYS,
  acknowledgeAlert,
  auditExportQuery,
  checkIp,
  checkPassword,
  createAlertRule,
  createAssessment,
  createDrill,
  createLoadTest,
  deleteAlertRule,
  evaluateAlertRule,
  exportAuditLogs,
  getDisasterRecovery,
  getLatestAssessment,
  getRetentionPreview,
  getSecurityPolicy,
  listAlertRules,
  listAlerts,
  listAssessments,
  listDrills,
  listLoadTests,
  listSlos,
  purgeRetention,
  resolveAlert,
  updateAlertRule,
  updateSecurityPolicy,
  updateSlo,
} from "../enterprise";
import { canManageEnterprise } from "../permissions";
import type {
  AlertRule,
  AuditLog,
  DisasterRecoveryStatus,
  EnterpriseAlert,
  LoadTestRun,
  RetentionPreview,
  SecurityAssessment,
  SecurityPolicy,
  SloStatus,
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
  return { apiFetch: vi.fn(), apiDownload: vi.fn(), apiUpload: vi.fn(), ApiRequestError };
});

vi.mock("@/lib/shipments", () => ({ downloadBlob: vi.fn() }));

const mockedApiFetch = vi.mocked(apiFetch);
const mockedApiDownload = vi.mocked(apiDownload);
const mockedDownloadBlob = vi.mocked(downloadBlob);

beforeEach(() => {
  mockedApiFetch.mockReset();
  mockedApiDownload.mockReset();
  mockedDownloadBlob.mockReset();
});

const POLICY: SecurityPolicy = {
  password_min_length: 12,
  password_require_numbers: true,
  password_require_symbols: true,
  session_timeout_minutes: 60,
  max_concurrent_sessions: 3,
  allowed_ip_ranges: ["203.0.113.0/24"],
  require_two_factor: true,
  is_default: false,
};

const RETENTION: RetentionPreview = {
  effective: {
    audit_logs: 365,
    notifications: 90,
    job_executions: 30,
    failed_documents: 180,
    security_events: 365,
  },
  defaults: {
    audit_logs: 365,
    notifications: 90,
    job_executions: 30,
    failed_documents: 180,
    security_events: 365,
  },
  overrides: { notifications: 30 },
  preview: {
    audit_logs: { count: 12, cutoff: "2025-11-28T00:00:00.000Z" },
    notifications: { count: 4, cutoff: "2026-08-30T00:00:00.000Z" },
    job_executions: { count: 0, cutoff: null },
    failed_documents: { count: 2, cutoff: "2026-06-01T00:00:00.000Z" },
    security_events: { count: 0, cutoff: null },
  },
  generated_at: "2026-11-28T10:00:00.000Z",
};

const DR_STATUS: DisasterRecoveryStatus = {
  rpo_minutes: 60,
  rto_minutes: 240,
  latest_backup: {
    id: "backup-1",
    status: "completed",
    created_at: "2026-11-28T00:00:00.000Z",
    checksum: "abc123",
  },
  last_verified_backup: { id: "backup-1", verified_at: "2026-11-28T01:00:00.000Z", ok: true },
  latest_drill: null,
  total_drills: 2,
};

const RULE: AlertRule = {
  id: "rule-1",
  name: "Queue backing up",
  metric: "queue_depth",
  operator: "gt",
  threshold: 100,
  window_minutes: 15,
  enabled: true,
  severity: "critical",
  created_at: "2026-11-28T00:00:00.000Z",
  updated_at: "2026-11-28T00:00:00.000Z",
};

const ALERT_ROW: EnterpriseAlert = {
  id: "alert-1",
  alert_rule_id: "rule-1",
  metric: "queue_depth",
  observed_value: 150,
  threshold: 100,
  severity: "critical",
  status: "active",
  message: "Queue depth 150 exceeded 100",
  triggered_at: "2026-11-28T09:00:00.000Z",
  acknowledged_at: null,
  resolved_at: null,
  created_at: "2026-11-28T09:00:00.000Z",
};

const SLO: SloStatus = {
  key: "availability",
  name: "Availability",
  kind: "availability",
  target_value: 99.9,
  target_unit: "%",
  window_days: 30,
  enabled: true,
  measured_value: 99.95,
  sample_size: 43200,
  status: "meeting",
  measured_at: "2026-11-28T10:00:00.000Z",
};

const LOAD_TEST: LoadTestRun = {
  id: "lt-1",
  name: "Morning smoke",
  target: "health",
  requests: 10,
  status: "completed",
  total_requests: 10,
  success_count: 10,
  error_count: 0,
  avg_ms: 42,
  p95_ms: 80,
  max_ms: 120,
  requests_per_second: 25,
  detail: null,
  started_at: "2026-11-28T10:00:00.000Z",
  finished_at: "2026-11-28T10:00:01.000Z",
  created_at: "2026-11-28T10:00:00.000Z",
};

const ASSESSMENT: SecurityAssessment = {
  id: "assess-1",
  overall: "warn",
  open_findings: 1,
  total_checks: 5,
  passed: 4,
  warnings: 1,
  failures: 0,
  findings: [
    {
      id: "finding-1",
      label: "Session timeout is long",
      status: "warn",
      detail: "Session timeout is 480 minutes.",
      category: "sessions",
    },
  ],
  generated_by: { id: 1, name: "Aqib" },
  generated_at: "2026-11-28T10:00:00.000Z",
  created_at: "2026-11-28T10:00:00.000Z",
};

const AUDIT_ROW: AuditLog = {
  id: "audit-1",
  organization_id: "org-1",
  actor_type: "user",
  actor: { id: 1, name: "Aqib" },
  action: "claim.created",
  entity_type: "claim",
  entity_id: "claim-1",
  before: null,
  after: { status: "potential" },
  correlation_id: "corr-1",
  ip_address: "203.0.113.42",
  created_at: "2026-11-28T10:00:00.000Z",
};

const META = { current_page: 1, last_page: 1, per_page: 15, total: 1 };

describe("enterprise client", () => {
  it("builds the audit export query with only the filters given", () => {
    expect(
      auditExportQuery({
        format: "csv",
        entity_type: "claim",
        date_from: "2026-11-01",
      }),
    ).toBe("format=csv&entity_type=claim&date_from=2026-11-01");
    expect(auditExportQuery({ format: "json" })).toBe("format=json");
  });

  it("downloads the audit CSV as a file and returns no rows", async () => {
    const blob = new Blob(["a,b"], { type: "text/csv" });
    mockedApiDownload.mockResolvedValue(blob);
    await expect(exportAuditLogs({ format: "csv" })).resolves.toEqual([]);
    expect(mockedApiDownload).toHaveBeenCalledWith("/audit-logs/export?format=csv");
    expect(mockedDownloadBlob).toHaveBeenCalledWith(
      blob,
      expect.stringMatching(/^claimtrail-audit-logs-\d{4}-\d{2}-\d{2}\.csv$/),
    );
  });

  it("returns audit rows from the JSON export envelope", async () => {
    mockedApiFetch.mockResolvedValue({ data: [AUDIT_ROW] });
    await expect(
      exportAuditLogs({ format: "json", action: "claim.created" }),
    ).resolves.toEqual([AUDIT_ROW]);
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/audit-logs/export?format=json&action=claim.created",
    );
  });

  it("gets and updates the security policy", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: POLICY });
    await expect(getSecurityPolicy()).resolves.toEqual(POLICY);
    expect(mockedApiFetch).toHaveBeenCalledWith("/security/policies");
    mockedApiFetch.mockResolvedValueOnce({ data: POLICY });
    await expect(updateSecurityPolicy({ password_min_length: 12 })).resolves.toEqual(POLICY);
    expect(mockedApiFetch).toHaveBeenCalledWith("/security/policies", {
      method: "PUT",
      body: { password_min_length: 12 },
    });
  });

  it("checks a password and an IP address", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: { valid: false, errors: ["Too short."] } });
    await expect(checkPassword("abc")).resolves.toEqual({ valid: false, errors: ["Too short."] });
    expect(mockedApiFetch).toHaveBeenCalledWith("/security/policies/check-password", {
      method: "POST",
      body: { password: "abc" },
    });
    mockedApiFetch.mockResolvedValueOnce({
      data: { ip: "203.0.113.42", allowed: true, in_range: true, ranges: ["203.0.113.0/24"] },
    });
    await expect(checkIp("203.0.113.42")).resolves.toEqual({
      ip: "203.0.113.42",
      allowed: true,
      in_range: true,
      ranges: ["203.0.113.0/24"],
    });
    expect(mockedApiFetch).toHaveBeenCalledWith("/security/policies/check-ip", {
      method: "POST",
      body: { ip: "203.0.113.42" },
    });
  });

  it("previews retention and purges dry-run by default", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: RETENTION });
    await expect(getRetentionPreview()).resolves.toEqual(RETENTION);
    expect(mockedApiFetch).toHaveBeenCalledWith("/retention/preview");
    mockedApiFetch.mockResolvedValueOnce({ data: { dry_run: true, counts: { audit_logs: 12 } } });
    await expect(purgeRetention()).resolves.toEqual({ dry_run: true, counts: { audit_logs: 12 } });
    expect(mockedApiFetch).toHaveBeenCalledWith("/retention/purge", {
      method: "POST",
      body: { dry_run: true },
    });
    mockedApiFetch.mockResolvedValueOnce({ data: { dry_run: false, counts: { audit_logs: 12 } } });
    await expect(purgeRetention(false)).resolves.toEqual({
      dry_run: false,
      counts: { audit_logs: 12 },
    });
  });

  it("gets disaster recovery status and drills", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: DR_STATUS });
    await expect(getDisasterRecovery()).resolves.toEqual(DR_STATUS);
    expect(mockedApiFetch).toHaveBeenCalledWith("/enterprise/disaster-recovery");
    mockedApiFetch.mockResolvedValueOnce({
      data: [
        {
          id: "drill-1",
          kind: "verify",
          status: "completed",
          result: {},
          performed_by: { id: 1, name: "Aqib" },
          performed_at: "2026-11-28T09:00:00.000Z",
          created_at: "2026-11-28T09:00:00.000Z",
        },
      ],
      meta: META,
    });
    const drills = await listDrills();
    expect(drills.data).toHaveLength(1);
    expect(drills.meta.total).toBe(1);
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/enterprise/disaster-recovery/drills?page=1&per_page=15",
    );
  });

  it("creates a drill and a load test", async () => {
    mockedApiFetch.mockResolvedValueOnce({
      data: {
        id: "drill-1",
        kind: "restore_dry_run",
        status: "completed",
        result: {},
        performed_by: null,
        performed_at: "2026-11-28T09:00:00.000Z",
        created_at: "2026-11-28T09:00:00.000Z",
      },
    });
    const drill = await createDrill({ kind: "restore_dry_run" });
    expect(drill.kind).toBe("restore_dry_run");
    expect(mockedApiFetch).toHaveBeenCalledWith("/enterprise/disaster-recovery/drills", {
      method: "POST",
      body: { kind: "restore_dry_run" },
    });
    mockedApiFetch.mockResolvedValueOnce({ data: LOAD_TEST });
    await expect(
      createLoadTest({ name: "Morning smoke", requests: 10, target: "health" }),
    ).resolves.toEqual(LOAD_TEST);
    expect(mockedApiFetch).toHaveBeenCalledWith("/enterprise/load-tests", {
      method: "POST",
      body: { name: "Morning smoke", requests: 10, target: "health" },
    });
    mockedApiFetch.mockResolvedValueOnce({ data: [LOAD_TEST], meta: META });
    const runs = await listLoadTests();
    expect(runs.data).toEqual([LOAD_TEST]);
  });

  it("lists, creates, updates, deletes, and evaluates alert rules", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: [RULE] });
    await expect(listAlertRules()).resolves.toEqual([RULE]);
    expect(mockedApiFetch).toHaveBeenCalledWith("/enterprise/alert-rules");
    mockedApiFetch.mockResolvedValueOnce({ data: RULE });
    await expect(
      createAlertRule({
        name: "Queue backing up",
        metric: "queue_depth",
        operator: "gt",
        threshold: 100,
        window_minutes: 15,
        severity: "critical",
      }),
    ).resolves.toEqual(RULE);
    mockedApiFetch.mockResolvedValueOnce({ data: { ...RULE, threshold: 50 } });
    await expect(updateAlertRule("rule-1", { threshold: 50 })).resolves.toEqual({
      ...RULE,
      threshold: 50,
    });
    expect(mockedApiFetch).toHaveBeenCalledWith("/enterprise/alert-rules/rule-1", {
      method: "PUT",
      body: { threshold: 50 },
    });
    mockedApiFetch.mockResolvedValueOnce({ message: "Alert rule deleted." });
    await expect(deleteAlertRule("rule-1")).resolves.toBe("Alert rule deleted.");
    mockedApiFetch.mockResolvedValueOnce({
      data: {
        rule_id: "rule-1",
        metric: "queue_depth",
        observed_value: 150,
        breached: true,
        alert: ALERT_ROW,
      },
    });
    const evaluation = await evaluateAlertRule("rule-1");
    expect(evaluation.breached).toBe(true);
    expect(evaluation.alert).toEqual(ALERT_ROW);
    expect(mockedApiFetch).toHaveBeenCalledWith("/enterprise/alert-rules/rule-1/evaluate", {
      method: "POST",
    });
  });

  it("lists alerts with filters and acknowledges and resolves them", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: [ALERT_ROW], meta: META });
    const page = await listAlerts({ status: "active", page: 2 });
    expect(page.data).toEqual([ALERT_ROW]);
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/enterprise/alerts?status=active&page=2&per_page=15",
    );
    mockedApiFetch.mockResolvedValueOnce({ data: { ...ALERT_ROW, status: "acknowledged" } });
    await expect(acknowledgeAlert("alert-1")).resolves.toMatchObject({ status: "acknowledged" });
    expect(mockedApiFetch).toHaveBeenCalledWith("/enterprise/alerts/alert-1/acknowledge", {
      method: "POST",
    });
    mockedApiFetch.mockResolvedValueOnce({ data: { ...ALERT_ROW, status: "resolved" } });
    await expect(resolveAlert("alert-1")).resolves.toMatchObject({ status: "resolved" });
  });

  it("lists and updates SLOs", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: [SLO] });
    await expect(listSlos()).resolves.toEqual([SLO]);
    expect(mockedApiFetch).toHaveBeenCalledWith("/enterprise/slos");
    mockedApiFetch.mockResolvedValueOnce({ data: { ...SLO, target_value: 99.5 } });
    await expect(updateSlo("availability", { target_value: 99.5 })).resolves.toEqual({
      ...SLO,
      target_value: 99.5,
    });
    expect(mockedApiFetch).toHaveBeenCalledWith("/enterprise/slos/availability", {
      method: "PUT",
      body: { target_value: 99.5 },
    });
  });

  it("lists and creates assessments and returns null for latest when none exists", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: [ASSESSMENT], meta: META });
    const history = await listAssessments();
    expect(history.data).toEqual([ASSESSMENT]);
    mockedApiFetch.mockResolvedValueOnce({ data: ASSESSMENT });
    await expect(createAssessment()).resolves.toEqual(ASSESSMENT);
    expect(mockedApiFetch).toHaveBeenCalledWith("/enterprise/assessments", { method: "POST" });
    mockedApiFetch.mockRejectedValueOnce(new ApiRequestError(404, { message: "Not found" }));
    await expect(getLatestAssessment()).resolves.toBeNull();
  });

  it("exposes exactly the alert metrics the backend enforces", () => {
    expect(ALERT_METRICS.map((metric) => metric.value)).toEqual([
      "queue_depth",
      "failed_jobs",
      "error_rate",
      "disk_usage_percent",
      "backup_age_hours",
    ]);
  });

  it("exposes exactly the SLO keys and retention kinds the backend uses", () => {
    expect(SLO_KEYS).toEqual(["availability", "error_rate", "latency_p95"]);
    expect(RETENTION_KINDS).toEqual([
      "audit_logs",
      "notifications",
      "job_executions",
      "failed_documents",
      "security_events",
    ]);
  });

  it("matches the backend policy defaults", () => {
    expect(SECURITY_POLICY_DEFAULTS).toEqual({
      password_min_length: 8,
      password_require_numbers: false,
      password_require_symbols: false,
      session_timeout_minutes: 120,
      max_concurrent_sessions: null,
      allowed_ip_ranges: [],
      require_two_factor: false,
      is_default: true,
    });
  });
});

describe("enterprise permissions", () => {
  it("lets owner, admin, and operations manager manage enterprise tools", () => {
    for (const role of ["owner", "admin", "operations_manager"] as const) {
      expect(canManageEnterprise(role)).toBe(true);
    }
  });

  it("keeps dispatcher, billing staff, and viewers read-only", () => {
    for (const role of ["dispatcher", "billing_staff", "viewer"] as const) {
      expect(canManageEnterprise(role)).toBe(false);
    }
    expect(canManageEnterprise(null)).toBe(false);
    expect(canManageEnterprise(undefined)).toBe(false);
  });
});
