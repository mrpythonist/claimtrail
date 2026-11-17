"use client";

import Link from "next/link";
import { useEffect, useState, type FormEvent, type ReactNode } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { CountBar } from "@/components/ui/CountBar";
import { EmptyState } from "@/components/ui/EmptyState";
import { Input, Select } from "@/components/ui/Input";
import { OnboardingChecklist } from "@/components/onboarding/OnboardingChecklist";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import {
  CLAIM_STATUSES,
  CLAIM_STATUS_LABELS,
  daysRemainingLabel,
  deadlineStatusTone,
} from "@/lib/claims";
import { canManageOrganization } from "@/lib/permissions";
import { formatCurrencyAmount } from "@/lib/reporting";
import { TIMEZONES } from "@/lib/timezones";
import type {
  DashboardData,
  DeadlineStatus,
  MoneyAmount,
  OnboardingProgress,
  Organization,
  OrganizationRole,
} from "@/lib/types";

function CreateOrganizationCard() {
  const { createOrganization } = useAuth();
  const [name, setName] = useState("");
  const [timezone, setTimezone] = useState("UTC");
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (!name.trim()) {
      setError("Organization name is required.");
      return;
    }
    setError(null);
    setLoading(true);
    try {
      await createOrganization(name.trim(), timezone);
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Something went wrong. Please try again.",
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <Card>
      <CardHeader
        title="Create your organization"
        description="An organization is your company's workspace. You will be its owner."
      />
      <CardBody>
        <form onSubmit={handleSubmit} className="max-w-md space-y-4" noValidate>
          {error && <Alert tone="error">{error}</Alert>}
          <Input
            id="org-name"
            label="Organization name"
            type="text"
            value={name}
            onChange={(event) => setName(event.target.value)}
            placeholder="Acme Trucking Co."
          />
          <Select
            id="org-timezone"
            label="Timezone"
            value={timezone}
            onChange={(event) => setTimezone(event.target.value)}
          >
            {TIMEZONES.map((tz) => (
              <option key={tz.value} value={tz.value}>
                {tz.label}
              </option>
            ))}
          </Select>
          <Button type="submit" loading={loading}>
            Create organization
          </Button>
        </form>
      </CardBody>
    </Card>
  );
}

/**
 * Onboarding panel for the dashboard. Hidden when onboarding is
 * complete or dismissed, and when the endpoint cannot be reached.
 */
function OnboardingPanel({
  org,
  role,
}: {
  org: Organization;
  role: OrganizationRole | null;
}) {
  const [progress, setProgress] = useState<OnboardingProgress | null>(null);
  const [dismissing, setDismissing] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<{ data: OnboardingProgress }>("/onboarding", { orgId: org.id })
      .then((response) => {
        if (!cancelled) setProgress(response.data);
      })
      .catch(() => {
        // Onboarding is a guide, not a blocker: hide the panel on failure.
        if (!cancelled) setProgress(null);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id]);

  async function handleDismiss() {
    setDismissing(true);
    try {
      await apiFetch("/onboarding/dismiss", {
        method: "POST",
        orgId: org.id,
      });
      setProgress((current) =>
        current ? { ...current, dismissed: true } : current,
      );
    } catch {
      // Dismiss failing leaves the panel in place; nothing else breaks.
    } finally {
      setDismissing(false);
    }
  }

  if (!progress || progress.all_completed || progress.dismissed) return null;

  return (
    <Card>
      <CardHeader
        title="Get started"
        description="A few first steps to get your organization ready to recover money."
        action={
          <Link href="/onboarding">
            <Button variant="secondary" size="sm">
              View all steps
            </Button>
          </Link>
        }
      />
      <CardBody>
        <OnboardingChecklist
          progress={progress}
          canDismiss={canManageOrganization(role)}
          dismissing={dismissing}
          onDismiss={handleDismiss}
        />
      </CardBody>
    </Card>
  );
}

