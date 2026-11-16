import type { OrganizationRole } from "./types";

/**
 * The single place in the frontend that maps roles to capabilities.
 *
 * This mirrors the backend role map (config/permissions.php) for the
 * Milestone 1 surface only, and is used to enable or hide controls.
 * It is a usability aid, not a security boundary: the backend enforces
 * every permission and the UI must handle 403 responses gracefully.
 */

export function canManageOrganization(role: OrganizationRole | null | undefined): boolean {
  return role === "owner" || role === "admin";
}

export function canManageMembers(role: OrganizationRole | null | undefined): boolean {
  return role === "owner" || role === "admin";
}

export function canManageInvitations(role: OrganizationRole | null | undefined): boolean {
  return role === "owner" || role === "admin";
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
