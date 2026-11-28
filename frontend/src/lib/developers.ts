import { apiFetch } from "./api";
import type {
  AccountingConnection,
  AccountingProviderOption,
  AccountingSyncSummary,
  ApiKey,
  ApiKeyCreated,
  WebhookDelivery,
  WebhookEndpoint,
  WebhookEndpointCreated,
} from "./types";

/**
 * Typed client for the developer surface (v2.0.2): public API
 * keys, webhook endpoints and deliveries, and mock accounting
 * connections.
 *
 * The public API is a separate surface (/api/public/v1)
 * authenticated by Bearer API key, never by user session.
 * Keys are stored hashed: the full key is returned exactly
 * once, at creation or rotation, and never again. Rotation
 * keeps the old key working for a 24 hour grace window, then
 * it stops; revocation is immediate and permanent. A key with
 * the wrong scope gets 403, an unknown or revoked key 401,
 * and a key from another organization sees nothing
 * cross-tenant. The public surface is rate limited per key
 * (120 requests per minute, 429 with Retry-After) and gated
 * by plan: the Free plan is refused with a 402.
 *
 * Webhook deliveries are real HTTP attempts signed with
 * HMAC-SHA256 over the raw body; the signature travels in
 * X-ClaimTrail-Signature as `sha256=<hex>`. Failed deliveries
 * retry with backoff, at most 5 attempts, then are recorded
 * failed.
 *
 * Accounting providers are mock only (QuickBooks / Xero
 * modelled schema-only): no live credentials, no real sync,
 * no money moves. Broker integrations are read/status access
 * only: this version does not send claim packages to a
 * broker, and no submission channel exists.
 *
 * Managing keys, endpoints, and connections needs
 * manage-api-keys / manage-integrations and the Starter plan
 * or better; the UI gates on canManageApiKeys /
 * canManageIntegrations and still handles 403 and 402
 * responses gracefully.
 */

export const API_KEY_SCOPES: { value: string; label: string }[] = [
  { value: "claims:read", label: "Read claims" },
  { value: "claims:write", label: "Create claims" },
  { value: "shipments:read", label: "Read shipments" },
  { value: "payments:read", label: "Read payments" },
  { value: "documents:read", label: "Read documents" },
  { value: "broker:status", label: "Broker claim status (broker keys only)" },
];

export const WEBHOOK_EVENTS: { value: string; label: string }[] = [
  { value: "claim.created", label: "Claim created" },
  { value: "claim.updated", label: "Claim updated" },
  { value: "claim.paid", label: "Claim paid" },
  { value: "payment.recorded", label: "Payment recorded" },
  { value: "document.processed", label: "Document processed" },
  { value: "workflow.run_completed", label: "Workflow run completed" },
];

export async function listApiKeys(): Promise<ApiKey[]> {
  const body = await apiFetch<{ data: ApiKey[] }>("/api-keys");
  return body.data;
}

export async function createApiKey(input: {
  name: string;
  kind: "standard" | "broker";
  scopes: string[];
  broker_name?: string;
}): Promise<ApiKeyCreated> {
  const body = await apiFetch<{ data: ApiKey; plain_text_key: string }>("/api-keys", {
    method: "POST",
    body: input,
  });
  return { key: body.data, plain_text_key: body.plain_text_key };
}

export async function rotateApiKey(id: string): Promise<ApiKeyCreated> {
  const body = await apiFetch<{ data: ApiKey; plain_text_key: string }>(
    `/api-keys/${id}/rotate`,
    { method: "POST" },
  );
  return { key: body.data, plain_text_key: body.plain_text_key };
}

export async function revokeApiKey(id: string): Promise<void> {
  await apiFetch(`/api-keys/${id}`, { method: "DELETE" });
}

export async function listWebhookEndpoints(): Promise<WebhookEndpoint[]> {
  const body = await apiFetch<{ data: WebhookEndpoint[] }>("/webhooks");
  return body.data;
}

export async function createWebhookEndpoint(input: {
  name: string;
  url: string;
  events: string[];
}): Promise<WebhookEndpointCreated> {
  const body = await apiFetch<{ data: WebhookEndpoint; plain_text_secret: string }>(
    "/webhooks",
    { method: "POST", body: input },
  );
  return { endpoint: body.data, plain_text_secret: body.plain_text_secret };
}

export async function updateWebhookEndpoint(
  id: string,
  input: { name?: string; events?: string[]; status?: "active" | "disabled" },
): Promise<WebhookEndpoint> {
  const body = await apiFetch<{ data: WebhookEndpoint }>(`/webhooks/${id}`, {
    method: "PUT",
    body: input,
  });
  return body.data;
}

export async function deleteWebhookEndpoint(id: string): Promise<void> {
  await apiFetch(`/webhooks/${id}`, { method: "DELETE" });
}

export async function testWebhookEndpoint(id: string): Promise<WebhookDelivery> {
  const body = await apiFetch<{ data: WebhookDelivery }>(`/webhooks/${id}/test`, {
    method: "POST",
  });
  return body.data;
}

export async function listWebhookDeliveries(id: string): Promise<WebhookDelivery[]> {
  const body = await apiFetch<{ data: WebhookDelivery[] }>(`/webhooks/${id}/deliveries`);
  return body.data;
}

export async function listAccountingProviders(): Promise<AccountingProviderOption[]> {
  const body = await apiFetch<{ data: AccountingProviderOption[] }>("/accounting/providers");
  return body.data;
}

export async function listAccountingConnections(): Promise<AccountingConnection[]> {
  const body = await apiFetch<{ data: AccountingConnection[] }>("/accounting");
  return body.data;
}

export async function createAccountingConnection(input: {
  provider: string;
  name: string;
  company_name: string;
}): Promise<AccountingConnection> {
  const body = await apiFetch<{ data: AccountingConnection }>("/accounting", {
    method: "POST",
    body: { ...input, settings: { company_name: input.company_name } },
  });
  return body.data;
}

export async function testAccountingConnection(id: string): Promise<{
  ok: boolean;
  live_available: boolean;
  message: string;
}> {
  const body = await apiFetch<{
    data: { ok: boolean; live_available: boolean; message: string };
  }>(`/accounting/${id}/test`, { method: "POST" });
  return body.data;
}

export async function syncAccountingConnection(id: string): Promise<AccountingSyncSummary> {
  const body = await apiFetch<{ data: AccountingSyncSummary }>(`/accounting/${id}/sync`, {
    method: "POST",
  });
  return body.data;
}

export async function disconnectAccountingConnection(id: string): Promise<AccountingConnection> {
  const body = await apiFetch<{ data: AccountingConnection }>(`/accounting/${id}`, {
    method: "DELETE",
  });
  return body.data;
}