function StatCard({
  label,
  value,
  detail,
}: {
  label: string;
  value: ReactNode;
  detail?: ReactNode;
}) {
  return (
    <Card>
      <CardBody>
        <p className="text-sm text-slate-500">{label}</p>
        <p className="mt-1 text-3xl font-semibold text-slate-900">{value}</p>
        {detail && <p className="mt-1 text-xs text-slate-500">{detail}</p>}
      </CardBody>
    </Card>
  );
}

function MoneyFigure({
  title,
  description,
  rows,
}: {
  title: string;
  description: string;
  rows: MoneyAmount[];
}) {
  return (
    <Card>
      <CardHeader title={title} description={description} />
      <CardBody>
        {rows.length === 0 ? (
          <p className="text-sm text-slate-500">No money in this figure yet.</p>
        ) : (
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
        )}
      </CardBody>
    </Card>
  );
}

/**
 * Loads and renders the overview for one organization. The parent keys
 * it by organization id, so switching organizations remounts it with
 * fresh state. Date and status filters refetch the overview; the
 * status filter adds a claims_filtered section from the API.
 */
function DashboardContent({ org }: { org: Organization }) {
  const [data, setData] = useState<DashboardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [status, setStatus] = useState("");

  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams();
    if (dateFrom) params.set("date_from", dateFrom);
    if (dateTo) params.set("date_to", dateTo);
    if (status) params.set("status", status);
    const query = params.toString();

    apiFetch<{ data: DashboardData }>(
      `/dashboard${query ? `?${query}` : ""}`,
      { orgId: org.id },
    )
      .then((response) => {
        if (!cancelled) setData(response.data);
      })
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load the dashboard.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, dateFrom, dateTo, status]);

  if (loading) {
    return <Spinner label="Loading dashboard" />;
  }

  if (error) {
    return <Alert tone="error">{error}</Alert>;
  }

  if (!data) {
    return (
      <Alert tone="info">No data to show for this organization yet.</Alert>
    );
  }

  const counts = data.counts;
  const maxStatusCount = Math.max(
    0,
    ...data.claims_by_status.map((row) => row.count),
  );
  const hasFilters = dateFrom !== "" || dateTo !== "" || status !== "";

  return (
    <div className="space-y-6">
      <OnboardingPanel org={org} role={data.current_user_role} />

      <Card>
        <CardBody>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Input
              id="dashboard-date-from"
              label="From date"
              type="date"
              value={dateFrom}
              onChange={(event) => setDateFrom(event.target.value)}
              help="Filters shipments and claims by created date."
            />
            <Input
              id="dashboard-date-to"
              label="To date"
              type="date"
              value={dateTo}
              onChange={(event) => setDateTo(event.target.value)}
            />
            <Select
              id="dashboard-status"
              label="Claim status"
              value={status}
              onChange={(event) => setStatus(event.target.value)}
              help="Adds a filtered-claims summary below."
            >
              <option value="">All statuses</option>
              {CLAIM_STATUSES.map((claimStatus) => (
                <option key={claimStatus} value={claimStatus}>
                  {CLAIM_STATUS_LABELS[claimStatus]}
                </option>
              ))}
            </Select>
            {hasFilters && (
              <div className="flex items-end">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => {
                    setDateFrom("");
                    setDateTo("");
                    setStatus("");
                  }}
                >
                  Clear filters
                </Button>
              </div>
            )}
          </div>
        </CardBody>
      </Card>

      {data.claims_filtered && (
        <Card>
          <CardHeader
            title={`Filtered claims: ${data.claims_filtered.label}`}
            description="Claims in the selected status, within the date range."
          />
          <CardBody>
            <p className="text-sm text-slate-700">
              <span className="text-2xl font-semibold text-slate-900">
                {data.claims_filtered.count}
              </span>{" "}
              claims
              {data.claims_filtered.requested_amounts.length > 0 && (
                <>
                  {" "}
                  requesting{" "}
                  {data.claims_filtered.requested_amounts
                    .map((row) => formatCurrencyAmount(row.amount, row.currency))
                    .join(", ")}
                </>
              )}
            </p>
            <p className="mt-2 text-sm">
              <Link
                href="/claims"
                className="font-medium text-brand-700 hover:underline"
              >
                View claims
              </Link>
            </p>
          </CardBody>
        </Card>
      )}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          label="Members"
          value={counts.members_total}
          detail={`${counts.members_active} active, ${counts.members_inactive} inactive`}
        />
        <StatCard
          label="Pending invitations"
          value={counts.invitations_pending}
          detail={
            <Link
              href="/settings/members"
              className="text-brand-700 hover:underline"
            >
              Manage members and invitations
            </Link>
          }
        />
        <StatCard
          label="Your role"
          value={
            <span className="capitalize">
              {data.current_user_role?.replace("_", " ") ?? "Unknown"}
            </span>
          }
          detail={`in ${data.organization.name}`}
        />
        <StatCard
          label="Organization timezone"
          value={<span className="text-lg">{data.organization.timezone}</span>}
          detail={
            <>
              Member since{" "}
              {data.member_since
                ? new Date(data.member_since).toLocaleDateString()
                : "Unknown"}
            </>
          }
        />
      </div>

      <div>
        <h2 className="text-lg font-semibold text-slate-900">Shipments and events</h2>
        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            label="Shipments"
            value={counts.shipments_total}
            detail={
              <Link href="/shipments" className="text-brand-700 hover:underline">
                View shipments
              </Link>
            }
          />
          <StatCard
            label="Shipments with events"
            value={counts.shipments_with_events}
            detail={`${counts.events_total} events in total`}
          />
          <StatCard
            label="Shipments with opportunities"
            value={counts.shipments_with_opportunities}
            detail="Shipments with at least one open potential event"
          />
          <StatCard
            label="Open events"
            value={counts.events_open}
            detail="Potential events not yet reviewed or dismissed"
          />
          <StatCard
            label="Evidence files"
            value={counts.evidence_total}
            detail={
              <Link href="/evidence" className="text-brand-700 hover:underline">
                View evidence
              </Link>
            }
          />
        </div>
      </div>

      <div>
        <h2 className="text-lg font-semibold text-slate-900">Claims</h2>
        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            label="Open claims"
            value={counts.claims_open}
            detail={`${counts.claims_total} claims in total`}
          />
          <StatCard
            label="Open opportunities"
            value={counts.claims_open_opportunities}
            detail="Potential, evidence required, or under review"
          />
          <StatCard
            label="Awaiting evidence"
            value={counts.claims_awaiting_evidence}
            detail={
              <Link href="/claims" className="text-brand-700 hover:underline">
                View claims
              </Link>
            }
          />
          <StatCard
            label="Ready to submit"
            value={counts.claims_ready}
            detail={
              <Link href="/claims" className="text-brand-700 hover:underline">
                View claims
              </Link>
            }
          />
          <StatCard
            label="Submitted"
            value={counts.claims_submitted}
            detail="Waiting on the broker"
          />
          <StatCard
            label="Approved"
            value={counts.claims_approved}
            detail="Accepted or partially accepted"
          />
          <StatCard
            label="Rejected"
            value={counts.claims_rejected}
            detail="Turned down by the broker"
          />
          <StatCard
            label="Paid"
            value={counts.claims_paid}
            detail="Payment recorded"
          />
          <StatCard
            label="Closed"
            value={counts.claims_closed}
            detail="Closed without payment"
          />
        </div>
      </div>

      <div>
        <h2 className="text-lg font-semibold text-slate-900">Money</h2>
        <p className="mt-1 text-sm text-slate-500">
          Every figure is shown per currency. Currencies are never mixed and
          no conversion is applied.
        </p>
        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <MoneyFigure
            title="Estimated potential"
            description="Requested on claims still being prepared. Estimated money, not recovered money."
            rows={data.financials.potential}
          />
          <MoneyFigure
            title="Submitted"
            description="Requested on claims that reached the broker."
            rows={data.financials.submitted}
          />
          <MoneyFigure
            title="Approved"
            description="Accepted amounts on accepted, partially accepted, and paid claims."
            rows={data.financials.accepted}
          />
          <MoneyFigure
            title="Recovered"
            description="Actual money received on paid claims."
            rows={data.financials.recovered}
          />
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader
            title="Claims by status"
            description={`${counts.claims_total} claims in this range.`}
          />
          <CardBody>
            {counts.claims_total === 0 ? (
              <EmptyState
                title="No claims yet"
                description="Create a claim from a shipment event to start tracking recovery."
              />
            ) : (
              <div className="space-y-3">
                {data.claims_by_status.map((row) => (
                  <CountBar
                    key={row.status}
                    label={row.label}
                    count={row.count}
                    maxCount={maxStatusCount}
                  />
                ))}
              </div>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader
            title="Deadlines in the next 14 days"
            description={`${counts.deadlines_next_14_days} active deadlines due within 14 days.`}
          />
          <CardBody>
            <div className="mb-4 flex flex-wrap gap-4 text-sm text-slate-600">
              <span>{counts.deadlines_upcoming} upcoming</span>
              <span>{counts.deadlines_due_soon} due soon</span>
              <span>{counts.deadlines_overdue} overdue</span>
            </div>
            {data.upcoming_deadlines.length === 0 ? (
              <EmptyState
                title="No deadlines coming up"
                description="No active deadlines are due in the next 14 days."
              />
            ) : (
              <ul className="space-y-3">
                {data.upcoming_deadlines.map((deadline) => (
                  <li
                    key={deadline.deadline_id}
                    className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3 last:border-0 last:pb-0"
                  >
                    <div>
                      <Link
                        href={`/claims/${deadline.claim_id}`}
                        className="font-medium text-brand-700 hover:underline"
                      >
                        {deadline.claim_reference}
                      </Link>
                      <p className="text-xs text-slate-500">
                        Due {new Date(deadline.deadline_at).toLocaleDateString()} ·{" "}
                        {daysRemainingLabel(deadline.deadline_at)}
                      </p>
                    </div>
                    <Badge
                      tone={deadlineStatusTone(deadline.status as DeadlineStatus)}
                    >
                      {deadline.status_label}
                    </Badge>
                  </li>
                ))}
              </ul>
            )}
            <p className="mt-4 text-sm">
              <Link
                href="/deadlines"
                className="font-medium text-brand-700 hover:underline"
              >
                View all deadlines
              </Link>
            </p>
          </CardBody>
        </Card>
      </div>
    </div>
  );
}

