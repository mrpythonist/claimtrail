"use client";

import { Fragment, useCallback, useEffect, useRef, useState } from "react";
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
import {
  flushFailedJobs,
  forgetFailedJob,
  getFailedJobs,
  getOpsHealth,
  getQueueStats,
  getRecentErrors,
  retryAllFailedJobs,
  retryFailedJob,
} from "@/lib/ops";
import { canViewOperations } from "@/lib/permissions";
import type {
  FailedJob,
  HealthStatus,
  OpsHealth,
  Organization,
  PaginatedMeta,
  QueueStats,
  RecentErrors,
} from "@/lib/types";

type BadgeTone = "neutral" | "success" | "warning" | "danger" | "info";

const CHECK_ORDER: { key: keyof OpsHealth["checks"]; label: string }[] = [
  { key: "database", label: "Database" },
  { key: "cache", label: "Cache" },
  { key: "queue", label: "Queue" },
  { key: "storage", label: "Storage" },
];

function formatDate(value: string): string {
  return new Date(value).toLocaleString();
}

function healthTone(status: HealthStatus): BadgeTone {
  switch (status) {
    case "ok":
      return "success";
    case "degraded":
      return "warning";
    case "down":
      return "danger";
    default:
      return "neutral";
  }
}

function healthLabel(status: HealthStatus): string {
  switch (status) {
    case "ok":
      return "OK";
    case "degraded":
      return "Degraded";
    case "down":
      return "Down";
    default:
      return "Skipped";
  }
}

/** Laravel log levels, most severe first. Unknown levels render neutral. */
function levelTone(level: string): BadgeTone {
  const normalized = level.toLowerCase();
  if (
    normalized === "emergency" ||
    normalized === "alert" ||
    normalized === "critical" ||
    normalized === "error"
  ) {
    return "danger";
  }
  if (normalized === "warning") return "warning";
  if (normalized === "notice" || normalized === "info") return "info";
  return "neutral";
}

