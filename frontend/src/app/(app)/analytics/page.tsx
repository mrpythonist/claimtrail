"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { CountBar } from "@/components/ui/CountBar";
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
  getAnalyticsOverview,
  getMonthlySeries,
  getYearlySeries,
} from "@/lib/claim-analytics";
import { canExportReports, canViewAnalytics } from "@/lib/permissions";
import { formatMonthLabel } from "@/lib/reporting";
import type {
  AnalyticsMonthlyPoint,
  AnalyticsOverview,
  AnalyticsYearlyPoint,
  Organization,
} from "@/lib/types";

/**
 * Analytics overview for one organization: recovery and
 * rejection rates, average submission and payment times,
 * monthly and yearly comparisons, and CSV exports.
 *
 * Every metric is computed from the organization's own
 * recorded claims, shipments, and payments. Money is summed
 * as recorded, with no currency conversion, and every
 * average carries its sample size, so a rate over three
 * claims never looks like a rate over three hundred. A null
 * rate means its denominator was zero.
 */
function AnalyticsContent({ org }: { org: Organization }) {
  const canView = canViewAnalytics(org.current_user_role);
  const canExport = canExportReports(org.current_user_role);
  const [overview, setOverview] = useState<AnalyticsOverview | null>(null);
  const [overviewLoading, setOverviewLoading] = useState(true);
  const [overviewError, setOverviewError] = useState<string | null>(null);
  const [monthly, setMonthly] = useState<AnalyticsMonthlyPoint[]>([]);
  const [monthlyLoading, setMonthlyLoading] = useState(true);
  const [monthlyError, setMonthlyError] = useState<string | null>(null);
  const [yearly, setYearly] = useState<AnalyticsYearlyPoint[]>([]);
  const [yearlyLoading, setYearlyLoading] = useState(true);
  const [yearlyError, setYearlyError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [exporting, setExporting] = useState(false);
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");

  useEffect(() => {
    let cancelled = false;
    setOverviewLoading(true);
    setMonthlyLoading(true);
    setYearlyLoading(true);
    const query =
      dateFrom || dateTo
        ? { from: dateFrom || undefined, to: dateTo || undefined }
        : undefined;
    getAnalyticsOverview(query)
      .then((data) => {
        if (!cancelled) setOverview(data);
      })
      .catch((error: unknown) => {
        if (!cancelled) {
          setOverviewError(
            error instanceof ApiRequestError
              ? error.message
              : "Could not load the analytics overview.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setOverviewLoading(false);
      });
    getMonthlySeries(query)
      .then((data) => {
        if (!cancelled) setMonthly(data);
      })
      .catch((error: unknown) => {
        if (!cancelled) {
          setMonthlyError(
            error instanceof ApiRequestError
              ? error.message
              : "Could not load the monthly series.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setMonthlyLoading(false);
      });
    getYearlySeries(query)
      .then((data) => {
        if (!cancelled) setYearly(data);
      })
      .catch((error: unknown) => {
        if (!cancelled) {
          setYearlyError(
            error instanceof ApiRequestError
              ? error.message
              : "Could not load the yearly series.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setYearlyLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [dateFrom, dateTo]);

  async function handleExport(type: "overview" | "monthly") {
    setActionError(null);
    setExporting(true);
    try {
      await exportAnalytics(type, {
        from: dateFrom || undefined,
        to: dateTo || undefined,
        orgId: org.id,
      });
    } catch (error: unknown) {
      setActionError(
        error instanceof ApiRequestError
          ? error.message
          : "Could not export the analytics.",
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

  const maxMonthlyClaims = Math.max(0, ...monthly.map((point) => point.claims_created));
  const maxYearlyClaims = Math.max(0, ...yearly.map((point) => point.claims_created));

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end gap-3">
        <Input
          id="analytics-from"
          label="From"
          type="date"
          value={dateFrom}
          onChange={(event) => setDateFrom(event.target.value)}
        />
        <Input
          id="analytics-to"
          label="To"
          type="date"
          value={dateTo}
          onChange={(event) => setDateTo(event.target.value)}
        />
        <p className="w-full text-sm text-slate-500">
          Leave the dates empty for the last 12 months. Rates use claims
          that reached submission; claims still being prepared never enter
          a denominator.
        </p>
      </div>

      {actionError ? <Alert tone="error">{actionError}</Alert> : null}

      <Card>
        <CardHeader title="Recovery and rejection" />
        <CardBody>
          {overviewLoading ? (
            <Spinner />
          ) : overviewError ? (
            <Alert tone="error">{overviewError}</Alert>
          ) : overview ? (
            <>
              <dl className="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div>
                  <dt className="text-sm text-slate-500">
                    Recovery rate (vs requested)
                  </dt>
                  <dd className="text-lg font-semibold">
                    {formatAnalyticsRate(
                      overview.rates.recovery_rate_vs_requested,
                    )}
                  </dd>
                </div>
                <div>
                  <dt className="text-sm text-slate-500">
                    Recovery rate (vs approved)
                  </dt>
                  <dd className="text-lg font-semibold">
                    {formatAnalyticsRate(
                      overview.rates.recovery_rate_vs_approved,
                    )}
                  </dd>
                </div>
                <div>
                  <dt className="text-sm text-slate-500">Rejection rate</dt>
                  <dd className="text-lg font-semibold">
                    {formatAnalyticsRate(overview.rates.rejection_rate)}
                  </dd>
                </div>
                <div>
                  <dt className="text-sm text-slate-500">Claims</dt>
                  <dd className="text-lg font-semibold">
                    {overview.counts.claims} ({overview.counts.submitted}{" "}
                    submitted, {overview.counts.paid} paid,{" "}
                    {overview.counts.rejected} rejected)
                  </dd>
                </div>
              </dl>
              <dl className="mt-4 grid grid-cols-2 gap-4 md:grid-cols-4">
                <div>
                  <dt className="text-sm text-slate-500">Requested</dt>
                  <dd className="text-lg font-semibold">
                    {overview.money.requested_amount}
                  </dd>
                </div>
                <div>
                  <dt className="text-sm text-slate-500">Approved</dt>
                  <dd className="text-lg font-semibold">
                    {overview.money.approved_amount}
                  </dd>
                </div>
                <div>
                  <dt className="text-sm text-slate-500">Paid</dt>
                  <dd className="text-lg font-semibold">
                    {overview.money.paid_amount}
                  </dd>
                </div>
                <div>
                  <dt className="text-sm text-slate-500">Outstanding</dt>
                  <dd className="text-lg font-semibold">
                    {overview.money.outstanding_amount}
                  </dd>
                </div>
              </dl>
              <p className="mt-4 text-sm text-slate-500">
                Average submission time:{" "}
                {formatAnalyticsAverage(overview.average_submission_days)}.
                Average payment time:{" "}
                {formatAnalyticsAverage(overview.average_payment_days)}.
                Money is summed as recorded
                {overview.currencies.length > 0
                  ? ` (${overview.currencies.join(", ")})`
                  : ""}{" "}
                with no currency conversion.
              </p>
              <p className="mt-2 text-sm text-slate-500">
                Compared with the preceding window (
                {overview.comparison.previous_from} to{" "}
                {overview.comparison.previous_to}):{" "}
                {overview.comparison.change.claims >= 0 ? "+" : ""}
                {overview.comparison.change.claims} claims, recovery rate{" "}
                {overview.comparison.change.recovery_rate_vs_requested_pp ===
                null
                  ? "—"
                  : `${overview.comparison.change.recovery_rate_vs_requested_pp > 0 ? "+" : ""}${overview.comparison.change.recovery_rate_vs_requested_pp} points`}
                , rejection rate{" "}
                {overview.comparison.change.rejection_rate_pp === null
                  ? "—"
                  : `${overview.comparison.change.rejection_rate_pp > 0 ? "+" : ""}${overview.comparison.change.rejection_rate_pp} points`}
                .
              </p>
              <div className="mt-4 flex flex-wrap gap-3">
                <Link
                  href="/analytics/brokers"
                  className="text-sm font-medium text-sky-700 hover:underline"
                >
                  Broker performance
                </Link>
                <Link
                  href="/analytics/carriers"
                  className="text-sm font-medium text-sky-700 hover:underline"
                >
                  Carrier performance
                </Link>
                <Link
                  href="/analytics/event-types"
                  className="text-sm font-medium text-sky-700 hover:underline"
                >
                  Event-type performance
                </Link>
              </div>
            </>
          ) : null}
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Monthly comparison" />
        <CardBody>
          {monthlyLoading ? (
            <Spinner />
          ) : monthlyError ? (
            <Alert tone="error">{monthlyError}</Alert>
          ) : monthly.length === 0 ? (
            <EmptyState
              title="No claims in this range"
              description="Claims created in the selected range appear here, one bar per month."
            />
          ) : (
            <>
              <div className="space-y-3">
                {monthly.map((point) => (
                  <CountBar
                    key={point.month}
                    label={formatMonthLabel(point.month)}
                    count={point.claims_created}
                    maxCount={maxMonthlyClaims}
                  />
                ))}
              </div>
              <div className="mt-6">
                <Table
                  headers={[
                    "Month",
                    "Created",
                    "Submitted",
                    "Paid",
                    "Rejected",
                    "Requested",
                    "Paid amount",
                    "Recovery",
                  ]}
                >
                  {monthly.map((point) => (
                    <tr key={point.month}>
                      <TableCell>{formatMonthLabel(point.month)}</TableCell>
                      <TableCell>{point.claims_created}</TableCell>
                      <TableCell>{point.submitted}</TableCell>
                      <TableCell>{point.paid}</TableCell>
                      <TableCell>{point.rejected}</TableCell>
                      <TableCell>{point.requested_amount}</TableCell>
                      <TableCell>{point.paid_amount}</TableCell>
                      <TableCell>
                        {formatAnalyticsRate(
                          point.recovery_rate_vs_requested,
                        )}
                      </TableCell>
                    </tr>
                  ))}
                </Table>
              </div>
            </>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Yearly comparison" />
        <CardBody>
          {yearlyLoading ? (
            <Spinner />
          ) : yearlyError ? (
            <Alert tone="error">{yearlyError}</Alert>
          ) : yearly.length === 0 ? (
            <EmptyState
              title="No claims in this range"
              description="Claims created in the selected range appear here, one bar per year."
            />
          ) : (
            <div className="space-y-3">
              {yearly.map((point) => (
                <CountBar
                  key={point.year}
                  label={point.year}
                  count={point.claims_created}
                  maxCount={maxYearlyClaims}
                />
              ))}
            </div>
          )}
        </CardBody>
      </Card>

      {canExport ? (
        <Card>
          <CardHeader title="Exports" />
          <CardBody>
            <div className="flex flex-wrap gap-3">
              <Button
                onClick={() => handleExport("overview")}
                disabled={exporting}
              >
                {exporting ? "Exporting…" : "Export overview CSV"}
              </Button>
              <Button
                onClick={() => handleExport("monthly")}
                disabled={exporting}
              >
                {exporting ? "Exporting…" : "Export monthly CSV"}
              </Button>
            </div>
            <p className="mt-3 text-sm text-slate-500">
              CSV only. Broker, carrier, and event-type exports live on
              their own pages.
            </p>
          </CardBody>
        </Card>
      ) : null}
    </div>
  );
}

export default function AnalyticsPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Analytics"
        description={
          currentOrg
            ? `Recovery and rejection rates, average times, and comparisons for ${currentOrg.name}. Computed from the organization's own recorded claims, shipments, and payments.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Analytics" },
        ]}
      />
      {currentOrg ? (
        <AnalyticsContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to use analytics.</Alert>
      )}
    </div>
  );
}
