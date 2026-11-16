"use client";

import Link from "next/link";
import { Fragment, useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Input, Select } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { Table, TableCell } from "@/components/ui/Table";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { canViewAuditLogs } from "@/lib/permissions";
import { changedFields, fieldLabel, formatAuditValue } from "@/lib/reporting";
import type { AuditLog, Organization, PaginatedMeta } from "@/lib/types";

// Entity types the backend audit observer records, as class names.
const ENTITY_TYPES = [
  "Shipment",
  "ShipmentEvent",
  "EvidenceFile",
  "Claim",
  "ClaimDeadline",
  "OrganizationMember",
  "OrganizationInvitation",
];

function formatDate(value: string): string {
  return new Date(value).toLocaleString();
}

/**
 * Link into the underlying record where a detail page exists.
 * Other entity types render as plain text.
 */
function EntityLink({ log }: { log: AuditLog }) {
  if (log.entity_type === "Claim") {
    return (
      <Link
        href={`/claims/${log.entity_id}`}
        className="font-medium text-brand-700 hover:underline"
      >
        {log.entity_type}
      </Link>
    );
  }
  if (log.entity_type === "Shipment") {
    return (
      <Link
        href={`/shipments/${log.entity_id}`}
        className="font-medium text-brand-700 hover:underline"
      >
        {log.entity_type}
      </Link>
    );
  }
  return <span>{log.entity_type}</span>;
}

/**
 * Before/after viewer for one audit row. Shows only the fields that
 * changed; values are rendered through formatAuditValue, which never
 * prints raw structured blobs.
 */
function ChangeViewer({ log }: { log: AuditLog }) {
  const changes = changedFields(log.before, log.after);
  if (changes.length === 0) {
    return (
      <p className="text-sm text-slate-500">
        No field changes were recorded for this entry.
      </p>
    );
  }
  return (
    <Table headers={["Field", "Before", "After"]}>
      {changes.map((change) => (
        <tr key={change.field}>
          <TableCell className="font-medium">
            {fieldLabel(change.field)}
          </TableCell>
          <TableCell>{formatAuditValue(change.before)}</TableCell>
          <TableCell>{formatAuditValue(change.after)}</TableCell>
        </tr>
      ))}
    </Table>
  );
}

/**
 * Audit log list for one organization. The parent keys it by
 * organization id, so switching organizations remounts it with
 * fresh state.
 */
