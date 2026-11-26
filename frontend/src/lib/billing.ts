import { apiFetch } from "./api";
import type {
  BillingCheckoutSession,
  BillingEvent,
  BillingInvoice,
  BillingPlan,
  BillingPortalSession,
  BillingProration,
  BillingRefund,
  BillingSubscription,
  BillingUsageSummary,
  PaginatedMeta,
} from "./types";

/**
 * Typed client for the billing endpoints (GET /billing/*).
 *
 * Every call is tenant-scoped: apiFetch attaches the stored
 * organization id, and each function here takes the org id
 * explicitly so pages pass the current organization rather than
 * relying on stored state. The backend requires the
 * manage-billing permission (Owner, Admin, Billing Staff) for
 * all of these except the plan catalog, which any member may
 * read; the UI gates on canManageBilling and still handles 403
 * responses gracefully.
 *
 * The provider is the mock (see docs/BILLING.md): checkout is
 * completed in-app, cards are the Stripe test numbers, and no
 * real money moves. The test cards are pm_mock_visa (4242,
 * succeeds) and pm_mock_declined (4000...0002, declines).
 */

/** The two mock test cards the billing page offers. */
export const MOCK_TEST_CARDS = [
  { token: "pm_mock_visa", label: "Visa ending 4242 (succeeds)" },
  { token: "pm_mock_declined", label: "Card ending 0002 (declines)" },
] as const;

export async function getPlans(orgId: string): Promise<BillingPlan[]> {
  const response = await apiFetch<{ data: BillingPlan[] }>("/billing/plans", {
    orgId,
  });
  return response.data;
}

export async function getSubscription(
  orgId: string,
): Promise<BillingSubscription> {
  const response = await apiFetch<{ data: BillingSubscription }>(
    "/billing/subscription",
    { orgId },
  );
  return response.data;
}

export async function startCheckout(
  orgId: string,
  plan: string,
  interval: "monthly" | "annual",
  extraCarriers = 0,
): Promise<BillingCheckoutSession> {
  const response = await apiFetch<{ data: BillingCheckoutSession }>(
    "/billing/checkout",
    {
      method: "POST",
      orgId,
      body: { plan, interval, extra_carriers: extraCarriers },
    },
  );
  return response.data;
}

export async function completeCheckout(
  orgId: string,
  sessionId: string,
  paymentMethod: string | null,
): Promise<BillingSubscription> {
  const response = await apiFetch<{ data: BillingSubscription }>(
    `/billing/checkout/${encodeURIComponent(sessionId)}/complete`,
    { method: "POST", orgId, body: { payment_method: paymentMethod } },
  );
  return response.data;
}

export async function startTrial(
  orgId: string,
  paymentMethod: string | null,
): Promise<BillingSubscription> {
  const response = await apiFetch<{ data: BillingSubscription }>(
    "/billing/trials",
    { method: "POST", orgId, body: { payment_method: paymentMethod } },
  );
  return response.data;
}

export interface PlanChangePreview {
  proration: BillingProration;
  effective: "immediately" | "at_period_end";
  current_plan: string;
  current_period_end: string | null;
}

export async function previewPlanChange(
  orgId: string,
  plan: string,
  interval: "monthly" | "annual",
  extraCarriers = 0,
): Promise<PlanChangePreview> {
  const response = await apiFetch<{ data: PlanChangePreview }>(
    "/billing/subscription/change-plan",
    {
      method: "POST",
      orgId,
      body: { plan, interval, extra_carriers: extraCarriers, preview: true },
    },
  );
  return response.data;
}

export interface PlanChangeResult {
  subscription: BillingSubscription;
  invoice: BillingInvoice | null;
  proration: BillingProration;
  effective: "immediately" | "at_period_end";
}

export async function changePlan(
  orgId: string,
  plan: string,
  interval: "monthly" | "annual",
  extraCarriers = 0,
): Promise<PlanChangeResult> {
  const response = await apiFetch<{ data: PlanChangeResult }>(
    "/billing/subscription/change-plan",
    {
      method: "POST",
      orgId,
      body: { plan, interval, extra_carriers: extraCarriers },
    },
  );
  return response.data;
}

export async function cancelSubscription(
  orgId: string,
  immediate: boolean,
): Promise<BillingSubscription> {
  const response = await apiFetch<{ data: BillingSubscription }>(
    "/billing/subscription/cancel",
    { method: "POST", orgId, body: { immediate } },
  );
  return response.data;
}

export async function resumeSubscription(
  orgId: string,
): Promise<BillingSubscription> {
  const response = await apiFetch<{ data: BillingSubscription }>(
    "/billing/subscription/resume",
    { method: "POST", orgId },
  );
  return response.data;
}

export async function getInvoices(
  orgId: string,
  page = 1,
  perPage = 15,
): Promise<{ data: BillingInvoice[]; meta: PaginatedMeta }> {
  const params = new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
  });
  return apiFetch<{ data: BillingInvoice[]; meta: PaginatedMeta }>(
    `/billing/invoices?${params.toString()}`,
    { orgId },
  );
}