export default function DashboardPage() {
  const { user, currentOrg, organizations } = useAuth();
  const [resendMessage, setResendMessage] = useState<string | null>(null);
  const [resending, setResending] = useState(false);

  async function resendVerification() {
    setResendMessage(null);
    setResending(true);
    try {
      const response = await apiFetch<{ message: string }>(
        "/email/verification-notification",
        { method: "POST", withOrg: false },
      );
      setResendMessage(response.message);
    } catch (err) {
      setResendMessage(
        err instanceof ApiRequestError
          ? err.message
          : "Something went wrong. Please try again.",
      );
    } finally {
      setResending(false);
    }
  }

  return (
    <div>
      <PageHeader
        title="Dashboard"
        description={
          currentOrg
            ? `Overview for ${currentOrg.name}.`
            : "Get started by creating your organization."
        }
      />

      {user && !user.email_verified_at && (
        <div className="mb-6">
          <Alert tone="warning">
            Your email address is not verified yet.{" "}
            <button
              type="button"
              onClick={resendVerification}
              disabled={resending}
              className="font-medium underline hover:text-amber-900 disabled:no-underline disabled:opacity-60"
            >
              {resending ? "Sending..." : "Send a new verification link"}
            </button>
            {resendMessage && <span className="ml-2">{resendMessage}</span>}
          </Alert>
        </div>
      )}

      {organizations.length === 0 ? (
        <CreateOrganizationCard />
      ) : currentOrg ? (
        <DashboardContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Select an organization to see its overview.</Alert>
      )}
    </div>
  );
}
