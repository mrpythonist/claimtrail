import { apiFetch } from "./api";
import type {
  InboundEmail,
  InboundEmailAddress,
  InboundEmailStatus,
  PaginatedMeta,
} from "./types";

/**
 * Typed client for the email intake endpoints (v1.3.0).
 *
 * Each organization has its own inbound email addresses, like
 * {org-slug}-{random6}@inbound.claimtrail.local. Mail forwarded
 * to one arrives through the HMAC-signed webhook as a normalized
 * JSON payload; no real provider is wired yet. Any member may
 * list and read addresses and emails. Creating, regenerating,
 * and deactivating addresses, and matching, unmatching, and
 * dismissing emails need the manage-email-intake permission
 * (owner, admin, operations manager, dispatcher); the UI gates
 * on canManageEmailIntake and still handles 403 responses
 * gracefully.
 */

export const INBOUND_EMAIL_STATUSES: {
  value: InboundEmailStatus;
  label: string;
}[] = [
  { value: "received", label: "Received" },
  { value: "processed", label: "Processed" },
  { value: "matched", label: "Matched" },
  { value: "unmatched", label: "Unmatched" },
  { value: "failed", label: "Failed" },
  { value: "dismissed", label: "Dismissed" },
];

export function inboundEmailStatusLabel(status: InboundEmailStatus): string {
  return INBOUND_EMAIL_STATUSES.find((s) => s.value === status)?.label ?? status;
}

export async function listInboundAddresses(
  orgId: string,
): Promise<InboundEmailAddress[]> {
  const response = await apiFetch<{ data: InboundEmailAddress[] }>(
    "/email-intake/addresses",
    { orgId },
  );
  return response.data;
}

export async function createInboundAddress(
  orgId: string,
  label?: string,
): Promise<InboundEmailAddress> {
  const response = await apiFetch<{ data: InboundEmailAddress }>(
    "/email-intake/addresses",
    { method: "POST", orgId, body: { label: label ?? null } },
  );
  return response.data;
}

export async function regenerateInboundAddress(
  orgId: string,
  addressId: string,
): Promise<InboundEmailAddress> {
  const response = await apiFetch<{ data: InboundEmailAddress }>(
    `/email-intake/addresses/${encodeURIComponent(addressId)}/regenerate`,
    { method: "POST", orgId },
  );
  return response.data;
}

export async function deactivateInboundAddress(
  orgId: string,
  addressId: string,
): Promise<InboundEmailAddress> {
  const response = await apiFetch<{ data: InboundEmailAddress }>(
    `/email-intake/addresses/${encodeURIComponent(addressId)}/deactivate`,
    { method: "POST", orgId },
  );
  return response.data;
}

export async function listInboundEmails(
  orgId: string,
  status?: InboundEmailStatus | "all",
  matched?: boolean,
  page = 1,
  perPage = 25,
): Promise<{ data: InboundEmail[]; meta: PaginatedMeta }> {
  const params = new URLSearchParams({
    page: String(page),
    per_page: String(perPage),
  });
  if (status !== undefined && status !== "all") {
    params.set("status", status);
  }
  if (matched !== undefined) {
    params.set("matched", matched ? "true" : "false");
  }
  return apiFetch<{ data: InboundEmail[]; meta: PaginatedMeta }>(
    `/email-intake/emails?${params.toString()}`,
    { orgId },
  );
}

export async function getInboundEmail(
  orgId: string,
  emailId: string,
): Promise<InboundEmail> {
  const response = await apiFetch<{ data: InboundEmail }>(
    `/email-intake/emails/${encodeURIComponent(emailId)}`,
    { orgId },
  );
  return response.data;
}

export async function matchInboundEmail(
  orgId: string,
  emailId: string,
  shipmentId: string,
): Promise<InboundEmail> {
  const response = await apiFetch<{ data: InboundEmail }>(
    `/email-intake/emails/${encodeURIComponent(emailId)}/match`,
    { method: "POST", orgId, body: { shipment_id: shipmentId } },
  );
  return response.data;
}

export async function unmatchInboundEmail(
  orgId: string,
  emailId: string,
  reason?: string,
): Promise<InboundEmail> {
  const response = await apiFetch<{ data: InboundEmail }>(
    `/email-intake/emails/${encodeURIComponent(emailId)}/unmatch`,
    { method: "POST", orgId, body: { reason: reason ?? null } },
  );
  return response.data;
}

export async function dismissInboundEmail(
  orgId: string,
  emailId: string,
  reason: string,
): Promise<InboundEmail> {
  const response = await apiFetch<{ data: InboundEmail }>(
    `/email-intake/emails/${encodeURIComponent(emailId)}/dismiss`,
    { method: "POST", orgId, body: { reason } },
  );
  return response.data;
}
