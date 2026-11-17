"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { BulkBar } from "@/components/bulk/BulkBar";
import { Button } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Input, Select } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { TableCell } from "@/components/ui/Table";
import { SavedViews } from "@/components/views/SavedViews";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import {
  bulkClaimTargetNeeds,
  bulkResultSummary,
  toggleAllOnPage,
  toggleId,
} from "@/lib/bulk";
import {
  CLAIM_CATEGORIES,
  CLAIM_STATUSES,
  CLAIM_STATUS_LABELS,
  claimStatusTone,
  formatMoney,
} from "@/lib/claims";
import {
  canCreateClaims,
  canEditClaims,
  canTransitionClaimTo,
} from "@/lib/permissions";
import type {
  Carrier,
  Claim,
  ClaimStatus,
  EventCategory,
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

/** Paid is never a bulk target: payments go through record-payment only. */
const BULK_TARGETS: ClaimStatus[] = CLAIM_STATUSES.filter(
  (status) => status !== "paid",
);

const DEADLINE_STATUSES = [
  { value: "overdue", label: "Overdue" },
  { value: "upcoming", label: "Upcoming" },
  { value: "none", label: "No deadline" },
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
  const [carriers, setCarriers] = useState<Carrier[]>([]);
  const [categories, setCategories] = useState<EventCategory[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [categoryFilter, setCategoryFilter] = useState("");
  const [amountMin, setAmountMin] = useState("");
  const [amountMax, setAmountMax] = useState("");
  const [deadlineFrom, setDeadlineFrom] = useState("");
  const [deadlineTo, setDeadlineTo] = useState("");
  const [deadlineStatus, setDeadlineStatus] = useState("");
  const [eventType, setEventType] = useState("");
  const [broker, setBroker] = useState("");
  const [carrierFilter, setCarrierFilter] = useState("");
  const [sort, setSort] = useState("created_at:desc");
  const [page, setPage] = useState(1);
  const [refreshKey, setRefreshKey] = useState(0);

  // Bulk selection state.
  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const [bulkTarget, setBulkTarget] = useState<ClaimStatus>("ready_to_submit");
  const [bulkAcceptedAmount, setBulkAcceptedAmount] = useState("");
  const [bulkReason, setBulkReason] = useState("");
  const [bulkWorking, setBulkWorking] = useState(false);
  const [bulkSummary, setBulkSummary] = useState<string | null>(null);
  const [bulkSummaryTone, setBulkSummaryTone] = useState<
    "success" | "error" | "info"
  >("success");

  const myRole = org.current_user_role;

  useEffect(() => {
    let cancelled = false;
    apiFetch<{ data: Carrier[] }>("/carriers?per_page=100", { orgId: org.id })
      .then((response) => {
        if (!cancelled) setCarriers(response.data);
      })
      .catch(() => {
        if (!cancelled) setCarriers([]);
      });
    apiFetch<{ data: EventCategory[] }>("/event-categories", {
      orgId: org.id,
    })
      .then((response) => {
        if (!cancelled) setCategories(response.data);
      })
      .catch(() => {
        if (!cancelled) setCategories([]);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id]);

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
    if (amountMin) params.set("amount_min", amountMin);
    if (amountMax) params.set("amount_max", amountMax);
    if (deadlineFrom) params.set("deadline_from", deadlineFrom);
    if (deadlineTo) params.set("deadline_to", deadlineTo);
    if (deadlineStatus) params.set("deadline_status", deadlineStatus);
    if (eventType) params.set("event_type", eventType);
    if (broker) params.set("broker", broker);
    if (carrierFilter) params.set("carrier_id", carrierFilter);

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
  }, [
    org.id,
    page,
    search,
    statusFilter,
    categoryFilter,
    amountMin,
    amountMax,
    deadlineFrom,
    deadlineTo,
    deadlineStatus,
    eventType,
    broker,
    carrierFilter,
    sort,
    refreshKey,
  ]);

  // Selection only ever applies to the rows on screen, so it clears
  // whenever the page or any filter changes. The handlers below own
  // that: resetPage, applyFilters, and changePage all clear it.
  function changePage(next: number) {
    setSelectedIds([]);
    setPage(next);
  }

  function filterParams(): URLSearchParams {
    const params = new URLSearchParams();
    if (search) params.set("search", search);
    if (statusFilter) params.set("status", statusFilter);
    if (categoryFilter) params.set("claim_category", categoryFilter);
    if (amountMin) params.set("amount_min", amountMin);
    if (amountMax) params.set("amount_max", amountMax);
    if (deadlineFrom) params.set("deadline_from", deadlineFrom);
    if (deadlineTo) params.set("deadline_to", deadlineTo);
    if (deadlineStatus) params.set("deadline_status", deadlineStatus);
    if (eventType) params.set("event_type", eventType);
    if (broker) params.set("broker", broker);
    if (carrierFilter) params.set("carrier_id", carrierFilter);
    return params;
  }

  /** Current filter state as a record, for saving as a view. */
  function currentFilters(): Record<string, string> {
    const filters: Record<string, string> = {};
    for (const [key, value] of filterParams().entries()) {
      filters[key] = value;
    }
    return filters;
  }

  function applyFilters(filters: Record<string, string>) {
    setSelectedIds([]);
    setSearch(filters.search ?? "");
    setStatusFilter(filters.status ?? "");
    setCategoryFilter(filters.claim_category ?? "");
    setAmountMin(filters.amount_min ?? "");
    setAmountMax(filters.amount_max ?? "");
    setDeadlineFrom(filters.deadline_from ?? "");
    setDeadlineTo(filters.deadline_to ?? "");
    setDeadlineStatus(filters.deadline_status ?? "");
    setEventType(filters.event_type ?? "");
    setBroker(filters.broker ?? "");
    setCarrierFilter(filters.carrier_id ?? "");
    setPage(1);
  }

  function clearFilters() {
    applyFilters({});
  }

  const hasFilters =
    search !== "" ||
    statusFilter !== "" ||
    categoryFilter !== "" ||
    amountMin !== "" ||
    amountMax !== "" ||
    deadlineFrom !== "" ||
    deadlineTo !== "" ||
    deadlineStatus !== "" ||
    eventType !== "" ||
    broker !== "" ||
    carrierFilter !== "";

  function resetPage() {
    setSelectedIds([]);
    setPage(1);
  }

  async function handleBulkTransition() {
    if (selectedIds.length === 0) return;
    const needs = bulkClaimTargetNeeds(bulkTarget);
    if (needs.acceptedAmount && !bulkAcceptedAmount.trim()) {
      setBulkSummary("Enter an accepted amount for this target status.");
      setBulkSummaryTone("error");
      return;
    }
    if (needs.reason && !bulkReason.trim()) {
      setBulkSummary("Enter a reason for this target status.");
      setBulkSummaryTone("error");
      return;
    }
    setBulkWorking(true);
    setBulkSummary(null);
    try {
      const body: Record<string, unknown> = {
        ids: selectedIds,
        to_status: bulkTarget,
      };
      if (needs.acceptedAmount) {
        body.accepted_amount = Number(bulkAcceptedAmount);
      }
      if (bulkTarget === "rejected") {
        body.rejection_reason = bulkReason.trim();
      } else if (bulkTarget === "closed") {
        body.reason = bulkReason.trim();
      }
      const response = await apiFetch<{
        data: {
          updated: { id: string; claim_reference: string; status: string }[];
          failed: { id: string; reason: string }[];
        };
      }>("/claims/bulk-transition", {
        method: "POST",
        body,
        orgId: org.id,
      });
      const failed = response.data.failed;
      setBulkSummary(
        bulkResultSummary(response.data.updated.length, failed, "claims"),
      );
      setBulkSummaryTone(failed.length > 0 ? "error" : "success");
      setSelectedIds([]);
      setBulkAcceptedAmount("");
      setBulkReason("");
      setRefreshKey((key) => key + 1);
    } catch (err) {
      setBulkSummary(
        err instanceof ApiRequestError
          ? err.message
          : "Could not update the selected claims.",
      );
      setBulkSummaryTone("error");
    } finally {
      setBulkWorking(false);
    }
  }

  async function handleBulkDelete() {
    if (selectedIds.length === 0) return;
    const confirmed = window.confirm(
      `Delete ${selectedIds.length} selected claim${selectedIds.length === 1 ? "" : "s"}? Only claims that have not been submitted yet can be deleted. This cannot be undone.`,
    );
    if (!confirmed) return;
    setBulkWorking(true);
    setBulkSummary(null);
    try {
      const response = await apiFetch<{
        data: {
          deleted: string[];
          failed: { id: string; reason: string }[];
        };
      }>("/claims/bulk-delete", {
        method: "POST",
        body: { ids: selectedIds },
        orgId: org.id,
      });
      const failed = response.data.failed;
      setBulkSummary(
        bulkResultSummary(response.data.deleted.length, failed, "claims"),
      );
      setBulkSummaryTone(failed.length > 0 ? "error" : "success");
      setSelectedIds([]);
      setRefreshKey((key) => key + 1);
    } catch (err) {
      setBulkSummary(
        err instanceof ApiRequestError
          ? err.message
          : "Could not delete the selected claims.",
      );
      setBulkSummaryTone("error");
    } finally {
      setBulkWorking(false);
    }
  }

  const pageIds = claims.map((claim) => claim.id);
  const allPageSelected =
    pageIds.length > 0 && pageIds.every((id) => selectedIds.includes(id));
  const bulkNeeds = bulkClaimTargetNeeds(bulkTarget);
  const canApplyBulk = canTransitionClaimTo(myRole, bulkTarget);

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
          <SavedViews
            resourceType="claim"
            orgId={org.id}
            currentFilters={currentFilters()}
            onApply={applyFilters}
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
              id="carrier-filter"
              label="Carrier"
              value={carrierFilter}
              onChange={(event) => {
                setCarrierFilter(event.target.value);
                resetPage();
              }}
            >
              <option value="">All carriers</option>
              {carriers.map((carrier) => (
                <option key={carrier.id} value={carrier.id}>
                  {carrier.name}
                </option>
              ))}
            </Select>
            <Input
              id="amount-min"
              label="Minimum amount"
              type="number"
              inputMode="decimal"
              min="0"
              step="0.01"
              value={amountMin}
              onChange={(event) => {
                setAmountMin(event.target.value);
                resetPage();
              }}
              placeholder="0.00"
            />
            <Input
              id="amount-max"
              label="Maximum amount"
              type="number"
              inputMode="decimal"
              min="0"
              step="0.01"
              value={amountMax}
              onChange={(event) => {
                setAmountMax(event.target.value);
                resetPage();
              }}
              placeholder="0.00"
            />
            <Input
              id="deadline-from"
              label="Deadline from date"
              type="date"
              value={deadlineFrom}
              onChange={(event) => {
                setDeadlineFrom(event.target.value);
                resetPage();
              }}
            />
            <Input
              id="deadline-to"
              label="Deadline to date"
              type="date"
              value={deadlineTo}
              onChange={(event) => {
                setDeadlineTo(event.target.value);
                resetPage();
              }}
            />
            <Select
              id="deadline-status-filter"
              label="Deadline status"
              value={deadlineStatus}
              onChange={(event) => {
                setDeadlineStatus(event.target.value);
                resetPage();
              }}
            >
              <option value="">All deadline statuses</option>
              {DEADLINE_STATUSES.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
            <Select
              id="event-type-filter"
              label="Event type"
              value={eventType}
              onChange={(event) => {
                setEventType(event.target.value);
                resetPage();
              }}
            >
              <option value="">All event types</option>
              {categories.map((category) => (
                <option key={category.id} value={category.slug}>
                  {category.label}
                </option>
              ))}
            </Select>
            <Input
              id="broker-filter"
              label="Broker / Customer"
              type="text"
              value={broker}
              onChange={(event) => {
                setBroker(event.target.value);
                resetPage();
              }}
              placeholder="Broker or customer name"
            />
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
          {hasFilters && (
            <div className="border-b border-slate-200 px-5 py-3">
              <Button variant="ghost" size="sm" onClick={clearFilters}>
                Clear filters
              </Button>
            </div>
          )}

          <BulkBar
            selectedCount={selectedIds.length}
            itemLabel="claim"
            onClearSelection={() => setSelectedIds([])}
            summary={bulkSummary}
            summaryTone={bulkSummaryTone}
          >
            {canApplyBulk && (
              <>
                <Select
                  id="bulk-claim-status"
                  label="Change status to"
                  value={bulkTarget}
                  onChange={(event) =>
                    setBulkTarget(event.target.value as ClaimStatus)
                  }
                >
                  {BULK_TARGETS.map((status) => (
                    <option key={status} value={status}>
                      {CLAIM_STATUS_LABELS[status]}
                    </option>
                  ))}
                </Select>
                {bulkNeeds.acceptedAmount && (
                  <Input
                    id="bulk-accepted-amount"
                    label="Accepted amount"
                    type="number"
                    inputMode="decimal"
                    min="0"
                    step="0.01"
                    value={bulkAcceptedAmount}
                    onChange={(event) =>
                      setBulkAcceptedAmount(event.target.value)
                    }
                    placeholder="0.00"
                  />
                )}
                {bulkNeeds.reason && (
                  <Input
                    id="bulk-reason"
                    label={bulkTarget === "rejected" ? "Rejection reason" : "Reason"}
                    type="text"
                    value={bulkReason}
                    onChange={(event) => setBulkReason(event.target.value)}
                    placeholder={
                      bulkTarget === "rejected"
                        ? "Why were these claims rejected?"
                        : "Why are these claims being closed?"
                    }
                  />
                )}
                <Button
                  loading={bulkWorking}
                  disabled={selectedIds.length === 0}
                  onClick={handleBulkTransition}
                >
                  Apply
                </Button>
              </>
            )}
            {canEditClaims(myRole) && (
              <Button
                variant="danger"
                loading={bulkWorking}
                disabled={selectedIds.length === 0}
                onClick={handleBulkDelete}
              >
                Delete
              </Button>
            )}
          </BulkBar>

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
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead>
                    <tr className="border-b border-slate-200">
                      <th scope="col" className="px-4 py-3 first:pl-5">
                        <input
                          type="checkbox"
                          aria-label="Select all claims on this page"
                          checked={allPageSelected}
                          onChange={() =>
                            setSelectedIds((current) =>
                              toggleAllOnPage(current, pageIds),
                            )
                          }
                          className="h-4 w-4 rounded border-slate-300"
                        />
                      </th>
                      {[
                        "Reference",
                        "Shipment",
                        "Category",
                        "Requested",
                        "Status",
                        "Deadline",
                        "Evidence",
                      ].map((header) => (
                        <th
                          key={header}
                          scope="col"
                          className="px-4 py-3 font-medium text-slate-500 last:pr-5"
                        >
                          {header}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {claims.map((claim) => (
                      <tr key={claim.id}>
                        <TableCell>
                          <input
                            type="checkbox"
                            aria-label={`Select claim ${claim.claim_reference}`}
                            checked={selectedIds.includes(claim.id)}
                            onChange={() =>
                              setSelectedIds((current) =>
                                toggleId(current, claim.id),
                              )
                            }
                            className="h-4 w-4 rounded border-slate-300"
                          />
                        </TableCell>
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
                  </tbody>
                </table>
              </div>
              {meta && meta.last_page > 1 && (
                <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 px-5 py-3 text-sm">
                  <p className="text-slate-500">
                    Page {meta.current_page} of {meta.last_page} ({meta.total}{" "}
                    claims)
                  </p>
                  <div className="flex gap-2">
                    <Button
                      variant="secondary"
                      size="sm"
                      disabled={page <= 1}
                      onClick={() => changePage(page - 1)}
                    >
                      Previous
                    </Button>
                    <Button
                      variant="secondary"
                      size="sm"
                      disabled={page >= meta.last_page}
                      onClick={() => changePage(page + 1)}
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