function AuditLogsContent({ org }: { org: Organization }) {
  const [logs, setLogs] = useState<AuditLog[]>([]);
  const [meta, setMeta] = useState<PaginatedMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [entityType, setEntityType] = useState("");
  const [action, setAction] = useState("");
  const [actorId, setActorId] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [page, setPage] = useState(1);
  const [expandedId, setExpandedId] = useState<string | null>(null);

  const myRole = org.current_user_role;

  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams({ page: String(page) });
    if (entityType) params.set("entity_type", entityType);
    if (action.trim()) params.set("action", action.trim());
    if (actorId.trim()) params.set("actor_id", actorId.trim());
    if (dateFrom) params.set("date_from", dateFrom);
    if (dateTo) params.set("date_to", dateTo);

    apiFetch<{ data: AuditLog[]; meta: PaginatedMeta }>(
      `/audit-logs?${params.toString()}`,
      { orgId: org.id },
    )
      .then((response) => {
        if (!cancelled) {
          setLogs(response.data);
          setMeta(response.meta);
        }
      })
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load audit logs.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, page, entityType, action, actorId, dateFrom, dateTo]);

  function resetPage() {
    setPage(1);
    setExpandedId(null);
  }

  if (!canViewAuditLogs(myRole)) {
    return (
      <Alert tone="info">
        Audit logs are not available for your role in this organization.
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

      {loading ? (
        <Spinner label="Loading audit logs" />
      ) : (
        <Card>
          <CardHeader
            title="Audit log"
            description="Append-only record of who changed what. Audit rows cannot be edited or deleted."
          />
          <div className="grid gap-3 border-b border-slate-200 px-5 py-4 sm:grid-cols-2 lg:grid-cols-3">
            <Select
              id="audit-entity-type"
              label="Entity type"
              value={entityType}
              onChange={(event) => {
                setEntityType(event.target.value);
                resetPage();
              }}
            >
              <option value="">All entity types</option>
              {ENTITY_TYPES.map((type) => (
                <option key={type} value={type}>
                  {type}
                </option>
              ))}
            </Select>
            <Input
              id="audit-action"
              label="Action"
              type="text"
              value={action}
              onChange={(event) => {
                setAction(event.target.value);
                resetPage();
              }}
              placeholder="e.g. claim.status_changed"
            />
            <Input
              id="audit-actor-id"
              label="Actor user id"
              type="text"
              value={actorId}
              onChange={(event) => {
                setActorId(event.target.value);
                resetPage();
              }}
              placeholder="e.g. 12"
            />
            <Input
              id="audit-date-from"
              label="From date"
              type="date"
              value={dateFrom}
              onChange={(event) => {
                setDateFrom(event.target.value);
                resetPage();
              }}
            />
            <Input
              id="audit-date-to"
              label="To date"
              type="date"
              value={dateTo}
              onChange={(event) => {
                setDateTo(event.target.value);
                resetPage();
              }}
            />
            {(entityType || action || actorId || dateFrom || dateTo) && (
              <div className="flex items-end">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => {
                    setEntityType("");
                    setAction("");
                    setActorId("");
                    setDateFrom("");
                    setDateTo("");
                    resetPage();
                  }}
                >
                  Clear filters
                </Button>
              </div>
            )}
          </div>

          {logs.length === 0 ? (
            <EmptyState
              title="No audit entries found"
              description="Try different filters, or wait for activity in this organization to be recorded."
            />
          ) : (
            <>
              <Table
                headers={[
                  "When",
                  "Actor",
                  "Action",
                  "Entity",
                  "Changes",
                ]}
              >
                {logs.map((log) => {
                  const expanded = expandedId === log.id;
                  const changeCount = changedFields(log.before, log.after).length;
                  return (
                    <Fragment key={log.id}>
                      <tr>
                        <TableCell>{formatDate(log.created_at)}</TableCell>
                        <TableCell>
                          {log.actor_type === "system" ? (
                            <span className="inline-flex items-center gap-2">
                              System <Badge tone="neutral">System</Badge>
                            </span>
                          ) : (
                            (log.actor?.name ?? "Unknown user")
                          )}
                        </TableCell>
                        <TableCell>
                          <span className="font-mono text-xs">{log.action}</span>
                        </TableCell>
                        <TableCell>
                          <EntityLink log={log} />
                          <p className="truncate text-xs text-slate-500" title={log.entity_id}>
                            {log.entity_id.slice(0, 8)}
                          </p>
                        </TableCell>
                        <TableCell>
                          <Button
                            variant="secondary"
                            size="sm"
                            onClick={() =>
                              setExpandedId(expanded ? null : log.id)
                            }
                            aria-expanded={expanded}
                          >
                            {expanded
                              ? "Hide changes"
                              : `View changes (${changeCount})`}
                          </Button>
                        </TableCell>
                      </tr>
                      {expanded && (
                        <tr>
                          <td colSpan={5} className="bg-slate-50 px-5 py-4">
                            <ChangeViewer log={log} />
                            <p className="mt-3 text-xs text-slate-500">
                              {log.correlation_id &&
                                `Correlation ${log.correlation_id} · `}
                              {log.ip_address
                                ? `From ${log.ip_address}`
                                : "No IP address recorded"}
                            </p>
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  );
                })}
              </Table>
              {meta && meta.last_page > 1 && (
                <div className="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-sm">
                  <p className="text-slate-500">
                    Page {meta.current_page} of {meta.last_page} ({meta.total}{" "}
                    entries)
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

export default function AuditLogsPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Audit Logs"
        description={
          currentOrg
            ? `Audit trail for ${currentOrg.name}.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Audit Logs" },
        ]}
      />
      {currentOrg ? (
        <AuditLogsContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">
          Create an organization first to see its audit trail.
        </Alert>
      )}
    </div>
  );
}
