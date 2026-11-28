"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Input, Select } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { Table, TableCell } from "@/components/ui/Table";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import {
  ALERT_METRICS,
  ALERT_OPERATORS,
  ALERT_SEVERITIES,
  DRILL_KINDS,
  LOAD_TEST_TARGETS,
  RETENTION_KINDS,
  RETENTION_KIND_LABELS,
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
import { canManageEnterprise } from "@/lib/permissions";
import type {
  AlertEvaluation,
  AlertMetric,
  AlertOperator,
  AlertRule,
  AlertSeverity,
  AuditLog,
  DisasterRecoveryDrill,
  DisasterRecoveryDrillKind,
  DisasterRecoveryStatus,
  EnterpriseAlert,
  EnterpriseAlertStatus,
  IpCheckResult,
  LoadTestRun,
  LoadTestTarget,
  Organization,
  PaginatedMeta,
  PasswordCheckResult,
  RetentionPreview,
  SecurityAssessment,
  SecurityPolicy,
  SloKey,
  SloStatus,
} from "@/lib/types";

/**
 * Enterprise readiness for one organization (v2.0.3): audit-log
 * export, security policies, retention, disaster recovery,
 * monitoring (alert rules and alerts), load tests, SLOs, and
 * security assessments.
 *
 * Everything here needs the manage-enterprise permission and
 * the Growth plan or better; the UI gates on
 * canManageEnterprise and still handles 403 and 402 responses
 * gracefully. Honest limits, stated where they apply: the audit
 * trail is append-only and the retention purge is the only
 * deletion path; password rules are enforced on password
 * change; IP ranges are check-only, not enforced at the edge;
 * two-factor is recorded policy only, no 2FA exists yet; and
 * there is no real failover, drills only verify or dry-run
 * restore a backup.
 */

type Tab =
  | "overview"
  | "audit"
  | "security"
  | "retention"
  | "disaster-recovery"
  | "monitoring"
  | "load-tests"
  | "assessment";

const TABS: { value: Tab; label: string }[] = [
  { value: "overview", label: "Overview" },
  { value: "audit", label: "Audit" },
  { value: "security", label: "Security policies" },
  { value: "retention", label: "Retention" },
  { value: "disaster-recovery", label: "Disaster recovery" },
  { value: "monitoring", label: "Monitoring" },
  { value: "load-tests", label: "Load tests" },
  { value: "assessment", label: "Assessment" },
];

const EMPTY_META: PaginatedMeta = { current_page: 1, last_page: 1, per_page: 15, total: 0 };

function errorMessage(err: unknown, fallback: string): string {
  return err instanceof ApiRequestError ? err.message : fallback;
}

function isPlanError(err: unknown): boolean {
  return err instanceof ApiRequestError && err.status === 402;
}

function isForbidden(err: unknown): boolean {
  return err instanceof ApiRequestError && err.status === 403;
}

function formatDate(value: string | null): string {
  if (!value) return "—";
  return new Date(value).toLocaleString();
}

function sloTone(status: SloStatus["status"]): "success" | "warning" | "danger" | "info" {
  switch (status) {
    case "meeting":
      return "success";
    case "at_risk":
      return "warning";
    case "breaching":
      return "danger";
    default:
      return "info";
  }
}

function assessmentTone(overall: SecurityAssessment["overall"]): "success" | "warning" | "danger" {
  return overall === "pass" ? "success" : overall === "warn" ? "warning" : "danger";
}

function findingTone(status: string): "success" | "warning" | "danger" | "info" {
  switch (status) {
    case "pass":
      return "success";
    case "warn":
      return "warning";
    case "fail":
      return "danger";
    default:
      return "info";
  }
}

