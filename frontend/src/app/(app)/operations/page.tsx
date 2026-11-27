"use client";

import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { CountBar } from "@/components/ui/CountBar";
import { EmptyState } from "@/components/ui/EmptyState";
import { Input } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { Table, TableCell } from "@/components/ui/Table";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { formatAnalyticsAverage, formatAnalyticsRate } from "@/lib/claim-analytics";
import {
  getDetentionTrends,
  getMissingEvidence,
  getRecommendations,
  getRevenueLeakage,
  listBrokerTrends,
  listFacilityPerformance,
  listRejectionReasons,
} from "@/lib/operations";
import { canViewAnalytics } from "@/lib/permissions";
import { formatMonthLabel } from "@/lib/reporting";
import type {
  OperationsBrokerTrendRow,
  OperationsDetentionTrends,
  OperationsFacilityRow,
  OperationsMissingEvidence,
  OperationsRecommendations,
  OperationsRejectionReasonRow,
  OperationsRevenueLeakage,
  Organization,
} from "@/lib/types";

/**
 * Operational intelligence for one organization: detention
 * trends, facility performance, rejection reasons,
 * missing-evidence trends, broker-level trends, revenue
 * leakage, and deterministic operational recommendations.
 *
 * Every number is computed from the organization's own
 * recorded claims, shipments, payments, and evidence. Money
 * is summed as recorded with no currency conversion, and
 * every average carries its sample size, so a rate over
 * three claims never looks like a rate over three hundred.
 * A null rate means its denominator was zero. Leakage is
 * gaps between recorded amounts, not proven lost money.
 * Recommendations are fixed rules over those numbers; each
 * one carries the numbers it was derived from.
 */
