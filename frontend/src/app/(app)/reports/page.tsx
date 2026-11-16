"use client";

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
import { apiDownload, apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { canExportReports, canViewReports } from "@/lib/permissions";
import {
  formatCurrencyAmount,
  formatMonthLabel,
} from "@/lib/reporting";
import { downloadBlob } from "@/lib/shipments";
import type {
  MoneyAmount,
  Organization,
  RecoveryHistoryMonth,
  ReportSummary,
} from "@/lib/types";

const MONEY_FIGURES: {
  key: keyof ReportSummary["money"];
  title: string;
  description: string;
}[] = [
  {
    key: "potential",
    title: "Estimated potential",
    description:
      "Requested on claims still being prepared. Estimated money, not recovered money.",
  },
  {
    key: "submitted",
    title: "Submitted",
    description: "Requested on claims that reached the broker.",
  },
  {
    key: "accepted",
    title: "Accepted",
    description: "Accepted amounts on accepted, partially accepted, and paid claims.",
  },
  {
    key: "recovered",
    title: "Recovered",
    description: "Actual money received on paid claims.",
  },
  {
    key: "rejected",
    title: "Rejected",
    description: "Requested on rejected claims.",
  },
  {
    key: "outstanding",
    title: "Outstanding",
    description: "Accepted money that has not arrived yet.",
  },
];

function MoneyRows({ rows }: { rows: MoneyAmount[] }) {
  if (rows.length === 0) {
    return <p className="text-sm text-slate-500">No money in this figure yet.</p>;
  }
  return (
    <ul className="space-y-1">
      {rows.map((row) => (
        <li
          key={row.currency}
          className="flex items-center justify-between text-sm"
        >
          <span className="font-medium text-slate-500">{row.currency}</span>
          <span className="font-semibold text-slate-900">
            {formatCurrencyAmount(row.amount, row.currency)}
          </span>
        </li>
      ))}
    </ul>
  );
}

function ReportsContent({ org }: { org: Organization }) {
  const [summary, setSummary] = useState<ReportSummary | null>(null);
  const [history, setHistory] = useState<RecoveryHistoryMonth[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [exporting, setExporting] = useState(false);

  const myRole = org.current_user_role;

  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams();
    if (dateFrom) params.set("date_from", dateFrom);
    if (dateTo) params.set("date_to", dateTo);
    const query = params.toString();

    Promise.all([
      apiFetch<{ data: ReportSummary }>(
        `/reports/summary${query ? `?${query}` : ""}`,
        { orgId: org.id },
      ),
      apiFetch<{ data: RecoveryHistoryMonth[] }>(
        `/reports/recovery-history${query ? `?${query}` : ""}`,
        { orgId: org.id },
      ),
    ])
      .then(([summaryResponse, historyResponse]) => {
        if (!cancelled) {
          setSummary(summaryResponse.data);
          setHistory(historyResponse.data);
        }
      })
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load reports.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, dateFrom, dateTo]);

  async function handleExport() {
    setError(null);
    setExporting(true);
    try {
      const params = new URLSearchParams();
      if (dateFrom) params.set("date_from", dateFrom);
      if (dateTo) params.set("date_to", dateTo);
      const query = params.toString();
      const blob = await apiDownload(
        `/reports/export${query ? `?${query}` : ""}`,
        { orgId: org.id },
      );
      const today = new Date().toISOString().slice(0, 10);
      downloadBlob(blob, `claimtrail-report-${today}.csv`);
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not export the report.",
      );
    } finally {
      setExporting(false);
    }
  }

  if (!canViewReports(myRole)) {
    return (
      <Alert tone="info">
        Reports are not available for your role in this organization.
      </Alert>
    );
  }

  if (loading) {
    return <Spinner label="Loading reports" />;
  }

  if (error) {
    return <Alert tone="error">{error}</Alert>;
  }

  if (!summary) {
    return <Alert tone="info">No report data to show yet.</Alert>;
  }

  const maxStatusCount = Math.max(
    0,
    ...summary.counts_by_status.map((row) => row.count),
  );
  const maxCategoryCount = Math.max(
    0,
    ...summary.counts_by_event_type.map((row) => row.count),
  );
  const totalClaims = summary.counts_by_status.reduce(
    (total, row) => total + row.count,
    0,
  );

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader
          title="Filters"
          description="Date range applies to when claims were created. Recovery history defaults to the last 12 months when no range is given."
          action={
            canExportReports(myRole) ? (
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
        <CardBody>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Input
              id="report-date-from"
              label="From date"
              type="date"
              value={dateFrom}
              onChange={(event) => setDateFrom(event.target.value)}
            />
            <Input
              id="report-date-to"
              label="To date"
              type="date"
              value={dateTo}
              onChange={(event) => setDateTo(event.target.value)}
            />
            {(dateFrom || dateTo) && (
              <div className="flex items-end">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => {
                    setDateFrom("");
                    setDateTo("");
                  }}
                >
                  Clear dates
                </Button>
              </div>
            )}
          </div>
        </CardBody>
      </Card>

      {totalClaims === 0 ? (
        <Card>
          <EmptyState
            title="No claims in this range"
            description="Create claims from shipment events, or widen the date range, to see money figures and counts here."
          />
        </Card>
      ) : (
        <>
          <div>
            <h2 className="text-lg font-semibold text-slate-900">Money</h2>
            <p className="mt-1 text-sm text-slate-500">
              Every figure is shown per currency. Currencies are never mixed
              and no conversion is applied.
            </p>
            <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {MONEY_FIGURES.map((figure) => (
                <Card key={figure.key}>
                  <CardHeader
                    title={figure.title}
                    description={figure.description}
                  />
                  <CardBody>
                    <MoneyRows rows={summary.money[figure.key]} />
                  </CardBody>
                </Card>
              ))}
            </div>
          </div>

          <div className="grid gap-6 lg:grid-cols-2">
            <Card>
              <CardHeader
                title="Claims by status"
                description={`${totalClaims} claims in this range.`}
              />
              <CardBody>
                <div className="space-y-3">
                  {summary.counts_by_status.map((row) => (
                    <CountBar
                      key={row.status}
                      label={row.label}
                      count={row.count}
                      maxCount={maxStatusCount}
                    />
                  ))}
                </div>
              </CardBody>
            </Card>
            <Card>
              <CardHeader
                title="Claims by event type"
                description="Counts by the claim category."
              />
              <CardBody>
                <div className="space-y-3">
                  {summary.counts_by_event_type.map((row) => (
                    <CountBar
                      key={row.category}
                      label={row.label}
                      count={row.count}
                      maxCount={maxCategoryCount}
                    />
                  ))}
                </div>
              </CardBody>
            </Card>
          </div>
        </>
      )}

      <Card>
        <CardHeader
          title="Recovery history"
          description="Recovered money per month, per currency, from paid claims."
        />
        {history.length === 0 ? (
          <EmptyState
            title="No recoveries in this range"
            description="When a claim is paid, the recovered amount shows here in the month it was paid."
          />
        ) : (
          <Table headers={["Month", "Recovered"]}>
            {history.map((row) => (
              <tr key={row.month}>
                <TableCell>{formatMonthLabel(row.month)}</TableCell>
                <TableCell>
                  <ul className="space-y-1">
                    {row.amounts.map((amount) => (
                      <li key={amount.currency}>
                        {formatCurrencyAmount(amount.amount, amount.currency)}
                      </li>
                    ))}
                  </ul>
                </TableCell>
              </tr>
            ))}
          </Table>
        )}
      </Card>
    </div>
  );
}

export default function ReportsPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Reports"
        description={
          currentOrg
            ? `Recovery reports for ${currentOrg.name}.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Reports" },
        ]}
      />
      {currentOrg ? (
        <ReportsContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to see reports.</Alert>
      )}
    </div>
  );
}
