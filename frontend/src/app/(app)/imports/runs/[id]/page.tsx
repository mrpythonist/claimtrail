"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Select, Textarea } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import {
  IMPORT_ROW_STATUSES,
  correctImportRow,
  getImportRun,
  importRunSourceLabel,
  importRunStatusLabel,
  importRowStatusLabel,
  listImportRunRows,
} from "@/lib/imports";
import { canManageImports } from "@/lib/permissions";
import type {
  ImportRow,
  ImportRowStatus,
  ImportRun,
  Organization,
  PaginatedMeta,
} from "@/lib/types";

function rowStatusTone(status: ImportRowStatus): "neutral" | "info" | "warning" | "success" {
  switch (status) {
    case "imported":
    case "corrected":
      return "success";
    case "duplicate":
    case "skipped":
    case "failed":
      return "warning";
  }
}

/**
 * One import run: the outcome counts, every row, and the
 * correction form for rows that failed or duplicated. A
 * correction edits the row's field values as JSON, re-validates
 * with the importer's rules, and creates the shipment on
 * success. Correcting needs manage-imports.
 */
function ImportRunDetailContent({ org, runId }: { org: Organization; runId: string }) {
  const [run, setRun] = useState<ImportRun | null>(null);
  const [rows, setRows] = useState<ImportRow[]>([]);
  const [meta, setMeta] = useState<PaginatedMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState<ImportRowStatus | "all">("all");
  const [page, setPage] = useState(1);
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [rowErrors, setRowErrors] = useState<Record<string, string>>({});
  const [busyRow, setBusyRow] = useState<string | null>(null);

  const myRole = org.current_user_role;
  const isManager = canManageImports(myRole);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    getImportRun(org.id, runId)
      .then((response) => {
        if (!cancelled) setRun(response);
      })
      .catch((err) => {
        if (!cancelled) {
          setError(err instanceof ApiRequestError ? err.message : "Could not load the import run.");
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, runId]);

  useEffect(() => {
    let cancelled = false;
    listImportRunRows(org.id, runId, { status: statusFilter, page })
      .then((response) => {
        if (!cancelled) {
          setRows(response.data);
          setMeta(response.meta);
        }
      })
      .catch((err) => {
        if (!cancelled) {
          setError(err instanceof ApiRequestError ? err.message : "Could not load the rows.");
        }
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, runId, statusFilter, page]);

  async function submitCorrection(event: FormEvent, row: ImportRow) {
    event.preventDefault();
    const text = drafts[row.id] ?? JSON.stringify(row.data, null, 2);

    let parsed: Record<string, unknown>;
    try {
      parsed = JSON.parse(text) as Record<string, unknown>;
    } catch {
      setRowErrors((current) => ({ ...current, [row.id]: "The correction is not valid JSON." }));
      return;
    }

    setBusyRow(row.id);
    try {
      const corrected = await correctImportRow(org.id, runId, row.id, parsed);
      setRows((current) => current.map((item) => (item.id === row.id ? corrected : item)));
      setRowErrors((current) => {
        const next = { ...current };
        delete next[row.id];
        return next;
      });
      setRun((current) =>
        current
          ? {
              ...current,
              imported_rows: current.imported_rows + 1,
              skipped_rows: Math.max(0, current.skipped_rows - 1),
              duplicate_rows:
                row.status === "duplicate" ? Math.max(0, current.duplicate_rows - 1) : current.duplicate_rows,
              error_rows:
                row.status === "skipped" || row.status === "failed"
                  ? Math.max(0, current.error_rows - 1)
                  : current.error_rows,
            }
          : current,
      );
    } catch (err) {
      setRowErrors((current) => ({
        ...current,
        [row.id]: err instanceof ApiRequestError ? err.message : "Could not correct the row.",
      }));
    } finally {
      setBusyRow(null);
    }
  }

  if (loading) {
    return <Spinner label="Loading import run" />;
  }

  if (error || !run) {
    return <Alert tone="error">{error ?? "Import run not found."}</Alert>;
  }

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader
          title={run.filename ?? "Import run"}
          description={`${importRunSourceLabel(run.source)} · ${run.imported_rows} imported, ${run.duplicate_rows} duplicates, ${run.error_rows} errors · ${new Date(run.created_at).toLocaleString()}`}
        />
        <CardBody>
          <div className="flex flex-wrap items-center gap-3">
            <Badge
              tone={
                run.status === "completed"
                  ? "success"
                  : run.status === "partial" || run.status === "failed"
                    ? "warning"
                    : "info"
              }
            >
              {importRunStatusLabel(run.status)}
            </Badge>
            <span className="text-sm text-slate-500">
              {run.total_rows} rows · {run.skipped_rows} skipped
            </span>
            {run.error_message ? <span className="text-sm text-red-600">{run.error_message}</span> : null}
          </div>
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Rows" description="One row per CSV data row, in file order. Correct a failed or duplicate row to retry it." />
        <CardBody>
          <div className="mb-4 flex flex-wrap items-end gap-3">
            <Select
              label="Status"
              id="row-status"
              value={statusFilter}
              onChange={(event) => {
                setStatusFilter(event.target.value as ImportRowStatus | "all");
                setPage(1);
              }}
            >
              <option value="all">All rows</option>
              {IMPORT_ROW_STATUSES.map((status) => (
                <option key={status.value} value={status.value}>
                  {status.label}
                </option>
              ))}
            </Select>
          </div>

          {rows.length === 0 ? (
            <EmptyState title="No rows match" description="Try a different status filter." />
          ) : (
            <ul className="divide-y divide-slate-200">
              {rows.map((row) => (
                <li key={row.id} className="py-4">
                  <div className="flex flex-wrap items-center gap-3">
                    <span className="text-sm font-medium text-slate-900">Row {row.row_index}</span>
                    <Badge tone={rowStatusTone(row.status)}>{importRowStatusLabel(row.status)}</Badge>
                    <span className="text-sm text-slate-500">
                      {(row.data.internal_reference as string | undefined) ?? "No reference"}
                    </span>
                    {row.shipment_id ? (
                      <Link href={`/shipments/${row.shipment_id}`} className="text-sm text-sky-700 hover:underline">
                        View shipment
                      </Link>
                    ) : null}
                  </div>
                  {row.errors ? (
                    <ul className="mt-2 space-y-1 text-sm text-red-600">
                      {Object.entries(row.errors).map(([field, messages]) => (
                        <li key={field}>
                          {field}: {messages.join(" ")}
                        </li>
                      ))}
                    </ul>
                  ) : null}
                  {isManager &&
                  (row.status === "skipped" || row.status === "duplicate" || row.status === "failed") ? (
                    <form
                      className="mt-3 space-y-2"
                      onSubmit={(event) => submitCorrection(event, row)}
                    >
                      {rowErrors[row.id] ? <Alert tone="error">{rowErrors[row.id]}</Alert> : null}
                      <Textarea
                        label="Corrected field values (JSON)"
                        id={`correct-${row.id}`}
                        value={drafts[row.id] ?? JSON.stringify(row.data, null, 2)}
                        onChange={(event) =>
                          setDrafts((current) => ({ ...current, [row.id]: event.target.value }))
                        }
                        rows={4}
                      />
                      <Button type="submit" size="sm" disabled={busyRow === row.id}>
                        Correct and retry
                      </Button>
                    </form>
                  ) : null}
                </li>
              ))}
            </ul>
          )}
          {meta && meta.last_page > 1 ? (
            <div className="mt-4 flex items-center gap-2">
              <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
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
        </CardBody>
      </Card>
    </div>
  );
}

export default function ImportRunDetailPage() {
  const { currentOrg } = useAuth();
  const params = useParams();
  const runId = typeof params.id === "string" ? params.id : "";

  return (
    <div>
      <PageHeader
        title="Import run"
        description="Outcome counts and per-row results, with error correction."
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Imports", href: "/imports" },
          { label: "Run" },
        ]}
      />
      {currentOrg ? (
        <ImportRunDetailContent key={currentOrg.id} org={currentOrg} runId={runId} />
      ) : (
        <Alert tone="info">Create an organization first to use imports.</Alert>
      )}
    </div>
  );
}