/** Seconds as "45s", "3m 12s", or "2h 5m". Null means no pending job. */
function formatAge(seconds: number | null): string {
  if (seconds === null) return "No pending jobs";
  if (seconds < 60) return `${seconds}s`;
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes}m ${seconds % 60}s`;
  const hours = Math.floor(minutes / 60);
  return `${hours}h ${minutes % 60}m`;
}

/**
 * Operations dashboard for one organization. The parent keys it by
 * organization id, so switching organizations remounts it with
 * fresh state.
 */
function OperationsContent({ org }: { org: Organization }) {
  const canView = canViewOperations(org.current_user_role);

  const [health, setHealth] = useState<OpsHealth | null>(null);
  const [queue, setQueue] = useState<QueueStats | null>(null);
  const [failedJobs, setFailedJobs] = useState<FailedJob[]>([]);
  const [jobsMeta, setJobsMeta] = useState<PaginatedMeta | null>(null);
  const [errors, setErrors] = useState<RecentErrors | null>(null);
  // No loading state for roles that cannot see the page: the
  // access-denied alert renders immediately instead of a spinner.
  const [loading, setLoading] = useState(canView);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [flushHours, setFlushHours] = useState("168");
  const [expandedUuid, setExpandedUuid] = useState<string | null>(null);
  const [busyAction, setBusyAction] = useState<string | null>(null);

  // Monotonic request id: a superseded load (page changed, refresh
  // clicked twice) must not overwrite newer state with stale data.
  const requestIdRef = useRef(0);

  /**
   * Reloads every section. Each section loads independently, so one
   * failing endpoint shows an error but does not hide the sections
   * that did load. Paging the failed-jobs table reloads the other
   * sections too, which keeps health and queue totals fresh.
   */
  const loadAll = useCallback(async (orgId: string, jobsPage: number) => {
    const requestId = ++requestIdRef.current;
    const results = await Promise.allSettled([
      getOpsHealth(orgId),
      getQueueStats(orgId),
      getFailedJobs(orgId, jobsPage),
      getRecentErrors(orgId),
    ]);
    if (requestId !== requestIdRef.current) return;

    const failures: string[] = [];
    if (results[0].status === "fulfilled") {
      setHealth(results[0].value);
    } else {
      failures.push("health");
    }
    if (results[1].status === "fulfilled") {
      setQueue(results[1].value);
    } else {
      failures.push("queue stats");
    }
    if (results[2].status === "fulfilled") {
      setFailedJobs(results[2].value.data);
      setJobsMeta(results[2].value.meta);
    } else {
      failures.push("failed jobs");
    }
    if (results[3].status === "fulfilled") {
      setErrors(results[3].value);
    } else {
      failures.push("recent errors");
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
    void loadAll(org.id, page);
  }, [org.id, page, canView, loadAll]);

  /** Runs a failed-job action, then refreshes jobs and queue totals. */
  async function runAction(actionKey: string, action: () => Promise<string>) {
    setBusyAction(actionKey);
    setError(null);
    setNotice(null);
    try {
      const message = await action();
      setNotice(message);
      await loadAll(org.id, page);
    } catch (err) {
      setError(
        err instanceof ApiRequestError ? err.message : "The action failed.",
      );
    } finally {
      setBusyAction(null);
    }
  }

  function handleRetry(uuid: string) {
    void runAction(`retry:${uuid}`, async () => {
      const response = await retryFailedJob(org.id, uuid);
      return response.message;
    });
  }

  function handleForget(uuid: string) {
    void runAction(`forget:${uuid}`, async () => {
      const response = await forgetFailedJob(org.id, uuid);
      return response.message;
    });
  }

  function handleRetryAll() {
    void runAction("retry-all", async () => {
      const result = await retryAllFailedJobs(org.id);
      const parts = [`Retried ${result.retried} failed jobs.`];
      if (result.failed.length > 0) {
        parts.push(
          `${result.failed.length} could not be retried: ${result.failed.join(", ")}.`,
        );
      }
      return parts.join(" ");
    });
  }

  function handleFlush() {
    const hours = Number.parseInt(flushHours, 10);
    if (!Number.isInteger(hours) || hours < 1) {
      setError("Enter a valid number of hours (at least 1).");
      return;
    }
    void runAction("flush", async () => {
      const result = await flushFailedJobs(org.id, hours);
      return `Deleted ${result.deleted} failed jobs older than ${hours} hours.`;
    });
  }

  if (!canView) {
    return (
      <Alert tone="info">
        Operations are not available for your role in this organization.
      </Alert>
    );
  }

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
        <Spinner label="Loading operations" />
      ) : (
        <div className="space-y-6">
          <div className="flex justify-end">
            <Button
              variant="secondary"
              size="sm"
              loading={refreshing}
              onClick={() => {
                // Set here, in the event handler, rather than inside
                // loadAll: the effect that also calls loadAll must
                // not trigger a synchronous setState.
                setRefreshing(true);
                void loadAll(org.id, page);
              }}
            >
              Refresh
            </Button>
          </div>

          <Card>
            <CardHeader
              title="System health"
              description={
                health
                  ? `Checked ${formatDate(health.checked_at)}`
                  : "Health data is unavailable."
              }
              action={
                health ? (
                  <Badge tone={healthTone(health.status)}>
                    {healthLabel(health.status)}
                  </Badge>
                ) : undefined
              }
            />
            {health ? (
              <CardBody className="space-y-4">
                <dl className="divide-y divide-slate-100">
                  {CHECK_ORDER.map(({ key, label }) => {
                    const check = health.checks[key];
                    return (
                      <div
                        key={key}
                        className="flex flex-wrap items-center justify-between gap-2 py-2 first:pt-0 last:pb-0"
                      >
                        <dt className="text-sm font-medium text-slate-700">
                          {label}
                        </dt>
                        <dd className="flex items-center gap-3 text-sm text-slate-500">
                          {check.detail && (
                            <span className="max-w-xs truncate" title={check.detail}>
                              {check.detail}
                            </span>
                          )}
                          <span>
                            {check.latency_ms === null
                              ? "Latency unknown"
                              : `${check.latency_ms} ms`}
                          </span>
                          <Badge tone={healthTone(check.status)}>
                            {healthLabel(check.status)}
                          </Badge>
                        </dd>
                      </div>
                    );
                  })}
                </dl>
                <div className="grid gap-2 border-t border-slate-100 pt-4 text-sm sm:grid-cols-3">
                  <p className="text-slate-500">
                    App version{" "}
                    <span className="font-medium text-slate-900">
                      {health.version}
                    </span>
                  </p>
                  <p className="text-slate-500">
                    PHP{" "}
                    <span className="font-medium text-slate-900">
                      {health.php_version}
                    </span>
                  </p>
                  <p className="text-slate-500">
                    Laravel{" "}
                    <span className="font-medium text-slate-900">
                      {health.laravel_version}
                    </span>
                  </p>
                </div>
                <div className="grid grid-cols-2 gap-2 border-t border-slate-100 pt-4 text-sm sm:grid-cols-4">
                  <p className="text-slate-500">
                    Pending jobs{" "}
                    <span className="font-medium text-slate-900">
                      {health.queue_totals.pending}
                    </span>
                  </p>
                  <p className="text-slate-500">
                    Reserved jobs{" "}
                    <span className="font-medium text-slate-900">
                      {health.queue_totals.reserved}
                    </span>
                  </p>
                  <p className="text-slate-500">
                    Delayed jobs{" "}
                    <span className="font-medium text-slate-900">
                      {health.queue_totals.delayed}
                    </span>
                  </p>
                  <p className="text-slate-500">
                    Failed jobs{" "}
                    <span className="font-medium text-slate-900">
                      {health.queue_totals.failed}
                    </span>
                  </p>
                </div>
              </CardBody>
            ) : (
              <EmptyState
                title="Health data unavailable"
                description="The health check did not return data. Try refreshing."
              />
            )}
          </Card>

          <Card>
            <CardHeader
              title="Queue stats"
              description={
                queue
                  ? `Driver ${queue.driver} on connection ${queue.connection}.`
                  : "Queue data is unavailable."
              }
            />
            {!queue ? (
              <EmptyState
                title="Queue data unavailable"
                description="Queue stats did not load. Try refreshing."
              />
            ) : !queue.supported ? (
              <CardBody>
                <p className="text-sm text-slate-500">
                  Queue monitoring needs the database queue driver. Per-queue
                  stats are unavailable with the current driver.
                </p>
              </CardBody>
            ) : queue.queues.length === 0 ? (
              <EmptyState
                title="No queues found"
                description="No queues have jobs waiting, reserved, or delayed."
              />
            ) : (
              <>
                <Table
                  headers={[
                    "Queue",
                    "Pending",
                    "Reserved",
                    "Delayed",
                    "Oldest pending",
                  ]}
                >
                  {queue.queues.map((row) => (
                    <tr key={row.queue}>
                      <TableCell className="font-medium">{row.queue}</TableCell>
                      <TableCell>{row.pending}</TableCell>
                      <TableCell>{row.reserved}</TableCell>
                      <TableCell>{row.delayed}</TableCell>
                      <TableCell>
                        {formatAge(row.oldest_pending_age_seconds)}
                      </TableCell>
                    </tr>
                  ))}
                </Table>
                <div className="flex flex-wrap gap-x-6 gap-y-1 border-t border-slate-200 px-5 py-3 text-sm text-slate-500">
                  <span>
                    Total pending{" "}
                    <span className="font-medium text-slate-900">
                      {queue.totals.pending}
                    </span>
                  </span>
                  <span>
                    Total reserved{" "}
                    <span className="font-medium text-slate-900">
                      {queue.totals.reserved}
                    </span>
                  </span>
                  <span>
                    Total delayed{" "}
                    <span className="font-medium text-slate-900">
                      {queue.totals.delayed}
                    </span>
                  </span>
                  <span>
                    Total failed{" "}
                    <span className="font-medium text-slate-900">
                      {queue.totals.failed}
                    </span>
                  </span>
                </div>
              </>
            )}
          </Card>

          <Card>
            <CardHeader
              title="Failed jobs"
              description="Jobs that exhausted their retries. Retry re-queues a job; forget removes its record."
            />
            <div className="flex flex-wrap items-end gap-3 border-b border-slate-200 px-5 py-4">
              <Button
                variant="secondary"
                size="sm"
                loading={busyAction === "retry-all"}
                disabled={busyAction !== null || failedJobs.length === 0}
                onClick={handleRetryAll}
              >
                Retry all
              </Button>
              <div className="flex items-end gap-2">
                <div className="w-28">
                  <Input
                    id="flush-hours"
                    label="Flush older than (hours)"
                    type="number"
                    min={1}
                    value={flushHours}
                    onChange={(event) => setFlushHours(event.target.value)}
                  />
                </div>
                <Button
                  variant="danger"
                  size="sm"
                  loading={busyAction === "flush"}
                  disabled={busyAction !== null}
                  onClick={handleFlush}
                >
                  Flush old
                </Button>
              </div>
            </div>
            {failedJobs.length === 0 ? (
              <EmptyState
                title="No failed jobs"
                description="Every queued job is either waiting, running, or completed."
              />
            ) : (
              <>
                <Table
                  headers={["Job", "Queue", "Failed at", "Exception", "Actions"]}
                >
                  {failedJobs.map((job) => {
                    const expanded = expandedUuid === job.uuid;
                    return (
                      <Fragment key={job.uuid}>
                        <tr>
                          <TableCell className="font-medium">
                            <span
                              className="block max-w-55 truncate"
                              title={job.job}
                            >
                              {job.job}
                            </span>
                          </TableCell>
                          <TableCell>{job.queue}</TableCell>
                          <TableCell>{formatDate(job.failed_at)}</TableCell>
                          <TableCell>
                            <span
                              className="block max-w-55 truncate font-mono text-xs"
                              title={job.exception}
                            >
                              {job.exception}
                            </span>
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() =>
                                setExpandedUuid(expanded ? null : job.uuid)
                              }
                              aria-expanded={expanded}
                            >
                              {expanded ? "Hide exception" : "View exception"}
                            </Button>
                          </TableCell>
                          <TableCell>
                            <div className="flex gap-2">
                              <Button
                                variant="secondary"
                                size="sm"
                                loading={busyAction === `retry:${job.uuid}`}
                                disabled={busyAction !== null}
                                onClick={() => handleRetry(job.uuid)}
                              >
                                Retry
                              </Button>
                              <Button
                                variant="danger"
                                size="sm"
                                loading={busyAction === `forget:${job.uuid}`}
                                disabled={busyAction !== null}
                                onClick={() => handleForget(job.uuid)}
                              >
                                Forget
                              </Button>
                            </div>
                          </TableCell>
                        </tr>
                        {expanded && (
                          <tr>
                            <td colSpan={5} className="bg-slate-50 px-5 py-4">
                              <pre className="overflow-x-auto whitespace-pre-wrap break-words font-mono text-xs text-slate-700">
                                {job.exception}
                              </pre>
                            </td>
                          </tr>
                        )}
                      </Fragment>
                    );
                  })}
                </Table>
                {jobsMeta && jobsMeta.last_page > 1 && (
                  <div className="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-sm">
                    <p className="text-slate-500">
                      Page {jobsMeta.current_page} of {jobsMeta.last_page} (
                      {jobsMeta.total} jobs)
                    </p>
                    <div className="flex gap-2">
                      <Button
                        variant="secondary"
                        size="sm"
                        disabled={page <= 1}
                        onClick={() => setPage((current) => current - 1)}
                      >
                        Previous
                      </Button>
                      <Button
                        variant="secondary"
                        size="sm"
                        disabled={page >= jobsMeta.last_page}
                        onClick={() => setPage((current) => current + 1)}
                      >
                        Next
                      </Button>
                    </div>
                  </div>
                )}
              </>
            )}
          </Card>

          <Card>
            <CardHeader
              title="Recent errors"
              description={
                errors
                  ? `Latest entries from the application log (${errors.scanned_lines} lines scanned).`
                  : "Error data is unavailable."
              }
            />
            {!errors ? (
              <EmptyState
                title="Error data unavailable"
                description="Recent errors did not load. Try refreshing."
              />
            ) : errors.entries.length === 0 ? (
              <EmptyState
                title="No recent errors"
                description="Nothing in the application log at error level or above."
              />
            ) : (
              <CardBody className="divide-y divide-slate-100 py-0">
                {errors.entries.map((entry, index) => (
                  <div key={`${entry.datetime}-${index}`} className="py-3">
                    <div className="flex flex-wrap items-center gap-2">
                      <Badge tone={levelTone(entry.level)}>
                        {entry.level.toUpperCase()}
                      </Badge>
                      <span className="text-sm text-slate-500">
                        {formatDate(entry.datetime)}
                      </span>
                      {entry.method && entry.route && (
                        <span className="font-mono text-xs text-slate-500">
                          {entry.method} {entry.route}
                        </span>
                      )}
                    </div>
                    <p className="mt-1 text-sm text-slate-900">
                      {entry.message}
                    </p>
                    {entry.correlation_id && (
                      <p className="mt-0.5 font-mono text-xs text-slate-500">
                        Correlation {entry.correlation_id}
                      </p>
                    )}
                    {entry.exception && (
                      <pre className="mt-2 overflow-x-auto whitespace-pre-wrap break-words rounded bg-slate-50 p-2 font-mono text-xs text-slate-600">
                        {entry.exception}
                      </pre>
                    )}
                  </div>
                ))}
              </CardBody>
            )}
          </Card>
        </div>
      )}
    </div>
  );
}

export default function OperationsPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Operations"
        description={
          currentOrg
            ? `System health, queues, and failed jobs for ${currentOrg.name}.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Operations" },
        ]}
      />
      {currentOrg ? (
        <OperationsContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">
          Create an organization first to see its operations.
        </Alert>
      )}
    </div>
  );
}
