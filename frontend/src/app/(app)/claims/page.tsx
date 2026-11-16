"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
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
import {
  CLAIM_CATEGORIES,
  CLAIM_STATUSES,
  CLAIM_STATUS_LABELS,
  claimStatusTone,
  formatMoney,
} from "@/lib/claims";
import { canCreateClaims } from "@/lib/permissions";
import type {
  Claim,
  Organization,
  PaginatedMeta,
} from "@/lib/types";

const SORT_OPTIONS = [
  { value: "created_at:desc", label: "Newest first" },
  { value: "created_at:asc", label: "Oldest first" },
  { value: "requested_amount:desc", label: "Requested amount, highest first" },
  { value: "requested_amount:asc", label: "Requested amount, lowest first" },
  { value: "submission_deadline_at:asc", label: "Submission deadline, earliest first" },
  { value: "submission_deadline_at:desc", label: "Submission deadline, latest first" },
  { value: "status:asc", label: "Status A to Z" },
];

function formatDate(value: string | null): string {
  if (!value) return "Not set";
  return new Date(value).toLocaleDateString();
}

/**
 * Claim list for one organization. The parent keys it by organization
 * id, so switching organizations remounts it with fresh state.
 */
function ClaimsContent({ org }: { org: Organization }) {
  const [claims, setClaims] = useState<Claim[]>([]);
  const [meta, setMeta] = useState<PaginatedMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [categoryFilter, setCategoryFilter] = useState("");
  const [sort, setSort] = useState("created_at:desc");
  const [page, setPage] = useState(1);

  const myRole = org.current_user_role;

  useEffect(() => {
    let cancelled = false;
    const [sortField, sortDirection] = sort.split(":");
    const params = new URLSearchParams({
      page: String(page),
      sort: sortField,
      direction: sortDirection,
    });
    if (search) params.set("search", search);
    if (statusFilter) params.set("status", statusFilter);
    if (categoryFilter) params.set("claim_category", categoryFilter);

    apiFetch<{ data: Claim[]; meta: PaginatedMeta }>(
      `/claims?${params.toString()}`,
      { orgId: org.id },
    )
      .then((response) => {
        if (!cancelled) {
          setClaims(response.data);
          setMeta(response.meta);
        }
      })
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load claims.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, page, search, statusFilter, categoryFilter, sort]);

  function resetPage() {
    setPage(1);
  }

  return (
    <div>
      {error && (
        <div className="mb-4">
          <Alert tone="error">{error}</Alert>
        </div>
      )}

      {loading ? (
        <Spinner label="Loading claims" />
      ) : (
        <Card>
          <CardHeader
            title="Claims"
            description="Detention, layover, TONU, lumper, and other claims against brokers. Requested amounts are potential amounts, never a guarantee of recovery."
          />
          <div className="grid gap-3 border-b border-slate-200 px-5 py-4 sm:grid-cols-2 lg:grid-cols-4">
            <Input
              id="claim-search"
              label="Search"
              type="search"
              value={search}
              onChange={(event) => {
                setSearch(event.target.value);
                resetPage();
              }}
              placeholder="Reference, broker, or description"
            />
            <Select
              id="status-filter"
              label="Status"
              value={statusFilter}
              onChange={(event) => {
                setStatusFilter(event.target.value);
                resetPage();
              }}
            >
              <option value="">All statuses</option>
              {CLAIM_STATUSES.map((status) => (
                <option key={status} value={status}>
                  {CLAIM_STATUS_LABELS[status]}
                </option>
              ))}
            </Select>
            <Select
              id="category-filter"
              label="Category"
              value={categoryFilter}
              onChange={(event) => {
                setCategoryFilter(event.target.value);
                resetPage();
              }}
            >
              <option value="">All categories</option>
              {CLAIM_CATEGORIES.map((category) => (
                <option key={category.value} value={category.value}>
                  {category.label}
                </option>
              ))}
            </Select>
            <Select
              id="sort"
              label="Sort by"
              value={sort}
              onChange={(event) => {
                setSort(event.target.value);
                resetPage();
              }}
            >
              {SORT_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          </div>

          {claims.length === 0 ? (
            <EmptyState
              title="No claims found"
              description="Try a different search or filter, or create your first claim from a shipment."
              action={
                canCreateClaims(myRole) ? (
                  <Link href="/claims/new">
                    <Button>New claim</Button>
                  </Link>
                ) : undefined
              }
            />
          ) : (
            <>
              <Table
                headers={[
                  "Reference",
                  "Shipment",
                  "Category",
                  "Requested",
                  "Status",
                  "Deadline",
                  "Evidence",
                ]}
              >
                {claims.map((claim) => (
                  <tr key={claim.id}>
                    <TableCell>
                      <Link
                        href={`/claims/${claim.id}`}
                        className="font-medium text-brand-700 hover:underline"
                      >
                        {claim.claim_reference}
                      </Link>
                      {claim.external_broker_reference && (
                        <p className="text-xs text-slate-500">
                          {claim.external_broker_reference}
                        </p>
                      )}
                    </TableCell>
                    <TableCell>
                      {claim.shipment ? (
                        <Link
                          href={`/shipments/${claim.shipment.id}`}
                          className="text-brand-700 hover:underline"
                        >
                          {claim.shipment.internal_reference}
                        </Link>
                      ) : (
                        "Not available"
                      )}
                      {claim.shipment && (
                        <p className="text-xs text-slate-500">
                          {claim.shipment.broker_or_customer_name}
                        </p>
                      )}
                    </TableCell>
                    <TableCell>{claim.claim_category_label}</TableCell>
                    <TableCell>
                      {formatMoney(claim.requested_amount, claim.currency)}
                    </TableCell>
                    <TableCell>
                      <Badge tone={claimStatusTone(claim.status)}>
                        {claim.status_label}
                      </Badge>
                    </TableCell>
                    <TableCell>
                      {formatDate(claim.submission_deadline_at)}
                    </TableCell>
                    <TableCell>{claim.evidence_count}</TableCell>
                  </tr>
                ))}
              </Table>
              {meta && meta.last_page > 1 && (
                <div className="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-sm">
                  <p className="text-slate-500">
                    Page {meta.current_page} of {meta.last_page} ({meta.total}{" "}
                    claims)
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

export default function ClaimsPage() {
  const { currentOrg } = useAuth();
  const myRole = currentOrg?.current_user_role;

  return (
    <div>
      <PageHeader
        title="Claims"
        description={
          currentOrg
            ? `Claims for ${currentOrg.name}.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Claims" },
        ]}
        action={
          currentOrg && canCreateClaims(myRole) ? (
            <Link href="/claims/new">
              <Button>New claim</Button>
            </Link>
          ) : undefined
        }
      />
      {currentOrg ? (
        <ClaimsContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to manage claims.</Alert>
      )}
    </div>
  );
}