function EnterpriseContent({ org }: { org: Organization }) {
  const [tab, setTab] = useState<Tab>("overview");
  const canManage = canManageEnterprise(org.current_user_role);
  const [planBlocked, setPlanBlocked] = useState(false);
  const [forbidden, setForbidden] = useState(false);

  // Overview
  const [overviewLoading, setOverviewLoading] = useState(true);
  const [overviewError, setOverviewError] = useState<string | null>(null);
  const [latestAssessment, setLatestAssessment] = useState<SecurityAssessment | null>(null);
  const [slos, setSlos] = useState<SloStatus[]>([]);
  const [recentAlerts, setRecentAlerts] = useState<EnterpriseAlert[]>([]);

  // Audit
  const [auditFormat, setAuditFormat] = useState<"csv" | "json">("csv");
  const [auditEntityType, setAuditEntityType] = useState("");
  const [auditAction, setAuditAction] = useState("");
  const [auditActorId, setAuditActorId] = useState("");
  const [auditDateFrom, setAuditDateFrom] = useState("");
  const [auditDateTo, setAuditDateTo] = useState("");
  const [auditRows, setAuditRows] = useState<AuditLog[]>([]);
  const [auditBusy, setAuditBusy] = useState(false);
  const [auditNote, setAuditNote] = useState<string | null>(null);
  const [auditError, setAuditError] = useState<string | null>(null);

  // Security policies
  const [policyLoading, setPolicyLoading] = useState(true);
  const [policyError, setPolicyError] = useState<string | null>(null);
  const [policy, setPolicy] = useState<SecurityPolicy | null>(null);
  const [policyMinLength, setPolicyMinLength] = useState("8");
  const [policyRequireNumbers, setPolicyRequireNumbers] = useState(false);
  const [policyRequireSymbols, setPolicyRequireSymbols] = useState(false);
  const [policySessionTimeout, setPolicySessionTimeout] = useState("120");
  const [policyMaxSessions, setPolicyMaxSessions] = useState("");
  const [policyIpRanges, setPolicyIpRanges] = useState("");
  const [policyRequireTwoFactor, setPolicyRequireTwoFactor] = useState(false);
  const [policyBusy, setPolicyBusy] = useState(false);
  const [policyNote, setPolicyNote] = useState<string | null>(null);
  const [policyFormError, setPolicyFormError] = useState<string | null>(null);
  const [checkPasswordValue, setCheckPasswordValue] = useState("");
  const [passwordResult, setPasswordResult] = useState<PasswordCheckResult | null>(null);
  const [passwordBusy, setPasswordBusy] = useState(false);
  const [checkIpValue, setCheckIpValue] = useState("");
  const [ipResult, setIpResult] = useState<IpCheckResult | null>(null);
  const [ipBusy, setIpBusy] = useState(false);

  // Retention
  const [retentionLoading, setRetentionLoading] = useState(true);
  const [retentionError, setRetentionError] = useState<string | null>(null);
  const [retention, setRetention] = useState<RetentionPreview | null>(null);
  const [purgeBusy, setPurgeBusy] = useState(false);
  const [purgeNote, setPurgeNote] = useState<string | null>(null);
  const [purgeError, setPurgeError] = useState<string | null>(null);

  // Disaster recovery
  const [drLoading, setDrLoading] = useState(true);
  const [drError, setDrError] = useState<string | null>(null);
  const [drStatus, setDrStatus] = useState<DisasterRecoveryStatus | null>(null);
  const [drills, setDrills] = useState<DisasterRecoveryDrill[]>([]);
  const [drillsMeta, setDrillsMeta] = useState<PaginatedMeta>(EMPTY_META);
  const [drillsPage, setDrillsPage] = useState(1);
  const [drillKind, setDrillKind] = useState<DisasterRecoveryDrillKind>("verify");
  const [drillBackupRunId, setDrillBackupRunId] = useState("");
  const [drillBusy, setDrillBusy] = useState(false);
  const [drillError, setDrillError] = useState<string | null>(null);

  // Monitoring
  const [monitoringLoading, setMonitoringLoading] = useState(true);
  const [monitoringError, setMonitoringError] = useState<string | null>(null);
  const [rules, setRules] = useState<AlertRule[]>([]);
  const [alerts, setAlerts] = useState<EnterpriseAlert[]>([]);
  const [alertsMeta, setAlertsMeta] = useState<PaginatedMeta>(EMPTY_META);
  const [alertsPage, setAlertsPage] = useState(1);
  const [alertStatusFilter, setAlertStatusFilter] = useState<EnterpriseAlertStatus | "">("");
  const [ruleName, setRuleName] = useState("");
  const [ruleMetric, setRuleMetric] = useState<AlertMetric>("queue_depth");
  const [ruleOperator, setRuleOperator] = useState<AlertOperator>("gt");
  const [ruleThreshold, setRuleThreshold] = useState("10");
  const [ruleWindow, setRuleWindow] = useState("15");
  const [ruleSeverity, setRuleSeverity] = useState<AlertSeverity>("warning");
  const [ruleEnabled, setRuleEnabled] = useState(true);
  const [editingRuleId, setEditingRuleId] = useState<string | null>(null);
  const [ruleBusy, setRuleBusy] = useState(false);
  const [ruleError, setRuleError] = useState<string | null>(null);
  const [ruleNote, setRuleNote] = useState<string | null>(null);
  const [evaluation, setEvaluation] = useState<AlertEvaluation | null>(null);

  // Load tests
  const [loadTestsLoading, setLoadTestsLoading] = useState(true);
  const [loadTestsError, setLoadTestsError] = useState<string | null>(null);
  const [loadTests, setLoadTests] = useState<LoadTestRun[]>([]);
  const [loadTestsMeta, setLoadTestsMeta] = useState<PaginatedMeta>(EMPTY_META);
  const [loadTestsPage, setLoadTestsPage] = useState(1);
  const [loadTestName, setLoadTestName] = useState("");
  const [loadTestRequests, setLoadTestRequests] = useState("10");
  const [loadTestTarget, setLoadTestTarget] = useState<LoadTestTarget>("health");
  const [loadTestBusy, setLoadTestBusy] = useState(false);
  const [loadTestError, setLoadTestError] = useState<string | null>(null);

  // Assessment
  const [assessmentLoading, setAssessmentLoading] = useState(true);
  const [assessmentError, setAssessmentError] = useState<string | null>(null);
  const [assessments, setAssessments] = useState<SecurityAssessment[]>([]);
  const [assessmentsMeta, setAssessmentsMeta] = useState<PaginatedMeta>(EMPTY_META);
  const [assessmentsPage, setAssessmentsPage] = useState(1);
  const [assessmentBusy, setAssessmentBusy] = useState(false);
  const [assessmentNote, setAssessmentNote] = useState<string | null>(null);

  // SLO editing
  const [editingSloKey, setEditingSloKey] = useState<SloKey | null>(null);
  const [sloTarget, setSloTarget] = useState("");
  const [sloWindow, setSloWindow] = useState("");
  const [sloEnabled, setSloEnabled] = useState(true);
  const [sloBusy, setSloBusy] = useState(false);
  const [sloError, setSloError] = useState<string | null>(null);

  function notePlanOrForbidden(err: unknown): void {
    if (isPlanError(err)) setPlanBlocked(true);
    if (isForbidden(err)) setForbidden(true);
  }

  async function loadOverview() {
    setOverviewLoading(true);
    setOverviewError(null);
    try {
      const [assessment, sloList, alertPage] = await Promise.all([
        getLatestAssessment(),
        listSlos(),
        listAlerts({ page: 1, perPage: 5 }),
      ]);
      setLatestAssessment(assessment);
      setSlos(sloList);
      setRecentAlerts(alertPage.data);
    } catch (err) {
      notePlanOrForbidden(err);
      setOverviewError(errorMessage(err, "Could not load the enterprise overview."));
    } finally {
      setOverviewLoading(false);
    }
  }

  async function loadSecurity() {
    setPolicyLoading(true);
    setPolicyError(null);
    try {
      const loaded = await getSecurityPolicy();
      setPolicy(loaded);
      setPolicyMinLength(String(loaded.password_min_length));
      setPolicyRequireNumbers(loaded.password_require_numbers);
      setPolicyRequireSymbols(loaded.password_require_symbols);
      setPolicySessionTimeout(String(loaded.session_timeout_minutes));
      setPolicyMaxSessions(loaded.max_concurrent_sessions === null ? "" : String(loaded.max_concurrent_sessions));
      setPolicyIpRanges(loaded.allowed_ip_ranges.join(", "));
      setPolicyRequireTwoFactor(loaded.require_two_factor);
    } catch (err) {
      notePlanOrForbidden(err);
      setPolicyError(errorMessage(err, "Could not load the security policy."));
    } finally {
      setPolicyLoading(false);
    }
  }

  async function loadRetention() {
    setRetentionLoading(true);
    setRetentionError(null);
    try {
      setRetention(await getRetentionPreview());
    } catch (err) {
      notePlanOrForbidden(err);
      setRetentionError(errorMessage(err, "Could not load the retention preview."));
    } finally {
      setRetentionLoading(false);
    }
  }

  async function loadDrills(page: number) {
    setDrLoading(true);
    setDrError(null);
    try {
      const [status, drillPage] = await Promise.all([
        getDisasterRecovery(),
        listDrills(page),
      ]);
      setDrStatus(status);
      setDrills(drillPage.data);
      setDrillsMeta(drillPage.meta);
    } catch (err) {
      notePlanOrForbidden(err);
      setDrError(errorMessage(err, "Could not load disaster recovery."));
    } finally {
      setDrLoading(false);
    }
  }

  async function loadMonitoring(page: number, status: EnterpriseAlertStatus | "") {
    setMonitoringLoading(true);
    setMonitoringError(null);
    try {
      const [ruleList, alertPage] = await Promise.all([
        listAlertRules(),
        listAlerts({ status, page, perPage: 15 }),
      ]);
      setRules(ruleList);
      setAlerts(alertPage.data);
      setAlertsMeta(alertPage.meta);
    } catch (err) {
      notePlanOrForbidden(err);
      setMonitoringError(errorMessage(err, "Could not load monitoring."));
    } finally {
      setMonitoringLoading(false);
    }
  }

  async function loadLoadTests(page: number) {
    setLoadTestsLoading(true);
    setLoadTestsError(null);
    try {
      const result = await listLoadTests(page);
      setLoadTests(result.data);
      setLoadTestsMeta(result.meta);
    } catch (err) {
      notePlanOrForbidden(err);
      setLoadTestsError(errorMessage(err, "Could not load load tests."));
    } finally {
      setLoadTestsLoading(false);
    }
  }

  async function loadAssessment(page: number) {
    setAssessmentLoading(true);
    setAssessmentError(null);
    try {
      const [latest, history] = await Promise.all([
        getLatestAssessment(),
        listAssessments(page),
      ]);
      setLatestAssessment(latest);
      setAssessments(history.data);
      setAssessmentsMeta(history.meta);
    } catch (err) {
      notePlanOrForbidden(err);
      setAssessmentError(errorMessage(err, "Could not load assessments."));
    } finally {
      setAssessmentLoading(false);
    }
  }

  useEffect(() => {
    let cancelled = false;
    if (tab === "overview") {
      void (async () => {
        if (cancelled) return;
        await loadOverview();
      })();
    } else if (tab === "security") {
      void loadSecurity();
    } else if (tab === "retention") {
      void loadRetention();
    } else if (tab === "disaster-recovery") {
      void loadDrills(drillsPage);
    } else if (tab === "monitoring") {
      void loadMonitoring(alertsPage, alertStatusFilter);
    } else if (tab === "load-tests") {
      void loadLoadTests(loadTestsPage);
    } else if (tab === "assessment") {
      void loadAssessment(assessmentsPage);
    }
    return () => {
      cancelled = true;
    };
    // Loads are per-tab; page/filter changes reload through the handlers below.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tab, org.id]);

  async function runAuditExport(event: FormEvent) {
    event.preventDefault();
    setAuditBusy(true);
    setAuditError(null);
    setAuditNote(null);
    try {
      const rows = await exportAuditLogs({
        format: auditFormat,
        entity_type: auditEntityType.trim() || undefined,
        action: auditAction.trim() || undefined,
        actor_id: auditActorId.trim() || undefined,
        date_from: auditDateFrom || undefined,
        date_to: auditDateTo || undefined,
      });
      if (auditFormat === "csv") {
        setAuditRows([]);
        setAuditNote("The CSV download has started.");
      } else {
        setAuditRows(rows);
        setAuditNote(`Returned ${rows.length} audit row${rows.length === 1 ? "" : "s"}.`);
      }
    } catch (err) {
      notePlanOrForbidden(err);
      setAuditError(errorMessage(err, "Could not export the audit log."));
    } finally {
      setAuditBusy(false);
    }
  }

  async function savePolicy(event: FormEvent) {
    event.preventDefault();
    const minLength = Number(policyMinLength);
    const sessionTimeout = Number(policySessionTimeout);
    if (!Number.isFinite(minLength) || minLength < 1) {
      setPolicyFormError("Minimum password length must be at least 1.");
      return;
    }
    if (!Number.isFinite(sessionTimeout) || sessionTimeout < 1) {
      setPolicyFormError("Session timeout must be at least 1 minute.");
      return;
    }
    setPolicyFormError(null);
    setPolicyNote(null);
    setPolicyBusy(true);
    try {
      const updated = await updateSecurityPolicy({
        password_min_length: minLength,
        password_require_numbers: policyRequireNumbers,
        password_require_symbols: policyRequireSymbols,
        session_timeout_minutes: sessionTimeout,
        max_concurrent_sessions: policyMaxSessions.trim() === "" ? null : Number(policyMaxSessions),
        allowed_ip_ranges: policyIpRanges
          .split(",")
          .map((range) => range.trim())
          .filter((range) => range.length > 0),
        require_two_factor: policyRequireTwoFactor,
      });
      setPolicy(updated);
      setPolicyNote("Security policy saved.");
    } catch (err) {
      notePlanOrForbidden(err);
      setPolicyFormError(errorMessage(err, "Could not save the security policy."));
    } finally {
      setPolicyBusy(false);
    }
  }

  async function runPasswordCheck(event: FormEvent) {
    event.preventDefault();
    if (!checkPasswordValue) return;
    setPasswordBusy(true);
    try {
      setPasswordResult(await checkPassword(checkPasswordValue));
    } catch (err) {
      notePlanOrForbidden(err);
      setPolicyFormError(errorMessage(err, "Could not check the password."));
    } finally {
      setPasswordBusy(false);
    }
  }

  async function runIpCheck(event: FormEvent) {
    event.preventDefault();
    if (!checkIpValue.trim()) return;
    setIpBusy(true);
    try {
      setIpResult(await checkIp(checkIpValue.trim()));
    } catch (err) {
      notePlanOrForbidden(err);
      setPolicyFormError(errorMessage(err, "Could not check the IP address."));
    } finally {
      setIpBusy(false);
    }
  }

  async function runPurge(dryRun: boolean) {
    if (!dryRun && !window.confirm("Run the retention purge for real? This deletes old rows and cannot be undone.")) {
      return;
    }
    setPurgeBusy(true);
    setPurgeError(null);
    setPurgeNote(null);
    try {
      const result = await purgeRetention(dryRun);
      const total = Object.values(result.counts).reduce((sum, count) => sum + count, 0);
      setPurgeNote(
        result.dry_run
          ? `Dry run: ${total} row${total === 1 ? "" : "s"} would be deleted. Nothing was deleted.`
          : `Purge complete: ${total} row${total === 1 ? "" : "s"} deleted.`,
      );
      await loadRetention();
    } catch (err) {
      notePlanOrForbidden(err);
      setPurgeError(errorMessage(err, "Could not run the retention purge."));
    } finally {
      setPurgeBusy(false);
    }
  }

  async function runDrill(event: FormEvent) {
    event.preventDefault();
    setDrillError(null);
    setDrillBusy(true);
    try {
      const created = await createDrill({
        kind: drillKind,
        backup_run_id: drillBackupRunId.trim() || undefined,
      });
      setDrills((current) => [created, ...current]);
      setDrillBackupRunId("");
      await loadDrills(1);
      setDrillsPage(1);
    } catch (err) {
      notePlanOrForbidden(err);
      setDrillError(errorMessage(err, "Could not run the drill."));
    } finally {
      setDrillBusy(false);
    }
  }

  function resetRuleForm() {
    setEditingRuleId(null);
    setRuleName("");
    setRuleMetric("queue_depth");
    setRuleOperator("gt");
    setRuleThreshold("10");
    setRuleWindow("15");
    setRuleSeverity("warning");
    setRuleEnabled(true);
  }

  function editRule(rule: AlertRule) {
    setEditingRuleId(rule.id);
    setRuleName(rule.name);
    setRuleMetric(rule.metric);
    setRuleOperator(rule.operator);
    setRuleThreshold(String(rule.threshold));
    setRuleWindow(String(rule.window_minutes));
    setRuleSeverity(rule.severity);
    setRuleEnabled(rule.enabled);
  }

  async function saveRule(event: FormEvent) {
    event.preventDefault();
    if (!ruleName.trim()) {
      setRuleError("Give the rule a name.");
      return;
    }
    const threshold = Number(ruleThreshold);
    const windowMinutes = Number(ruleWindow);
    if (!Number.isFinite(threshold)) {
      setRuleError("Threshold must be a number.");
      return;
    }
    if (!Number.isFinite(windowMinutes) || windowMinutes < 1) {
      setRuleError("Window must be at least 1 minute.");
      return;
    }
    setRuleError(null);
    setRuleNote(null);
    setEvaluation(null);
    setRuleBusy(true);
    try {
      const input = {
        name: ruleName.trim(),
        metric: ruleMetric,
        operator: ruleOperator,
        threshold,
        window_minutes: windowMinutes,
        enabled: ruleEnabled,
        severity: ruleSeverity,
      };
      if (editingRuleId) {
        const updated = await updateAlertRule(editingRuleId, input);
        setRules((current) => current.map((item) => (item.id === updated.id ? updated : item)));
        setRuleNote("Alert rule updated.");
      } else {
        const created = await createAlertRule(input);
        setRules((current) => [created, ...current]);
        setRuleNote("Alert rule created.");
      }
      resetRuleForm();
    } catch (err) {
      notePlanOrForbidden(err);
      setRuleError(errorMessage(err, "Could not save the alert rule."));
    } finally {
      setRuleBusy(false);
    }
  }

  async function removeRule(rule: AlertRule) {
    if (!window.confirm(`Delete the alert rule "${rule.name}"?`)) return;
    setRuleError(null);
    try {
      await deleteAlertRule(rule.id);
      setRules((current) => current.filter((item) => item.id !== rule.id));
    } catch (err) {
      notePlanOrForbidden(err);
      setRuleError(errorMessage(err, "Could not delete the alert rule."));
    }
  }

  async function runEvaluate(rule: AlertRule) {
    setRuleError(null);
    try {
      setEvaluation(await evaluateAlertRule(rule.id));
    } catch (err) {
      notePlanOrForbidden(err);
      setRuleError(errorMessage(err, "Could not evaluate the alert rule."));
    }
  }

  async function changeAlertStatus(alert: EnterpriseAlert, action: "acknowledge" | "resolve") {
    setMonitoringError(null);
    try {
      const updated = action === "acknowledge" ? await acknowledgeAlert(alert.id) : await resolveAlert(alert.id);
      setAlerts((current) => current.map((item) => (item.id === updated.id ? updated : item)));
      setRecentAlerts((current) => current.map((item) => (item.id === updated.id ? updated : item)));
    } catch (err) {
      notePlanOrForbidden(err);
      setMonitoringError(errorMessage(err, `Could not ${action} the alert.`));
    }
  }

  async function runLoadTest(event: FormEvent) {
    event.preventDefault();
    const requests = Number(loadTestRequests);
    if (!Number.isInteger(requests) || requests < 1 || requests > 200) {
      setLoadTestError("Requests must be a whole number between 1 and 200.");
      return;
    }
    setLoadTestError(null);
    setLoadTestBusy(true);
    try {
      const created = await createLoadTest({
        name: loadTestName.trim() || undefined,
        requests,
        target: loadTestTarget,
      });
      setLoadTests((current) => [created, ...current]);
      setLoadTestName("");
      await loadLoadTests(1);
      setLoadTestsPage(1);
    } catch (err) {
      notePlanOrForbidden(err);
      setLoadTestError(errorMessage(err, "Could not run the load test."));
    } finally {
      setLoadTestBusy(false);
    }
  }

  async function generateAssessment() {
    setAssessmentBusy(true);
    setAssessmentNote(null);
    setAssessmentError(null);
    try {
      const created = await createAssessment();
      setLatestAssessment(created);
      setAssessments((current) => [created, ...current]);
      setAssessmentNote("Assessment generated.");
    } catch (err) {
      notePlanOrForbidden(err);
      setAssessmentError(errorMessage(err, "Could not generate the assessment."));
    } finally {
      setAssessmentBusy(false);
    }
  }

  function startSloEdit(slo: SloStatus) {
    setEditingSloKey(slo.key);
    setSloTarget(String(slo.target_value));
    setSloWindow(String(slo.window_days));
    setSloEnabled(slo.enabled);
    setSloError(null);
  }

  async function saveSlo(event: FormEvent) {
    event.preventDefault();
    if (!editingSloKey) return;
    const target = Number(sloTarget);
    const windowDays = Number(sloWindow);
    if (!Number.isFinite(target) || !Number.isFinite(windowDays) || windowDays < 1) {
      setSloError("Target and window must be numbers, and the window at least 1 day.");
      return;
    }
    setSloError(null);
    setSloBusy(true);
    try {
      const updated = await updateSlo(editingSloKey, {
        target_value: target,
        window_days: windowDays,
        enabled: sloEnabled,
      });
      setSlos((current) => current.map((item) => (item.key === updated.key ? updated : item)));
      setEditingSloKey(null);
    } catch (err) {
      notePlanOrForbidden(err);
      setSloError(errorMessage(err, "Could not save the SLO."));
    } finally {
      setSloBusy(false);
    }
  }

  function alertButtons(alert: EnterpriseAlert) {
    return (
      <>
        {alert.status === "active" && (
          <Button variant="secondary" disabled={!canManage} onClick={() => changeAlertStatus(alert, "acknowledge")}>
            Acknowledge
          </Button>
        )}{" "}
        {alert.status !== "resolved" && (
          <Button variant="secondary" disabled={!canManage} onClick={() => changeAlertStatus(alert, "resolve")}>
            Resolve
          </Button>
        )}
      </>
    );
  }

  return (
    <div>
      <div style={{ display: "flex", flexWrap: "wrap", gap: 8, marginBottom: 16 }}>
        {TABS.map((item) => (
          <Button
            key={item.value}
            variant={tab === item.value ? "primary" : "secondary"}
            onClick={() => setTab(item.value)}
          >
            {item.label}
          </Button>
        ))}
      </div>

      {planBlocked && (
        <Alert tone="info">
          Enterprise tools need the Growth plan or better. Upgrade the plan to use them.
        </Alert>
      )}
      {forbidden && !planBlocked && (
        <Alert tone="warning">
          You do not have the manage-enterprise permission for this organization.
        </Alert>
      )}
      {!canManage && (
        <Alert tone="info">
          Enterprise tools are managed by owners, admins, and operations managers. You can read
          this page, but management actions are disabled for your role.
        </Alert>
      )}

      {tab === "overview" && (
        <div>
          {overviewError && <Alert tone="error">{overviewError}</Alert>}
          {overviewLoading ? (
            <Spinner label="Loading enterprise overview" />
          ) : (
            <>
              <Card>
                <CardHeader title="Latest security assessment" />
                <CardBody>
                  {latestAssessment ? (
                    <div>
                      <p>
                        Overall:{" "}
                        <Badge tone={assessmentTone(latestAssessment.overall)}>
                          {latestAssessment.overall}
                        </Badge>{" "}
                        {latestAssessment.passed} passed, {latestAssessment.warnings} warnings,{" "}
                        {latestAssessment.failures} failures of {latestAssessment.total_checks}{" "}
                        checks, {latestAssessment.open_findings} open findings.
                      </p>
                      <p>Generated {formatDate(latestAssessment.generated_at)}</p>
                    </div>
                  ) : (
                    <EmptyState
                      title="No assessment yet"
                      description="Generate a security assessment from the Assessment tab."
                    />
                  )}
                </CardBody>
              </Card>
              <Card>
                <CardHeader title="Service level objectives" />
                <CardBody>
                  {slos.length === 0 ? (
                    <EmptyState title="No SLOs" description="No service level objectives are configured." />
                  ) : (
                    <>
                      <Table headers={["SLO", "Target", "Measured", "Samples", "Status", "Actions"]}>
                        {slos.map((slo) => (
                          <tr key={slo.key} className="border-b border-slate-100 last:border-0">
                            <TableCell>{slo.name}</TableCell>
                            <TableCell>
                              {slo.target_value}
                              {slo.target_unit} over {slo.window_days} days
                              {!slo.enabled && " (disabled)"}
                            </TableCell>
                            <TableCell>
                              {slo.measured_value === null
                                ? "—"
                                : `${slo.measured_value}${slo.target_unit}`}
                            </TableCell>
                            <TableCell>{String(slo.sample_size)}</TableCell>
                            <TableCell>
                              <Badge tone={sloTone(slo.status)}>{slo.status}</Badge>
                            </TableCell>
                            <TableCell>
                              <Button variant="secondary" disabled={!canManage} onClick={() => startSloEdit(slo)}>
                                Edit
                              </Button>
                            </TableCell>
                          </tr>
                        ))}
                      </Table>
                      {sloError && <Alert tone="error">{sloError}</Alert>}
                      {editingSloKey && (
                        <form onSubmit={saveSlo} style={{ marginTop: 16 }}>
                          <Input
                            id="slo-target"
                            label="Target value"
                            type="number"
                            step="any"
                            value={sloTarget}
                            onChange={(event) => setSloTarget(event.target.value)}
                          />
                          <Input
                            id="slo-window"
                            label="Window (days)"
                            type="number"
                            value={sloWindow}
                            onChange={(event) => setSloWindow(event.target.value)}
                          />
                          <label style={{ display: "block" }}>
                            <input
                              type="checkbox"
                              checked={sloEnabled}
                              onChange={(event) => setSloEnabled(event.target.checked)}
                            />{" "}
                            Enabled
                          </label>
                          <Button type="submit" disabled={sloBusy || !canManage}>
                            {sloBusy ? "Saving..." : "Save SLO"}
                          </Button>{" "}
                          <Button variant="secondary" onClick={() => setEditingSloKey(null)}>
                            Cancel
                          </Button>
                        </form>
                      )}
                    </>
                  )}
                </CardBody>
              </Card>
              <Card>
                <CardHeader title="Recent alerts" />
                <CardBody>
                  {recentAlerts.length === 0 ? (
                    <EmptyState title="No recent alerts" description="No alerts have fired recently." />
                  ) : (
                    <Table headers={["Metric", "Observed", "Threshold", "Severity", "Status", "Triggered", "Actions"]}>
                      {recentAlerts.map((alert) => (
                        <tr key={alert.id} className="border-b border-slate-100 last:border-0">
                          <TableCell>{alert.metric}</TableCell>
                          <TableCell>{String(alert.observed_value)}</TableCell>
                          <TableCell>{String(alert.threshold)}</TableCell>
                          <TableCell>
                            <Badge tone={alert.severity === "critical" ? "danger" : "warning"}>
                              {alert.severity}
                            </Badge>
                          </TableCell>
                          <TableCell>{alert.status}</TableCell>
                          <TableCell>{formatDate(alert.triggered_at)}</TableCell>
                          <TableCell>{alertButtons(alert)}</TableCell>
                        </tr>
                      ))}
                    </Table>
                  )}
                </CardBody>
              </Card>
            </>
          )}
        </div>
      )}

      {tab === "audit" && (
        <div>
          <Alert tone="info">
            The audit trail is append-only. Exporting never deletes anything; the retention purge
            on the Retention tab is the only deletion path.
          </Alert>
          {auditNote && <Alert tone="success">{auditNote}</Alert>}
          {auditError && <Alert tone="error">{auditError}</Alert>}
          <Card>
            <CardHeader title="Export audit logs" />
            <CardBody>
              <form onSubmit={runAuditExport}>
                <Select
                  id="audit-format"
                  label="Export format"
                  value={auditFormat}
                  onChange={(event) => setAuditFormat(event.target.value as "csv" | "json")}
                >
                  <option value="csv">CSV (file download)</option>
                  <option value="json">JSON (shown below)</option>
                </Select>
                <Input id="audit-entity-type" label="Entity type" value={auditEntityType} onChange={(event) => setAuditEntityType(event.target.value)} placeholder="e.g. claim" />
                <Input id="audit-action" label="Action" value={auditAction} onChange={(event) => setAuditAction(event.target.value)} placeholder="e.g. claim.created" />
                <Input id="audit-actor-id" label="Actor ID" value={auditActorId} onChange={(event) => setAuditActorId(event.target.value)} placeholder="User ID" />
                <Input id="audit-date-from" label="Date from" type="date" value={auditDateFrom} onChange={(event) => setAuditDateFrom(event.target.value)} />
                <Input id="audit-date-to" label="Date to" type="date" value={auditDateTo} onChange={(event) => setAuditDateTo(event.target.value)} />
                <Button type="submit" disabled={auditBusy || !canManage}>
                  {auditBusy ? "Exporting..." : auditFormat === "csv" ? "Download CSV" : "Fetch JSON"}
                </Button>
              </form>
            </CardBody>
          </Card>
          {auditFormat === "json" &&
            (auditRows.length === 0 ? (
              <EmptyState title="No audit rows" description="Run a JSON export to see matching audit rows here." />
            ) : (
              <Card>
                <CardHeader title="Audit rows" />
                <CardBody>
                  <Table headers={["Action", "Entity", "Actor", "Created"]}>
                    {auditRows.map((row) => (
                      <tr key={row.id} className="border-b border-slate-100 last:border-0">
                        <TableCell>{row.action}</TableCell>
                        <TableCell>
                          {row.entity_type} ({row.entity_id})
                        </TableCell>
                        <TableCell>{row.actor ? row.actor.name : "System"}</TableCell>
                        <TableCell>{formatDate(row.created_at)}</TableCell>
                      </tr>
                    ))}
                  </Table>
                </CardBody>
              </Card>
            ))}
        </div>
      )}

      {tab === "security" && (
        <div>
          <Alert tone="info">
            Password rules are enforced on password change. IP ranges are check-only, not enforced
            at the edge. Two-factor is recorded policy only; no two-factor authentication exists
            yet.
          </Alert>
          {policyNote && <Alert tone="success">{policyNote}</Alert>}
          {policyError && <Alert tone="error">{policyError}</Alert>}
          {policyFormError && <Alert tone="error">{policyFormError}</Alert>}
          {policyLoading ? (
            <Spinner label="Loading security policy" />
          ) : (
            <>
              <Card>
                <CardHeader title="Security policy" description={policy?.is_default ? "No custom policy saved yet; showing defaults." : undefined} />
                <CardBody>
                  <form onSubmit={savePolicy}>
                    <Input id="policy-min-length" label="Minimum password length" type="number" value={policyMinLength} onChange={(event) => setPolicyMinLength(event.target.value)} />
                    <label style={{ display: "block" }}>
                      <input type="checkbox" checked={policyRequireNumbers} onChange={(event) => setPolicyRequireNumbers(event.target.checked)} />{" "}
                      Require numbers in passwords
                    </label>
                    <label style={{ display: "block" }}>
                      <input type="checkbox" checked={policyRequireSymbols} onChange={(event) => setPolicyRequireSymbols(event.target.checked)} />{" "}
                      Require symbols in passwords
                    </label>
                    <Input id="policy-session-timeout" label="Session timeout (minutes)" type="number" value={policySessionTimeout} onChange={(event) => setPolicySessionTimeout(event.target.value)} />
                    <Input id="policy-max-sessions" label="Maximum concurrent sessions" type="number" value={policyMaxSessions} onChange={(event) => setPolicyMaxSessions(event.target.value)} placeholder="Leave blank for no limit" />
                    <Input id="policy-ip-ranges" label="Allowed IP ranges" value={policyIpRanges} onChange={(event) => setPolicyIpRanges(event.target.value)} placeholder="e.g. 203.0.113.0/24, 198.51.100.5" help="Comma-separated CIDR ranges. Check-only; not enforced at the edge." />
                    <label style={{ display: "block" }}>
                      <input type="checkbox" checked={policyRequireTwoFactor} onChange={(event) => setPolicyRequireTwoFactor(event.target.checked)} />{" "}
                      Require two-factor authentication (recorded policy only)
                    </label>
                    <Button type="submit" disabled={policyBusy || !canManage}>
                      {policyBusy ? "Saving..." : "Save policy"}
                    </Button>
                  </form>
                </CardBody>
              </Card>
              <Card>
                <CardHeader title="Check a password" />
                <CardBody>
                  <form onSubmit={runPasswordCheck}>
                    <Input id="check-password" label="Password" type="password" value={checkPasswordValue} onChange={(event) => setCheckPasswordValue(event.target.value)} placeholder="Password to check" />
                    <Button type="submit" disabled={passwordBusy || !checkPasswordValue}>
                      {passwordBusy ? "Checking..." : "Check password"}
                    </Button>
                  </form>
                  {passwordResult && (
                    <div style={{ marginTop: 12 }}>
                      {passwordResult.valid ? (
                        <Alert tone="success">This password meets the current policy.</Alert>
                      ) : (
                        <Alert tone="error">
                          This password does not meet the current policy:
                          <ul>
                            {passwordResult.errors.map((error) => (
                              <li key={error}>{error}</li>
                            ))}
                          </ul>
                        </Alert>
                      )}
                    </div>
                  )}
                </CardBody>
              </Card>
              <Card>
                <CardHeader title="Check an IP address" />
                <CardBody>
                  <form onSubmit={runIpCheck}>
                    <Input id="check-ip" label="IP address" value={checkIpValue} onChange={(event) => setCheckIpValue(event.target.value)} placeholder="e.g. 203.0.113.42" />
                    <Button type="submit" disabled={ipBusy || !checkIpValue.trim()}>
                      {ipBusy ? "Checking..." : "Check IP"}
                    </Button>
                  </form>
                  {ipResult && (
                    <div style={{ marginTop: 12 }}>
                      <Alert tone={ipResult.allowed ? "success" : "warning"}>
                        {ipResult.ip} is {ipResult.allowed ? "allowed" : "not allowed"}
                        {ipResult.in_range === null
                          ? " (no IP ranges are configured, so nothing is restricted)"
                          : ipResult.in_range
                            ? " (inside a configured range)"
                            : " (outside the configured ranges)"}
                        . This is a check only; IP ranges are not enforced at the edge.
                      </Alert>
                    </div>
                  )}
                </CardBody>
              </Card>
            </>
          )}
        </div>
      )}

      {tab === "retention" && (
        <div>
          {purgeNote && <Alert tone="success">{purgeNote}</Alert>}
          {purgeError && <Alert tone="error">{purgeError}</Alert>}
          {retentionError && <Alert tone="error">{retentionError}</Alert>}
          {retentionLoading ? (
            <Spinner label="Loading retention preview" />
          ) : retention ? (
            <>
              <Card>
                <CardHeader title="Effective retention settings" description="Days kept per kind. 0 keeps forever." />
                <CardBody>
                  <Table headers={["Kind", "Effective (days)", "Default (days)", "Override (days)"]}>
                    {RETENTION_KINDS.map((kind) => (
                      <tr key={kind} className="border-b border-slate-100 last:border-0">
                        <TableCell>{RETENTION_KIND_LABELS[kind]}</TableCell>
                        <TableCell>{String(retention.effective[kind])}</TableCell>
                        <TableCell>{String(retention.defaults[kind])}</TableCell>
                        <TableCell>
                          {retention.overrides[kind] === undefined ? "—" : String(retention.overrides[kind])}
                        </TableCell>
                      </tr>
                    ))}
                  </Table>
                  <p>Preview generated {formatDate(retention.generated_at)}</p>
                </CardBody>
              </Card>
              <Card>
                <CardHeader title="What a purge would delete" />
                <CardBody>
                  <Table headers={["Kind", "Rows older than cutoff", "Cutoff"]}>
                    {RETENTION_KINDS.map((kind) => (
                      <tr key={kind} className="border-b border-slate-100 last:border-0">
                        <TableCell>{RETENTION_KIND_LABELS[kind]}</TableCell>
                        <TableCell>{String(retention.preview[kind]?.count ?? 0)}</TableCell>
                        <TableCell>{retention.preview[kind]?.cutoff ? formatDate(retention.preview[kind].cutoff) : "—"}</TableCell>
                      </tr>
                    ))}
                  </Table>
                </CardBody>
              </Card>
              <Card>
                <CardHeader title="Run the purge" description="The retention purge is the only way audit rows are deleted. Always dry-run first." />
                <CardBody>
                  <Button variant="secondary" disabled={purgeBusy || !canManage} onClick={() => runPurge(true)}>
                    {purgeBusy ? "Running..." : "Purge (dry run)"}
                  </Button>{" "}
                  <Button variant="danger" disabled={purgeBusy || !canManage} onClick={() => runPurge(false)}>
                    Run purge
                  </Button>
                </CardBody>
              </Card>
            </>
          ) : (
            <EmptyState title="No retention preview" description="Retention settings could not be loaded." />
          )}
        </div>
      )}

      {tab === "disaster-recovery" && (
        <div>
          <Alert tone="info">
            There is no real failover. Drills only verify a backup or dry-run a restore; the app
            keeps serving from the same database until someone restores it by hand.
          </Alert>
          {drError && <Alert tone="error">{drError}</Alert>}
          {drillError && <Alert tone="error">{drillError}</Alert>}
          {drLoading ? (
            <Spinner label="Loading disaster recovery" />
          ) : (
            <>
              <Card>
                <CardHeader title="Recovery objectives and backups" />
                <CardBody>
                  {drStatus ? (
                    <div>
                      <p>
                        Recovery point objective: {drStatus.rpo_minutes} minutes. Recovery time
                        objective: {drStatus.rto_minutes} minutes.
                      </p>
                      <p>
                        Latest backup:{" "}
                        {drStatus.latest_backup
                          ? `${drStatus.latest_backup.id} (${drStatus.latest_backup.status}, ${formatDate(drStatus.latest_backup.created_at)})`
                          : "None yet"}
                      </p>
                      <p>
                        Last verified backup:{" "}
                        {drStatus.last_verified_backup
                          ? `${drStatus.last_verified_backup.id} (${drStatus.last_verified_backup.ok ? "verified" : "verification failed"}, ${formatDate(drStatus.last_verified_backup.verified_at)})`
                          : "None verified yet"}
                      </p>
                      <p>
                        Latest drill:{" "}
                        {drStatus.latest_drill
                          ? `${drStatus.latest_drill.kind} (${drStatus.latest_drill.status}, ${formatDate(drStatus.latest_drill.performed_at)})`
                          : "No drills yet"}{" "}
                        ({drStatus.total_drills} total)
                      </p>
                    </div>
                  ) : (
                    <EmptyState title="No disaster recovery data" description="Recovery objectives and backups could not be loaded." />
                  )}
                </CardBody>
              </Card>
              {canManage && (
                <Card>
                  <CardHeader title="Run a drill" />
                  <CardBody>
                    <form onSubmit={runDrill}>
                      <Select
                        id="drill-kind"
                        label="Drill kind"
                        value={drillKind}
                        onChange={(event) => setDrillKind(event.target.value as DisasterRecoveryDrillKind)}
                      >
                        {DRILL_KINDS.map((kind) => (
                          <option key={kind.value} value={kind.value}>
                            {kind.label}
                          </option>
                        ))}
                      </Select>
                      <Input id="drill-backup-run-id" label="Backup run ID" value={drillBackupRunId} onChange={(event) => setDrillBackupRunId(event.target.value)} placeholder="Leave blank to use the latest backup" />
                      <Button type="submit" disabled={drillBusy}>
                        {drillBusy ? "Running..." : "Run drill"}
                      </Button>
                    </form>
                  </CardBody>
                </Card>
              )}
              <Card>
                <CardHeader title="Drill history" />
                <CardBody>
                  {drills.length === 0 ? (
                    <EmptyState title="No drills yet" description="Run a drill to verify or dry-run restore a backup." />
                  ) : (
                    <>
                      <Table headers={["Kind", "Status", "Performed by", "Performed at"]}>
                        {drills.map((drill) => (
                          <tr key={drill.id} className="border-b border-slate-100 last:border-0">
                            <TableCell>{drill.kind}</TableCell>
                            <TableCell>
                              <Badge tone={drill.status === "completed" ? "success" : "danger"}>
                                {drill.status}
                              </Badge>
                            </TableCell>
                            <TableCell>{drill.performed_by ? drill.performed_by.name : "—"}</TableCell>
                            <TableCell>{formatDate(drill.performed_at)}</TableCell>
                          </tr>
                        ))}
                      </Table>
                      <div style={{ display: "flex", gap: 8, marginTop: 12 }}>
                        <Button
                          variant="secondary"
                          disabled={drillsMeta.current_page <= 1}
                          onClick={() => {
                            setDrillsPage(drillsMeta.current_page - 1);
                            void loadDrills(drillsMeta.current_page - 1);
                          }}
                        >
                          Previous
                        </Button>
                        <Button
                          variant="secondary"
                          disabled={drillsMeta.current_page >= drillsMeta.last_page}
                          onClick={() => {
                            setDrillsPage(drillsMeta.current_page + 1);
                            void loadDrills(drillsMeta.current_page + 1);
                          }}
                        >
                          Next
                        </Button>
                        <span>
                          Page {drillsMeta.current_page} of {drillsMeta.last_page} ({drillsMeta.total} total)
                        </span>
                      </div>
                    </>
                  )}
                </CardBody>
              </Card>
            </>
          )}
        </div>
      )}

      {tab === "monitoring" && (
        <div>
          {monitoringError && <Alert tone="error">{monitoringError}</Alert>}
          {ruleNote && <Alert tone="success">{ruleNote}</Alert>}
          {ruleError && <Alert tone="error">{ruleError}</Alert>}
          {evaluation && (
            <Alert tone={evaluation.breached ? "warning" : "info"}>
              {evaluation.metric}: observed{" "}
              {evaluation.observed_value === null ? "—" : String(evaluation.observed_value)} —{" "}
              {evaluation.breached ? "threshold breached." : "within threshold."}
              {evaluation.alert ? ` Alert ${evaluation.alert.id} ${evaluation.alert.status}.` : ""}
            </Alert>
          )}
          {monitoringLoading ? (
            <Spinner label="Loading monitoring" />
          ) : (
            <>
              <Card>
                <CardHeader title="Alert rules" />
                <CardBody>
                  {rules.length === 0 ? (
                    <EmptyState title="No alert rules yet" description="Create a rule to watch queue depth, failed jobs, error rate, disk usage, or backup age." />
                  ) : (
                    <Table headers={["Name", "Metric", "Condition", "Window", "Severity", "Enabled", "Actions"]}>
                      {rules.map((rule) => (
                        <tr key={rule.id} className="border-b border-slate-100 last:border-0">
                          <TableCell>{rule.name}</TableCell>
                          <TableCell>{rule.metric}</TableCell>
                          <TableCell>
                            {rule.operator === "gt" ? ">" : ">="} {rule.threshold}
                          </TableCell>
                          <TableCell>{rule.window_minutes} min</TableCell>
                          <TableCell>
                            <Badge tone={rule.severity === "critical" ? "danger" : "warning"}>
                              {rule.severity}
                            </Badge>
                          </TableCell>
                          <TableCell>{rule.enabled ? "Yes" : "No"}</TableCell>
                          <TableCell>
                            <Button variant="secondary" disabled={!canManage} onClick={() => runEvaluate(rule)}>
                              Evaluate
                            </Button>{" "}
                            <Button variant="secondary" disabled={!canManage} onClick={() => editRule(rule)}>
                              Edit
                            </Button>{" "}
                            <Button variant="danger" disabled={!canManage} onClick={() => removeRule(rule)}>
                              Delete
                            </Button>
                          </TableCell>
                        </tr>
                      ))}
                    </Table>
                  )}
                </CardBody>
              </Card>
              {canManage && (
                <Card>
                  <CardHeader title={editingRuleId ? "Edit alert rule" : "Create an alert rule"} />
                  <CardBody>
                    <form onSubmit={saveRule}>
                      <Input id="rule-name" label="Rule name" value={ruleName} onChange={(event) => setRuleName(event.target.value)} placeholder="e.g. Queue backing up" />
                      <Select
                        id="rule-metric"
                        label="Metric"
                        value={ruleMetric}
                        onChange={(event) => setRuleMetric(event.target.value as AlertMetric)}
                      >
                        {ALERT_METRICS.map((metric) => (
                          <option key={metric.value} value={metric.value}>
                            {metric.label}
                          </option>
                        ))}
                      </Select>
                      <Select
                        id="rule-operator"
                        label="Operator"
                        value={ruleOperator}
                        onChange={(event) => setRuleOperator(event.target.value as AlertOperator)}
                      >
                        {ALERT_OPERATORS.map((operator) => (
                          <option key={operator.value} value={operator.value}>
                            {operator.label}
                          </option>
                        ))}
                      </Select>
                      <Input id="rule-threshold" label="Threshold" type="number" step="any" value={ruleThreshold} onChange={(event) => setRuleThreshold(event.target.value)} />
                      <Input id="rule-window" label="Window (minutes)" type="number" value={ruleWindow} onChange={(event) => setRuleWindow(event.target.value)} />
                      <Select
                        id="rule-severity"
                        label="Severity"
                        value={ruleSeverity}
                        onChange={(event) => setRuleSeverity(event.target.value as AlertSeverity)}
                      >
                        {ALERT_SEVERITIES.map((severity) => (
                          <option key={severity.value} value={severity.value}>
                            {severity.label}
                          </option>
                        ))}
                      </Select>
                      <label style={{ display: "block" }}>
                        <input type="checkbox" checked={ruleEnabled} onChange={(event) => setRuleEnabled(event.target.checked)} />{" "}
                        Enabled
                      </label>
                      <Button type="submit" disabled={ruleBusy}>
                        {ruleBusy ? "Saving..." : editingRuleId ? "Update rule" : "Create rule"}
                      </Button>{" "}
                      {editingRuleId && (
                        <Button variant="secondary" onClick={resetRuleForm}>
                          Cancel edit
                        </Button>
                      )}
                    </form>
                  </CardBody>
                </Card>
              )}
              <Card>
                <CardHeader title="Alerts" />
                <CardBody>
                  <Select
                    id="alert-status-filter"
                    label="Status filter"
                    value={alertStatusFilter}
                    onChange={(event) => {
                      const status = event.target.value as EnterpriseAlertStatus | "";
                      setAlertStatusFilter(status);
                      setAlertsPage(1);
                      void loadMonitoring(1, status);
                    }}
                  >
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="acknowledged">Acknowledged</option>
                    <option value="resolved">Resolved</option>
                  </Select>
                  {alerts.length === 0 ? (
                    <EmptyState title="No alerts" description="No alerts match this filter." />
                  ) : (
                    <>
                      <Table headers={["Metric", "Observed", "Threshold", "Severity", "Status", "Message", "Triggered", "Actions"]}>
                        {alerts.map((alert) => (
                          <tr key={alert.id} className="border-b border-slate-100 last:border-0">
                            <TableCell>{alert.metric}</TableCell>
                            <TableCell>{String(alert.observed_value)}</TableCell>
                            <TableCell>{String(alert.threshold)}</TableCell>
                            <TableCell>
                              <Badge tone={alert.severity === "critical" ? "danger" : "warning"}>
                                {alert.severity}
                              </Badge>
                            </TableCell>
                            <TableCell>{alert.status}</TableCell>
                            <TableCell>{alert.message}</TableCell>
                            <TableCell>{formatDate(alert.triggered_at)}</TableCell>
                            <TableCell>{alertButtons(alert)}</TableCell>
                          </tr>
                        ))}
                      </Table>
                      <div style={{ display: "flex", gap: 8, marginTop: 12 }}>
                        <Button
                          variant="secondary"
                          disabled={alertsMeta.current_page <= 1}
                          onClick={() => {
                            setAlertsPage(alertsMeta.current_page - 1);
                            void loadMonitoring(alertsMeta.current_page - 1, alertStatusFilter);
                          }}
                        >
                          Previous
                        </Button>
                        <Button
                          variant="secondary"
                          disabled={alertsMeta.current_page >= alertsMeta.last_page}
                          onClick={() => {
                            setAlertsPage(alertsMeta.current_page + 1);
                            void loadMonitoring(alertsMeta.current_page + 1, alertStatusFilter);
                          }}
                        >
                          Next
                        </Button>
                        <span>
                          Page {alertsMeta.current_page} of {alertsMeta.last_page} ({alertsMeta.total} total)
                        </span>
                      </div>
                    </>
                  )}
                </CardBody>
              </Card>
            </>
          )}
        </div>
      )}

      {tab === "load-tests" && (
        <div>
          {loadTestError && <Alert tone="error">{loadTestError}</Alert>}
          {loadTestsError && <Alert tone="error">{loadTestsError}</Alert>}
          {canManage && (
            <Card>
              <CardHeader title="Run a load test" description="A short synthetic burst against the API, 1 to 200 requests." />
              <CardBody>
                <form onSubmit={runLoadTest}>
                  <Input id="load-test-name" label="Name" value={loadTestName} onChange={(event) => setLoadTestName(event.target.value)} placeholder="Optional, e.g. Morning smoke" />
                  <Input id="load-test-requests" label="Requests" type="number" value={loadTestRequests} onChange={(event) => setLoadTestRequests(event.target.value)} help="Whole number, 1 to 200." />
                  <Select
                    id="load-test-target"
                    label="Target"
                    value={loadTestTarget}
                    onChange={(event) => setLoadTestTarget(event.target.value as LoadTestTarget)}
                  >
                    {LOAD_TEST_TARGETS.map((target) => (
                      <option key={target.value} value={target.value}>
                        {target.label}
                      </option>
                    ))}
                  </Select>
                  <Button type="submit" disabled={loadTestBusy}>
                    {loadTestBusy ? "Running..." : "Run load test"}
                  </Button>
                </form>
              </CardBody>
            </Card>
          )}
          {loadTestsLoading ? (
            <Spinner label="Loading load tests" />
          ) : loadTests.length === 0 ? (
            <EmptyState title="No load tests yet" description="Run a load test to see timing and success rates here." />
          ) : (
            <Card>
              <CardHeader title="Load test history" />
              <CardBody>
                <Table headers={["Name", "Target", "Requests", "Status", "Success", "Errors", "Avg ms", "p95 ms", "Req/s", "Started"]}>
                  {loadTests.map((run) => (
                    <tr key={run.id} className="border-b border-slate-100 last:border-0">
                      <TableCell>{run.name ?? "—"}</TableCell>
                      <TableCell>{run.target}</TableCell>
                      <TableCell>{String(run.total_requests)}</TableCell>
                      <TableCell>
                        <Badge tone={run.status === "completed" ? "success" : "danger"}>{run.status}</Badge>
                      </TableCell>
                      <TableCell>{String(run.success_count)}</TableCell>
                      <TableCell>{String(run.error_count)}</TableCell>
                      <TableCell>{String(run.avg_ms)}</TableCell>
                      <TableCell>{String(run.p95_ms)}</TableCell>
                      <TableCell>{String(run.requests_per_second)}</TableCell>
                      <TableCell>{formatDate(run.started_at)}</TableCell>
                    </tr>
                  ))}
                </Table>
                <div style={{ display: "flex", gap: 8, marginTop: 12 }}>
                  <Button
                    variant="secondary"
                    disabled={loadTestsMeta.current_page <= 1}
                    onClick={() => {
                      setLoadTestsPage(loadTestsMeta.current_page - 1);
                      void loadLoadTests(loadTestsMeta.current_page - 1);
                    }}
                  >
                    Previous
                  </Button>
                  <Button
                    variant="secondary"
                    disabled={loadTestsMeta.current_page >= loadTestsMeta.last_page}
                    onClick={() => {
                      setLoadTestsPage(loadTestsMeta.current_page + 1);
                      void loadLoadTests(loadTestsMeta.current_page + 1);
                    }}
                  >
                    Next
                  </Button>
                  <span>
                    Page {loadTestsMeta.current_page} of {loadTestsMeta.last_page} ({loadTestsMeta.total} total)
                  </span>
                </div>
              </CardBody>
            </Card>
          )}
        </div>
      )}

      {tab === "assessment" && (
        <div>
          {assessmentNote && <Alert tone="success">{assessmentNote}</Alert>}
          {assessmentError && <Alert tone="error">{assessmentError}</Alert>}
          {canManage && (
            <Card>
              <CardHeader title="Generate an assessment" description="Runs the security checks again and records a new assessment." />
              <CardBody>
                <Button disabled={assessmentBusy} onClick={generateAssessment}>
                  {assessmentBusy ? "Generating..." : "Generate assessment"}
                </Button>
              </CardBody>
            </Card>
          )}
          {assessmentLoading ? (
            <Spinner label="Loading assessments" />
          ) : (
            <>
              <Card>
                <CardHeader title="Latest assessment findings" />
                <CardBody>
                  {latestAssessment ? (
                    <>
                      <p>
                        Overall:{" "}
                        <Badge tone={assessmentTone(latestAssessment.overall)}>
                          {latestAssessment.overall}
                        </Badge>{" "}
                        {latestAssessment.passed} passed, {latestAssessment.warnings} warnings,{" "}
                        {latestAssessment.failures} failures of {latestAssessment.total_checks}{" "}
                        checks. Generated {formatDate(latestAssessment.generated_at)}
                        {latestAssessment.generated_by ? ` by ${latestAssessment.generated_by.name}` : ""}.
                      </p>
                      {latestAssessment.findings.length === 0 ? (
                        <EmptyState title="No findings" description="This assessment recorded no findings." />
                      ) : (
                        <Table headers={["Finding", "Category", "Status", "Detail"]}>
                          {latestAssessment.findings.map((finding) => (
                            <tr key={finding.id} className="border-b border-slate-100 last:border-0">
                              <TableCell>{finding.label}</TableCell>
                              <TableCell>{finding.category}</TableCell>
                              <TableCell>
                                <Badge tone={findingTone(finding.status)}>{finding.status}</Badge>
                              </TableCell>
                              <TableCell>{finding.detail}</TableCell>
                            </tr>
                          ))}
                        </Table>
                      )}
                    </>
                  ) : (
                    <EmptyState title="No assessment yet" description="Generate an assessment to see findings here." />
                  )}
                </CardBody>
              </Card>
              <Card>
                <CardHeader title="Assessment history" />
                <CardBody>
                  {assessments.length === 0 ? (
                    <EmptyState title="No assessments yet" description="Generated assessments will appear here." />
                  ) : (
                    <>
                      <Table headers={["Overall", "Passed", "Warnings", "Failures", "Open findings", "Generated"]}>
                        {assessments.map((assessment) => (
                          <tr key={assessment.id} className="border-b border-slate-100 last:border-0">
                            <TableCell>
                              <Badge tone={assessmentTone(assessment.overall)}>{assessment.overall}</Badge>
                            </TableCell>
                            <TableCell>{String(assessment.passed)}</TableCell>
                            <TableCell>{String(assessment.warnings)}</TableCell>
                            <TableCell>{String(assessment.failures)}</TableCell>
                            <TableCell>{String(assessment.open_findings)}</TableCell>
                            <TableCell>{formatDate(assessment.generated_at)}</TableCell>
                          </tr>
                        ))}
                      </Table>
                      <div style={{ display: "flex", gap: 8, marginTop: 12 }}>
                        <Button
                          variant="secondary"
                          disabled={assessmentsMeta.current_page <= 1}
                          onClick={() => {
                            setAssessmentsPage(assessmentsMeta.current_page - 1);
                            void loadAssessment(assessmentsMeta.current_page - 1);
                          }}
                        >
                          Previous
                        </Button>
                        <Button
                          variant="secondary"
                          disabled={assessmentsMeta.current_page >= assessmentsMeta.last_page}
                          onClick={() => {
                            setAssessmentsPage(assessmentsMeta.current_page + 1);
                            void loadAssessment(assessmentsMeta.current_page + 1);
                          }}
                        >
                          Next
                        </Button>
                        <span>
                          Page {assessmentsMeta.current_page} of {assessmentsMeta.last_page} ({assessmentsMeta.total} total)
                        </span>
                      </div>
                    </>
                  )}
                </CardBody>
              </Card>
            </>
          )}
        </div>
      )}
    </div>
  );
}

export default function EnterprisePage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Enterprise"
        description={
          currentOrg
            ? `Audit logs, security policies, retention, disaster recovery, monitoring, load tests, SLOs, and security assessments for ${currentOrg.name}. Enterprise tools need the Growth plan or better.`
            : "No organization selected."
        }
        crumbs={[{ label: "Dashboard", href: "/dashboard" }, { label: "Enterprise" }]}
      />
      {currentOrg ? (
        <EnterpriseContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to use the enterprise tools.</Alert>
      )}
    </div>
  );
}
