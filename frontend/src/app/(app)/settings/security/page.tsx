"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Input } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { Table, TableCell } from "@/components/ui/Table";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { canViewSecurity } from "@/lib/permissions";
import {
  createBackup,
  formatBytes,
  getBackups,
  getRetention,
  getSecurityCheck,
  getSecurityEvents,
  retentionKindLabel,
  securityCheckLabel,
  securityCheckTone,
  securityEventSummary,
  updateRetention,
  verifyBackup,
} from "@/lib/security";
import type {
  BackupRun,
  Organization,
  PaginatedMeta,
  RetentionDays,
  RetentionSettings,
  SecurityCheckResult,
  SecurityEvent,
} from "@/lib/types";

type BadgeTone = "neutral" | "success" | "warning" | "danger" | "info";

const RETENTION_KINDS: (keyof RetentionDays)[] = [
  "audit_logs",
  "notifications",
  "job_executions",
  "failed_documents",
  "security_events",
];

function formatDate(value: string | null): string {
  if (value === null) return "Never";
  return new Date(value).toLocaleString();
}

function backupTone(status: BackupRun["status"]): BadgeTone {
  switch (status) {
    case "completed":
      return "success";
    case "failed":
      return "danger";
    default:
      return "neutral";
  }
}

function backupLabel(status: BackupRun["status"]): string {
  switch (status) {
    case "completed":
      return "Completed";
    case "failed":
      return "Failed";
    default:
      return "Running";
  }
}

/**
 * Security hub for one organization. The parent keys it by
 * organization id, so switching organizations remounts it with
 * fresh state.
 */
