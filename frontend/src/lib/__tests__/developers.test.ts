import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import {
  API_KEY_SCOPES,
  WEBHOOK_EVENTS,
  createAccountingConnection,
  createApiKey,
  createWebhookEndpoint,
  listApiKeys,
  listWebhookDeliveries,
  listWebhookEndpoints,
  rotateApiKey,
  syncAccountingConnection,
  testWebhookEndpoint,
} from "../developers";
import { canManageApiKeys, canManageIntegrations } from "../permissions";
import type {
  AccountingConnection,
  ApiKey,
  WebhookDelivery,
  WebhookEndpoint,
} from "@/lib/types";

vi.mock("@/lib/api", () => {
  class ApiRequestError extends Error {
    status: number;
    fields: Record<string, string[]>;
    constructor(status: number, body: { message: string }) {
      super(body.message);
      this.status = status;
      this.fields = {};
    }
  }
  return { apiFetch: vi.fn(), apiUpload: vi.fn(), ApiRequestError };
});

const mockedApiFetch = vi.mocked(apiFetch);

beforeEach(() => {
  mockedApiFetch.mockReset();
});

const KEY: ApiKey = {
  id: "key-1",
  organization_id: "org-1",
  name: "TMS sync key",
  kind: "standard",
  key_prefix: "ct_live_ab12",
  scopes: ["claims:read"],
  status: "active",
  broker_name: null,
  grace_ends_at: null,
  last_used_at: null,
  created_at: "2026-11-28T00:00:00.000Z",
  updated_at: "2026-11-28T00:00:00.000Z",
};

const ENDPOINT: WebhookEndpoint = {
  id: "hook-1",
  organization_id: "org-1",
  name: "Ops hook",
  url_host: "hooks.example.com",
  events: ["claim.created"],
  status: "active",
  last_delivered_at: null,
  last_error: null,
  created_at: "2026-11-28T00:00:00.000Z",
  updated_at: "2026-11-28T00:00:00.000Z",
};

const DELIVERY: WebhookDelivery = {
  id: "del-1",
  webhook_endpoint_id: "hook-1",
  organization_id: "org-1",
  event_type: "claim.created",
  status: "delivered",
  attempts: 1,
  http_status: 200,
  response_body: "received",
  next_retry_at: null,
  delivered_at: "2026-11-28T00:00:00.000Z",
  created_at: "2026-11-28T00:00:00.000Z",
  updated_at: "2026-11-28T00:00:00.000Z",
};

const CONNECTION: AccountingConnection = {
  id: "acct-1",
  organization_id: "org-1",
  provider: "mock_quickbooks",
  provider_label: "QuickBooks (Mock)",
  name: "QB mock",
  status: "connected",
  company_name: "Acme Carriers LLC",
  live_available: false,
  last_sync_summary: null,
  last_synced_at: null,
  last_error: null,
  created_at: "2026-11-28T00:00:00.000Z",
  updated_at: "2026-11-28T00:00:00.000Z",
};

