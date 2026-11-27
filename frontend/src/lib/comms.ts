import { apiFetch, apiUpload } from "./api";
import type {
  CommunicationChannel,
  CommunicationChannelEvent,
  CommunicationChannelTest,
  CommunicationDelivery,
  CommunicationDeliveryStatus,
  CommunicationProvider,
  DriverEvidenceLink,
  DriverEvidenceLinkCreated,
  DriverEvidencePage,
  DriverEvidenceSubmission,
} from "./types";

/**
 * Typed client for the communication endpoints (v1.3.3).
 *
 * Channels connect Slack or Microsoft Teams through an
 * incoming webhook, stored encrypted, never returned by
 * the API. No provider in v1.3.3 is live: the mock
 * records deliveries locally, and Slack and Teams record
 * a well-formed payload without sending it anywhere.
 * Driver evidence links let a driver submit evidence for
 * one shipment or claim without an account; the raw
 * token is returned once at creation and never again.
 * Any member may list channels, deliveries, and links;
 * creating, updating, deleting, testing, and resending
 * need the manage-communications permission (owner,
 * admin, operations manager, dispatcher); the UI gates
 * on canManageCommunications and still handles 403
 * responses gracefully.
 */

export const COMMUNICATION_CHANNEL_EVENTS: {
  value: CommunicationChannelEvent;
  label: string;
}[] = [
  { value: "claim_created", label: "Claim created" },
  { value: "evidence_received", label: "Evidence received" },
  { value: "driver_link_used", label: "Driver link used" },
  { value: "sync_completed", label: "Sync completed" },
  { value: "delivery_failed", label: "Delivery failed" },
];

export function communicationChannelEventLabel(
  event: CommunicationChannelEvent,
): string {
  return (
    COMMUNICATION_CHANNEL_EVENTS.find((e) => e.value === event)?.label ?? event
  );
}

export const COMMUNICATION_DELIVERY_STATUSES: {
  value: CommunicationDeliveryStatus;
  label: string;
}[] = [
  { value: "logged", label: "Logged" },
  { value: "sent", label: "Sent" },
  { value: "failed", label: "Failed" },
];

export function communicationDeliveryStatusLabel(
  status: CommunicationDeliveryStatus,
): string {
  return (
    COMMUNICATION_DELIVERY_STATUSES.find((s) => s.value === status)?.label ??
    status
  );
}

export async function listCommunicationProviders(
  orgId: string,
): Promise<CommunicationProvider[]> {
  const response = await apiFetch<{ data: CommunicationProvider[] }>(
    "/comms/providers",
    { orgId },
  );
  return response.data;
}

export async function listCommunicationChannels(
  orgId: string,
): Promise<CommunicationChannel[]> {
  const response = await apiFetch<{ data: CommunicationChannel[] }>(
    "/comms/channels",
    { orgId },
  );
  return response.data;
}

export async function createCommunicationChannel(
  orgId: string,
  input: {
    provider: string;
    name: string;
    webhook_url?: string | null;
    settings?: Partial<Record<CommunicationChannelEvent, boolean>>;
  },
): Promise<CommunicationChannel> {
  const response = await apiFetch<{ data: CommunicationChannel }>(
    "/comms/channels",
    { method: "POST", orgId, body: input },
  );
  return response.data;
}

export async function getCommunicationChannel(
  orgId: string,
  channelId: string,
): Promise<CommunicationChannel> {
  const response = await apiFetch<{ data: CommunicationChannel }>(
    `/comms/channels/${channelId}`,
    { orgId },
  );
  return response.data;
}

export async function updateCommunicationChannel(
  orgId: string,
  channelId: string,
  input: {
    name?: string;
    status?: "active" | "disabled";
    webhook_url?: string | null;
    settings?: Partial<Record<CommunicationChannelEvent, boolean>>;
  },
): Promise<CommunicationChannel> {
  const response = await apiFetch<{ data: CommunicationChannel }>(
    `/comms/channels/${channelId}`,
    { method: "PUT", orgId, body: input },
  );
  return response.data;
}

export async function deleteCommunicationChannel(
  orgId: string,
  channelId: string,
): Promise<void> {
  await apiFetch(`/comms/channels/${channelId}`, {
    method: "DELETE",
    orgId,
  });
}

