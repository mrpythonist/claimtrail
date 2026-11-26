"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Select } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import {
  IMPORT_RUN_SOURCES,
  IMPORT_RUN_STATUSES,
  deleteImportSchedule,
  deleteSyncRun,
  importFrequencyLabel,
  importRunSourceLabel,
  importRunStatusLabel,
  listImportRuns,
  listImportSchedules,
  listSyncRuns,
  runImportSchedule,
  runSyncRun,
  syncRunStatusLabel,
} from "@/lib/imports";
import { canManageImports } from "@/lib/permissions";
import type {
  ImportRun,
  ImportRunSource,
  ImportRunStatus,
  ImportSchedule,
  Organization,
  PaginatedMeta,
  SyncRun,
} from "@/lib/types";

function runStatusTone(status: ImportRunStatus): "neutral" | "info" | "warning" | "success" {
  switch (status) {
    case "completed":
      return "success";
    case "partial":
      return "warning";
    case "failed":
      return "warning";
    case "pending":
    case "running":
      return "info";
  }
}

function syncStatusTone(status: SyncRun["status"]): "neutral" | "info" | "warning" | "success" {
  switch (status) {
    case "completed":
      return "success";
    case "failed":
      return "warning";
    case "pending":
    case "running":
      return "info";
  }
}

/**
 * Advanced imports for one organization. The history list is
 * every import run, newest first, with status and source
 * filters. Schedules re-run a stored file with a saved mapping;
 * sync workflows are named, repeatable imports with their own
 * lifecycle. Uploading a CSV still happens from Shipments.
 * Any member may read this page; running and deleting need
 * manage-imports.
 */
