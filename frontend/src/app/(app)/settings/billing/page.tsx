"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { Table, TableCell } from "@/components/ui/Table";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import {
  MOCK_TEST_CARDS,
  cancelSubscription,
  changePlan,
  completeCheckout,
  createPortalSession,
  formatCents,
  getInvoices,
  getPlans,
  getSubscription,
  intervalLabel,
  invoiceLineKindLabel,
  invoiceStatusLabel,
  invoiceStatusTone,
  payInvoice,
  previewPlanChange,
  resumeSubscription,
  startCheckout,
  startTrial,
  subscriptionStatusLabel,
  subscriptionStatusTone,
  updatePaymentMethod,
  type PlanChangePreview,
} from "@/lib/billing";
import { canManageBilling } from "@/lib/permissions";
import type {
  BillingInvoice,
  BillingPlan,
  BillingSubscription,
  Organization,
  PaginatedMeta,
} from "@/lib/types";

type BadgeTone = "neutral" | "success" | "warning" | "danger" | "info";

function formatDate(value: string | null): string {
  if (value === null) return "Never";
  return new Date(value).toLocaleDateString();
}

function planPrice(plan: BillingPlan, interval: "monthly" | "annual"): string {
  if (plan.monthly_cents === 0) return "$0";
  if (interval === "annual") {
    return `${formatCents(plan.annual_cents)}/year`;
  }
  return `${formatCents(plan.monthly_cents)}/month`;
}

function limitsSummary(plan: BillingPlan): string {
  const parts: string[] = [];
  if (plan.trucks) parts.push(plan.trucks);
  const claims = plan.limits.claims_per_month;
  if (typeof claims === "number") parts.push(`${claims} claims/month`);
  const members = plan.limits.members;
  if (typeof members === "number") parts.push(`${members} members`);
  const storage = plan.limits.storage;
  if (typeof storage === "string") parts.push(`${storage} storage`);
  return parts.join(" · ");
}

/**
 * Billing hub for one organization. The parent keys it by
 * organization id, so switching organizations remounts it with
 * fresh state.
 *
 * The provider is the mock (see docs/BILLING.md): checkout is
 * completed in-app with a Stripe test card, and no real money
 * moves. The test card selector at the top of the plans section
 * is the card used for every checkout and payment on this page.
 */