export async function testCommunicationChannel(
  orgId: string,
  channelId: string,
): Promise<CommunicationChannelTest> {
  const response = await apiFetch<{ data: CommunicationChannelTest }>(
    `/comms/channels/${channelId}/test`,
    { method: "POST", orgId },
  );
  return response.data;
}

export async function listCommunicationDeliveries(
  orgId: string,
  filters?: {
    communication_channel_id?: string;
    event?: CommunicationChannelEvent | "test";
    status?: CommunicationDeliveryStatus;
  },
): Promise<CommunicationDelivery[]> {
  const query = new URLSearchParams();
  if (filters?.communication_channel_id) {
    query.set("communication_channel_id", filters.communication_channel_id);
  }
  if (filters?.event) {
    query.set("event", filters.event);
  }
  if (filters?.status) {
    query.set("status", filters.status);
  }
  const suffix = query.size > 0 ? `?${query.toString()}` : "";
  const response = await apiFetch<{ data: CommunicationDelivery[] }>(
    `/comms/deliveries${suffix}`,
    { orgId },
  );
  return response.data;
}

export async function getCommunicationDelivery(
  orgId: string,
  deliveryId: string,
): Promise<CommunicationDelivery> {
  const response = await apiFetch<{ data: CommunicationDelivery }>(
    `/comms/deliveries/${deliveryId}`,
    { orgId },
  );
  return response.data;
}

export async function listDriverEvidenceLinks(
  orgId: string,
  filters?: {
    shipment_id?: string;
    claim_id?: string;
    state?: string;
  },
): Promise<DriverEvidenceLink[]> {
  const query = new URLSearchParams();
  if (filters?.shipment_id) {
    query.set("shipment_id", filters.shipment_id);
  }
  if (filters?.claim_id) {
    query.set("claim_id", filters.claim_id);
  }
  if (filters?.state) {
    query.set("state", filters.state);
  }
  const suffix = query.size > 0 ? `?${query.toString()}` : "";
  const response = await apiFetch<{ data: DriverEvidenceLink[] }>(
    `/comms/driver-links${suffix}`,
    { orgId },
  );
  return response.data;
}

export async function createDriverEvidenceLink(
  orgId: string,
  input: {
    shipment_id?: string | null;
    claim_id?: string | null;
    driver_name?: string | null;
    driver_email?: string | null;
    ttl_hours?: number;
    max_uses?: number;
  },
): Promise<DriverEvidenceLinkCreated> {
  const response = await apiFetch<{ data: DriverEvidenceLinkCreated }>(
    "/comms/driver-links",
    { method: "POST", orgId, body: input },
  );
  return response.data;
}

export async function getDriverEvidenceLink(
  orgId: string,
  linkId: string,
): Promise<DriverEvidenceLink> {
  const response = await apiFetch<{ data: DriverEvidenceLink }>(
    `/comms/driver-links/${linkId}`,
    { orgId },
  );
  return response.data;
}

export async function revokeDriverEvidenceLink(
  orgId: string,
  linkId: string,
): Promise<void> {
  await apiFetch(`/comms/driver-links/${linkId}`, {
    method: "DELETE",
    orgId,
  });
}

export async function resendDriverEvidenceLink(
  orgId: string,
  linkId: string,
): Promise<DriverEvidenceLinkCreated> {
  const response = await apiFetch<{ data: DriverEvidenceLinkCreated }>(
    `/comms/driver-links/${linkId}/resend`,
    { method: "POST", orgId },
  );
  return response.data;
}

export async function listDriverLinkSubmissions(
  orgId: string,
  linkId: string,
): Promise<DriverEvidenceSubmission[]> {
  const response = await apiFetch<{ data: DriverEvidenceSubmission[] }>(
    `/comms/driver-links/${linkId}/submissions`,
    { orgId },
  );
  return response.data;
}

/** Public: load the driver evidence page for a link token. No auth. */
export async function getDriverEvidencePage(
  token: string,
): Promise<DriverEvidencePage> {
  const response = await apiFetch<{ data: DriverEvidencePage }>(
    `/driver-evidence/${token}`,
    { withOrg: false },
  );
  return response.data;
}

/** Public: submit evidence through a link token. No auth. */
export async function submitDriverEvidence(
  token: string,
  input: FormData,
): Promise<DriverEvidenceSubmission> {
  const response = await apiUpload<{ data: DriverEvidenceSubmission }>(
    `/driver-evidence/${token}/submit`,
    input,
    { withOrg: false },
  );
  return response.data;
}
