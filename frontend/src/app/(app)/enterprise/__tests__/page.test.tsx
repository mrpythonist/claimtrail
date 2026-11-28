import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { useAuth } from "@/lib/auth";
import {
  acknowledgeAlert,
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
} from "@/lib/enterprise";
import type {
  AlertRule,
  DisasterRecoveryStatus,
  EnterpriseAlert,
  LoadTestRun,
  Organization,
  RetentionPreview,
  SecurityAssessment,
  SecurityPolicy,
  SloStatus,
} from "@/lib/types";
import EnterprisePage from "../page";

vi.mock("@/lib/enterprise", () => ({
  ALERT_METRICS: [{ value: "queue_depth", label: "Queue depth" }],
  ALERT_OPERATORS: [{ value: "gt", label: "Greater than (>)" }],
  ALERT_SEVERITIES: [{ value: "warning", label: "Warning" }],
  DRILL_KINDS: [{ value: "verify", label: "Verify backup" }],
  LOAD_TEST_TARGETS: [{ value: "health", label: "Health endpoint" }],
  RETENTION_KINDS: ["audit_logs", "notifications", "job_executions", "failed_documents", "security_events"],
  RETENTION_KIND_LABELS: {
    audit_logs: "Audit logs",
    notifications: "Notifications",
    job_executions: "Job executions",
    failed_documents: "Failed documents",
    security_events: "Security events",
  },
  acknowledgeAlert: vi.fn(),
  checkIp: vi.fn(),
  checkPassword: vi.fn(),
  createAlertRule: vi.fn(),
  createAssessment: vi.fn(),
  createDrill: vi.fn(),
  createLoadTest: vi.fn(),
  deleteAlertRule: vi.fn(),
  evaluateAlertRule: vi.fn(),
  exportAuditLogs: vi.fn(),
  getDisasterRecovery: vi.fn(),
  getLatestAssessment: vi.fn(),
  getRetentionPreview: vi.fn(),
  getSecurityPolicy: vi.fn(),
  listAlertRules: vi.fn(),
  listAlerts: vi.fn(),
  listAssessments: vi.fn(),
  listDrills: vi.fn(),
  listLoadTests: vi.fn(),
  listSlos: vi.fn(),
  purgeRetention: vi.fn(),
  resolveAlert: vi.fn(),
  updateAlertRule: vi.fn(),
  updateSecurityPolicy: vi.fn(),
  updateSlo: vi.fn(),
}));

vi.mock("@/lib/auth", () => ({ useAuth: vi.fn() }));

const mockedUseAuth = vi.mocked(useAuth);
const mockedLatest = vi.mocked(getLatestAssessment);
const mockedSlos = vi.mocked(listSlos);
const mockedAlerts = vi.mocked(listAlerts);
const mockedPolicy = vi.mocked(getSecurityPolicy);
const mockedRetention = vi.mocked(getRetentionPreview);
const mockedDr = vi.mocked(getDisasterRecovery);
const mockedDrills = vi.mocked(listDrills);
const mockedRules = vi.mocked(listAlertRules);
const mockedLoadTests = vi.mocked(listLoadTests);
const mockedAssessments = vi.mocked(listAssessments);

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

const META = { current_page: 1, last_page: 1, per_page: 15, total: 1 };

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

