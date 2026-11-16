import type { MoneyAmount } from "./types";

/**
 * Shared Milestone 4 helpers: per-currency money formatting, count
 * bars, month labels, and safe audit value rendering. Nothing here
 * calls the API.
 *
 * Money is never merged across currencies: every figure is shown
 * with its currency code, and there is no helper that sums amounts
 * of different currencies together.
 */

/**
 * One money figure as "USD 1,250.00": the currency code always
 * travels with the amount. Amounts arrive from the API as
 * two-decimal strings; an unparseable value falls back to the raw
 * string rather than inventing a number.
 */
export function formatCurrencyAmount(amount: string, currency: string): string {
  const code = currency.toUpperCase();
  const value = Number(amount);
  if (!Number.isFinite(value)) {
    return `${code} ${amount}`;
  }
  return `${code} ${value.toLocaleString("en-US", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

/** Renders one per-currency row list, or a placeholder when empty. */
export function formatMoneyRows(rows: MoneyAmount[]): string[] {
  return rows.map((row) => formatCurrencyAmount(row.amount, row.currency));
}

/**
 * Width of a count bar as a percentage of the largest count in the
 * set. Zero when there is nothing to compare against, so an empty
 * table renders no bars.
 */
export function barWidth(count: number, maxCount: number): number {
  if (maxCount <= 0 || count <= 0) return 0;
  return Math.round((count / maxCount) * 100);
}

/** "2026-11" becomes "Nov 2026"; anything else passes through. */
export function formatMonthLabel(month: string): string {
  const match = /^(\d{4})-(\d{2})$/.exec(month);
  if (!match) return month;
  const date = new Date(Number(match[1]), Number(match[2]) - 1, 1);
  return date.toLocaleString("en-US", { month: "short", year: "numeric" });
}

/**
 * One changed field from an audit row, with its before and after
 * values. A field present in only one side is a set (before null)
 * or a removal (after null).
 */
export interface ChangedField {
  field: string;
  before: unknown;
  after: unknown;
}

/**
 * The union of keys in before and after, in before-first order.
 * Both payloads hold only changed fields, so this is exactly the
 * set of fields the audit row records.
 */
export function changedFields(
  before: Record<string, unknown> | null,
  after: Record<string, unknown> | null,
): ChangedField[] {
  const fields: ChangedField[] = [];
  const seen = new Set<string>();
  for (const key of Object.keys(before ?? {})) {
    seen.add(key);
    fields.push({ field: key, before: before?.[key], after: after?.[key] });
  }
  for (const key of Object.keys(after ?? {})) {
    if (seen.has(key)) continue;
    fields.push({ field: key, before: undefined, after: after?.[key] });
  }
  return fields;
}

/**
 * Renders one audit value for display. Scalars print as-is, null
 * and undefined print as "Not set", and anything structured (an
 * object or array) prints as "Changed": the viewer never renders
 * raw blobs, which could carry more than the field name suggests.
 */
export function formatAuditValue(value: unknown): string {
  if (value === null || value === undefined) return "Not set";
  if (typeof value === "string") return value === "" ? "Empty" : value;
  if (typeof value === "number" || typeof value === "boolean") {
    return String(value);
  }
  return "Changed";
}

/** Turns a snake_case field name into a readable label. */
export function fieldLabel(field: string): string {
  return field
    .split("_")
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(" ");
}

/**
 * Entity links carried in a notification data payload. The backend
 * stores ids as strings; anything else is ignored so a malformed
 * payload cannot produce a broken link.
 */
export function notificationLinks(
  data: Record<string, unknown> | null,
): { claimId: string | null; shipmentId: string | null; deadlineId: string | null } {
  const pick = (key: string): string | null => {
    const value = data?.[key];
    return typeof value === "string" && value !== "" ? value : null;
  };
  return {
    claimId: pick("claim_id"),
    shipmentId: pick("shipment_id"),
    deadlineId: pick("deadline_id"),
  };
}
