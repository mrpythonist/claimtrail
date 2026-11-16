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
import { apiDownload, apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import {
  canCreateShipments,
  canExportShipments,
  canImportShipments,
} from "@/lib/permissions";
import {
  downloadBlob,
  SHIPMENT_STATUS_LABELS,
  shipmentStatusTone,
} from "@/lib/shipments";
import type {
  Carrier,
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
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [carrierFilter, setCarrierFilter] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [sort, setSort] = useState("created_at:desc");
  const [page, setPage] = useState(1);
  const [exporting, setExporting] = useState(false);

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
  }, [org.id, page, search, statusFilter, carrierFilter, dateFrom, dateTo, sort]);

  function filterParams(): URLSearchParams {
    const params = new URLSearchParams();
    if (search) params.set("search", search);
    if (statusFilter) params.set("status", statusFilter);
    if (carrierFilter) params.set("carrier_id", carrierFilter);
    if (dateFrom) params.set("date_from", dateFrom);
    if (dateTo) params.set("date_to", dateTo);
    return params;
  }

  async function handleExport() {
    setError(null);
    setExporting(true);
    try {
      const blob = await apiDownload(
        `/shipments/export?${filterParams().toString()}`,
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
        <Spinner label="Loading shipments" />
      ) : (
        <Card>
          <CardHeader
            title="Shipments"
            description="Loads, appointments, rates, and status. Export downloads the current filtered list as a CSV file."
            action={
              canExportShipments(myRole) ? (
                <Button
                  variant="secondary"
                  size="sm"
                  loading={exporting}
                  onClick={handleExport}
                >
                  Export CSV
                </Button>
              ) : undefined
            }
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
              <Table
                headers={[
                  "Reference",
                  "Broker / Customer",
                  "Carrier",
                  "Pickup",
                  "Delivery",
                  "Status",
                  "Events",
                  "Evidence",
                ]}
              >
                {shipments.map((shipment) => (
                  <tr key={shipment.id}>
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
              </Table>
              {meta && meta.last_page > 1 && (
                <div className="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-sm">
                  <p className="text-slate-500">
                    Page {meta.current_page} of {meta.last_page} ({meta.total}{" "}
                    shipments)
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
