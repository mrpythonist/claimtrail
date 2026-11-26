import { apiFetch } from "./api";
import type {
  PaginatedMeta,
  SupportTicket,
  SupportTicketCategory,
  SupportTicketPriority,
  SupportTicketStatus,
} from "./types";

/**
 * Typed client for the support ticket endpoints
 * (GET/POST /support/tickets).
 *
 * Every member of an organization may open a ticket and reply on
 * it. Triage, status changes, and internal notes need the
 * manage-support permission (owner and admin); the UI gates on
 * canManageSupport and still handles 403 responses gracefully.
 * Ticket bodies and messages are never written to the audit log.
 */

export const SUPPORT_CATEGORIES: { value: SupportTicketCategory; label: string }[] = [
  { value: "billing", label: "Billing" },
  { value: "technical", label: "Technical" },
  { value: "feature_request", label: "Feature request" },
  { value: "account", label: "Account" },
  { value: "other", label: "Other" },
];

export const SUPPORT_PRIORITIES: { value: SupportTicketPriority; label: string }[] = [
  { value: "low", label: "Low" },
  { value: "normal", label: "Normal" },
  { value: "high", label: "High" },
  { value: "urgent", label: "Urgent" },
];

export const SUPPORT_STATUSES: { value: SupportTicketStatus; label: string }[] = [
  { value: "open", label: "Open" },
  { value: "in_progress", label: "In progress" },
  { value: "waiting_on_customer", label: "Waiting on customer" },
  { value: "resolved", label: "Resolved" },
  { value: "closed", label: "Closed" },
];

export function supportStatusLabel(status: SupportTicketStatus): string {
  return SUPPORT_STATUSES.find((s) => s.value === status)?.label ?? status;
}

export function supportCategoryLabel(category: SupportTicketCategory): string {
  return SUPPORT_CATEGORIES.find((c) => c.value === category)?.label ?? category;
}

export async function listTickets(
  orgId: string,
  status?: SupportTicketStatus | "all",
  page = 1,
  perPage = 25,
): Promise<{ data: SupportTicket[]; meta: PaginatedMeta }> {
  const params = new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
  });
  if (status !== undefined && status !== "all") {
    params.set("status", status);
  }
  return apiFetch<{ data: SupportTicket[]; meta: PaginatedMeta }>(
    `/support/tickets?${params.toString()}`,
    { orgId },
  );
}

export async function getTicket(orgId: string, ticketId: string): Promise<SupportTicket> {
  const response = await apiFetch<{ data: SupportTicket }>(
    `/support/tickets/${encodeURIComponent(ticketId)}`,
    { orgId },
  );
  return response.data;
}

export async function createTicket(
  orgId: string,
  input: {
    subject: string;
    category: SupportTicketCategory;
    priority: SupportTicketPriority;
    message: string;
  },
): Promise<SupportTicket> {
  const response = await apiFetch<{ data: SupportTicket }>("/support/tickets", {
    method: "POST",
    orgId,
    body: input,
  });
  return response.data;
}

export async function replyToTicket(
  orgId: string,
  ticketId: string,
  body: string,
  internal = false,
): Promise<{
  id: string;
  body: string;
  internal: boolean;
  created_at: string;
  ticket: SupportTicket;
}> {
  const response = await apiFetch<{
    data: {
      id: string;
      body: string;
      internal: boolean;
      created_at: string;
      ticket: SupportTicket;
    };
  }>(`/support/tickets/${encodeURIComponent(ticketId)}/messages`, {
    method: "POST",
    orgId,
    body: { body, internal },
  });
  return response.data;
}

export async function setTicketStatus(
  orgId: string,
  ticketId: string,
  status: SupportTicketStatus,
): Promise<SupportTicket> {
  const response = await apiFetch<{ data: SupportTicket }>(
    `/support/tickets/${encodeURIComponent(ticketId)}/status`,
    { method: "POST", orgId, body: { status } },
  );
  return response.data;
}