function ImportsContent({ org }: { org: Organization }) {
  const [runs, setRuns] = useState<ImportRun[]>([]);
  const [meta, setMeta] = useState<PaginatedMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState<ImportRunStatus | "all">("all");
  const [sourceFilter, setSourceFilter] = useState<ImportRunSource | "all">("all");
  const [page, setPage] = useState(1);

  const [schedules, setSchedules] = useState<ImportSchedule[]>([]);
  const [schedulesLoading, setSchedulesLoading] = useState(true);
  const [schedulesError, setSchedulesError] = useState<string | null>(null);
  const [syncRuns, setSyncRuns] = useState<SyncRun[]>([]);
  const [syncLoading, setSyncLoading] = useState(true);
  const [syncError, setSyncError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);

  const myRole = org.current_user_role;
  const isManager = canManageImports(myRole);

  useEffect(() => {
    let cancelled = false;
    listImportRuns(org.id, { status: statusFilter, source: sourceFilter, page })
      .then((response) => {
        if (!cancelled) {
          setRuns(response.data);
          setMeta(response.meta);
        }
      })
      .catch((err) => {
        if (!cancelled) {
          setError(err instanceof ApiRequestError ? err.message : "Could not load import history.");
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, statusFilter, sourceFilter, page]);

  useEffect(() => {
    let cancelled = false;
    listImportSchedules(org.id)
      .then((response) => {
        if (!cancelled) setSchedules(response);
      })
      .catch((err) => {
        if (!cancelled) {
          setSchedulesError(err instanceof ApiRequestError ? err.message : "Could not load schedules.");
        }
      })
      .finally(() => {
        if (!cancelled) setSchedulesLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id]);

  useEffect(() => {
    let cancelled = false;
    listSyncRuns(org.id)
      .then((response) => {
        if (!cancelled) setSyncRuns(response);
      })
      .catch((err) => {
        if (!cancelled) {
          setSyncError(err instanceof ApiRequestError ? err.message : "Could not load sync workflows.");
        }
      })
      .finally(() => {
        if (!cancelled) setSyncLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id]);

  async function runSchedule(scheduleId: string) {
    setActionError(null);
    setBusyId(scheduleId);
    try {
      const run = await runImportSchedule(org.id, scheduleId);
      setSchedules((current) =>
        current.map((schedule) =>
          schedule.id === scheduleId
            ? { ...schedule, last_run_at: run.finished_at, last_import_run_id: run.id }
            : schedule,
        ),
      );
      setRuns((current) => [run, ...current]);
    } catch (err) {
      setActionError(err instanceof ApiRequestError ? err.message : "Could not run the schedule.");
    } finally {
      setBusyId(null);
    }
  }

  async function removeSchedule(scheduleId: string) {
    setActionError(null);
    setBusyId(scheduleId);
    try {
      await deleteImportSchedule(org.id, scheduleId);
      setSchedules((current) => current.filter((schedule) => schedule.id !== scheduleId));
    } catch (err) {
      setActionError(err instanceof ApiRequestError ? err.message : "Could not delete the schedule.");
    } finally {
      setBusyId(null);
    }
  }

  async function runSync(syncRunId: string) {
    setActionError(null);
    setBusyId(syncRunId);
    try {
      const updated = await runSyncRun(org.id, syncRunId);
      setSyncRuns((current) =>
        current.map((syncRun) => (syncRun.id === syncRunId ? updated : syncRun)),
      );
    } catch (err) {
      setActionError(err instanceof ApiRequestError ? err.message : "Could not run the sync workflow.");
    } finally {
      setBusyId(null);
    }
  }

  async function removeSync(syncRunId: string) {
    setActionError(null);
    setBusyId(syncRunId);
    try {
      await deleteSyncRun(org.id, syncRunId);
      setSyncRuns((current) => current.filter((syncRun) => syncRun.id !== syncRunId));
    } catch (err) {
      setActionError(err instanceof ApiRequestError ? err.message : "Could not delete the sync workflow.");
    } finally {
      setBusyId(null);
    }
  }

  return (
    <div className="space-y-6">
      {actionError ? <Alert tone="error">{actionError}</Alert> : null}

      <Card>
        <CardHeader
          title="Scheduled imports"
          description="A schedule re-runs a file your organization already uploaded, with a saved mapping. Daily and weekly schedules run automatically; manual ones run here."
        />
        <CardBody>
          {schedulesLoading ? (
            <Spinner label="Loading schedules" />
          ) : schedulesError ? (
            <Alert tone="error">{schedulesError}</Alert>
          ) : schedules.length === 0 ? (
            <EmptyState
              title="No scheduled imports"
              description="Schedules are created from the API for now; the list appears here once one exists."
            />
          ) : (
            <ul className="divide-y divide-slate-200">
              {schedules.map((schedule) => (
                <li key={schedule.id} className="flex flex-wrap items-center gap-3 py-3">
                  <div className="min-w-0 flex-1">
                    <p className="font-medium text-slate-900">{schedule.name}</p>
                    <p className="text-sm text-slate-500">
                      {importFrequencyLabel(schedule.frequency)}
                      {schedule.next_run_at ? ` · next run ${new Date(schedule.next_run_at).toLocaleString()}` : ""}
                      {schedule.last_run_at ? ` · last run ${new Date(schedule.last_run_at).toLocaleString()}` : ""}
                    </p>
                  </div>
                  {isManager ? (
                    <div className="flex gap-2">
                      <Button
                        size="sm"
                        disabled={busyId === schedule.id}
                        onClick={() => runSchedule(schedule.id)}
                      >
                        Run now
                      </Button>
                      <Button
                        size="sm"
                        variant="secondary"
                        disabled={busyId === schedule.id}
                        onClick={() => removeSchedule(schedule.id)}
                      >
                        Delete
                      </Button>
                    </div>
                  ) : null}
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader
          title="Synchronization workflows"
          description="A sync workflow is a named, repeatable import over a saved mapping and a stored file. It runs on demand and keeps its own status."
        />
        <CardBody>
          {syncLoading ? (
            <Spinner label="Loading sync workflows" />
          ) : syncError ? (
            <Alert tone="error">{syncError}</Alert>
          ) : syncRuns.length === 0 ? (
            <EmptyState
              title="No sync workflows"
              description="Sync workflows are created from the API for now; the list appears here once one exists."
            />
          ) : (
            <ul className="divide-y divide-slate-200">
              {syncRuns.map((syncRun) => (
                <li key={syncRun.id} className="flex flex-wrap items-center gap-3 py-3">
                  <div className="min-w-0 flex-1">
                    <p className="font-medium text-slate-900">{syncRun.name}</p>
                    <p className="text-sm text-slate-500">
                      {syncRun.summary
                        ? `${syncRun.summary.imported ?? 0} imported, ${syncRun.summary.skipped ?? 0} skipped`
                        : "Never run"}
                      {syncRun.error_message ? ` · ${syncRun.error_message}` : ""}
                    </p>
                  </div>
                  <Badge tone={syncStatusTone(syncRun.status)}>{syncRunStatusLabel(syncRun.status)}</Badge>
                  {isManager ? (
                    <div className="flex gap-2">
                      <Button
                        size="sm"
                        disabled={busyId === syncRun.id}
                        onClick={() => runSync(syncRun.id)}
                      >
                        Run now
                      </Button>
                      <Button
                        size="sm"
                        variant="secondary"
                        disabled={busyId === syncRun.id}
                        onClick={() => removeSync(syncRun.id)}
                      >
                        Delete
                      </Button>
                    </div>
                  ) : null}
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader
          title="Import history"
          description="Every import run, newest first. Uploads come from the shipment CSV import; schedules and sync runs record here too."
        />
        <CardBody>
          <div className="mb-4 flex flex-wrap items-end gap-3">
            <Select
              label="Status"
              id="run-status"
              value={statusFilter}
              onChange={(event) => {
                setStatusFilter(event.target.value as ImportRunStatus | "all");
                setPage(1);
              }}
            >
              <option value="all">All statuses</option>
              {IMPORT_RUN_STATUSES.map((status) => (
                <option key={status.value} value={status.value}>
                  {status.label}
                </option>
              ))}
            </Select>
            <Select
              label="Source"
              id="run-source"
              value={sourceFilter}
              onChange={(event) => {
                setSourceFilter(event.target.value as ImportRunSource | "all");
                setPage(1);
              }}
            >
              <option value="all">All sources</option>
              {IMPORT_RUN_SOURCES.map((source) => (
                <option key={source.value} value={source.value}>
                  {source.label}
                </option>
              ))}
            </Select>
            <Link href="/imports/mappings" className="text-sm font-medium text-sky-700 hover:underline">
              Saved CSV mappings
            </Link>
          </div>

          {loading ? (
            <Spinner label="Loading import history" />
          ) : error ? (
            <Alert tone="error">{error}</Alert>
          ) : runs.length === 0 ? (
            <EmptyState
              title="No imports yet"
              description="Import a shipment CSV from Shipments to see the run here."
            />
          ) : (
            <>
              <ul className="divide-y divide-slate-200">
                {runs.map((run) => (
                  <li key={run.id} className="flex flex-wrap items-center gap-3 py-3">
                    <div className="min-w-0 flex-1">
                      <Link
                        href={`/imports/runs/${run.id}`}
                        className="font-medium text-sky-700 hover:underline"
                      >
                        {run.filename ?? "Import"}
                      </Link>
                      <p className="text-sm text-slate-500">
                        {importRunSourceLabel(run.source)} · {run.imported_rows} imported,{" "}
                        {run.duplicate_rows} duplicates, {run.error_rows} errors ·{" "}
                        {new Date(run.created_at).toLocaleString()}
                      </p>
                      {run.error_message ? (
                        <p className="text-sm text-red-600">{run.error_message}</p>
                      ) : null}
                    </div>
                    <Badge tone={runStatusTone(run.status)}>{importRunStatusLabel(run.status)}</Badge>
                  </li>
                ))}
              </ul>
              {meta && meta.last_page > 1 ? (
                <div className="mt-4 flex items-center gap-2">
                  <Button
                    size="sm"
                    variant="secondary"
                    disabled={page <= 1}
                    onClick={() => setPage(page - 1)}
                  >
                    Previous
                  </Button>
                  <span className="text-sm text-slate-500">
                    Page {meta.current_page} of {meta.last_page}
                  </span>
                  <Button
                    size="sm"
                    variant="secondary"
                    disabled={page >= meta.last_page}
                    onClick={() => setPage(page + 1)}
                  >
                    Next
                  </Button>
                </div>
              ) : null}
            </>
          )}
        </CardBody>
      </Card>
    </div>
  );
}

export default function ImportsPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Imports"
        description={
          currentOrg
            ? `Advanced imports for ${currentOrg.name}. History, schedules, and sync workflows live here; CSV uploads start from Shipments.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Imports" },
        ]}
      />
      {currentOrg ? (
        <ImportsContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to use imports.</Alert>
      )}
    </div>
  );
}