describe("developers client", () => {
  it("lists API keys from the data envelope", async () => {
    mockedApiFetch.mockResolvedValue({ data: [KEY] });
    await expect(listApiKeys()).resolves.toEqual([KEY]);
    expect(mockedApiFetch).toHaveBeenCalledWith("/api-keys");
  });

  it("returns the plain text key exactly once on creation", async () => {
    mockedApiFetch.mockResolvedValue({ data: KEY, plain_text_key: "ct_live_full_key_once" });
    const created = await createApiKey({ name: "TMS sync key", kind: "standard", scopes: ["claims:read"] });
    expect(created.key).toEqual(KEY);
    expect(created.plain_text_key).toBe("ct_live_full_key_once");
    expect(mockedApiFetch).toHaveBeenCalledWith("/api-keys", {
      method: "POST",
      body: { name: "TMS sync key", kind: "standard", scopes: ["claims:read"] },
    });
  });

  it("rotates a key and returns the replacement plain text key", async () => {
    mockedApiFetch.mockResolvedValue({ data: { ...KEY, status: "rotating" }, plain_text_key: "ct_live_replacement" });
    const rotated = await rotateApiKey("key-1");
    expect(rotated.plain_text_key).toBe("ct_live_replacement");
    expect(rotated.key.status).toBe("rotating");
    expect(mockedApiFetch).toHaveBeenCalledWith("/api-keys/key-1/rotate", { method: "POST" });
  });

  it("lists webhook endpoints and their deliveries", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: [ENDPOINT] });
    await expect(listWebhookEndpoints()).resolves.toEqual([ENDPOINT]);
    mockedApiFetch.mockResolvedValueOnce({ data: [DELIVERY] });
    await expect(listWebhookDeliveries("hook-1")).resolves.toEqual([DELIVERY]);
    expect(mockedApiFetch).toHaveBeenCalledWith("/webhooks/hook-1/deliveries");
  });

  it("returns the plain text secret exactly once on endpoint creation", async () => {
    mockedApiFetch.mockResolvedValue({ data: ENDPOINT, plain_text_secret: "secret_once" });
    const created = await createWebhookEndpoint({
      name: "Ops hook",
      url: "https://hooks.example.com/claimtrail",
      events: ["claim.created"],
    });
    expect(created.endpoint).toEqual(ENDPOINT);
    expect(created.plain_text_secret).toBe("secret_once");
  });

  it("sends a test delivery and returns the delivery row", async () => {
    mockedApiFetch.mockResolvedValue({ data: DELIVERY });
    await expect(testWebhookEndpoint("hook-1")).resolves.toEqual(DELIVERY);
    expect(mockedApiFetch).toHaveBeenCalledWith("/webhooks/hook-1/test", { method: "POST" });
  });

  it("creates an accounting connection with the company name nested in settings", async () => {
    mockedApiFetch.mockResolvedValue({ data: CONNECTION });
    const created = await createAccountingConnection({
      provider: "mock_quickbooks",
      name: "QB mock",
      company_name: "Acme Carriers LLC",
    });
    expect(created).toEqual(CONNECTION);
    expect(mockedApiFetch).toHaveBeenCalledWith("/accounting", {
      method: "POST",
      body: {
        provider: "mock_quickbooks",
        name: "QB mock",
        company_name: "Acme Carriers LLC",
        settings: { company_name: "Acme Carriers LLC" },
      },
    });
  });

  it("returns the mock sync summary with exported and skipped counts", async () => {
    const summary = { exported: 1, skipped: 1, records: [] };
    mockedApiFetch.mockResolvedValue({ data: summary });
    await expect(syncAccountingConnection("acct-1")).resolves.toEqual(summary);
    expect(mockedApiFetch).toHaveBeenCalledWith("/accounting/acct-1/sync", { method: "POST" });
  });

  it("exposes exactly the scopes the backend enforces", () => {
    expect(API_KEY_SCOPES.map((scope) => scope.value)).toEqual([
      "claims:read",
      "claims:write",
      "shipments:read",
      "payments:read",
      "documents:read",
      "broker:status",
    ]);
  });

  it("exposes exactly the webhook events the backend dispatches", () => {
    expect(WEBHOOK_EVENTS.map((event) => event.value)).toEqual([
      "claim.created",
      "claim.updated",
      "claim.paid",
      "payment.recorded",
      "document.processed",
      "workflow.run_completed",
    ]);
  });
});

describe("developer permissions", () => {
  it("lets owner, admin, operations manager, and dispatcher manage keys and integrations", () => {
    for (const role of ["owner", "admin", "operations_manager", "dispatcher"] as const) {
      expect(canManageApiKeys(role)).toBe(true);
      expect(canManageIntegrations(role)).toBe(true);
    }
  });

  it("keeps billing staff and viewers read-only", () => {
    for (const role of ["billing_staff", "viewer"] as const) {
      expect(canManageApiKeys(role)).toBe(false);
      expect(canManageIntegrations(role)).toBe(false);
    }
    expect(canManageApiKeys(null)).toBe(false);
    expect(canManageIntegrations(undefined)).toBe(false);
  });
});
