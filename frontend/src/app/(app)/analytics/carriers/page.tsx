"use client";

import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
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
  exportAnalytics,
  formatAnalyticsAverage,
  formatAnalyticsRate,
  listCarrierPerformance,
} from "@/lib/claim-analytics";
import { canExportReports, canViewAnalytics } from "@/lib/permissions";
import type { AnalyticsCarrierRow, Organization } from "@/lib/types";

/**
 * Carrier performance: one row per carrier, most claims
 * first. Shipments with no carrier group under "No carrier
 * assigned". Rates use the submitted population; money is
 * summed as recorded, with no currency conversion.
 */
function CarrierPerformanceContent({ org }: { org: Organization }) {
  const canView = canViewAnalytics(org.current_user_role);
  const canExport = canExportReports(org.current_user_role);
  const [rows, setRows] = useState<AnalyticsCarrierRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [exporting, setExporting] = useState(false);
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    listCarrierPerformance({
      from: dateFrom || undefined,
      to: dateTo || undefined,
    })
      .then((data) => {
        if (!cancelled) setRows(data);
      })
      .catch((error: unknown) => {
        if (!cancelled) {
          setError(
            error instanceof ApiRequestError
              ? error.message
              : "Could not load carrier performance.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [dateFrom, dateTo]);

  async function handleExport() {
    setActionError(null);
    setExporting(true);
    try {
      await exportAnalytics("carriers", {
        from: dateFrom || undefined,
        to: dateTo || undefined,
        orgId: org.id,
      });
    } catch (error: unknown) {
      setActionError(
        error instanceof ApiRequestError
          ? error.message
          : "Could not export carrier performance.",
      );
    } finally {
      setExporting(false);
    }
  }

  if (!canView) {
    return (
      <Alert tone="info">
        Your role cannot view analytics. Ask an admin to change your role.
      </Alert>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end gap-3">
        <Input
          id="carriers-from"
          label="From"
          type="date"
          value={dateFrom}
          onChange={(event) => setDateFrom(event.target.value)}
        />
        <Input
          id="carriers-to"
          label="To"
          type="date"
          value={dateTo}
          onChange={(event) => setDateTo(event.target.value)}
        />
        <p className="w-full text-sm text-slate-500">
          Leave the dates empty for the last 12 months.
        </p>
      </div>

      {actionError ? <Alert tone="error">{actionError}</Alert> : null}

      <Card>
        <CardHeader title="Carrier performance" />
        <CardBody>
          {loading ? (
            <Spinner />
          ) : error ? (
            <Alert tone="error">{error}</Alert>
          ) : rows.length === 0 ? (
            <EmptyState
              title="No carriers in this range"
              description="Carriers on the shipments of claims created in the selected range appear here."
            />
          ) : (
            <Table
              headers={[
                "Carrier",
                "Claims",
                "Submitted",
                "Paid",
                "Rejected",
                "Requested",
                "Paid amount",
                "Recovery",
                "Rejection",
                "Avg payment time",
              ]}
            >
              {rows.map((row) => (
                <tr key={row.carrier_id ?? "none"}>
                  <TableCell>{row.carrier}</TableCell>
                  <TableCell>{row.claims}</TableCell>
                  <TableCell>{row.submitted}</TableCell>
                  <TableCell>{row.paid}</TableCell>
                  <TableCell>{row.rejected}</TableCell>
                  <TableCell>{row.requested_amount}</TableCell>
                  <TableCell>{row.paid_amount}</TableCell>
                  <TableCell>
                    {formatAnalyticsRate(row.recovery_rate_vs_requested)}
                  </TableCell>
                  <TableCell>
                    {formatAnalyticsRate(row.rejection_rate)}
                  </TableCell>
                  <TableCell>
                    {formatAnalyticsAverage(row.average_payment_days)}
                  </TableCell>
                </tr>
              ))}
            </Table>
          )}
          {canExport && rows.length > 0 ? (
            <div className="mt-4">
              <Button onClick={handleExport} disabled={exporting}>
                {exporting ? "Exporting…" : "Export carriers CSV"}
              </Button>
            </div>
          ) : null}
        </CardBody>
      </Card>
    </div>
  );
}

export default function CarrierPerformancePage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Carrier performance"
        description={
          currentOrg
            ? `Recovery, rejection, and payment times per carrier for ${currentOrg.name}.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Analytics", href: "/analytics" },
          { label: "Carriers" },
        ]}
      />
      {currentOrg ? (
        <CarrierPerformanceContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to use analytics.</Alert>
      )}
    </div>
  );
}
