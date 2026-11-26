import type { ClaimStatus, OrganizationRole } from "./types";

/**
 * The single place in the frontend that maps roles to capabilities.
 *
 * This mirrors the backend role map (config/permissions.php) for the
 * Milestone 1, Milestone 2, Milestone 3, and Milestone 4 surface, and is used to
 * enable or hide controls. It is a usability aid, not a security
 * boundary: the backend enforces every permission and the UI must
 * handle 403 responses gracefully.
 */

function isOneOf(
  role: OrganizationRole | null | undefined,
  allowed: OrganizationRole[],
): boolean {
  return role !== null && role !== undefined && allowed.includes(role);
}

const SHIPMENT_EDITORS: OrganizationRole[] = [
  "owner",
  "admin",
  "operations_manager",
  "dispatcher",
];

const SHIPMENT_MANAGERS: OrganizationRole[] = [
  "owner",
  "admin",
  "operations_manager",
];

const CUSTOMIZATION_MANAGERS: OrganizationRole[] = [
  "owner",
  "admin",
  "operations_manager",
];

const SHIPMENT_EXPORTERS: OrganizationRole[] = [
  "owner",
  "admin",
  "operations_manager",
  "billing_staff",
];

const EVIDENCE_UPLOADERS: OrganizationRole[] = [
  "owner",
  "admin",
  "operations_manager",
  "dispatcher",
  "billing_staff",
];

const CLAIM_CREATORS: OrganizationRole[] = [
  "owner",
  "admin",
  "operations_manager",
  "billing_staff",
];

const CLAIM_REVIEWERS: OrganizationRole[] = [
  "owner",
  "admin",
  "operations_manager",
];

const CLAIM_PAYMENT_RECORDERS: OrganizationRole[] = [
  "owner",
  "admin",
  "billing_staff",
];

const CLAIM_EVIDENCE_ATTACHERS: OrganizationRole[] = [
  "owner",
  "admin",
  "operations_manager",
  "dispatcher",
  "billing_staff",
];

const REQUIREMENT_MANAGERS: OrganizationRole[] = [
  "owner",
  "admin",
  "operations_manager",
];

// ViewReports in the backend map also covers viewers: they can read
// reports but cannot export them.
const REPORT_VIEWERS: OrganizationRole[] = [
  "owner",
  "admin",
  "operations_manager",
  "billing_staff",
  "viewer",
];

const REPORT_EXPORTERS: OrganizationRole[] = [
  "owner",
  "admin",
  "operations_manager",
  "billing_staff",
];

const AUDIT_LOG_VIEWERS: OrganizationRole[] = [
  "owner",
  "admin",
  "operations_manager",
];

const OPERATIONS_VIEWERS: OrganizationRole[] = ["owner", "admin"];

const SECURITY_VIEWERS: OrganizationRole[] = ["owner", "admin"];

// ManageBilling in the backend map (config/permissions.php):
// Owner, Admin, and Billing Staff. Viewers, dispatchers, and
// operations managers can see the plan catalog but not the
// subscription, invoices, or billing actions.
const BILLING_MANAGERS: OrganizationRole[] = [
  "owner",
  "admin",
  "billing_staff",
];

export function canManageOrganization(role: OrganizationRole | null | undefined): boolean {
  return role === "owner" || role === "admin";
}

export function canManageMembers(role: OrganizationRole | null | undefined): boolean {
  return role === "owner" || role === "admin";
}

export function canManageInvitations(role: OrganizationRole | null | undefined): boolean {
  return role === "owner" || role === "admin";
}

export function canCreateShipments(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, SHIPMENT_EDITORS);
}

export function canEditShipments(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, SHIPMENT_EDITORS);
}

export function canArchiveShipments(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, SHIPMENT_MANAGERS);
}

export function canImportShipments(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, SHIPMENT_MANAGERS);
}

export function canExportShipments(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, SHIPMENT_EXPORTERS);
}

export function canExportClaims(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, SHIPMENT_EXPORTERS);
}

export function canManageCustomization(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, CUSTOMIZATION_MANAGERS);
}

export function canManageEvents(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, SHIPMENT_EDITORS);
}

export function canDeleteEvents(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, SHIPMENT_MANAGERS);
}

export function canUploadEvidence(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, EVIDENCE_UPLOADERS);
}

export function canDeleteEvidence(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, SHIPMENT_MANAGERS);
}

export function canVerifyEvidence(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, SHIPMENT_EXPORTERS);
}

export function canCreateClaims(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, CLAIM_CREATORS);
}

export function canEditClaims(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, CLAIM_CREATORS);
}

export function canReviewClaims(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, CLAIM_REVIEWERS);
}

export function canSubmitClaims(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, CLAIM_CREATORS);
}

export function canRecordClaimPayments(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, CLAIM_PAYMENT_RECORDERS);
}

export function canAttachClaimEvidence(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, CLAIM_EVIDENCE_ATTACHERS);
}

export function canGenerateClaimPackages(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, CLAIM_CREATORS);
}

export function canManageEvidenceRequirements(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, REQUIREMENT_MANAGERS);
}

export function canManageDeadlines(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, CLAIM_CREATORS);
}

export function canViewReports(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, REPORT_VIEWERS);
}

export function canExportReports(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, REPORT_EXPORTERS);
}

export function canViewAuditLogs(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, AUDIT_LOG_VIEWERS);
}

export function canViewOperations(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, OPERATIONS_VIEWERS);
}

export function canViewSecurity(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, SECURITY_VIEWERS);
}

export function canManageBilling(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, BILLING_MANAGERS);
}

// ManageSupport in the backend map (config/permissions.php):
// Owner and Admin. They triage tickets, change statuses, and
// write internal notes. Every member may open a ticket and
// reply on it.
const SUPPORT_MANAGERS: OrganizationRole[] = ["owner", "admin"];

export function canManageSupport(role: OrganizationRole | null | undefined): boolean {
  return isOneOf(role, SUPPORT_MANAGERS);
}

/**
 * Whether the role may move a claim to the given target status.
 * Mirrors the backend ClaimPolicy: submitting needs the submit
 * permission, review outcomes need the review permission, and the
 * remaining moves need the edit permission. Paid is never a
 * transition target; payments go through record-payment only.
 */
export function canTransitionClaimTo(
  role: OrganizationRole | null | undefined,
  target: ClaimStatus,
): boolean {
  switch (target) {
    case "submitted":
      return canSubmitClaims(role);
    case "ready_to_submit":
    case "accepted":
    case "partially_accepted":
    case "rejected":
      return canReviewClaims(role);
    case "paid":
      return false;
    default:
      return canEditClaims(role);
  }
}

export function roleLabel(role: OrganizationRole): string {
  switch (role) {
    case "owner":
      return "Owner";
    case "admin":
      return "Admin";
    case "operations_manager":
      return "Operations Manager";
    case "dispatcher":
      return "Dispatcher";
    case "billing_staff":
      return "Billing Staff";
    case "viewer":
      return "Viewer";
  }
}
