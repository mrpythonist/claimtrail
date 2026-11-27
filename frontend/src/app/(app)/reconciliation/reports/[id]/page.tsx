"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { getReconciliationReport } from "@/lib/reconciliation";
import type { Organization, ReconciliationReport } from "@/lib/types";

/**
 * One generated reconciliation report: the period totals and
 * the discrepancies flagged at generation time. A report is
 * a snapshot; it never changes a claim or moves money.
 */
function ReportContent({ org, reportId }: { org: Organization; reportId: string }) {
  const [report, setReport] = useState<ReconciliationReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    getReconciliationReport(reportId)
      .then((data) => {
        if (!cancelled) setReport(data);
      })
      .catch((error: unknown) => {
        if (!cancelled) {
          setError(
            error instanceof ApiRequestError
              ? error.message
              : "Could not load the reconciliation report.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [reportId]);

  if (loading) return <Spinner />;
  if (error) return <Alert tone="error">{error}</Alert>;
  if (!report) return <EmptyState title="Report not found" description="This report does not exist in this organization." />;

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader
          title={`${report.period_start} to ${report.period_end}`}
          action={
            report.totals.discrepancies_count > 0 ? (
              <Badge tone="warning">{report.totals.discrepancies_count} flagged</Badge>
            ) : (
              <Badge tone="success">Clean</Badge>
            )
          }
        />
        <CardBody>
          <dl className="grid grid-cols-2 gap-4 md:grid-cols-4">
            <div>
              <dt className="text-sm text-slate-500">Requested</dt>
              <dd className="text-lg font-semibold">{report.totals.requested_amount}</dd>
            </div>
            <div>
              <dt className="text-sm text-slate-500">Approved</dt>
              <dd className="text-lg font-semibold">{report.totals.approved_amount}</dd>
            </div>
            <div>
              <dt className="text-sm text-slate-500">Paid</dt>
              <dd className="text-lg font-semibold">{report.totals.paid_amount}</dd>
            </div>
            <div>
              <dt className="text-sm text-slate-500">Outstanding</dt>
              <dd className="text-lg font-semibold">{report.totals.outstanding_amount}</dd>
            </div>
          </dl>
          <p className="mt-4 text-sm text-slate-500">
            {report.totals.claims_count} claims with activity in the period ·{" "}
            {report.totals.payments_count} ledger payments · generated{" "}
            {report.generated_at}
          </p>
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Discrepancies" />
        <CardBody>
          {report.discrepancies.length === 0 ? (
            <EmptyState
              title="No discrepancies"
              description="Requested, approved, and paid totals agree for every claim in this period, and no payment predates the ledger."
            />
          ) : (
            <ul className="divide-y divide-slate-200">
              {report.discrepancies.map((item, index) => (
                <li key={`${item.claim_id}-${item.kind}-${index}`} className="py-3">
                  <div className="flex items-center justify-between gap-4">
                    <div>
                      <p className="font-medium">
                        {item.claim_reference} · {item.kind.replaceAll("_", " ")}
                      </p>
                      <p className="mt-1 text-sm text-slate-500">{item.detail}</p>
                    </div>
                    <Link
                      href={`/reconciliation/claims/${item.claim_id}`}
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

      <Link href="/reconciliation" className="text-sm font-medium text-sky-700 hover:underline">
        Back to reconciliation
      </Link>
    </div>
  );
}

export default function ReconciliationReportPage() {
  const { currentOrg } = useAuth();
  const params = useParams();
  const reportId = typeof params.id === "string" ? params.id : "";

  if (reportId === "") {
    return (
      <div>
        <PageHeader
          title="Reconciliation report"
          description="No report selected."
          crumbs={[
            { label: "Dashboard", href: "/dashboard" },
            { label: "Reconciliation", href: "/reconciliation" },
            { label: "Report" },
          ]}
        />
        <Alert tone="error">No report was selected.</Alert>
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title="Reconciliation report"
        description={
          currentOrg
            ? `Period snapshot for ${currentOrg.name}.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Reconciliation", href: "/reconciliation" },
          { label: "Report" },
        ]}
      />
      {currentOrg ? (
        <ReportContent key={`${currentOrg.id}-${reportId}`} org={currentOrg} reportId={reportId} />
      ) : (
        <Alert tone="info">Create an organization first to use reconciliation.</Alert>
      )}
    </div>
  );
}
