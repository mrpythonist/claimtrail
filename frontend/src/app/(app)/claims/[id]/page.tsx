"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Input, Select, Textarea } from "@/components/ui/Input";
import { Modal } from "@/components/ui/Modal";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { Table, TableCell } from "@/components/ui/Table";
import { EvidencePreviewModal } from "@/components/evidence/EvidencePreviewModal";
import {
  apiDownload,
  apiFetch,
  ApiRequestError,
} from "@/lib/api";
import { useAuth } from "@/lib/auth";
import {
  CLAIM_STATUS_LABELS,
  canRecordPaymentFor,
  claimStatusTone,
  daysRemainingLabel,
  deadlineStatusTone,
  documentStatusTone,
  formatMoney,
  isClaimImmutable,
  REMINDER_DAY_OPTIONS,
  transitionNeeds,
} from "@/lib/claims";
import {
  canAttachClaimEvidence,
  canEditClaims,
  canGenerateClaimPackages,
  canManageDeadlines,
  canRecordClaimPayments,
  canTransitionClaimTo,
} from "@/lib/permissions";
import {
  blankToNull,
  downloadBlob,
  formatFileSize,
  inputValueToIso,
  isPreviewableMime,
  isoToInputValue,
  openBlobInNewTab,
} from "@/lib/shipments";
import { TIMEZONES } from "@/lib/timezones";
import type { FieldErrors } from "@/lib/validation";
import {
  validateDeadlineForm,
  validateRecordPaymentForm,
  validateTransitionForm,
} from "@/lib/validation";
import type {
  Claim,
  ClaimDeadline,
  ClaimStatus,
  ClaimStatusHistory,
  EvidenceFile,
  GeneratedDocument,
  Organization,
} from "@/lib/types";

function formatDateTime(value: string | null): string {
  if (!value) return "Not set";
  return new Date(value).toLocaleString();
}

function formatDate(value: string | null): string {
  if (!value) return "Not set";
  return new Date(value).toLocaleDateString();
}

function DetailRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="border-b border-slate-100 py-2 last:border-b-0 sm:grid sm:grid-cols-3 sm:gap-4">
      <dt className="text-sm font-medium text-slate-500">{label}</dt>
      <dd className="mt-1 text-sm text-slate-900 sm:mt-0 sm:col-span-2">{value}</dd>
    </div>
  );
}

/**
 * Deadline form for a claim. The parent keys it by claim and active
 * deadline id, so it mounts with the current deadline values instead
 * of syncing props in an effect.
 */
function DeadlineForm({
  claimId,
  deadline,
  saving,
  onSubmit,
}: {
  claimId: string;
  deadline: ClaimDeadline | null;
  saving: boolean;
  onSubmit: (payload: {
    deadline_at: string;
    timezone: string;
    source_reference: string | null;
    reminder_days_before: number[];
  }) => Promise<void>;
}) {
  const [deadlineAt, setDeadlineAt] = useState(
    isoToInputValue(deadline?.deadline_at ?? null),
  );
  const [timezone, setTimezone] = useState(deadline?.timezone ?? "UTC");
  const [sourceReference, setSourceReference] = useState(
    deadline?.source_reference ?? "",
  );
  const [reminders, setReminders] = useState<number[]>(
    deadline?.reminder_days_before ?? [],
  );
  const [errors, setErrors] = useState<FieldErrors>({});

  function toggleReminder(day: number) {
    setReminders((current) =>
      current.includes(day)
        ? current.filter((item) => item !== day)
        : [...current, day].sort((a, b) => a - b),
    );
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    const formErrors = validateDeadlineForm({
      deadline_at: deadlineAt,
      timezone,
    });
    setErrors(formErrors);
    if (Object.keys(formErrors).length > 0) return;
    const iso = inputValueToIso(deadlineAt);
    if (!iso) {
      setErrors({ deadline_at: "Enter a valid date and time." });
      return;
    }
    await onSubmit({
      deadline_at: iso,
      timezone,
      source_reference: blankToNull(sourceReference),
      reminder_days_before: reminders,
    });
  }

  return (
    <form
      onSubmit={handleSubmit}
      className="space-y-4"
      noValidate
      aria-label="Submission deadline"
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <Input
          id={`deadline-at-${claimId}`}
          label="Deadline date and time"
          type="datetime-local"
          value={deadlineAt}
          onChange={(event) => setDeadlineAt(event.target.value)}
          error={errors.deadline_at}
        />
        <Select
          id={`deadline-timezone-${claimId}`}
          label="Timezone"
          value={timezone}
          onChange={(event) => setTimezone(event.target.value)}
          error={errors.timezone}
        >
          {TIMEZONES.map((tz) => (
            <option key={tz.value} value={tz.value}>
              {tz.label}
            </option>
          ))}
        </Select>
      </div>
      <Input
        id={`deadline-source-${claimId}`}
        label="Source reference"
        type="text"
        value={sourceReference}
        onChange={(event) => setSourceReference(event.target.value)}
        placeholder="Where this deadline came from, for example a broker email"
        help="Deadlines are customer-supplied. Nothing here guesses a deadline from the event type or a broker rule."
      />
      <fieldset>
        <legend className="mb-1 block text-sm font-medium text-slate-700">
          Remind me before the deadline
        </legend>
        <div className="flex flex-wrap gap-4">
          {REMINDER_DAY_OPTIONS.map((day) => (
            <label
              key={day}
              className="flex items-center gap-1.5 text-sm text-slate-700"
            >
              <input
                type="checkbox"
                checked={reminders.includes(day)}
                onChange={() => toggleReminder(day)}
                className="h-4 w-4 rounded border-slate-300"
              />
              {day} day{day === 1 ? "" : "s"} before
            </label>
          ))}
        </div>
      </fieldset>
      <Button type="submit" loading={saving}>
        {deadline ? "Update deadline" : "Set deadline"}
      </Button>
    </form>
  );
}

