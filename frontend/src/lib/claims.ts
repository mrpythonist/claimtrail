import type {
  ClaimCategory,
  ClaimStatus,
  DeadlineStatus,
  GeneratedDocumentStatus,
  ReadinessStatus,
} from "./types";
import type { BadgeTone } from "./shipments";

/**
 * Shared Milestone 3 helpers: claim status and category labels, badge
 * tones, deadline math, and transition rules. Nothing here calls the
 * API. The backend remains the authority on which transitions are
 * allowed; allowed_transitions on the claim is what the UI renders.
 */

export const CLAIM_STATUSES: ClaimStatus[] = [
  "potential",
  "evidence_required",
  "under_review",
  "ready_to_submit",
  "submitted",
  "accepted",
  "partially_accepted",
  "rejected",
  "paid",
  "closed",
];

export const CLAIM_STATUS_LABELS: Record<ClaimStatus, string> = {
  potential: "Potential",
  evidence_required: "Evidence Required",
  under_review: "Under Review",
  ready_to_submit: "Ready to Submit",
  submitted: "Submitted",
  accepted: "Accepted",
  partially_accepted: "Partially Accepted",
  rejected: "Rejected",
  paid: "Paid",
  closed: "Closed",
};

export function claimStatusTone(status: ClaimStatus): BadgeTone {
  switch (status) {
    case "paid":
    case "accepted":
      return "success";
    case "ready_to_submit":
    case "submitted":
      return "info";
    case "evidence_required":
    case "under_review":
    case "partially_accepted":
      return "warning";
    case "rejected":
      return "danger";
    default:
      return "neutral";
  }
}

export const CLAIM_CATEGORIES: { value: ClaimCategory; label: string }[] = [
  { value: "detention", label: "Detention" },
  { value: "layover", label: "Layover" },
  { value: "tonu", label: "Truck Ordered Not Used (TONU)" },
  { value: "lumper", label: "Lumper" },
  { value: "additional_stop", label: "Additional Stop" },
  { value: "additional_miles", label: "Additional Miles" },
  { value: "other", label: "Other" },
];

export function claimCategoryLabel(category: string): string {
  return (
    CLAIM_CATEGORIES.find((item) => item.value === category)?.label ?? category
  );
}

/**
 * Statuses before submission. Once a claim leaves this set, its
 * amount, currency, category, and event link are frozen: the API
 * rejects changes to them with a 422.
 */
const PRE_SUBMISSION_STATUSES: ClaimStatus[] = [
  "potential",
  "evidence_required",
  "under_review",
  "ready_to_submit",
];

export function isClaimPastSubmission(status: ClaimStatus): boolean {
  return !PRE_SUBMISSION_STATUSES.includes(status);
}

/** Closed and paid claims cannot be edited at all (backend policy). */
export function isClaimImmutable(status: ClaimStatus): boolean {
  return status === "closed" || status === "paid";
}

/** Payment can only be recorded on an accepted or partially accepted claim. */
export function canRecordPaymentFor(status: ClaimStatus): boolean {
  return status === "accepted" || status === "partially_accepted";
}

/**
 * What a transition target needs from the user, mirroring the backend
 * transition service. "submitted" uses the current time unless a date
 * is given; "closed" needs a reason when the claim is still before
 * submission, and the form asks for one in every case.
 */
export function transitionNeeds(target: string): {
  acceptedAmount: boolean;
  rejectionReason: boolean;
  reason: boolean;
  submittedAt: boolean;
} {
  return {
    acceptedAmount: target === "accepted" || target === "partially_accepted",
    rejectionReason: target === "rejected",
    reason: target === "closed",
    submittedAt: target === "submitted",
  };
}

export const DEADLINE_STATUSES: DeadlineStatus[] = [
  "upcoming",
  "due_soon",
  "overdue",
  "met",
  "missed",
];

export const DEADLINE_STATUS_LABELS: Record<DeadlineStatus, string> = {
  upcoming: "Upcoming",
  due_soon: "Due Soon",
  overdue: "Overdue",
  met: "Met",
  missed: "Missed",
};

export function deadlineStatusTone(status: DeadlineStatus): BadgeTone {
  switch (status) {
    case "overdue":
    case "missed":
      return "danger";
    case "due_soon":
      return "warning";
    case "met":
      return "success";
    default:
      return "info";
  }
}

export function readinessStatusTone(status: ReadinessStatus): BadgeTone {
  switch (status) {
    case "ready":
      return "success";
    case "partial":
      return "warning";
    default:
      return "neutral";
  }
}

export function documentStatusTone(status: GeneratedDocumentStatus): BadgeTone {
  switch (status) {
    case "ready":
      return "success";
    case "failed":
      return "danger";
    default:
      return "warning";
  }
}

/** Reminder lead times offered on the deadline form, in days. */
export const REMINDER_DAY_OPTIONS = [1, 3, 7, 14, 30];

/** Whole days from now until the deadline; negative when overdue. */
export function daysUntilDeadline(deadlineAt: string): number {
  const diff = new Date(deadlineAt).getTime() - Date.now();
  return Math.ceil(diff / (1000 * 60 * 60 * 24));
}

export function daysRemainingLabel(deadlineAt: string): string {
  const days = daysUntilDeadline(deadlineAt);
  if (days < 0) return `${Math.abs(days)} day${Math.abs(days) === 1 ? "" : "s"} overdue`;
  if (days === 0) return "Due today";
  if (days === 1) return "Due tomorrow";
  return `${days} days left`;
}

/** Money as stored by the API: a decimal string plus a currency code. */
export function formatMoney(amount: string | null, currency: string): string {
  if (amount === null || amount === "") return "Not set";
  return `${currency} ${amount}`;
}

/**
 * Plain-language next step for the claim review page, based on the
 * claim status and how much evidence is still missing. Shown above
 * the approve/request-changes buttons so the reviewer knows what
 * still blocks submission.
 */
export function reviewNextAction(
  status: ClaimStatus,
  readinessPercentage: number | null,
  missingCount: number | null,
): string {
  if (status !== "under_review") {
    return "This claim is not under review, so it cannot be approved or sent back from here. Open the claim to see the moves that are available now.";
  }
  if (readinessPercentage !== null && readinessPercentage < 100) {
    const missing =
      missingCount !== null && missingCount > 0
        ? ` ${missingCount} required document${missingCount === 1 ? " is" : "s are"} still missing.`
        : "";
    return `Evidence is ${readinessPercentage}% ready.${missing} Ask for changes to send the claim back for evidence, or override readiness first if the missing documents are not needed.`;
  }
  return "Evidence is complete. Approve to move the claim to ready to submit, or ask for changes if something else needs fixing first.";
}
