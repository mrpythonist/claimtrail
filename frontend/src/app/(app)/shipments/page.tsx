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
import { apiDownload, apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { bulkResultSummary, toggleAllOnPage, toggleId } from "@/lib/bulk";
import {
  canArchiveShipments,
  canCreateShipments,
  canEditShipments,
  canExportShipments,
  canImportShipments,
} from "@/lib/permissions";
import { getExportTemplates } from "@/lib/customization";
import {
  downloadBlob,
  SHIPMENT_STATUS_LABELS,
  shipmentStatusTone,
} from "@/lib/shipments";
import type {
  Carrier,
  EventCategory,
  ExportTemplate,
  Organization,
  PaginatedMeta,
  Shipment,
  ShipmentStatus,
} from "@/lib/types";

const SORT_OPTIONS = [
  { value: "created_at:desc", label: "Newest first" },
  { value: "created_at:asc", label: "Oldest first" },
  { value: "pickup_appointment:asc", label: "Pickup appointment, earliest first" },
  { value: "pickup_appointment:desc", label: "Pickup appointment, latest first" },
  { value: "delivery_appointment:asc", label: "Delivery appointment, earliest first" },
  { value: "delivery_appointment:desc", label: "Delivery appointment, latest first" },
  { value: "status:asc", label: "Status A to Z" },
];

const STATUSES: ShipmentStatus[] = [
  "draft",
  "planned",
  "in_transit",
  "delivered",
  "completed",
  "archived",
];

function formatDate(value: string | null): string {
  if (!value) return "Not set";
  return new Date(value).toLocaleDateString();
}

/**
 * Shipment list for one organization. The parent keys it by organization
 * id, so switching organizations remounts it with fresh state.
 */
function ShipmentsContent({ org }: { org: Organization }) {
  const [shipments, setShipments] = useState<Shipment[]>([]);
  const [meta, setMeta] = useState<PaginatedMeta | null>(null);
  const [carriers, setCarriers] = useState<Carrier[]>([]);
  const [categories, setCategories] = useState<EventCategory[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [carrierFilter, setCarrierFilter] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [deliveryDateFrom, setDeliveryDateFrom] = useState("");
  const [deliveryDateTo, setDeliveryDateTo] = useState("");
  const [amountMin, setAmountMin] = useState("");
  const [amountMax, setAmountMax] = useState("");
  const [eventType, setEventType] = useState("");
  const [broker, setBroker] = useState("");
  const [sort, setSort] = useState("created_at:desc");
  const [page, setPage] = useState(1);
  const [exporting, setExporting] = useState(false);
  const [exportTemplates, setExportTemplates] = useState<ExportTemplate[]>([]);
  const [exportTemplateId, setExportTemplateId] = useState("");
  const [refreshKey, setRefreshKey] = useState(0);

  // Bulk selection state.
  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const [bulkStatus, setBulkStatus] = useState<ShipmentStatus>("planned");
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
        // The carrier filter is a convenience; the list still works
        // without it.
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
    // Export templates are optional; the export still works without them.
    getExportTemplates(org.id, "shipment")
      .then((templates) => {
        if (!cancelled) setExportTemplates(templates);
      })
      .catch(() => {
        if (!cancelled) setExportTemplates([]);
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
    if (carrierFilter) params.set("carrier_id", carrierFilter);
    if (dateFrom) params.set("date_from", dateFrom);
    if (dateTo) params.set("date_to", dateTo);
    if (deliveryDateFrom) params.set("delivery_date_from", deliveryDateFrom);
    if (deliveryDateTo) params.set("delivery_date_to", deliveryDateTo);
    if (amountMin) params.set("amount_min", amountMin);
    if (amountMax) params.set("amount_max", amountMax);
    if (eventType) params.set("event_type", eventType);
    if (broker) params.set("broker", broker);

    apiFetch<{ data: Shipment[]; meta: PaginatedMeta }>(
      `/shipments?${params.toString()}`,
      { orgId: org.id },
    )
      .then((response) => {
        if (!cancelled) {
          setShipments(response.data);
          setMeta(response.meta);
        }
      })
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load shipments.",
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
    carrierFilter,
    dateFrom,
    dateTo,
    deliveryDateFrom,
    deliveryDateTo,
    amountMin,
    amountMax,
    eventType,
    broker,
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
    if (carrierFilter) params.set("carrier_id", carrierFilter);
    if (dateFrom) params.set("date_from", dateFrom);
    if (dateTo) params.set("date_to", dateTo);
    if (deliveryDateFrom) params.set("delivery_date_from", deliveryDateFrom);
    if (deliveryDateTo) params.set("delivery_date_to", deliveryDateTo);
    if (amountMin) params.set("amount_min", amountMin);
    if (amountMax) params.set("amount_max", amountMax);
    if (eventType) params.set("event_type", eventType);
    if (broker) params.set("broker", broker);
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
    setCarrierFilter(filters.carrier_id ?? "");
    setDateFrom(filters.date_from ?? "");
    setDateTo(filters.date_to ?? "");
    setDeliveryDateFrom(filters.delivery_date_from ?? "");
    setDeliveryDateTo(filters.delivery_date_to ?? "");
    setAmountMin(filters.amount_min ?? "");
    setAmountMax(filters.amount_max ?? "");
    setEventType(filters.event_type ?? "");
    setBroker(filters.broker ?? "");
    setPage(1);
  }

  function clearFilters() {
    applyFilters({});
  }

  const hasFilters =
    search !== "" ||
    statusFilter !== "" ||
    carrierFilter !== "" ||
    dateFrom !== "" ||
    dateTo !== "" ||
    deliveryDateFrom !== "" ||
    deliveryDateTo !== "" ||
    amountMin !== "" ||
    amountMax !== "" ||
    eventType !== "" ||
    broker !== "";

  async function handleExport() {
    setError(null);
    setExporting(true);
    try {
      const params = filterParams();
      if (exportTemplateId) params.set("export_template_id", exportTemplateId);
      const blob = await apiDownload(
        `/shipments/export?${params.toString()}`,
        { orgId: org.id },
      );
      downloadBlob(blob, "shipments.csv");
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not export shipments.",
      );
    } finally {
      setExporting(false);
    }
  }

  function resetPage() {
    setSelectedIds([]);
    setPage(1);
  }

  async function handleBulkStatus() {
    if (selectedIds.length === 0) return;
    setBulkWorking(true);
    setBulkSummary(null);
    try {
      const response = await apiFetch<{
        data: {
          updated: string[];
          failed: { id: string; reason: string }[];
        };
      }>("/shipments/bulk-status", {
        method: "POST",
        body: { ids: selectedIds, to_status: bulkStatus },
        orgId: org.id,
      });
      const failed = response.data.failed;
      setBulkSummary(
        bulkResultSummary(response.data.updated.length, failed, "shipments"),
      );
      setBulkSummaryTone(failed.length > 0 ? "error" : "success");
      setSelectedIds([]);
      setRefreshKey((key) => key + 1);
    } catch (err) {
      setBulkSummary(
        err instanceof ApiRequestError
          ? err.message
          : "Could not update the selected shipments.",
      );
      setBulkSummaryTone("error");
    } finally {
      setBulkWorking(false);
    }
  }

  async function handleBulkArchive() {
    if (selectedIds.length === 0) return;
    setBulkWorking(true);
    setBulkSummary(null);
    try {
      const response = await apiFetch<{
        data: {
          updated: string[];
          failed: { id: string; reason: string }[];
        };
      }>("/shipments/bulk-archive", {
        method: "POST",
        body: { ids: selectedIds },
        orgId: org.id,
      });
      const failed = response.data.failed;
      setBulkSummary(
        bulkResultSummary(response.data.updated.length, failed, "shipments"),
      );
      setBulkSummaryTone(failed.length > 0 ? "error" : "success");
      setSelectedIds([]);
      setRefreshKey((key) => key + 1);
    } catch (err) {
      setBulkSummary(
        err instanceof ApiRequestError
          ? err.message
          : "Could not archive the selected shipments.",
      );
      setBulkSummaryTone("error");
    } finally {
      setBulkWorking(false);
    }
  }

  const pageIds = shipments.map((shipment) => shipment.id);
  const allPageSelected =
    pageIds.length > 0 && pageIds.every((id) => selectedIds.includes(id));

  return (
    <div>
      {error && (
        <div className="mb-4">
          <Alert tone="error">{error}</Alert>
        </div>
      )}

      {loading ? (
        <Spinner label="Loading shipments" />
      ) : (
        <Card>
          <CardHeader
            title="Shipments"
            description="Loads, appointments, rates, and status. Export downloads the current filtered list as a CSV file."
            action={
              canExportShipments(myRole) ? (
                <span className="flex flex-wrap items-center gap-2">
                  <select
                    aria-label="Export template"
                    value={exportTemplateId}
                    onChange={(event) => setExportTemplateId(event.target.value)}
                    className="min-h-9 rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-900 focus:border-brand-600 focus:outline-none"
                  >
                    <option value="">Default export</option>
                    {exportTemplates.map((template) => (
                      <option key={template.id} value={template.id}>
                        {template.name}
                      </option>
                    ))}
                  </select>
                  <Button
                    variant="secondary"
                    size="sm"
                    loading={exporting}
                    onClick={handleExport}
                  >
                    Export CSV
                  </Button>
                </span>
              ) : undefined
            }
          />
          <SavedViews
            resourceType="shipment"
            orgId={org.id}
            currentFilters={currentFilters()}
            onApply={applyFilters}
          />
          <div className="grid gap-3 border-b border-slate-200 px-5 py-4 sm:grid-cols-2 lg:grid-cols-3">
            <Input
              id="shipment-search"
              label="Search"
              type="search"
              value={search}
              onChange={(event) => {
                setSearch(event.target.value);
                resetPage();
              }}
              placeholder="Reference, broker, or customer"
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
              {STATUSES.map((status) => (
                <option key={status} value={status}>
                  {SHIPMENT_STATUS_LABELS[status]}
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
              id="date-from"
              label="From date"
              type="date"
              value={dateFrom}
              onChange={(event) => {
                setDateFrom(event.target.value);
                resetPage();
              }}
            />
            <Input
              id="date-to"
              label="To date"
              type="date"
              value={dateTo}
              onChange={(event) => {
                setDateTo(event.target.value);
                resetPage();
              }}
            />
            <Input
              id="delivery-date-from"
              label="Delivery from date"
              type="date"
              value={deliveryDateFrom}
              onChange={(event) => {
                setDeliveryDateFrom(event.target.value);
                resetPage();
              }}
            />
            <Input
              id="delivery-date-to"
              label="Delivery to date"
              type="date"
              value={deliveryDateTo}
              onChange={(event) => {
                setDeliveryDateTo(event.target.value);
                resetPage();
              }}
            />
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
            itemLabel="shipment"
            onClearSelection={() => setSelectedIds([])}
            summary={bulkSummary}
            summaryTone={bulkSummaryTone}
          >
            {canEditShipments(myRole) && (
              <>
                <Select
                  id="bulk-shipment-status"
                  label="Change status to"
                  value={bulkStatus}
                  onChange={(event) =>
                    setBulkStatus(event.target.value as ShipmentStatus)
                  }
                >
                  {STATUSES.map((status) => (
                    <option key={status} value={status}>
                      {SHIPMENT_STATUS_LABELS[status]}
                    </option>
                  ))}
                </Select>
                <Button
                  loading={bulkWorking}
                  disabled={selectedIds.length === 0}
                  onClick={handleBulkStatus}
                >
                  Apply
                </Button>
              </>
            )}
            {canArchiveShipments(myRole) && (
              <Button
                variant="danger"
                loading={bulkWorking}
                disabled={selectedIds.length === 0}
                onClick={handleBulkArchive}
              >
                Archive
              </Button>
            )}
          </BulkBar>

          {shipments.length === 0 ? (
            <EmptyState
              title="No shipments found"
              description="Try a different search or filter, or add your first shipment."
              action={
                canCreateShipments(myRole) ? (
                  <Link href="/shipments/new">
                    <Button>New shipment</Button>
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
                          aria-label="Select all shipments on this page"
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
                        "Broker / Customer",
                        "Carrier",
                        "Pickup",
                        "Delivery",
                        "Status",
                        "Events",
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
                    {shipments.map((shipment) => (
                      <tr key={shipment.id}>
                        <TableCell>
                          <input
                            type="checkbox"
                            aria-label={`Select shipment ${shipment.internal_reference}`}
                            checked={selectedIds.includes(shipment.id)}
                            onChange={() =>
                              setSelectedIds((current) =>
                                toggleId(current, shipment.id),
                              )
                            }
                            className="h-4 w-4 rounded border-slate-300"
                          />
                        </TableCell>
                        <TableCell>
                          <Link
                            href={`/shipments/${shipment.id}`}
                            className="font-medium text-brand-700 hover:underline"
                          >
                            {shipment.internal_reference}
                          </Link>
                          {shipment.external_load_reference && (
                            <p className="text-xs text-slate-500">
                              {shipment.external_load_reference}
                            </p>
                          )}
                        </TableCell>
                        <TableCell>{shipment.broker_or_customer_name}</TableCell>
                        <TableCell>{shipment.carrier?.name ?? "Not assigned"}</TableCell>
                        <TableCell>
                          {formatDate(shipment.pickup_appointment)}
                        </TableCell>
                        <TableCell>
                          {formatDate(shipment.delivery_appointment)}
                        </TableCell>
                        <TableCell>
                          <Badge tone={shipmentStatusTone(shipment.status)}>
                            {shipment.status_label}
                          </Badge>
                        </TableCell>
                        <TableCell>{shipment.events_count}</TableCell>
                        <TableCell>{shipment.evidence_count}</TableCell>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {meta && meta.last_page > 1 && (
                <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 px-5 py-3 text-sm">
                  <p className="text-slate-500">
                    Page {meta.current_page} of {meta.last_page} ({meta.total}{" "}
                    shipments)
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

export default function ShipmentsPage() {
  const { currentOrg } = useAuth();
  const myRole = currentOrg?.current_user_role;

  return (
    <div>
      <PageHeader
        title="Shipments"
        description={
          currentOrg
            ? `Loads for ${currentOrg.name}.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Shipments" },
        ]}
        action={
          currentOrg ? (
            <div className="flex flex-wrap gap-2">
              {canImportShipments(myRole) && (
                <Link href="/shipments/import">
                  <Button variant="secondary">Import</Button>
                </Link>
              )}
              {canCreateShipments(myRole) && (
                <Link href="/shipments/new">
                  <Button>New shipment</Button>
                </Link>
              )}
            </div>
          ) : undefined
        }
      />
      {currentOrg ? (
        <ShipmentsContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to manage shipments.</Alert>
      )}
    </div>
  );
}
