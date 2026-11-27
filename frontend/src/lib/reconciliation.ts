import { apiFetch } from "./api";
import type {
  ClaimBalance,
  ClaimPayment,
  ReconciliationPaymentMethod,
  ReconciliationReport,
  ReconciliationSummary,
} from "./types";

/**
 * Typed client for the reconciliation endpoints (v1.5.0).
 *
 * The ledger records what the organization says it received
 * against a claim: partial payments are normal, the claim
 * stays accepted until the paid total reaches the amount due,
 * and only then does it move to Paid. The outstanding rule
 * is due = approved ?? requested, paid = recovered_amount,
 * outstanding = max(0, due - paid). Nothing here moves real
 * money: billing runs on a mock provider, so a ledger
 * payment is a record, not a verified transfer. Any member
 * may read the ledger, balances, summary, and reports;
 * recording or voiding a payment and generating a report
 * need the manage-reconciliation permission (owner, admin,
 * operations manager, dispatcher); the UI gates on
 * canManageReconciliation and still handles 403 responses
 * gracefully.
 */

export const RECONCILIATION_PAYMENT_METHODS: {
  value: ReconciliationPaymentMethod;
  label: string;
}[] = [
  { value: "wire_transfer", label: "Wire transfer" },
  { value: "ach", label: "ACH" },
  { value: "cheque", label: "Cheque" },
  { value: "cash", label: "Cash" },
  { value: "card", label: "Card" },
  { value: "other", label: "Other" },
];

export function reconciliationPaymentMethodLabel(
  method: ReconciliationPaymentMethod,
): string {
  return (
    RECONCILIATION_PAYMENT_METHODS.find((m) => m.value === method)?.label ??
    method
  );
}

export function balanceStatusLabel(
  status: ClaimBalance["balance_status"],
): string {
  switch (status) {
    case "unpaid":
      return "Unpaid";
    case "partial":
      return "Partially paid";
    case "paid":
      return "Paid";
    case "overpaid":
      return "Overpaid";
  }
}

export async function getReconciliationSummary(): Promise<ReconciliationSummary> {
  const res = await apiFetch<{ data: ReconciliationSummary }>(
    "/reconciliation/summary",
  );
  return res.data;
}

export async function listLedgerPayments(query?: {
  claim_id?: string;
  payment_method?: ReconciliationPaymentMethod;
  from?: string;
  to?: string;
}): Promise<ClaimPayment[]> {
  const params = new URLSearchParams();
  if (query?.claim_id) params.set("claim_id", query.claim_id);
  if (query?.payment_method) params.set("payment_method", query.payment_method);
  if (query?.from) params.set("from", query.from);
  if (query?.to) params.set("to", query.to);
  const suffix = params.size > 0 ? `?${params.toString()}` : "";
  const res = await apiFetch<{ data: ClaimPayment[] }>(
    `/reconciliation/ledger${suffix}`,
  );
  return res.data;
}

export async function getClaimBalance(
  claimId: string,
): Promise<ClaimBalance> {
  const res = await apiFetch<{ data: ClaimBalance }>(
    `/reconciliation/claims/${claimId}`,
  );
  return res.data;
}

export async function recordClaimPayment(
  claimId: string,
  input: {
    amount: number;
    payment_method: ReconciliationPaymentMethod;
    paid_at: string;
    payment_reference?: string;
    notes?: string;
  },
): Promise<{ payment: ClaimPayment; balance: Omit<ClaimBalance, "payments"> }> {
  const res = await apiFetch<{
    data: ClaimPayment;
    balance: Omit<ClaimBalance, "payments">;
  }>(`/reconciliation/claims/${claimId}/payments`, {
    method: "POST",
    body: input,
  });
  return { payment: res.data, balance: res.balance };
}

export async function voidClaimPayment(paymentId: string): Promise<void> {
  await apiFetch(`/reconciliation/ledger/${paymentId}`, {
    method: "DELETE",
  });
}

export async function listReconciliationReports(): Promise<ReconciliationReport[]> {
  const res = await apiFetch<{ data: ReconciliationReport[] }>(
    "/reconciliation/reports",
  );
  return res.data;
}

export async function getReconciliationReport(
  reportId: string,
): Promise<ReconciliationReport> {
  const res = await apiFetch<{ data: ReconciliationReport }>(
    `/reconciliation/reports/${reportId}`,
  );
  return res.data;
}

export async function generateReconciliationReport(input?: {
  period_start?: string;
  period_end?: string;
}): Promise<ReconciliationReport> {
  const res = await apiFetch<{ data: ReconciliationReport }>(
    "/reconciliation/reports",
    { method: "POST", body: input ?? {} },
  );
  return res.data;
}
