"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Input } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { canManageReconciliation } from "@/lib/permissions";
import {
  generateReconciliationReport,
  getReconciliationSummary,
  listLedgerPayments,
  listReconciliationReports,
} from "@/lib/reconciliation";
import type {
  ClaimPayment,
  Organization,
  ReconciliationReport,
  ReconciliationSummary,
} from "@/lib/types";

/**
 * Financial reconciliation for one organization: the payment
 * ledger, organization totals, and generated reports. The
 * ledger records what the organization says it received;
 * nothing here moves real money, and no payment is verified
 * against a bank or a processor. Partial payments are normal:
 * a claim stays accepted until the paid total reaches the
 * amount due.
 */
function ReconciliationContent({ org }: { org: Organization }) {
  const canManage = canManageReconciliation(org.current_user_role);
  const [summary, setSummary] = useState<ReconciliationSummary | null>(null);
  const [summaryLoading, setSummaryLoading] = useState(true);
  const [summaryError, setSummaryError] = useState<string | null>(null);
  const [payments, setPayments] = useState<ClaimPayment[]>([]);
  const [paymentsLoading, setPaymentsLoading] = useState(true);
  const [paymentsError, setPaymentsError] = useState<string | null>(null);
  const [reports, setReports] = useState<ReconciliationReport[]>([]);
  const [reportsLoading, setReportsLoading] = useState(true);
  const [reportsError, setReportsError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [generating, setGenerating] = useState(false);
  const [periodStart, setPeriodStart] = useState("");
  const [periodEnd, setPeriodEnd] = useState("");

  useEffect(() => {
    let cancelled = false;
    getReconciliationSummary()
      .then((data) => {
        if (!cancelled) setSummary(data);
      })
      .catch((error: unknown) => {
        if (!cancelled) {
          setSummaryError(
            error instanceof ApiRequestError
              ? error.message
              : "Could not load the reconciliation summary.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setSummaryLoading(false);
      });
    listLedgerPayments()
      .then((data) => {
        if (!cancelled) setPayments(data);
      })
      .catch((error: unknown) => {
        if (!cancelled) {
          setPaymentsError(
            error instanceof ApiRequestError
              ? error.message
              : "Could not load the payment ledger.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setPaymentsLoading(false);
      });
    listReconciliationReports()
      .then((data) => {
        if (!cancelled) setReports(data);
      })
      .catch((error: unknown) => {
        if (!cancelled) {
          setReportsError(
            error instanceof ApiRequestError
              ? error.message
              : "Could not load reconciliation reports.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setReportsLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  async function handleGenerate() {
    setActionError(null);
    setGenerating(true);
    try {
      const report = await generateReconciliationReport({
        period_start: periodStart || undefined,
        period_end: periodEnd || undefined,
      });
      setReports((current) => [report, ...current]);
    } catch (error: unknown) {
      setActionError(
        error instanceof ApiRequestError
          ? error.message
          : "Could not generate the reconciliation report.",
      );
    } finally {
      setGenerating(false);
    }
  }

  return (
    <div className="space-y-6">
      {actionError ? <Alert tone="error">{actionError}</Alert> : null}

      <Card>
        <CardHeader title="Organization totals" />
        <CardBody>
          {summaryLoading ? (
            <Spinner />
          ) : summaryError ? (
            <Alert tone="error">{summaryError}</Alert>
          ) : summary ? (
            <dl className="grid grid-cols-2 gap-4 md:grid-cols-4">
              <div>
                <dt className="text-sm text-slate-500">Requested</dt>
                <dd className="text-lg font-semibold">
                  {summary.requested_amount}
                </dd>
              </div>
              <div>
                <dt className="text-sm text-slate-500">Approved</dt>
                <dd className="text-lg font-semibold">
                  {summary.approved_amount}
                </dd>
              </div>
              <div>
                <dt className="text-sm text-slate-500">Paid</dt>
                <dd className="text-lg font-semibold">
                  {summary.paid_amount}
                </dd>
              </div>
              <div>
                <dt className="text-sm text-slate-500">Outstanding</dt>
                <dd className="text-lg font-semibold">
                  {summary.outstanding_amount}
                </dd>
              </div>
            </dl>
          ) : null}
          {summary ? (
            <p className="mt-4 text-sm text-slate-500">
              {summary.claims_count} claims with amounts:{" "}
              {summary.by_balance_status.unpaid} unpaid,{" "}
              {summary.by_balance_status.partial} partially paid,{" "}
              {summary.by_balance_status.paid} paid,{" "}
              {summary.by_balance_status.overpaid} overpaid.
            </p>
          ) : null}
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Payment ledger" />
        <CardBody>
          {paymentsLoading ? (
            <Spinner />
          ) : paymentsError ? (
            <Alert tone="error">{paymentsError}</Alert>
          ) : payments.length === 0 ? (
            <EmptyState
              title="No payments recorded yet"
              description="Record a payment from a claim's reconciliation page. Partial payments are normal; the claim moves to Paid when the paid total reaches the amount due."
            />
          ) : (
            <ul className="divide-y divide-slate-200">
              {payments.map((payment) => (
                <li key={payment.id} className="py-3">
                  <div className="flex items-center justify-between gap-4">
                    <div>
                      <p className="font-medium">
                        {payment.currency} {payment.amount} ·{" "}
                        {payment.payment_method_label}
                      </p>
                      <p className="mt-1 text-sm text-slate-500">
                        {payment.paid_at}
                        {payment.payment_reference
                          ? ` · Ref ${payment.payment_reference}`
                          : ""}
                      </p>
                    </div>
                    <Link
                      href={`/reconciliation/claims/${payment.claim_id}`}
                      className="text-sm font-medium text-sky-700 hover:underline"
                    >
                      View claim
                    </Link>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Reconciliation reports" />
        <CardBody>
          {canManage ? (
            <div className="mb-4 flex flex-wrap items-end gap-3">
              <Input
                id="period-start"
                label="Period start"
                type="date"
                value={periodStart}
                onChange={(event) => setPeriodStart(event.target.value)}
              />
              <Input
                id="period-end"
                label="Period end"
                type="date"
                value={periodEnd}
                onChange={(event) => setPeriodEnd(event.target.value)}
              />
              <Button onClick={handleGenerate} disabled={generating}>
                {generating ? "Generating…" : "Generate report"}
              </Button>
              <p className="w-full text-sm text-slate-500">
                Leave the dates empty for the current month. Generating a
                report only reads claims and stores the snapshot.
              </p>
            </div>
          ) : null}
          {reportsLoading ? (
            <Spinner />
          ) : reportsError ? (
            <Alert tone="error">{reportsError}</Alert>
          ) : reports.length === 0 ? (
            <EmptyState
              title="No reports generated yet"
              description="Generate one for a period to snapshot requested, approved, paid, and outstanding totals with any discrepancies flagged."
            />
          ) : (
            <ul className="divide-y divide-slate-200">
              {reports.map((report) => (
                <li key={report.id} className="py-3">
                  <div className="flex items-center justify-between gap-4">
                    <div>
                      <p className="font-medium">
                        {report.period_start} to {report.period_end}
                      </p>
                      <p className="mt-1 text-sm text-slate-500">
                        Paid {report.totals.paid_amount} · Outstanding{" "}
                        {report.totals.outstanding_amount} ·{" "}
                        {report.totals.discrepancies_count} discrepancies
                      </p>
                    </div>
                    <div className="flex items-center gap-3">
                      {report.totals.discrepancies_count > 0 ? (
                        <Badge tone="warning">
                          {report.totals.discrepancies_count} flagged
                        </Badge>
                      ) : (
                        <Badge tone="success">Clean</Badge>
                      )}
                      <Link
                        href={`/reconciliation/reports/${report.id}`}
                        className="text-sm font-medium text-sky-700 hover:underline"
                      >
                        View report
                      </Link>
                    </div>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>
    </div>
  );
}

export default function ReconciliationPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Reconciliation"
        description={
          currentOrg
            ? `Payment ledger and reconciliation reports for ${currentOrg.name}. Amounts are what the organization records as received; nothing here moves real money.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Reconciliation" },
        ]}
      />
      {currentOrg ? (
        <ReconciliationContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to use reconciliation.</Alert>
      )}
    </div>
  );
}
