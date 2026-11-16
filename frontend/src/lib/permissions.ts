import type { OrganizationRole } from "./types";

/**
 * The single place in the frontend that maps roles to capabilities.
 *
 * This mirrors the backend role map (config/permissions.php) for the
 * Milestone 1 and Milestone 2 surface, and is used to enable or hide
 * controls. It is a usability aid, not a security boundary: the backend
 * enforces every permission and the UI must handle 403 responses
 * gracefully.
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
