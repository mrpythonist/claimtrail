"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Select } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { Table, TableCell } from "@/components/ui/Table";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import {
  DEADLINE_STATUSES,
  DEADLINE_STATUS_LABELS,
  daysRemainingLabel,
  deadlineStatusTone,
} from "@/lib/claims";
import type {
  ClaimDeadline,
  Organization,
  PaginatedMeta,
} from "@/lib/types";

const UPCOMING_WINDOWS = [
  { value: "", label: "Any time" },
  { value: "7", label: "Within 7 days" },
  { value: "14", label: "Within 14 days" },
  { value: "30", label: "Within 30 days" },
];

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString();
}

/**
 * Deadline overview for one organization. The parent keys it by
 * organization id, so switching organizations remounts it with fresh
 * state.
 */
function DeadlinesContent({ org }: { org: Organization }) {
  const [deadlines, setDeadlines] = useState<ClaimDeadline[]>([]);
  const [meta, setMeta] = useState<PaginatedMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState("");
  const [windowFilter, setWindowFilter] = useState("");
  const [page, setPage] = useState(1);

  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams({ page: String(page) });
    if (statusFilter) params.set("status", statusFilter);
    if (windowFilter) params.set("upcoming_days", windowFilter);

    apiFetch<{ data: ClaimDeadline[]; meta: PaginatedMeta }>(
      `/deadlines?${params.toString()}`,
      { orgId: org.id },
    )
      .then((response) => {
        if (!cancelled) {
          setDeadlines(response.data);
          setMeta(response.meta);
        }
      })
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load deadlines.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, page, statusFilter, windowFilter]);

  return (
    <div>
      {error && (
        <div className="mb-4">
          <Alert tone="error">{error}</Alert>
        </div>
      )}

      {loading ? (
        <Spinner label="Loading deadlines" />
      ) : (
        <Card>
          <CardHeader
            title="Deadlines"
            description="Active submission deadlines across all claims, earliest first. Deadlines are customer-supplied, never inferred."
          />
          <div className="grid gap-3 border-b border-slate-200 px-5 py-4 sm:grid-cols-2">
            <Select
              id="deadline-status-filter"
              label="Status"
              value={statusFilter}
              onChange={(event) => {
                setStatusFilter(event.target.value);
                setPage(1);
              }}
            >
              <option value="">All statuses</option>
              {DEADLINE_STATUSES.map((status) => (
                <option key={status} value={status}>
                  {DEADLINE_STATUS_LABELS[status]}
                </option>
              ))}
            </Select>
            <Select
              id="deadline-window-filter"
              label="Due within"
              value={windowFilter}
              onChange={(event) => {
                setWindowFilter(event.target.value);
                setPage(1);
              }}
            >
              {UPCOMING_WINDOWS.map((window) => (
                <option key={window.value} value={window.value}>
                  {window.label}
                </option>
              ))}
            </Select>
          </div>

          {deadlines.length === 0 ? (
            <EmptyState
              title="No deadlines found"
              description="Try a different filter, or set a deadline from a claim page."
              action={
                <Link href="/claims">
                  <Button>View claims</Button>
                </Link>
              }
            />
          ) : (
            <>
              <Table
                headers={[
                  "Claim",
                  "Deadline",
                  "Timezone",
                  "Status",
                  "Time left",
                  "Source",
                ]}
              >
                {deadlines.map((deadline) => (
                  <tr key={deadline.id}>
                    <TableCell>
                      <Link
                        href={`/claims/${deadline.claim_id}`}
                        className="font-medium text-brand-700 hover:underline"
                      >
                        {deadline.claim.claim_reference}
                      </Link>
                      <p className="text-xs text-slate-500">
                        {deadline.claim.status_label}
                      </p>
                    </TableCell>
                    <TableCell>{formatDateTime(deadline.deadline_at)}</TableCell>
                    <TableCell>{deadline.timezone}</TableCell>
                    <TableCell>
                      <Badge tone={deadlineStatusTone(deadline.status)}>
                        {deadline.status_label}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      {deadline.status === "met" || deadline.status === "missed"
                        ? "Closed"
                        : daysRemainingLabel(deadline.deadline_at)}
                    </TableCell>
                    <TableCell>
                      {deadline.source_reference ?? "Not recorded"}
                    </TableCell>
                  </tr>
                ))}
              </Table>
              {meta && meta.last_page > 1 && (
                <div className="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-sm">
                  <p className="text-slate-500">
                    Page {meta.current_page} of {meta.last_page} ({meta.total}{" "}
                    deadlines)
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
                      disabled={page >= meta.last_page}
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
      )}
    </div>
  );
}

export default function DeadlinesPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Deadlines"
        description={
          currentOrg
            ? `Submission deadlines for ${currentOrg.name}.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Deadlines" },
        ]}
      />
      {currentOrg ? (
        <DeadlinesContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to view deadlines.</Alert>
      )}
    </div>
  );
}