function SecurityContent({ org }: { org: Organization }) {
  const canView = canViewSecurity(org.current_user_role);

  const [check, setCheck] = useState<SecurityCheckResult | null>(null);
  const [backups, setBackups] = useState<BackupRun[]>([]);
  const [backupsMeta, setBackupsMeta] = useState<PaginatedMeta | null>(null);
  const [retention, setRetention] = useState<RetentionSettings | null>(null);
  const [events, setEvents] = useState<SecurityEvent[]>([]);
  // No loading state for roles that cannot see the page: the
  // access-denied alert renders immediately instead of a spinner.
  const [loading, setLoading] = useState(canView);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busyAction, setBusyAction] = useState<string | null>(null);
  const [retentionForm, setRetentionForm] = useState<Record<string, string>>(
    {},
  );
  const [savingRetention, setSavingRetention] = useState(false);

  // Monotonic request id: a superseded load must not overwrite
  // newer state with stale data.
  const requestIdRef = useRef(0);

  /**
   * Reloads every section. Each section loads independently, so
   * one failing endpoint shows an error but does not hide the
   * sections that did load.
   */
  const loadAll = useCallback(async (orgId: string) => {
    const requestId = ++requestIdRef.current;
    const results = await Promise.allSettled([
      getSecurityCheck(orgId),
      getBackups(orgId),
      getRetention(orgId),
      getSecurityEvents(orgId),
    ]);
    if (requestId !== requestIdRef.current) return;

    const failures: string[] = [];
    if (results[0].status === "fulfilled") {
      setCheck(results[0].value);
    } else {
      failures.push("security checks");
    }
    if (results[1].status === "fulfilled") {
      setBackups(results[1].value.data);
      setBackupsMeta(results[1].value.meta);
    } else {
      failures.push("backups");
    }
    if (results[2].status === "fulfilled") {
      setRetention(results[2].value);
      const form: Record<string, string> = {};
      for (const kind of RETENTION_KINDS) {
        form[kind] = String(results[2].value.effective[kind]);
      }
      setRetentionForm(form);
    } else {
      failures.push("retention settings");
    }
    if (results[3].status === "fulfilled") {
      setEvents(results[3].value);
    } else {
      failures.push("security events");
    }
    setError(
      failures.length > 0
        ? `Could not load ${failures.join(", ")}. Try refreshing.`
        : null,
    );
    setLoading(false);
    setRefreshing(false);
  }, []);

  useEffect(() => {
    if (!canView) return;
    // loadAll only sets state after its fetches resolve; the rule
    // cannot see through the async boundary, so this is the same
    // external-system sync the rule allows (see auth.tsx).
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void loadAll(org.id);
  }, [org.id, canView, loadAll]);

  /** Runs an action, then refreshes the page data. */
  async function runAction(actionKey: string, action: () => Promise<string>) {
    setBusyAction(actionKey);
    setError(null);
    setNotice(null);
    try {
      const message = await action();
      setNotice(message);
      await loadAll(org.id);
    } catch (err) {
      setError(
        err instanceof ApiRequestError ? err.message : "The action failed.",
      );
    } finally {
      setBusyAction(null);
    }
  }

  function handleCreateBackup() {
    void runAction("create-backup", async () => {
      const run = await createBackup(org.id);
      return `Backup ${run.id.slice(0, 8)} created (${formatBytes(run.size_bytes)}).`;
    });
  }

  function handleVerifyBackup(backupId: string) {
    void runAction(`verify:${backupId}`, async () => {
      const result = await verifyBackup(org.id, backupId);
      if (result.ok) {
        return "Backup verified: every checksum matches the manifest.";
      }
      return `Backup failed verification: ${result.mismatches.join("; ")}`;
    });
  }

  function handleSaveRetention() {
    const overrides: Partial<RetentionDays> = {};
    for (const kind of RETENTION_KINDS) {
      const raw = retentionForm[kind] ?? "";
      const days = Number.parseInt(raw, 10);
      if (!Number.isInteger(days) || days < 0 || days > 3650) {
        setError(
          `${retentionKindLabel(kind)} must be a whole number of days from 0 to 3650.`,
        );
        return;
      }
      overrides[kind] = days;
    }
    setSavingRetention(true);
    setError(null);
    setNotice(null);
    updateRetention(org.id, overrides)
      .then((saved) => {
        setRetention(saved);
        setNotice("Retention settings saved.");
      })
      .catch((err: unknown) => {
        setError(
          err instanceof ApiRequestError
            ? err.message
            : "Could not save retention settings.",
        );
      })
      .finally(() => {
        setSavingRetention(false);
      });
  }

  if (!canView) {
    return (
      <Alert tone="info">
        Security settings are not available for your role in this organization.
      </Alert>
    );
  }

  const latestBackup = backups[0] ?? null;

  return (
    <div>
      {error && (
        <div className="mb-4">
          <Alert tone="error">{error}</Alert>
        </div>
      )}
      {notice && (
        <div className="mb-4">
          <Alert tone="success">{notice}</Alert>
        </div>
      )}

      {loading ? (
        <Spinner label="Loading security" />
      ) : (
        <div className="space-y-6">
          <div className="flex justify-end">
            <Button
              variant="secondary"
              size="sm"
              loading={refreshing}
              onClick={() => {
                setRefreshing(true);
                void loadAll(org.id);
              }}
            >
              Refresh
            </Button>
          </div>

          <Card>
            <CardHeader
              title="Production-readiness checks"
              description="The same checks as the security:check command. A failure blocks a production launch; a warning is worth fixing first."
              action={
                check ? (
                  <Badge tone={securityCheckTone(check.overall)}>
                    {securityCheckLabel(check.overall)}
                  </Badge>
                ) : undefined
              }
            />
            {check ? (
              <CardBody>
                <ul className="space-y-3">
                  {check.checks.map((item) => (
                    <li
                      key={item.id}
                      className="flex flex-wrap items-start justify-between gap-2 border-b border-slate-100 pb-3 last:border-0 last:pb-0"
                    >
                      <div>
                        <p className="text-sm font-medium text-slate-900">
                          {item.label}
                        </p>
                        <p className="text-sm text-slate-500">{item.detail}</p>
                      </div>
                      <Badge tone={securityCheckTone(item.status)}>
                        {securityCheckLabel(item.status)}
                      </Badge>
                    </li>
                  ))}
                </ul>
              </CardBody>
            ) : (
              <CardBody>
                <EmptyState
                  title="Checks unavailable"
                  description="The security checks could not be loaded."
                />
              </CardBody>
            )}
          </Card>

          <Card>
            <CardHeader
              title="Backups"
              description={
                latestBackup
                  ? `Last backup ${formatDate(latestBackup.created_at)} (${latestBackup.driver}, ${formatBytes(latestBackup.size_bytes)}). Backups cover the whole database plus every stored file.`
                  : "No backups yet. Backups cover the whole database plus every stored file, and run daily at 02:00."
              }
              action={
                <Button
                  variant="secondary"
                  size="sm"
                  loading={busyAction === "create-backup"}
                  onClick={handleCreateBackup}
                >
                  Create backup now
                </Button>
              }
            />
            {backups.length > 0 ? (
              <Table
                headers={[
                  "Backup",
                  "Status",
                  "Driver",
                  "Size",
                  "Created",
                  "Restored",
                  "",
                ]}
              >
                {backups.map((run) => (
                  <tr key={run.id}>
                    <TableCell className="font-medium">
                      {run.id.slice(0, 8)}
                    </TableCell>
                    <TableCell>
                      <Badge tone={backupTone(run.status)}>
                        {backupLabel(run.status)}
                      </Badge>
                    </TableCell>
                    <TableCell>{run.driver}</TableCell>
                    <TableCell>{formatBytes(run.size_bytes)}</TableCell>
                    <TableCell>{formatDate(run.created_at)}</TableCell>
                    <TableCell>{formatDate(run.restored_at)}</TableCell>
                    <TableCell>
                      {run.status === "completed" && (
                        <Button
                          variant="secondary"
                          size="sm"
                          loading={busyAction === `verify:${run.id}`}
                          onClick={() => handleVerifyBackup(run.id)}
                        >
                          Verify
                        </Button>
                      )}
                      {run.status === "failed" && run.error_message && (
                        <span className="text-sm text-red-600">
                          {run.error_message}
                        </span>
                      )}
                    </TableCell>
                  </tr>
                ))}
              </Table>
            ) : (
              <CardBody>
                <EmptyState
                  title="No backups yet"
                  description="Create one now, or wait for the daily scheduled backup."
                />
              </CardBody>
            )}
            {backupsMeta && backupsMeta.total > backups.length && (
              <p className="border-t border-slate-200 px-5 py-3 text-sm text-slate-500">
                Showing {backups.length} of {backupsMeta.total} backup runs.
              </p>
            )}
            <p className="border-t border-slate-200 px-5 py-3 text-sm text-slate-500">
              Restores replace the live database, so they stay an operator
              action: run backup:restore on the server. Verify re-checks a
              backup&apos;s checksums without restoring it.
            </p>
          </Card>

          <Card>
            <CardHeader
              title="Data retention"
              description="How many days each kind of record is kept before the weekly purge. 0 keeps forever. Ready claim packages are never deleted by retention."
            />
            {retention ? (
              <CardBody>
                <div className="grid gap-4 sm:grid-cols-2">
                  {RETENTION_KINDS.map((kind) => (
                    <Input
                      key={kind}
                      label={retentionKindLabel(kind)}
                      id={`retention-${kind}`}
                      type="number"
                      min={0}
                      max={3650}
                      value={retentionForm[kind] ?? ""}
                      onChange={(event) => {
                        setRetentionForm((current) => ({
                          ...current,
                          [kind]: event.target.value,
                        }));
                      }}
                      help={`Default: ${retention.defaults[kind]} days.`}
                    />
                  ))}
                </div>
                <div className="mt-4">
                  <Button
                    loading={savingRetention}
                    onClick={handleSaveRetention}
                  >
                    Save retention settings
                  </Button>
                </div>
              </CardBody>
            ) : (
              <CardBody>
                <EmptyState
                  title="Retention settings unavailable"
                  description="The retention settings could not be loaded."
                />
              </CardBody>
            )}
          </Card>

          <Card>
            <CardHeader
              title="Security events"
              description="Failed logins and authorization denials for this organization, newest first."
            />
            {events.length > 0 ? (
              <Table headers={["Event", "IP address", "When"]}>
                {events.map((event) => (
                  <tr key={event.id}>
                    <TableCell className="font-medium">
                      {securityEventSummary(event)}
                    </TableCell>
                    <TableCell>{event.ip_address ?? "Unknown"}</TableCell>
                    <TableCell>{formatDate(event.created_at)}</TableCell>
                  </tr>
                ))}
              </Table>
            ) : (
              <CardBody>
                <EmptyState
                  title="No security events"
                  description="Failed logins and authorization denials will appear here."
                />
              </CardBody>
            )}
          </Card>

          <Card>
            <CardHeader
              title="Runbooks and documentation"
              description="The procedures behind this page, kept in the repository."
            />
            <CardBody>
              <ul className="list-disc space-y-1 pl-5 text-sm text-slate-700">
                <li>
                  docs/SECURITY.md — the controls in place, the tenant
                  isolation and RBAC audit findings, and the open review
                  items.
                </li>
                <li>
                  docs/INCIDENT-RESPONSE.md — severity levels, first-response
                  steps, containment and recovery, rollback, and data-breach
                  steps.
                </li>
                <li>
                  docs/OPERATIONS.md — the backup, restore, and retention
                  runbook, plus health, queue, and error monitoring.
                </li>
                <li>
                  Token lifetime and malware scanning are still open items:
                  see docs/SECURITY.md before the product handles real
                  customer data.
                </li>
              </ul>
            </CardBody>
          </Card>
        </div>
      )}
    </div>
  );
}

export default function SecurityPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Security"
        description={
          currentOrg
            ? `Production-readiness checks, backups, retention, and security events for ${currentOrg.name}.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Security" },
        ]}
      />
      {currentOrg ? (
        <SecurityContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">
          Create an organization first to see its security settings.
        </Alert>
      )}
    </div>
  );
}