function BillingContent({ org }: { org: Organization }) {
  const canManage = canManageBilling(org.current_user_role);

  const [plans, setPlans] = useState<BillingPlan[]>([]);
  const [subscription, setSubscription] =
    useState<BillingSubscription | null>(null);
  const [invoices, setInvoices] = useState<BillingInvoice[]>([]);
  const [invoicesMeta, setInvoicesMeta] = useState<PaginatedMeta | null>(null);
  // No loading state for roles that cannot see the page: the
  // access-denied alert renders immediately instead of a spinner.
  const [loading, setLoading] = useState(canManage);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busyAction, setBusyAction] = useState<string | null>(null);
  const [interval, setInterval] = useState<"monthly" | "annual">("monthly");
  const [testCard, setTestCard] = useState<string>("pm_mock_visa");
  const [preview, setPreview] = useState<{
    plan: BillingPlan;
    preview: PlanChangePreview;
  } | null>(null);
  const [portalUrl, setPortalUrl] = useState<string | null>(null);

  // Monotonic request id: a superseded load must not overwrite
  // newer state with stale data.
  const requestIdRef = useRef(0);

  /**
   * Reloads every section. Each section loads independently, so
   * one failing endpoint shows an error but does not hide the
   * sections that did load.
   */
  const loadAll = useCallback(async (orgId: string) => {
    const requestId = ++requestIdRef.current;
    const results = await Promise.allSettled([
      getPlans(orgId),
      getSubscription(orgId),
      getInvoices(orgId),
    ]);
    if (requestId !== requestIdRef.current) return;

    const failures: string[] = [];
    if (results[0].status === "fulfilled") {
      setPlans(results[0].value);
    } else {
      failures.push("plans");
    }
    if (results[1].status === "fulfilled") {
      setSubscription(results[1].value);
    } else {
      failures.push("subscription");
    }
    if (results[2].status === "fulfilled") {
      setInvoices(results[2].value.data);
      setInvoicesMeta(results[2].value.meta);
    } else {
      failures.push("invoices");
    }
    setError(
      failures.length > 0
        ? `Could not load ${failures.join(", ")}. Try refreshing.`
        : null,
    );
    setLoading(false);
    setRefreshing(false);
  }, []);

  useEffect(() => {
    if (!canManage) return;
    // loadAll only sets state after its fetches resolve; the rule
    // cannot see through the async boundary, so this is the same
    // external-system sync the rule allows (see auth.tsx).
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void loadAll(org.id);
  }, [org.id, canManage, loadAll]);

  /** Runs an action, then refreshes the page data. */
  async function runAction(actionKey: string, action: () => Promise<string>) {
    setBusyAction(actionKey);
    setError(null);
    setNotice(null);
    try {
      const message = await action();
      setNotice(message);
      await loadAll(org.id);
    } catch (err) {
      setError(
        err instanceof ApiRequestError ? err.message : "The action failed.",
      );
    } finally {
      setBusyAction(null);
    }
  }

  function handleSelectPlan(plan: BillingPlan) {
    if (!subscription) return;
    if (plan.key === subscription.plan) return;
    // Free moves through checkout; paid plans move through the
    // plan-change flow, which previews proration first.
    if (subscription.plan === "free") {
      void runAction(`subscribe:${plan.key}`, async () => {
        const session = await startCheckout(org.id, plan.key, interval);
        await completeCheckout(org.id, session.id, testCard);
        return `Subscribed to ${plan.name}.`;
      });
      return;
    }
    setPreview(null);
    setBusyAction(`preview:${plan.key}`);
    setError(null);
    previewPlanChange(org.id, plan.key, interval)
      .then((result) => {
        setPreview({ plan, preview: result });
      })
      .catch((err: unknown) => {
        setError(
          err instanceof ApiRequestError
            ? err.message
            : "Could not preview the plan change.",
        );
      })
      .finally(() => {
        setBusyAction(null);
      });
  }

  function handleConfirmPlanChange() {
    if (!preview) return;
    const plan = preview.plan;
    void runAction(`change:${plan.key}`, async () => {
      const result = await changePlan(org.id, plan.key, interval);
      setPreview(null);
      if (result.effective === "at_period_end") {
        return `${plan.name} takes effect at the end of the current period.`;
      }
      return `Changed to ${plan.name}.`;
    });
  }

  function handleStartTrial() {
    void runAction("trial", async () => {
      await startTrial(org.id, testCard === "" ? null : testCard);
      return "30-day Growth trial started. No card is charged during the trial.";
    });
  }

  function handleSwitchToSuccessFee() {
    if (!subscription) return;
    if (subscription.plan === "free") {
      void runAction("success-fee", async () => {
        const session = await startCheckout(org.id, "success_fee", "monthly");
        await completeCheckout(org.id, session.id, null);
        return "Switched to the Success Fee plan: 15% of what you collect, capped at $49/month for the first year.";
      });
      return;
    }
    void runAction("success-fee", async () => {
      const result = await changePlan(org.id, "success_fee", "monthly");
      if (result.effective === "at_period_end") {
        return "Success Fee takes effect at the end of the current period.";
      }
      return "Switched to the Success Fee plan.";
    });
  }

  function handleCancel(immediate: boolean) {
    void runAction(immediate ? "cancel-now" : "cancel-period-end", async () => {
      await cancelSubscription(org.id, immediate);
      return immediate
        ? "Subscription canceled immediately. The organization is now on Free."
        : "Subscription will cancel at the end of the current period.";
    });
  }

  function handleResume() {
    void runAction("resume", async () => {
      await resumeSubscription(org.id);
      return "Cancellation undone. The subscription continues.";
    });
  }

  function handlePayInvoice(invoice: BillingInvoice) {
    void runAction(`pay:${invoice.id}`, async () => {
      await payInvoice(org.id, invoice.id, testCard === "" ? null : testCard);
      return `Invoice ${invoice.number} paid.`;
    });
  }

  function handleSavePaymentMethod() {
    if (testCard === "") {
      setError("Choose a test card first.");
      return;
    }
    void runAction("payment-method", async () => {
      await updatePaymentMethod(org.id, testCard);
      return "Payment method saved.";
    });
  }

  function handleOpenPortal() {
    void runAction("portal", async () => {
      const session = await createPortalSession(org.id);
      setPortalUrl(session.url);
      return "Billing portal session created. The link below opens the mock portal.";
    });
  }

  if (!canManage) {
    return (
      <Alert tone="info">
        Billing settings are not available for your role in this organization.
      </Alert>
    );
  }

  const currentPlan =
    plans.find((plan) => plan.key === subscription?.plan) ?? null;
  const usage = subscription?.usage ?? null;
  const listedPlans = plans.filter((plan) => plan.listed);
  const trialAvailable =
    subscription?.plan === "free" && subscription.status !== "trialing";

  return (
    <div>
      {error && (
        <div className="mb-4">
          <Alert tone="error">{error}</Alert>
        </div>
      )}
      {notice && (
        <div className="mb-4">
          <Alert tone="success">{notice}</Alert>
        </div>
      )}

      {loading ? (
        <Spinner label="Loading billing" />
      ) : (
        <div className="space-y-6">
          <div className="flex justify-end">
            <Button
              variant="secondary"
              size="sm"
              loading={refreshing}
              onClick={() => {
                setRefreshing(true);
                void loadAll(org.id);
              }}
            >
              Refresh
            </Button>
          </div>

          <Card>
            <CardHeader
              title="Current subscription"
              description={
                subscription
                  ? `${subscription.plan_name} · ${intervalLabel(subscription.interval)} billing`
                  : "No subscription yet."
              }
              action={
                subscription ? (
                  <Badge tone={subscriptionStatusTone(subscription.status)}>
                    {subscriptionStatusLabel(subscription.status)}
                  </Badge>
                ) : undefined
              }
            />
            {subscription ? (
              <CardBody>
                <dl className="grid gap-3 text-sm sm:grid-cols-2">
                  <div>
                    <dt className="text-slate-500">Plan</dt>
                    <dd className="font-medium text-slate-900">
                      {subscription.plan_name}
                      {currentPlan?.description
                        ? ` — ${currentPlan.description}`
                        : ""}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-slate-500">Price</dt>
                    <dd className="font-medium text-slate-900">
                      {formatCents(subscription.period_cents)} per{" "}
                      {subscription.interval === "annual" ? "year" : "month"}
                      {subscription.extra_carriers > 0
                        ? ` (${subscription.extra_carriers} extra carriers)`
                        : ""}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-slate-500">Current period</dt>
                    <dd className="font-medium text-slate-900">
                      {formatDate(subscription.current_period_start)} –{" "}
                      {formatDate(subscription.current_period_end)}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-slate-500">Payment method</dt>
                    <dd className="font-medium text-slate-900">
                      {subscription.payment_method_brand
                        ? `${subscription.payment_method_brand} ending ${subscription.payment_method_last4}`
                        : "No card on file"}
                    </dd>
                  </div>
                  {subscription.trial_ends_at && (
                    <div>
                      <dt className="text-slate-500">Trial ends</dt>
                      <dd className="font-medium text-slate-900">
                        {formatDate(subscription.trial_ends_at)}
                      </dd>
                    </div>
                  )}
                  {subscription.credit_balance_cents > 0 && (
                    <div>
                      <dt className="text-slate-500">Credit balance</dt>
                      <dd className="font-medium text-slate-900">
                        {formatCents(subscription.credit_balance_cents)}
                      </dd>
                    </div>
                  )}
                </dl>
                {subscription.pending_plan && (
                  <p className="mt-3 text-sm text-slate-600">
                    {subscription.pending_plan} takes effect at the end of the
                    current period.
                  </p>
                )}
                {subscription.cancel_at_period_end && (
                  <p className="mt-3 text-sm text-slate-600">
                    This subscription cancels at the end of the current period.
                  </p>
                )}
                <div className="mt-4 flex flex-wrap gap-2">
                  {subscription.cancel_at_period_end ? (
                    <Button
                      variant="secondary"
                      size="sm"
                      loading={busyAction === "resume"}
                      onClick={handleResume}
                    >
                      Resume subscription
                    </Button>
                  ) : (
                    subscription.plan !== "free" && (
                      <>
                        <Button
                          variant="secondary"
                          size="sm"
                          loading={busyAction === "cancel-period-end"}
                          onClick={() => handleCancel(false)}
                        >
                          Cancel at period end
                        </Button>
                        <Button
                          variant="secondary"
                          size="sm"
                          loading={busyAction === "cancel-now"}
                          onClick={() => handleCancel(true)}
                        >
                          Cancel immediately
                        </Button>
                      </>
                    )
                  )}
                </div>
              </CardBody>
            ) : (
              <CardBody>
                <EmptyState
                  title="Subscription unavailable"
                  description="The subscription could not be loaded."
                />
              </CardBody>
            )}
          </Card>

          <Card>
            <CardHeader
              title="Plans"
              description="Monthly or annual billing. Annual is 10x the monthly price, two months free. Plan limits are shown here; enforcement comes in v1.2.1."
              action={
                <div className="flex gap-1" role="group" aria-label="Billing interval">
                  <Button
                    variant={interval === "monthly" ? "primary" : "secondary"}
                    size="sm"
                    onClick={() => setInterval("monthly")}
                  >
                    Monthly
                  </Button>
                  <Button
                    variant={interval === "annual" ? "primary" : "secondary"}
                    size="sm"
                    onClick={() => setInterval("annual")}
                  >
                    Annual
                  </Button>
                </div>
              }
            />
            <CardBody>
              <div className="mb-4 flex flex-wrap items-end gap-3">
                <label className="block text-sm font-medium text-slate-700">
                  Test card for checkout and payments
                  <select
                    value={testCard}
                    onChange={(event) => setTestCard(event.target.value)}
                    className="mt-1 block min-h-11 w-full rounded-md border border-slate-300 bg-white px-2.5 py-2 text-sm text-slate-900 sm:w-auto"
                  >
                    <option value="">No card</option>
                    {MOCK_TEST_CARDS.map((card) => (
                      <option key={card.token} value={card.token}>
                        {card.label}
                      </option>
                    ))}
                  </select>
                </label>
                <p className="text-sm text-slate-500">
                  Mock billing only: these are Stripe test cards and no real
                  money moves.
                </p>
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                {listedPlans.map((plan) => {
                  const isCurrent = plan.key === subscription?.plan;
                  const needsCard =
                    plan.monthly_cents > 0 && testCard === "";
                  return (
                    <div
                      key={plan.key}
                      className={`rounded-lg border p-4 ${
                        isCurrent
                          ? "border-amber-500 bg-amber-50"
                          : "border-slate-200"
                      }`}
                    >
                      <div className="flex items-start justify-between gap-2">
                        <div>
                          <p className="text-sm font-semibold text-slate-900">
                            {plan.name}
                          </p>
                          <p className="text-lg font-bold text-slate-900">
                            {planPrice(plan, interval)}
                          </p>
                        </div>
                        {isCurrent && <Badge tone="success">Current plan</Badge>}
                      </div>
                      {plan.description && (
                        <p className="mt-1 text-sm text-slate-500">
                          {plan.description}
                        </p>
                      )}
                      <p className="mt-2 text-sm text-slate-600">
                        {limitsSummary(plan)}
                      </p>
                      {!isCurrent && (
                        <div className="mt-3">
                          <Button
                            variant="secondary"
                            size="sm"
                            loading={
                              busyAction === `subscribe:${plan.key}` ||
                              busyAction === `preview:${plan.key}`
                            }
                            disabled={needsCard}
                            onClick={() => handleSelectPlan(plan)}
                          >
                            {subscription?.plan === "free"
                              ? `Subscribe to ${plan.name}`
                              : `Change to ${plan.name}`}
                          </Button>
                          {needsCard && (
                            <p className="mt-1 text-xs text-slate-500">
                              Choose a test card above to subscribe.
                            </p>
                          )}
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>

              {preview && (
                <div className="mt-4 rounded-lg border border-amber-500 bg-amber-50 p-4">
                  <p className="text-sm font-semibold text-slate-900">
                    Change to {preview.plan.name}
                  </p>
                  <p className="mt-1 text-sm text-slate-600">
                    {preview.preview.effective === "immediately"
                      ? `Takes effect immediately. ${preview.preview.proration.remaining_days} days remain in this period: credit ${formatCents(preview.preview.proration.credit_cents)}, charge ${formatCents(preview.preview.proration.charge_cents)}, net ${formatCents(preview.preview.proration.net_cents)} due now.`
                      : `Takes effect at the end of the current period (${formatDate(preview.preview.current_period_end)}). No charge today.`}
                  </p>
                  <div className="mt-3 flex gap-2">
                    <Button
                      size="sm"
                      loading={busyAction === `change:${preview.plan.key}`}
                      onClick={handleConfirmPlanChange}
                    >
                      Confirm change
                    </Button>
                    <Button
                      variant="secondary"
                      size="sm"
                      onClick={() => setPreview(null)}
                    >
                      Cancel
                    </Button>
                  </div>
                </div>
              )}

              {trialAvailable && (
                <div className="mt-4 rounded-lg border border-slate-200 p-4">
                  <p className="text-sm font-semibold text-slate-900">
                    Try Growth free for 30 days
                  </p>
                  <p className="mt-1 text-sm text-slate-600">
                    No card required. The trial converts to paid Growth at the
                    end of 30 days if a card is on file; otherwise it lapses
                    back to Free.
                  </p>
                  <div className="mt-3">
                    <Button
                      variant="secondary"
                      size="sm"
                      loading={busyAction === "trial"}
                      onClick={handleStartTrial}
                    >
                      Start 30-day free trial
                    </Button>
                  </div>
                </div>
              )}

              {subscription?.plan !== "success_fee" && (
                <div className="mt-4 rounded-lg border border-slate-200 p-4">
                  <p className="text-sm font-semibold text-slate-900">
                    Can&apos;t pay yet?
                  </p>
                  <p className="mt-1 text-sm text-slate-600">
                    The Success Fee plan has no monthly price: it charges 15%
                    of the amounts you record as paid, capped at $49/month for
                    the first year, invoiced monthly in arrears.
                  </p>
                  <div className="mt-3">
                    <Button
                      variant="secondary"
                      size="sm"
                      loading={busyAction === "success-fee"}
                      onClick={handleSwitchToSuccessFee}
                    >
                      Switch to Success Fee
                    </Button>
                  </div>
                </div>
              )}
            </CardBody>
          </Card>

          {subscription?.plan === "success_fee" && usage && (
            <Card>
              <CardHeader
                title="Success-fee usage this period"
                description="15% of the amounts recorded as paid, invoiced monthly in arrears. The first-year cap is $49 per month."
              />
              <CardBody>
                <dl className="grid gap-3 text-sm sm:grid-cols-3">
                  <div>
                    <dt className="text-slate-500">Recovered this period</dt>
                    <dd className="font-medium text-slate-900">
                      {formatCents(usage.recovered_cents)}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-slate-500">Fee accrued</dt>
                    <dd className="font-medium text-slate-900">
                      {formatCents(usage.accrued_fee_cents)}
                    </dd>
                  </div>
                  <div>
                    <dt className="text-slate-500">Cap remaining</dt>
                    <dd className="font-medium text-slate-900">
                      {usage.cap_remaining_cents !== null
                        ? formatCents(usage.cap_remaining_cents)
                        : "No cap"}
                    </dd>
                  </div>
                </dl>
                {usage.records.length > 0 ? (
                  <Table headers={["Claim", "Recovered", "Fee", "Recorded"]}>
                    {usage.records.map((record) => (
                      <tr key={record.id}>
                        <TableCell className="font-medium">
                          {record.claim_id ?? "—"}
                        </TableCell>
                        <TableCell>{formatCents(record.amount_cents)}</TableCell>
                        <TableCell>{formatCents(record.fee_cents)}</TableCell>
                        <TableCell>{formatDate(record.recorded_at)}</TableCell>
                      </tr>
                    ))}
                  </Table>
                ) : (
                  <p className="mt-3 text-sm text-slate-500">
                    No amounts recorded as paid in this period yet.
                  </p>
                )}
              </CardBody>
            </Card>
          )}

          <Card>
            <CardHeader
              title="Payment method"
              description="The card charged for renewals and invoice payments. Mock billing only: pick a Stripe test card."
            />
            <CardBody>
              <p className="text-sm text-slate-600">
                {subscription?.payment_method_brand
                  ? `On file: ${subscription.payment_method_brand} ending ${subscription.payment_method_last4}.`
                  : "No card on file."}
              </p>
              <div className="mt-3">
                <Button
                  variant="secondary"
                  size="sm"
                  loading={busyAction === "payment-method"}
                  disabled={testCard === ""}
                  onClick={handleSavePaymentMethod}
                >
                  Save selected test card
                </Button>
              </div>
            </CardBody>
          </Card>

          <Card>
            <CardHeader
              title="Invoices"
              description="Newest first. Open and past-due invoices can be paid here with the selected test card."
            />
            {invoices.length > 0 ? (
              <Table
                headers={["Invoice", "Status", "Total", "Due", "Paid", ""]}
              >
                {invoices.map((invoice) => (
                  <tr key={invoice.id}>
                    <TableCell className="font-medium">
                      {invoice.number}
                      {invoice.lines.length > 0 && (
                        <span className="block text-xs font-normal text-slate-500">
                          {invoice.lines
                            .map(
                              (line) =>
                                `${invoiceLineKindLabel(line.kind)}: ${formatCents(line.amount_cents)}`,
                            )
                            .join(" · ")}
                        </span>
                      )}
                      {invoice.failure_message && (
                        <span className="block text-xs font-normal text-red-600">
                          {invoice.failure_message}
                        </span>
                      )}
                    </TableCell>
                    <TableCell>
                      <Badge tone={invoiceStatusTone(invoice.status) as BadgeTone}>
                        {invoiceStatusLabel(invoice.status)}
                      </Badge>
                    </TableCell>
                    <TableCell>{formatCents(invoice.total_cents)}</TableCell>
                    <TableCell>{formatDate(invoice.due_date)}</TableCell>
                    <TableCell>{formatDate(invoice.paid_at)}</TableCell>
                    <TableCell>
                      {(invoice.status === "open" ||
                        invoice.status === "past_due") && (
                        <Button
                          variant="secondary"
                          size="sm"
                          loading={busyAction === `pay:${invoice.id}`}
                          onClick={() => handlePayInvoice(invoice)}
                        >
                          Pay now
                        </Button>
                      )}
                    </TableCell>
                  </tr>
                ))}
              </Table>
            ) : (
              <CardBody>
                <EmptyState
                  title="No invoices yet"
                  description="Invoices appear here after a checkout, renewal, or plan change."
                />
              </CardBody>
            )}
            {invoicesMeta && invoicesMeta.total > invoices.length && (
              <p className="border-t border-slate-200 px-5 py-3 text-sm text-slate-500">
                Showing {invoices.length} of {invoicesMeta.total} invoices.
              </p>
            )}
          </Card>

          <Card>
            <CardHeader
              title="Billing portal"
              description="The mocked customer portal: subscription, invoices, and payment method in one place. Real Stripe replaces this with a Stripe-hosted page."
            />
            <CardBody>
              <Button
                variant="secondary"
                size="sm"
                loading={busyAction === "portal"}
                onClick={handleOpenPortal}
              >
                Open billing portal
              </Button>
              {portalUrl && (
                <p className="mt-3 text-sm text-slate-600">
                  Portal link:{" "}
                  <a
                    href={portalUrl}
                    target="_blank"
                    rel="noreferrer"
                    className="text-blue-700 underline"
                  >
                    {portalUrl}
                  </a>
                </p>
              )}
            </CardBody>
          </Card>
        </div>
      )}
    </div>
  );
}

export default function BillingPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Billing"
        description={
          currentOrg
            ? `Subscription, plans, invoices, and the billing portal for ${currentOrg.name}.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Billing" },
        ]}
      />
      {currentOrg ? (
        <BillingContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">
          Create an organization first to see its billing settings.
        </Alert>
      )}
    </div>
  );
}