export async function payInvoice(
  orgId: string,
  invoiceId: string,
  paymentMethod: string | null,
): Promise<BillingInvoice> {
  const response = await apiFetch<{ data: BillingInvoice }>(
    `/billing/invoices/${encodeURIComponent(invoiceId)}/pay`,
    { method: "POST", orgId, body: { payment_method: paymentMethod } },
  );
  return response.data;
}

/**
 * Refund a paid invoice, in full or in part. The refund is
 * recorded against the mock provider, the invoice's refunded
 * total grows, and the refund appears in the billing event log.
 * A full refund sets the invoice status to refunded; a partial
 * refund sets it to partially_refunded. The reason is required:
 * refunds are issued case by case, and the reason is the record
 * of why. See docs/BILLING.md for the process.
 */
export async function refundInvoice(
  orgId: string,
  invoiceId: string,
  amountCents: number | null,
  reason: string,
): Promise<BillingRefund> {
  const response = await apiFetch<{ data: BillingRefund }>(
    `/billing/invoices/${encodeURIComponent(invoiceId)}/refund`,
    {
      method: "POST",
      orgId,
      body: { amount_cents: amountCents, reason },
    },
  );
  return response.data;
}

/** Recent billing events for the organization, newest first. */
export async function getBillingEvents(
  orgId: string,
  page = 1,
  perPage = 15,
): Promise<{ data: BillingEvent[]; meta: PaginatedMeta }> {
  const params = new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
  });
  return apiFetch<{ data: BillingEvent[]; meta: PaginatedMeta }>(
    `/billing/events?${params.toString()}`,
    { orgId },
  );
}

export async function updatePaymentMethod(
  orgId: string,
  paymentMethod: string,
): Promise<BillingSubscription> {
  const response = await apiFetch<{ data: BillingSubscription }>(
    "/billing/payment-method",
    { method: "POST", orgId, body: { payment_method: paymentMethod } },
  );
  return response.data;
}

export async function createPortalSession(
  orgId: string,
): Promise<BillingPortalSession> {
  const response = await apiFetch<{ data: BillingPortalSession }>(
    "/billing/portal",
    { method: "POST", orgId },
  );
  return response.data;
}

export async function getUsage(orgId: string): Promise<BillingUsageSummary> {
  const response = await apiFetch<{ data: BillingUsageSummary }>(
    "/billing/usage",
    { orgId },
  );
  return response.data;
}

/** Cents as "$49.00". Negative amounts keep the minus sign. */
export function formatCents(cents: number): string {
  const sign = cents < 0 ? "-" : "";
  return `${sign}$${(Math.abs(cents) / 100).toFixed(2)}`;
}

/** Format a byte count the way the billing limits are shown (100 MB, 1 GB). */
export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  const units = ["KB", "MB", "GB", "TB"];
  let value = bytes / 1024;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }
  const rounded = value >= 100 ? Math.round(value) : Math.round(value * 10) / 10;
  return `${rounded} ${units[unit]}`;
}

/** Human label for a subscription status. */
export function subscriptionStatusLabel(
  status: BillingSubscription["status"],
): string {
  switch (status) {
    case "trialing":
      return "Trial";
    case "active":
      return "Active";
    case "past_due":
      return "Past due";
    case "canceled":
      return "Canceled";
  }
}

/** Badge tone for a subscription status. */
export function subscriptionStatusTone(
  status: BillingSubscription["status"],
): "success" | "warning" | "danger" | "neutral" | "info" {
  switch (status) {
    case "trialing":
      return "info";
    case "active":
      return "success";
    case "past_due":
      return "danger";
    case "canceled":
      return "neutral";
  }
}

/** Human label for an invoice status. */
export function invoiceStatusLabel(status: BillingInvoice["status"]): string {
  switch (status) {
    case "draft":
      return "Draft";
    case "open":
      return "Open";
    case "paid":
      return "Paid";
    case "past_due":
      return "Past due";
    case "void":
      return "Void";
    case "uncollectible":
      return "Uncollectible";
    case "refunded":
      return "Refunded";
    case "partially_refunded":
      return "Partially refunded";
  }
}

/** Badge tone for an invoice status. */
export function invoiceStatusTone(
  status: BillingInvoice["status"],
): "success" | "warning" | "danger" | "neutral" | "info" {
  switch (status) {
    case "paid":
      return "success";
    case "past_due":
      return "danger";
    case "open":
    case "partially_refunded":
      return "warning";
    case "draft":
    case "void":
    case "uncollectible":
    case "refunded":
      return "neutral";
  }
}

/** Human label for an invoice line kind. */
export function invoiceLineKindLabel(kind: string): string {
  switch (kind) {
    case "subscription":
      return "Subscription";
    case "proration_credit":
      return "Proration credit";
    case "proration_charge":
      return "Proration charge";
    case "success_fee":
      return "Success fee";
    case "cap_adjustment":
      return "First-year cap adjustment";
    case "credit_applied":
      return "Credit applied";
    default:
      return kind;
  }
}

/** Human label for a billing interval. */
export function intervalLabel(interval: "monthly" | "annual"): string {
  return interval === "annual" ? "Annual" : "Monthly";
}
