"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Input, Select } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { canManageReconciliation } from "@/lib/permissions";
import {
  RECONCILIATION_PAYMENT_METHODS,
  balanceStatusLabel,
  getClaimBalance,
  recordClaimPayment,
  voidClaimPayment,
} from "@/lib/reconciliation";
import type {
  ClaimBalance,
  Organization,
  ReconciliationPaymentMethod,
} from "@/lib/types";

function balanceTone(
  status: ClaimBalance["balance_status"],
): "neutral" | "info" | "warning" | "success" {
  switch (status) {
    case "unpaid":
      return "neutral";
    case "partial":
      return "info";
    case "paid":
      return "success";
    case "overpaid":
      return "warning";
  }
}

/**
 * One claim's reconciliation balance: requested, approved,
 * due, paid, and outstanding, with the payment history and
 * a form to record a payment. Partial payments are normal;
 * the claim moves to Paid only when the paid total reaches
 * the amount due. A payment on a paid or closed claim cannot
 * be voided.
 */
function ClaimBalanceContent({ org, claimId }: { org: Organization; claimId: string }) {
  const canManage = canManageReconciliation(org.current_user_role);
  const [balance, setBalance] = useState<ClaimBalance | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [recording, setRecording] = useState(false);
  const [voidingId, setVoidingId] = useState<string | null>(null);

  const [amount, setAmount] = useState("");
  const [paymentMethod, setPaymentMethod] =
    useState<ReconciliationPaymentMethod>("wire_transfer");
  const [paidAt, setPaidAt] = useState(new Date().toISOString().slice(0, 10));
  const [paymentReference, setPaymentReference] = useState("");
  const [notes, setNotes] = useState("");

  useEffect(() => {
    let cancelled = false;
    getClaimBalance(claimId)
      .then((data) => {
        if (!cancelled) setBalance(data);
      })
      .catch((error: unknown) => {
        if (!cancelled) {
          setError(
            error instanceof ApiRequestError
              ? error.message
              : "Could not load the claim balance.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [claimId]);

  async function handleRecord() {
    setActionError(null);
    setRecording(true);
    try {
      await recordClaimPayment(claimId, {
        amount: Number(amount),
        payment_method: paymentMethod,
        paid_at: paidAt,
        payment_reference: paymentReference || undefined,
        notes: notes || undefined,
      });
      const refreshed = await getClaimBalance(claimId);
      setBalance(refreshed);
      setAmount("");
      setPaymentReference("");
      setNotes("");
    } catch (error: unknown) {
      setActionError(
        error instanceof ApiRequestError
          ? error.message
          : "Could not record the payment.",
      );
    } finally {
      setRecording(false);
    }
  }

  async function handleVoid(paymentId: string) {
    setActionError(null);
    setVoidingId(paymentId);
    try {
      await voidClaimPayment(paymentId);
      setBalance(await getClaimBalance(claimId));
    } catch (error: unknown) {
      setActionError(
        error instanceof ApiRequestError
          ? error.message
          : "Could not void the payment.",
      );
    } finally {
      setVoidingId(null);
    }
  }

  if (loading) return <Spinner />;
  if (error) return <Alert tone="error">{error}</Alert>;
  if (!balance) return <EmptyState title="Claim not found" description="This claim does not exist in this organization." />;

  const voidable = balance.status !== "paid" && balance.status !== "closed";

  return (
    <div className="space-y-6">
      {actionError ? <Alert tone="error">{actionError}</Alert> : null}

      <Card>
        <CardHeader
          title={`Claim ${balance.claim_reference}`}
          action={<Badge tone={balanceTone(balance.balance_status)}>{balanceStatusLabel(balance.balance_status)}</Badge>}
        />
        <CardBody>
          <dl className="grid grid-cols-2 gap-4 md:grid-cols-5">
            <div>
              <dt className="text-sm text-slate-500">Requested</dt>
              <dd className="text-lg font-semibold">
                {balance.currency} {balance.requested_amount}
              </dd>
            </div>
            <div>
              <dt className="text-sm text-slate-500">Approved</dt>
              <dd className="text-lg font-semibold">
                {balance.approved_amount === null
                  ? "Not recorded"
                  : `${balance.currency} ${balance.approved_amount}`}
              </dd>
            </div>
            <div>
              <dt className="text-sm text-slate-500">Due</dt>
              <dd className="text-lg font-semibold">
                {balance.currency} {balance.due_amount}
              </dd>
            </div>
            <div>
              <dt className="text-sm text-slate-500">Paid</dt>
              <dd className="text-lg font-semibold">
                {balance.currency} {balance.paid_amount}
              </dd>
            </div>
            <div>
              <dt className="text-sm text-slate-500">Outstanding</dt>
              <dd className="text-lg font-semibold">
                {balance.currency} {balance.outstanding_amount}
              </dd>
            </div>
          </dl>
          <p className="mt-4 text-sm text-slate-500">
            Due is the approved amount, or the requested amount where no
            approval is recorded. Paid is the claim&apos;s recorded total;{" "}
            {balance.currency} {balance.ledger_amount} of it is in the ledger
            {Number(balance.legacy_amount) > 0
              ? ` and ${balance.currency} ${balance.legacy_amount} was recorded before the ledger existed`
              : ""}
            .
          </p>
        </CardBody>
      </Card>

      {canManage && voidable ? (
        <Card>
          <CardHeader title="Record a payment" />
          <CardBody>
            <div className="grid gap-3 md:grid-cols-2">
              <Input
                id="amount"
                label={`Amount (${balance.currency})`}
                type="number"
                min="0.01"
                step="0.01"
                value={amount}
                onChange={(event) => setAmount(event.target.value)}
              />
              <Select
                id="payment-method"
                label="Payment method"
                value={paymentMethod}
                onChange={(event) =>
                  setPaymentMethod(event.target.value as ReconciliationPaymentMethod)
                }
              >
                {RECONCILIATION_PAYMENT_METHODS.map((method) => (
                  <option key={method.value} value={method.value}>
                    {method.label}
                  </option>
                ))}
              </Select>
              <Input
                id="paid-at"
                label="Payment date"
                type="date"
                value={paidAt}
                onChange={(event) => setPaidAt(event.target.value)}
              />
              <Input
                id="payment-reference"
                label="Payment reference"
                value={paymentReference}
                onChange={(event) => setPaymentReference(event.target.value)}
                placeholder="Wire, cheque, or ACH reference"
              />
              <div className="md:col-span-2">
                <Input
                  id="notes"
                  label="Notes"
                  value={notes}
                  onChange={(event) => setNotes(event.target.value)}
                />
              </div>
            </div>
            <div className="mt-4">
              <Button onClick={handleRecord} disabled={recording || amount === ""}>
                {recording ? "Recording…" : "Record payment"}
              </Button>
            </div>
            <p className="mt-3 text-sm text-slate-500">
              This records what the organization received. It does not move
              real money and is not verified against a bank or a processor.
            </p>
          </CardBody>
        </Card>
      ) : null}

      <Card>
        <CardHeader title="Payment history" />
        <CardBody>
          {balance.payments.length === 0 ? (
            <EmptyState
              title="No payments recorded yet"
              description="Payments recorded against this claim appear here with their method, reference, and date."
            />
          ) : (
            <ul className="divide-y divide-slate-200">
              {balance.payments.map((payment) => (
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
                        {payment.notes ? ` · ${payment.notes}` : ""}
                      </p>
                    </div>
                    {canManage && voidable ? (
                      <Button
                        variant="secondary"
                        onClick={() => handleVoid(payment.id)}
                        disabled={voidingId === payment.id}
                      >
                        {voidingId === payment.id ? "Voiding…" : "Void"}
                      </Button>
                    ) : null}
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

export default function ClaimBalancePage() {
  const { currentOrg } = useAuth();
  const params = useParams();
  const claimId = typeof params.id === "string" ? params.id : "";

  if (claimId === "") {
    return (
      <div>
        <PageHeader
          title="Claim balance"
          description="No claim selected."
          crumbs={[
            { label: "Dashboard", href: "/dashboard" },
            { label: "Reconciliation", href: "/reconciliation" },
            { label: "Claim balance" },
          ]}
        />
        <Alert tone="error">No claim was selected.</Alert>
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title="Claim balance"
        description={
          currentOrg
            ? `Payment history and outstanding balance for one claim in ${currentOrg.name}.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Reconciliation", href: "/reconciliation" },
          { label: "Claim balance" },
        ]}
      />
      {currentOrg ? (
        <ClaimBalanceContent key={`${currentOrg.id}-${claimId}`} org={currentOrg} claimId={claimId} />
      ) : (
        <Alert tone="info">Create an organization first to use reconciliation.</Alert>
      )}
    </div>
  );
}