function OperationsContent({ org }: { org: Organization }) {
  const canView = canViewAnalytics(org.current_user_role);
  const [detention, setDetention] = useState<OperationsDetentionTrends | null>(null);
  const [detentionError, setDetentionError] = useState<string | null>(null);
  const [facilities, setFacilities] = useState<OperationsFacilityRow[]>([]);
  const [facilitiesError, setFacilitiesError] = useState<string | null>(null);
  const [reasons, setReasons] = useState<OperationsRejectionReasonRow[]>([]);
  const [reasonsError, setReasonsError] = useState<string | null>(null);
  const [missing, setMissing] = useState<OperationsMissingEvidence | null>(null);
  const [missingError, setMissingError] = useState<string | null>(null);
  const [brokerTrends, setBrokerTrends] = useState<OperationsBrokerTrendRow[]>([]);
  const [brokerTrendsError, setBrokerTrendsError] = useState<string | null>(null);
  const [leakage, setLeakage] = useState<OperationsRevenueLeakage | null>(null);
  const [leakageError, setLeakageError] = useState<string | null>(null);
  const [recommendations, setRecommendations] = useState<OperationsRecommendations | null>(null);
  const [recommendationsError, setRecommendationsError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    const query =
      dateFrom || dateTo
        ? { from: dateFrom || undefined, to: dateTo || undefined }
        : undefined;
    const fail = (setError: (message: string) => void, fallback: string) => (error: unknown) => {
      if (!cancelled) {
        setError(
          error instanceof ApiRequestError ? error.message : fallback,
        );
      }
    };
    Promise.all([
      getDetentionTrends(query)
        .then((data) => {
          if (!cancelled) setDetention(data);
        })
        .catch(fail(setDetentionError, "Could not load the detention trends.")),
      listFacilityPerformance(query)
        .then((data) => {
          if (!cancelled) setFacilities(data);
        })
        .catch(fail(setFacilitiesError, "Could not load facility performance.")),
      listRejectionReasons(query)
        .then((data) => {
          if (!cancelled) setReasons(data);
        })
        .catch(fail(setReasonsError, "Could not load the rejection reasons.")),
      getMissingEvidence(query)
        .then((data) => {
          if (!cancelled) setMissing(data);
        })
        .catch(fail(setMissingError, "Could not load the missing-evidence trends.")),
      listBrokerTrends(query)
        .then((data) => {
          if (!cancelled) setBrokerTrends(data);
        })
        .catch(fail(setBrokerTrendsError, "Could not load the broker trends.")),
      getRevenueLeakage(query)
        .then((data) => {
          if (!cancelled) setLeakage(data);
        })
        .catch(fail(setLeakageError, "Could not load the revenue leakage.")),
      getRecommendations(query)
        .then((data) => {
          if (!cancelled) setRecommendations(data);
        })
        .catch(fail(setRecommendationsError, "Could not load the recommendations.")),
    ]).finally(() => {
      if (!cancelled) setLoading(false);
    });
    return () => {
      cancelled = true;
    };
  }, [dateFrom, dateTo]);

  if (!canView) {
    return (
      <Alert tone="info">
        Your role cannot view operational intelligence. Ask an admin to change your role.
      </Alert>
    );
  }

  const maxDetentionClaims = Math.max(
    0,
    ...(detention?.monthly.map((point) => point.claims_created) ?? []),
  );
  const maxMissingIncomplete = Math.max(
    0,
    ...(missing?.monthly.map((point) => point.incomplete) ?? []),
  );

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end gap-3">
        <Input
          id="operations-from"
          label="From"
          type="date"
          value={dateFrom}
          onChange={(event) => setDateFrom(event.target.value)}
        />
        <Input
          id="operations-to"
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

      {loading ? <Spinner /> : null}

      <Card>
        <CardHeader title="Recommendations" />
        <CardBody>
          {recommendationsError ? (
            <Alert tone="error">{recommendationsError}</Alert>
          ) : recommendations ? (
            recommendations.recommendations.length === 0 ? (
              <EmptyState
                title="Nothing to flag in this range"
                description="No rule met its minimum sample, so no recommendation was generated. That is a clean result, not missing data."
              />
            ) : (
              <ul className="space-y-4">
                {recommendations.recommendations.map((item) => (
                  <li key={item.type} className="border-b border-slate-200 pb-4 last:border-0 last:pb-0">
                    <p className="font-medium">
                      {item.title}{" "}
                      <span className="text-sm font-normal text-slate-500">
                        ({item.severity})
                      </span>
                    </p>
                    <p className="mt-1 text-sm text-slate-600">{item.detail}</p>
                  </li>
                ))}
              </ul>
            )
          ) : null}
          {recommendations ? (
            <p className="mt-4 text-sm text-slate-500">{recommendations.generated_from}</p>
          ) : null}
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Detention trends" />
        <CardBody>
          {detentionError ? (
            <Alert tone="error">{detentionError}</Alert>
          ) : detention ? (
            <>
              <dl className="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div>
                  <dt className="text-sm text-slate-500">Detention claims</dt>
                  <dd className="text-lg font-semibold">
                    {detention.counts.claims} ({detention.counts.submitted}{" "}
                    submitted, {detention.counts.paid} paid,{" "}
                    {detention.counts.rejected} rejected)
                  </dd>
                </div>
                <div>
                  <dt className="text-sm text-slate-500">Recovery rate (vs requested)</dt>
                  <dd className="text-lg font-semibold">
                    {formatAnalyticsRate(detention.rates.recovery_rate_vs_requested)}
                  </dd>
                </div>
                <div>
                  <dt className="text-sm text-slate-500">Rejection rate</dt>
                  <dd className="text-lg font-semibold">
                    {formatAnalyticsRate(detention.rates.rejection_rate)}
                  </dd>
                </div>
                <div>
                  <dt className="text-sm text-slate-500">Paid</dt>
                  <dd className="text-lg font-semibold">{detention.money.paid_amount}</dd>
                </div>
              </dl>
              <p className="mt-4 text-sm text-slate-500">
                Average submission time:{" "}
                {formatAnalyticsAverage(detention.average_submission_days)}.
                Average payment time:{" "}
                {formatAnalyticsAverage(detention.average_payment_days)}.
                Compared with the preceding window:{" "}
                {detention.comparison.change.claims >= 0 ? "+" : ""}
                {detention.comparison.change.claims} claims, recovery rate{" "}
                {detention.comparison.change.recovery_rate_vs_requested_pp === null
                  ? "—"
                  : `${detention.comparison.change.recovery_rate_vs_requested_pp > 0 ? "+" : ""}${detention.comparison.change.recovery_rate_vs_requested_pp} points`}
                .
              </p>
              {detention.monthly.length === 0 ? (
                <EmptyState
                  title="No detention claims in this range"
                  description="Detention claims created in the selected range appear here, one bar per month."
                />
              ) : (
                <div className="mt-4 space-y-3">
                  {detention.monthly.map((point) => (
                    <CountBar
                      key={point.month}
                      label={formatMonthLabel(point.month)}
                      count={point.claims_created}
                      maxCount={maxDetentionClaims}
                    />
                  ))}
                </div>
              )}
            </>
          ) : null}
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Facility performance" />
        <CardBody>
          {facilitiesError ? (
            <Alert tone="error">{facilitiesError}</Alert>
          ) : facilities.length === 0 ? (
            <EmptyState
              title="No claims in this range"
              description="Facilities are the delivery locations recorded on shipments, falling back to pickup locations."
            />
          ) : (
            <Table headers={["Facility", "Claims", "Submitted", "Paid", "Rejected", "Requested", "Paid amount", "Recovery", "Rejection"]}>
              {facilities.map((row) => (
                <tr key={row.facility}>
                  <TableCell>{row.facility}</TableCell>
                  <TableCell>{row.claims}</TableCell>
                  <TableCell>{row.submitted}</TableCell>
                  <TableCell>{row.paid}</TableCell>
                  <TableCell>{row.rejected}</TableCell>
                  <TableCell>{row.requested_amount}</TableCell>
                  <TableCell>{row.paid_amount}</TableCell>
                  <TableCell>{formatAnalyticsRate(row.recovery_rate_vs_requested)}</TableCell>
                  <TableCell>{formatAnalyticsRate(row.rejection_rate)}</TableCell>
                </tr>
              ))}
            </Table>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Rejection reasons" />
        <CardBody>
          {reasonsError ? (
            <Alert tone="error">{reasonsError}</Alert>
          ) : reasons.length === 0 ? (
            <EmptyState
              title="No rejections in this range"
              description="Rejected claims group by the reason recorded on the claim; unrecorded reasons show as Not recorded."
            />
          ) : (
            <Table headers={["Reason", "Rejected", "Share of rejections", "Requested"]}>
              {reasons.map((row) => (
                <tr key={row.reason}>
                  <TableCell>{row.reason}</TableCell>
                  <TableCell>{row.rejected}</TableCell>
                  <TableCell>{formatAnalyticsRate(row.share_of_rejected)}</TableCell>
                  <TableCell>{row.requested_amount}</TableCell>
                </tr>
              ))}
            </Table>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Missing evidence" />
        <CardBody>
          {missingError ? (
            <Alert tone="error">{missingError}</Alert>
          ) : missing ? (
            <>
              <p className="text-sm text-slate-600">
                {missing.claims_incomplete} of {missing.claims_with_requirements}{" "}
                claims with configured requirements are incomplete. Average
                readiness:{" "}
                {missing.average_readiness_percentage.percentage === null
                  ? "—"
                  : `${missing.average_readiness_percentage.percentage}% (n=${missing.average_readiness_percentage.sample_size})`}
                .
              </p>
              {missing.by_category.length === 0 ? (
                <EmptyState
                  title="Nothing missing"
                  description="No claim in this range is missing a required document category."
                />
              ) : (
                <div className="mt-4">
                  <Table headers={["Missing category", "Claims missing", "Share of incomplete"]}>
                    {missing.by_category.map((row) => (
                      <tr key={row.category}>
                        <TableCell>{row.category_label}</TableCell>
                        <TableCell>{row.claims_missing}</TableCell>
                        <TableCell>{formatAnalyticsRate(row.share_of_incomplete)}</TableCell>
                      </tr>
                    ))}
                  </Table>
                </div>
              )}
              {missing.monthly.length > 0 ? (
                <div className="mt-4 space-y-3">
                  {missing.monthly.map((point) => (
                    <CountBar
                      key={point.month}
                      label={formatMonthLabel(point.month)}
                      count={point.incomplete}
                      maxCount={maxMissingIncomplete}
                    />
                  ))}
                </div>
              ) : null}
            </>
          ) : null}
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Broker trends" />
        <CardBody>
          {brokerTrendsError ? (
            <Alert tone="error">{brokerTrendsError}</Alert>
          ) : brokerTrends.length === 0 ? (
            <EmptyState
              title="No claims in this range"
              description="One row per broker, with its change against the preceding window of the same length."
            />
          ) : (
            <Table headers={["Broker", "Claims", "Submitted", "Paid", "Rejected", "Recovery", "Rejection", "Recovery change", "Rejection change"]}>
              {brokerTrends.map((row) => (
                <tr key={row.broker}>
                  <TableCell>{row.broker}</TableCell>
                  <TableCell>{row.claims}</TableCell>
                  <TableCell>{row.submitted}</TableCell>
                  <TableCell>{row.paid}</TableCell>
                  <TableCell>{row.rejected}</TableCell>
                  <TableCell>{formatAnalyticsRate(row.recovery_rate_vs_requested)}</TableCell>
                  <TableCell>{formatAnalyticsRate(row.rejection_rate)}</TableCell>
                  <TableCell>
                    {row.change.recovery_rate_vs_requested_pp === null
                      ? "—"
                      : `${row.change.recovery_rate_vs_requested_pp > 0 ? "+" : ""}${row.change.recovery_rate_vs_requested_pp} points`}
                  </TableCell>
                  <TableCell>
                    {row.change.rejection_rate_pp === null
                      ? "—"
                      : `${row.change.rejection_rate_pp > 0 ? "+" : ""}${row.change.rejection_rate_pp} points`}
                  </TableCell>
                </tr>
              ))}
            </Table>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Revenue leakage" />
        <CardBody>
          {leakageError ? (
            <Alert tone="error">{leakageError}</Alert>
          ) : leakage ? (
            <>
              <dl className="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div>
                  <dt className="text-sm text-slate-500">Requested</dt>
                  <dd className="text-lg font-semibold">{leakage.requested_total}</dd>
                </div>
                <div>
                  <dt className="text-sm text-slate-500">Approved</dt>
                  <dd className="text-lg font-semibold">{leakage.approved_total}</dd>
                </div>
                <div>
                  <dt className="text-sm text-slate-500">Paid</dt>
                  <dd className="text-lg font-semibold">{leakage.paid_total}</dd>
                </div>
                <div>
                  <dt className="text-sm text-slate-500">Outstanding</dt>
                  <dd className="text-lg font-semibold">{leakage.outstanding_total}</dd>
                </div>
              </dl>
              <p className="mt-4 text-sm text-slate-500">
                Cut at approval: {leakage.requested_vs_approved_gap} across{" "}
                {leakage.claims_cut_at_approval} claims. Overpaid:{" "}
                {leakage.overpaid_total}. Recorded as paid outside the ledger:{" "}
                {leakage.legacy_total}. Still being prepared:{" "}
                {leakage.unsubmitted_claims} claims requesting{" "}
                {leakage.unsubmitted_requested_total} — pipeline, not leakage.
                These are gaps between recorded amounts, not proven lost
                money.
              </p>
              {leakage.largest_outstanding.length > 0 ? (
                <div className="mt-4">
                  <Table headers={["Claim", "Broker", "Requested", "Approved", "Paid", "Outstanding"]}>
                    {leakage.largest_outstanding.map((row) => (
                      <tr key={row.claim_id}>
                        <TableCell>{row.claim_reference}</TableCell>
                        <TableCell>{row.broker}</TableCell>
                        <TableCell>{row.requested_amount}</TableCell>
                        <TableCell>{row.approved_amount ?? "—"}</TableCell>
                        <TableCell>{row.paid_amount}</TableCell>
                        <TableCell>{row.outstanding_amount}</TableCell>
                      </tr>
                    ))}
                  </Table>
                </div>
              ) : null}
            </>
          ) : null}
        </CardBody>
      </Card>
    </div>
  );
}

export default function OperationsPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Operational Intelligence"
        description={
          currentOrg
            ? `Detention trends, facility and broker performance, rejection reasons, missing evidence, revenue leakage, and recommendations for ${currentOrg.name}. Computed from the organization's own recorded claims, shipments, payments, and evidence.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Operational Intelligence" },
        ]}
      />
      {currentOrg ? (
        <OperationsContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to use operational intelligence.</Alert>
      )}
    </div>
  );
}