const POLICY: SecurityPolicy = {
  password_min_length: 12,
  password_require_numbers: true,
  password_require_symbols: false,
  session_timeout_minutes: 60,
  max_concurrent_sessions: 3,
  allowed_ip_ranges: ["203.0.113.0/24"],
  require_two_factor: false,
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
  overrides: {},
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

function mockLoad() {
  mockedLatest.mockResolvedValue(ASSESSMENT);
  mockedSlos.mockResolvedValue([SLO]);
  mockedAlerts.mockResolvedValue({ data: [ALERT_ROW], meta: META });
  mockedPolicy.mockResolvedValue(POLICY);
  mockedRetention.mockResolvedValue(RETENTION);
  mockedDr.mockResolvedValue(DR_STATUS);
  mockedDrills.mockResolvedValue({ data: [], meta: META });
  mockedRules.mockResolvedValue([RULE]);
  mockedLoadTests.mockResolvedValue({ data: [LOAD_TEST], meta: META });
  mockedAssessments.mockResolvedValue({ data: [ASSESSMENT], meta: META });
}

beforeEach(() => {
  for (const fn of [
    mockedLatest,
    mockedSlos,
    mockedAlerts,
    mockedPolicy,
    mockedRetention,
    mockedDr,
    mockedDrills,
    mockedRules,
    mockedLoadTests,
    mockedAssessments,
    vi.mocked(acknowledgeAlert),
    vi.mocked(checkIp),
    vi.mocked(checkPassword),
    vi.mocked(createAlertRule),
    vi.mocked(createAssessment),
    vi.mocked(createDrill),
    vi.mocked(createLoadTest),
    vi.mocked(deleteAlertRule),
    vi.mocked(evaluateAlertRule),
    vi.mocked(exportAuditLogs),
    vi.mocked(purgeRetention),
    vi.mocked(resolveAlert),
    vi.mocked(updateAlertRule),
    vi.mocked(updateSecurityPolicy),
    vi.mocked(updateSlo),
  ]) {
    fn.mockReset();
  }
  mockedUseAuth.mockReturnValue({ currentOrg: ORG } as ReturnType<typeof useAuth>);
  mockLoad();
});

describe("EnterprisePage", () => {
  it("shows the tabs, the latest assessment, SLOs, and recent alerts on the overview", async () => {
    render(<EnterprisePage />);

    for (const label of [
      "Overview",
      "Audit",
      "Security policies",
      "Retention",
      "Disaster recovery",
      "Monitoring",
      "Load tests",
      "Assessment",
    ]) {
      expect(screen.getByText(label)).toBeInTheDocument();
    }

    await waitFor(() => {
      expect(screen.getByText("Availability")).toBeInTheDocument();
      expect(screen.getByText("queue_depth")).toBeInTheDocument();
    });
    expect(screen.getByText("Service level objectives")).toBeInTheDocument();
    expect(screen.getByText("Recent alerts")).toBeInTheDocument();
  });

  it("shows the audit export form with format and filters", async () => {
    render(<EnterprisePage />);
    fireEvent.click(screen.getByText("Audit"));

    await waitFor(() => {
      expect(screen.getByLabelText("Export format")).toBeInTheDocument();
      expect(screen.getByLabelText("Entity type")).toBeInTheDocument();
      expect(screen.getByText("Download CSV")).toBeInTheDocument();
    });
    expect(screen.getByText(/append-only/)).toBeInTheDocument();
  });

  it("shows the security policy form and the password and IP checkers", async () => {
    render(<EnterprisePage />);
    fireEvent.click(screen.getByText("Security policies"));

    await waitFor(() => {
      expect(screen.getByLabelText("Minimum password length")).toBeInTheDocument();
      expect(screen.getByLabelText("Session timeout (minutes)")).toBeInTheDocument();
      expect(screen.getByLabelText("Allowed IP ranges")).toBeInTheDocument();
    });
    expect(screen.getByLabelText("Password")).toBeInTheDocument();
    expect(screen.getByLabelText("IP address")).toBeInTheDocument();
    expect(screen.getByText("Check password")).toBeInTheDocument();
    expect(screen.getByText("Check IP")).toBeInTheDocument();
    expect(screen.getByDisplayValue("12")).toBeInTheDocument();
  });

  it("shows retention settings, the purge preview, and purge buttons", async () => {
    render(<EnterprisePage />);
    fireEvent.click(screen.getByText("Retention"));

    await waitFor(() => {
      expect(screen.getByText("Effective retention settings")).toBeInTheDocument();
      expect(screen.getAllByText("Audit logs").length).toBeGreaterThanOrEqual(2);
      expect(screen.getByText("What a purge would delete")).toBeInTheDocument();
      expect(screen.getByText("Purge (dry run)")).toBeInTheDocument();
      expect(screen.getByText("Run purge")).toBeInTheDocument();
    });
  });

  it("shows recovery objectives, backup state, and the drill form", async () => {
    render(<EnterprisePage />);
    fireEvent.click(screen.getByText("Disaster recovery"));

    await waitFor(() => {
      expect(screen.getByText("Recovery objectives and backups")).toBeInTheDocument();
      expect(screen.getByText(/Recovery point objective: 60 minutes/)).toBeInTheDocument();
      expect(screen.getByLabelText("Drill kind")).toBeInTheDocument();
      expect(screen.getByText("Run drill")).toBeInTheDocument();
    });
    expect(screen.getByText(/no real failover/)).toBeInTheDocument();
  });

  it("shows alert rules, the rule form, and alerts with acknowledge and resolve", async () => {
    render(<EnterprisePage />);
    fireEvent.click(screen.getByText("Monitoring"));

    await waitFor(() => {
      expect(screen.getByText("Queue backing up")).toBeInTheDocument();
      expect(screen.getByLabelText("Rule name")).toBeInTheDocument();
      expect(screen.getByText("Create rule")).toBeInTheDocument();
    });
    expect(screen.getByText("Acknowledge")).toBeInTheDocument();
    expect(screen.getByText("Resolve")).toBeInTheDocument();
  });

  it("shows the load test form and history", async () => {
    render(<EnterprisePage />);
    fireEvent.click(screen.getByText("Load tests"));

    await waitFor(() => {
      expect(screen.getByLabelText("Requests")).toBeInTheDocument();
      expect(screen.getByLabelText("Target")).toBeInTheDocument();
      expect(screen.getByText("Morning smoke")).toBeInTheDocument();
    });
  });

  it("shows the assessment findings and history", async () => {
    render(<EnterprisePage />);
    fireEvent.click(screen.getByText("Assessment"));

    await waitFor(() => {
      expect(screen.getByText("Latest assessment findings")).toBeInTheDocument();
      expect(screen.getByText("Session timeout is long")).toBeInTheDocument();
      expect(screen.getByText("Generate assessment")).toBeInTheDocument();
    });
  });

  it("shows an empty state when no assessment exists yet", async () => {
    mockedLatest.mockResolvedValue(null);
    mockedAssessments.mockResolvedValue({ data: [], meta: META });
    render(<EnterprisePage />);

    await waitFor(() => {
      expect(screen.getByText("No assessment yet")).toBeInTheDocument();
    });
  });
});