function ClaimDetailContent({
  org,
  claimId,
}: {
  org: Organization;
  claimId: string;
}) {
  const [claim, setClaim] = useState<Claim | null>(null);
  const [history, setHistory] = useState<ClaimStatusHistory[]>([]);
  const [attachedEvidence, setAttachedEvidence] = useState<EvidenceFile[]>([]);
  const [shipmentEvidence, setShipmentEvidence] = useState<EvidenceFile[]>([]);
  const [document, setDocument] = useState<GeneratedDocument | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  // Transition modal state.
  const [transitionTarget, setTransitionTarget] = useState<{
    value: string;
    label: string;
  } | null>(null);
  const [acceptedAmount, setAcceptedAmount] = useState("");
  const [rejectionReason, setRejectionReason] = useState("");
  const [transitionReason, setTransitionReason] = useState("");
  const [submittedAt, setSubmittedAt] = useState("");
  const [transitionErrors, setTransitionErrors] = useState<FieldErrors>({});
  const [transitioning, setTransitioning] = useState(false);

  // Record payment state.
  const [showPaymentForm, setShowPaymentForm] = useState(false);
  const [recoveredAmount, setRecoveredAmount] = useState("");
  const [paymentDate, setPaymentDate] = useState("");
  const [paymentReference, setPaymentReference] = useState("");
  const [paymentReason, setPaymentReason] = useState("");
  const [paymentErrors, setPaymentErrors] = useState<FieldErrors>({});
  const [recordingPayment, setRecordingPayment] = useState(false);

  // Deadline state.
  const [savingDeadline, setSavingDeadline] = useState(false);
  const [showDeadlineForm, setShowDeadlineForm] = useState(false);

  // Package state.
  const [includeNotes, setIncludeNotes] = useState(false);
  const [generating, setGenerating] = useState(false);
  const [downloadingPackage, setDownloadingPackage] = useState(false);
  const [refreshingPackage, setRefreshingPackage] = useState(false);

  // Evidence state.
  const [attachFileId, setAttachFileId] = useState("");
  const [attaching, setAttaching] = useState(false);
  const [detachTarget, setDetachTarget] = useState<EvidenceFile | null>(null);
  const [detaching, setDetaching] = useState(false);
  const [downloadingId, setDownloadingId] = useState<string | null>(null);
  const [previewFile, setPreviewFile] = useState<EvidenceFile | null>(null);

  const myRole = org.current_user_role;
  const immutable = claim ? isClaimImmutable(claim.status) : false;
  const showPayment =
    claim !== null &&
    canRecordPaymentFor(claim.status) &&
    canRecordClaimPayments(myRole);

  useEffect(() => {
    let cancelled = false;

    apiFetch<{ data: Claim }>(`/claims/${claimId}`, { orgId: org.id })
      .then((response) => {
        if (cancelled) return;
        setClaim(response.data);
        setDocument(response.data.latest_document);
        return apiFetch<{ data: EvidenceFile[] }>(
          `/evidence?shipment_id=${response.data.shipment_id}&per_page=100`,
          { orgId: org.id },
        );
      })
      .then((response) => {
        if (!cancelled && response) setShipmentEvidence(response.data);
      })
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load the claim.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    apiFetch<{ data: ClaimStatusHistory[] }>(`/claims/${claimId}/history`, {
      orgId: org.id,
    })
      .then((response) => {
        if (!cancelled) setHistory(response.data);
      })
      .catch(() => {
        // History is secondary; the claim still loads without it.
        if (!cancelled) setHistory([]);
      });

    apiFetch<{ data: EvidenceFile[] }>(`/claims/${claimId}/evidence`, {
      orgId: org.id,
    })
      .then((response) => {
        if (!cancelled) setAttachedEvidence(response.data);
      })
      .catch(() => {
        if (!cancelled) setAttachedEvidence([]);
      });

    return () => {
      cancelled = true;
    };
  }, [org.id, claimId]);

  function showError(err: unknown, fallback: string) {
    setError(err instanceof ApiRequestError ? err.message : fallback);
  }

  function refreshHistory() {
    apiFetch<{ data: ClaimStatusHistory[] }>(`/claims/${claimId}/history`, {
      orgId: org.id,
    })
      .then((response) => setHistory(response.data))
      .catch(() => undefined);
  }

  function openTransition(target: { value: string; label: string }) {
    setTransitionTarget(target);
    setAcceptedAmount("");
    setRejectionReason("");
    setTransitionReason("");
    setSubmittedAt("");
    setTransitionErrors({});
  }

  async function confirmTransition() {
    if (!transitionTarget || !claim) return;
    const formErrors = validateTransitionForm({
      to_status: transitionTarget.value,
      accepted_amount: acceptedAmount,
      requested_amount: claim.requested_amount,
      rejection_reason: rejectionReason,
      reason: transitionReason,
    });
    setTransitionErrors(formErrors);
    if (Object.keys(formErrors).length > 0) return;

    const needs = transitionNeeds(transitionTarget.value);
    const body: Record<string, unknown> = { to_status: transitionTarget.value };
    if (needs.acceptedAmount) body.accepted_amount = acceptedAmount.trim();
    if (needs.rejectionReason) {
      body.rejection_reason = rejectionReason.trim();
    }
    if (needs.reason) body.reason = transitionReason.trim();
    if (needs.submittedAt && submittedAt) {
      body.submitted_at = inputValueToIso(submittedAt);
    }

    setError(null);
    setNotice(null);
    setTransitioning(true);
    try {
      const response = await apiFetch<{ data: Claim }>(
        `/claims/${claimId}/transition`,
        { method: "POST", body, orgId: org.id },
      );
      setClaim(response.data);
      setDocument(response.data.latest_document);
      setNotice(`Status changed to ${response.data.status_label}.`);
      setTransitionTarget(null);
      refreshHistory();
    } catch (err) {
      showError(err, "Could not change the status.");
      setTransitionTarget(null);
    } finally {
      setTransitioning(false);
    }
  }

  async function handleRecordPayment(event: FormEvent) {
    event.preventDefault();
    if (!claim) return;
    const formErrors = validateRecordPaymentForm({
      recovered_amount: recoveredAmount,
      payment_date: paymentDate,
    });
    setPaymentErrors(formErrors);
    if (Object.keys(formErrors).length > 0) return;

    setError(null);
    setNotice(null);
    setRecordingPayment(true);
    try {
      const response = await apiFetch<{ data: Claim }>(
        `/claims/${claimId}/record-payment`,
        {
          method: "POST",
          body: {
            recovered_amount: recoveredAmount.trim(),
            payment_date: paymentDate,
            payment_reference: blankToNull(paymentReference),
            reason: blankToNull(paymentReason),
          },
          orgId: org.id,
        },
      );
      setClaim(response.data);
      setDocument(response.data.latest_document);
      setNotice("Payment recorded. The claim is now paid.");
      setShowPaymentForm(false);
      setRecoveredAmount("");
      setPaymentDate("");
      setPaymentReference("");
      setPaymentReason("");
      refreshHistory();
    } catch (err) {
      showError(err, "Could not record the payment.");
    } finally {
      setRecordingPayment(false);
    }
  }

  async function handleDeadlineSubmit(payload: {
    deadline_at: string;
    timezone: string;
    source_reference: string | null;
    reminder_days_before: number[];
  }) {
    setError(null);
    setNotice(null);
    setSavingDeadline(true);
    try {
      const response = await apiFetch<{ data: ClaimDeadline }>(
        `/claims/${claimId}/deadline`,
        { method: "POST", body: payload, orgId: org.id },
      );
      setClaim((current) =>
        current ? { ...current, active_deadline: response.data } : current,
      );
      setNotice("Deadline saved.");
      setShowDeadlineForm(false);
    } catch (err) {
      showError(err, "Could not save the deadline.");
    } finally {
      setSavingDeadline(false);
    }
  }

  async function handleGeneratePackage() {
    setError(null);
    setNotice(null);
    setGenerating(true);
    try {
      const response = await apiFetch<{ data: GeneratedDocument }>(
        `/claims/${claimId}/package`,
        {
          method: "POST",
          body: { include_notes: includeNotes },
          orgId: org.id,
        },
      );
      setDocument(response.data);
      setNotice("Package generation started. Refresh the status to check it.");
    } catch (err) {
      showError(err, "Could not generate the package.");
    } finally {
      setGenerating(false);
    }
  }

  async function handleRefreshPackage() {
    setError(null);
    setRefreshingPackage(true);
    try {
      const response = await apiFetch<{ data: GeneratedDocument | null }>(
        `/claims/${claimId}/package`,
        { orgId: org.id },
      );
      setDocument(response.data);
    } catch (err) {
      showError(err, "Could not refresh the package status.");
    } finally {
      setRefreshingPackage(false);
    }
  }

  async function handleDownloadPackage() {
    if (!document) return;
    setError(null);
    setDownloadingPackage(true);
    try {
      const blob = await apiDownload(`/documents/${document.id}/download`, {
        orgId: org.id,
      });
      downloadBlob(blob, `${claim?.claim_reference ?? "claim"}-package.pdf`);
    } catch (err) {
      showError(err, "Could not download the package.");
    } finally {
      setDownloadingPackage(false);
    }
  }

  async function handleAttachEvidence(event: FormEvent) {
    event.preventDefault();
    if (!attachFileId) return;
    setError(null);
    setNotice(null);
    setAttaching(true);
    try {
      const response = await apiFetch<{ data: EvidenceFile }>(
        `/claims/${claimId}/evidence`,
        {
          method: "POST",
          body: { evidence_file_id: attachFileId },
          orgId: org.id,
        },
      );
      setAttachedEvidence((current) => [response.data, ...current]);
      setAttachFileId("");
      setNotice(`Attached ${response.data.original_filename}.`);
    } catch (err) {
      showError(err, "Could not attach the file.");
    } finally {
      setAttaching(false);
    }
  }

  async function confirmDetach() {
    if (!detachTarget) return;
    setDetaching(true);
    try {
      await apiFetch(`/claims/${claimId}/evidence/${detachTarget.id}`, {
        method: "DELETE",
        orgId: org.id,
      });
      setAttachedEvidence((current) =>
        current.filter((item) => item.id !== detachTarget.id),
      );
      setNotice(`Detached ${detachTarget.original_filename}.`);
      setDetachTarget(null);
    } catch (err) {
      showError(err, "Could not detach the file.");
      setDetachTarget(null);
    } finally {
      setDetaching(false);
    }
  }

  async function handleDownloadEvidence(file: EvidenceFile, preview: boolean) {
    setError(null);
    setDownloadingId(file.id);
    try {
      const blob = await apiDownload(
        `/evidence/${file.id}/download${preview ? "?preview=1" : ""}`,
        { orgId: org.id },
      );
      if (preview && isPreviewableMime(file.mime_type)) {
        openBlobInNewTab(blob);
      } else {
        downloadBlob(blob, file.original_filename);
      }
    } catch (err) {
      showError(err, "Could not download the file.");
    } finally {
      setDownloadingId(null);
    }
  }

  if (loading) {
    return <Spinner label="Loading claim" />;
  }

  if (error && !claim) {
    return <Alert tone="error">{error}</Alert>;
  }

  if (!claim) {
    return <Alert tone="info">Claim not found.</Alert>;
  }

  const availableTransitions = claim.allowed_transitions.filter((target) =>
    canTransitionClaimTo(myRole, target.value as ClaimStatus),
  );
  const unattachedEvidence = shipmentEvidence.filter(
    (file) => !attachedEvidence.some((item) => item.id === file.id),
  );
  const transitionNeedsForTarget = transitionTarget
    ? transitionNeeds(transitionTarget.value)
    : null;

  return (
    <div className="space-y-6">
      {notice && <Alert tone="success">{notice}</Alert>}
      {error && <Alert tone="error">{error}</Alert>}

      <Card>
        <CardHeader
          title={claim.claim_reference}
          description={`${claim.claim_category_label} claim - created ${formatDate(claim.created_at)}.`}
          action={
            <Badge tone={claimStatusTone(claim.status)}>
              {claim.status_label}
            </Badge>
          }
        />
        <CardBody>
          <dl>
            <DetailRow
              label="Description"
              value={claim.description ?? "None"}
            />
            <DetailRow
              label="Broker reference"
              value={claim.external_broker_reference ?? "Not set"}
            />
            <DetailRow
              label="Internal notes"
              value={claim.internal_notes ?? "None"}
            />
            <DetailRow
              label="Submitted"
              value={formatDateTime(claim.submitted_at)}
            />
            <DetailRow
              label="Approved"
              value={formatDateTime(claim.approved_at)}
            />
            <DetailRow
              label="Rejected"
              value={formatDateTime(claim.rejected_at)}
            />
            <DetailRow label="Paid" value={formatDateTime(claim.paid_at)} />
            {claim.rejection_reason && (
              <DetailRow
                label="Rejection reason"
                value={claim.rejection_reason}
              />
            )}
            {claim.payment_reference && (
              <DetailRow
                label="Payment reference"
                value={claim.payment_reference}
              />
            )}
            <DetailRow
              label="Created by"
              value={claim.created_by?.name ?? "Unknown"}
            />
            <DetailRow
              label="Last updated by"
              value={claim.updated_by?.name ?? "Unknown"}
            />
          </dl>

          <div className="mt-6 flex flex-wrap gap-2">
            {canEditClaims(myRole) && !immutable && (
              <Link href={`/claims/${claim.id}/edit`}>
                <Button variant="secondary">Edit claim</Button>
              </Link>
            )}
            <Link href={`/claims/${claim.id}/review`}>
              <Button variant="secondary">Review readiness</Button>
            </Link>
          </div>
          {immutable && (
            <p className="mt-4 text-sm text-slate-500">
              This claim is {claim.status_label.toLowerCase()} and can no
              longer be edited.
            </p>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader
          title="Amounts"
          description="Requested is what was claimed. Accepted is what the broker agreed to pay. Recovered is what was actually paid."
        />
        <CardBody>
          <div className="grid gap-4 sm:grid-cols-3">
            <div className="rounded-md border border-slate-200 p-4">
              <p className="text-sm text-slate-500">Requested</p>
              <p className="mt-1 text-2xl font-semibold text-slate-900">
                {formatMoney(claim.requested_amount, claim.currency)}
              </p>
              <p className="mt-1 text-xs text-slate-500">
                Amount claimed from the broker
              </p>
            </div>
            <div className="rounded-md border border-slate-200 p-4">
              <p className="text-sm text-slate-500">Accepted</p>
              <p className="mt-1 text-2xl font-semibold text-slate-900">
                {formatMoney(claim.accepted_amount, claim.currency)}
              </p>
              <p className="mt-1 text-xs text-slate-500">
                Amount the broker agreed to pay
              </p>
            </div>
            <div className="rounded-md border border-slate-200 p-4">
              <p className="text-sm text-slate-500">Recovered</p>
              <p className="mt-1 text-2xl font-semibold text-slate-900">
                {formatMoney(claim.recovered_amount, claim.currency)}
              </p>
              <p className="mt-1 text-xs text-slate-500">
                Amount actually received
              </p>
            </div>
          </div>
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Shipment and event" />
        <CardBody>
          <dl>
            <DetailRow
              label="Shipment"
              value={
                claim.shipment
                  ? `${claim.shipment.internal_reference} - ${claim.shipment.broker_or_customer_name}`
                  : "Not available"
              }
            />
            <DetailRow
              label="Event"
              value={
                claim.event
                  ? `${formatDate(claim.event.event_date)}${claim.event.description ? ` - ${claim.event.description}` : ""}`
                  : "No event linked"
              }
            />
            <DetailRow
              label="Submission deadline"
              value={formatDateTime(claim.submission_deadline_at)}
            />
          </dl>
          {claim.shipment && (
            <div className="mt-4">
              <Link
                href={`/shipments/${claim.shipment.id}`}
                className="text-sm text-brand-700 hover:underline"
              >
                View shipment
              </Link>
            </div>
          )}
        </CardBody>
      </Card>

      {availableTransitions.length > 0 && (
        <Card>
          <CardHeader
            title="Change status"
            description="Move this claim to its next stage. Each move is recorded in the history below."
          />
          <CardBody>
            <div className="flex flex-wrap gap-2">
              {availableTransitions.map((target) => (
                <Button
                  key={target.value}
                  variant={
                    target.value === "rejected" || target.value === "closed"
                      ? "secondary"
                      : "primary"
                  }
                  onClick={() => openTransition(target)}
                >
                  {target.label}
                </Button>
              ))}
            </div>
          </CardBody>
        </Card>
      )}

      {showPayment && (
        <Card>
          <CardHeader
            title="Record payment"
            description="An accepted claim is not recovered revenue until a payment is recorded against it."
            action={
              <Button
                size="sm"
                onClick={() => setShowPaymentForm((show) => !show)}
              >
                {showPaymentForm ? "Hide form" : "Record payment"}
              </Button>
            }
          />
          {showPaymentForm && (
            <CardBody>
              <form
                onSubmit={handleRecordPayment}
                className="space-y-4"
                noValidate
              >
                <div className="grid gap-4 sm:grid-cols-2">
                  <Input
                    id="recovered_amount"
                    label="Recovered amount"
                    type="text"
                    inputMode="decimal"
                    value={recoveredAmount}
                    onChange={(event) => setRecoveredAmount(event.target.value)}
                    error={paymentErrors.recovered_amount}
                    placeholder="0.00"
                    help={`Accepted amount: ${formatMoney(claim.accepted_amount, claim.currency)}.`}
                  />
                  <Input
                    id="payment_date"
                    label="Payment date"
                    type="date"
                    value={paymentDate}
                    onChange={(event) => setPaymentDate(event.target.value)}
                    error={paymentErrors.payment_date}
                  />
                  <Input
                    id="payment_reference"
                    label="Payment reference"
                    type="text"
                    value={paymentReference}
                    onChange={(event) => setPaymentReference(event.target.value)}
                    placeholder="Check, transfer, or receipt reference"
                  />
                  <Input
                    id="payment_reason"
                    label="Reason"
                    type="text"
                    value={paymentReason}
                    onChange={(event) => setPaymentReason(event.target.value)}
                    placeholder="Optional note about this payment"
                  />
                </div>
                <Button type="submit" loading={recordingPayment}>
                  Record payment
                </Button>
              </form>
            </CardBody>
          )}
        </Card>
      )}

      <Card>
        <CardHeader
          title="Submission deadline"
          description="Deadlines are customer-supplied, never inferred from the event type or a broker rule."
          action={
            canManageDeadlines(myRole) ? (
              <Button
                size="sm"
                onClick={() => setShowDeadlineForm((show) => !show)}
              >
                {showDeadlineForm
                  ? "Hide form"
                  : claim.active_deadline
                    ? "Update deadline"
                    : "Set deadline"}
              </Button>
            ) : undefined
          }
        />
        <CardBody>
          {claim.active_deadline ? (
            <div className="space-y-2 text-sm">
              <p className="text-slate-900">
                <span className="font-medium">
                  {formatDateTime(claim.active_deadline.deadline_at)}
                </span>{" "}
                ({claim.active_deadline.timezone}) -{" "}
                {daysRemainingLabel(claim.active_deadline.deadline_at)}
              </p>
              <p>
                <Badge tone={deadlineStatusTone(claim.active_deadline.status)}>
                  {claim.active_deadline.status_label}
                </Badge>
              </p>
              {claim.active_deadline.source_reference && (
                <p className="text-slate-500">
                  Source: {claim.active_deadline.source_reference}
                </p>
              )}
              {claim.active_deadline.reminder_days_before.length > 0 && (
                <p className="text-slate-500">
                  Reminders:{" "}
                  {claim.active_deadline.reminder_days_before
                    .map((day) => `${day} day${day === 1 ? "" : "s"} before`)
                    .join(", ")}
                </p>
              )}
            </div>
          ) : (
            <p className="text-sm text-slate-500">
              No active deadline for this claim.
            </p>
          )}
          {showDeadlineForm && canManageDeadlines(myRole) && (
            <div className="mt-6 border-t border-slate-200 pt-6">
              <DeadlineForm
                key={`${claim.id}-${claim.active_deadline?.id ?? "none"}`}
                claimId={claim.id}
                deadline={claim.active_deadline}
                saving={savingDeadline}
                onSubmit={handleDeadlineSubmit}
              />
            </div>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader
          title="Claim package"
          description="A PDF summary of the claim and its evidence, ready to send to the broker."
          action={
            document?.status === "ready" ? (
              <Button
                size="sm"
                loading={downloadingPackage}
                onClick={handleDownloadPackage}
              >
                Download PDF
              </Button>
            ) : undefined
          }
        />
        <CardBody>
          {document ? (
            <div className="space-y-2 text-sm">
              <p>
                <Badge tone={documentStatusTone(document.status)}>
                  {document.status_label}
                </Badge>{" "}
                <span className="text-slate-500">
                  {document.document_type_label}
                  {document.generated_at
                    ? ` - generated ${formatDateTime(document.generated_at)}`
                    : ""}
                  {document.file_size
                    ? ` - ${formatFileSize(document.file_size)}`
                    : ""}
                </span>
              </p>
              <p className="text-slate-500">
                Internal notes:{" "}
                {document.includes_internal_notes ? "included" : "excluded"}
                {document.generated_by
                  ? ` - generated by ${document.generated_by.name}`
                  : ""}
              </p>
              {document.status === "failed" && document.error_message && (
                <Alert tone="error">{document.error_message}</Alert>
              )}
            </div>
          ) : (
            <p className="text-sm text-slate-500">
              No package has been generated for this claim yet.
            </p>
          )}
          {canGenerateClaimPackages(myRole) && (
            <div className="mt-4 space-y-3">
              <label className="flex items-start gap-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  checked={includeNotes}
                  onChange={(event) => setIncludeNotes(event.target.checked)}
                  className="mt-0.5 h-4 w-4 rounded border-slate-300"
                />
                <span>
                  Include internal notes in the package
                  <span className="block text-xs text-slate-500">
                    Off by default. Internal notes are left out of the package
                    unless you check this box.
                  </span>
                </span>
              </label>
              <div className="flex flex-wrap gap-2">
                <Button loading={generating} onClick={handleGeneratePackage}>
                  {document ? "Regenerate package" : "Generate package"}
                </Button>
                {document && document.status === "queued" && (
                  <Button
                    variant="secondary"
                    loading={refreshingPackage}
                    onClick={handleRefreshPackage}
                  >
                    Refresh status
                  </Button>
                )}
              </div>
            </div>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader
          title="Attached evidence"
          description="Documents attached to this claim. Readiness is counted from this list."
        />
        {canAttachClaimEvidence(myRole) && !immutable && (
          <CardBody className="border-b border-slate-200">
            <form onSubmit={handleAttachEvidence} className="space-y-4" noValidate>
              <Select
                id="attach-evidence"
                label="Attach a file from this shipment"
                value={attachFileId}
                onChange={(event) => setAttachFileId(event.target.value)}
                help={
                  unattachedEvidence.length === 0
                    ? "Every file from this shipment is already attached, or the shipment has no files yet."
                    : "Only files uploaded to this shipment can be attached."
                }
              >
                <option value="">Choose a file</option>
                {unattachedEvidence.map((file) => (
                  <option key={file.id} value={file.id}>
                    {file.original_filename} - {file.document_category_label}
                  </option>
                ))}
              </Select>
              <Button
                type="submit"
                loading={attaching}
                disabled={!attachFileId}
              >
                Attach file
              </Button>
            </form>
          </CardBody>
        )}
        {attachedEvidence.length === 0 ? (
          <EmptyState
            title="No evidence attached"
            description="Attach files from the shipment to support this claim."
          />
        ) : (
          <Table
            headers={["File", "Category", "Size", "Uploaded by", ""]}
          >
            {attachedEvidence.map((file) => (
              <tr key={file.id}>
                <TableCell>
                  <p className="font-medium text-slate-900">
                    {file.original_filename}
                  </p>
                  {file.description && (
                    <p className="text-xs text-slate-500">{file.description}</p>
                  )}
                  <p className="text-xs text-slate-500">
                    {formatDate(file.created_at)}
                  </p>
                </TableCell>
                <TableCell>{file.document_category_label}</TableCell>
                <TableCell>{formatFileSize(file.file_size)}</TableCell>
                <TableCell>{file.uploaded_by?.name ?? "Unknown"}</TableCell>
                <TableCell>
                  <div className="flex gap-1">
                    <Button
                      variant="ghost"
                      size="sm"
                      disabled={downloadingId === file.id}
                      onClick={() => setPreviewFile(file)}
                    >
                      Preview
                    </Button>
                    <Button
                      variant="ghost"
                      size="sm"
                      disabled={downloadingId === file.id}
                      onClick={() => handleDownloadEvidence(file, false)}
                    >
                      Download
                    </Button>
                    {canAttachClaimEvidence(myRole) && !immutable && (
                      <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setDetachTarget(file)}
                      >
                        Detach
                      </Button>
                    )}
                  </div>
                </TableCell>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      <Card>
        <CardHeader
          title="History"
          description="Every status change on this claim, newest first."
        />
        {history.length === 0 ? (
          <EmptyState
            title="No history yet"
            description="Status changes will appear here."
          />
        ) : (
          <CardBody>
            <ol className="space-y-4">
              {history.map((entry) => (
                <li
                  key={entry.id}
                  className="border-l-2 border-slate-200 pl-4"
                >
                  <p className="text-sm text-slate-900">
                    <span className="font-medium">
                      {entry.from_status
                        ? `${CLAIM_STATUS_LABELS[entry.from_status as ClaimStatus] ?? entry.from_status} to `
                        : "Created as "}
                      {CLAIM_STATUS_LABELS[entry.to_status as ClaimStatus] ??
                        entry.to_status}
                    </span>
                  </p>
                  <p className="mt-0.5 text-xs text-slate-500">
                    {formatDateTime(entry.created_at)}
                    {entry.changed_by ? ` by ${entry.changed_by.name}` : ""}
                  </p>
                  {entry.reason && (
                    <p className="mt-1 text-sm text-slate-600">
                      {entry.reason}
                    </p>
                  )}
                </li>
              ))}
            </ol>
          </CardBody>
        )}
      </Card>

      <Modal
        open={transitionTarget !== null}
        title={
          transitionTarget
            ? `Change status to ${transitionTarget.label}`
            : "Change status"
        }
        description={
          transitionTarget?.value === "submitted"
            ? "The submission date defaults to now. Set a different date only when the claim was submitted earlier."
            : undefined
        }
        confirmLabel={
          transitionTarget ? `Move to ${transitionTarget.label}` : "Confirm"
        }
        loading={transitioning}
        onConfirm={confirmTransition}
        onClose={() => setTransitionTarget(null)}
      >
        {transitionNeedsForTarget?.acceptedAmount && (
          <Input
            id="transition-accepted-amount"
            label="Accepted amount"
            type="text"
            inputMode="decimal"
            value={acceptedAmount}
            onChange={(event) => setAcceptedAmount(event.target.value)}
            error={transitionErrors.accepted_amount}
            placeholder="0.00"
            help={
              transitionTarget?.value === "partially_accepted"
                ? `Must be less than the requested ${formatMoney(claim.requested_amount, claim.currency)}.`
                : `Requested amount: ${formatMoney(claim.requested_amount, claim.currency)}.`
            }
          />
        )}
        {transitionNeedsForTarget?.rejectionReason && (
          <Textarea
            id="transition-rejection-reason"
            label="Rejection reason"
            rows={3}
            value={rejectionReason}
            onChange={(event) => setRejectionReason(event.target.value)}
            error={transitionErrors.rejection_reason}
            placeholder="Why did the broker reject this claim?"
          />
        )}
        {transitionNeedsForTarget?.reason && (
          <Textarea
            id="transition-reason"
            label="Reason"
            rows={3}
            value={transitionReason}
            onChange={(event) => setTransitionReason(event.target.value)}
            error={transitionErrors.reason}
            placeholder="Why is this claim being closed?"
          />
        )}
        {transitionNeedsForTarget?.submittedAt && (
          <Input
            id="transition-submitted-at"
            label="Submitted at (optional)"
            type="datetime-local"
            value={submittedAt}
            onChange={(event) => setSubmittedAt(event.target.value)}
            help="Leave blank to use the current time."
          />
        )}
      </Modal>

      <Modal
        open={detachTarget !== null}
        title="Detach evidence"
        description={
          detachTarget
            ? `Detach ${detachTarget.original_filename} from this claim? The file stays on the shipment and can be attached again later.`
            : undefined
        }
        confirmLabel="Detach file"
        danger
        loading={detaching}
        onConfirm={confirmDetach}
        onClose={() => setDetachTarget(null)}
      />
      <EvidencePreviewModal
        file={previewFile}
        orgId={org.id}
        onClose={() => setPreviewFile(null)}
      />
    </div>
  );
}

export default function ClaimDetailPage() {
  const params = useParams();
  const claimId = String(params.id ?? "");
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Claim"
        description="Status, amounts, evidence, deadline, and history for one claim."
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Claims", href: "/claims" },
          { label: "Claim" },
        ]}
      />
      {currentOrg ? (
        <ClaimDetailContent
          key={`${currentOrg.id}-${claimId}`}
          org={currentOrg}
          claimId={claimId}
        />
      ) : (
        <Alert tone="info">Create an organization first to view claims.</Alert>
      )}
    </div>
  );
}
