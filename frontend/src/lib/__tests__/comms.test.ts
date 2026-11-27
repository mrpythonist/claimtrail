import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch, apiUpload } from "@/lib/api";
import {
  COMMUNICATION_CHANNEL_EVENTS,
  COMMUNICATION_DELIVERY_STATUSES,
  communicationChannelEventLabel,
  communicationDeliveryStatusLabel,
  createCommunicationChannel,
  createDriverEvidenceLink,
  getCommunicationChannel,
  getDriverEvidencePage,
  listCommunicationChannels,
  listCommunicationDeliveries,
  listCommunicationProviders,
  listDriverEvidenceLinks,
  resendDriverEvidenceLink,
  submitDriverEvidence,
  testCommunicationChannel,
} from "../comms";

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
const mockedApiUpload = vi.mocked(apiUpload);

beforeEach(() => {
  mockedApiFetch.mockReset();
  mockedApiUpload.mockReset();
});

describe("communicationChannelEventLabel", () => {
  it("labels every event", () => {
    expect(communicationChannelEventLabel("claim_created")).toBe("Claim created");
    expect(communicationChannelEventLabel("evidence_received")).toBe("Evidence received");
    expect(communicationChannelEventLabel("driver_link_used")).toBe("Driver link used");
    expect(communicationChannelEventLabel("sync_completed")).toBe("Sync completed");
    expect(communicationChannelEventLabel("delivery_failed")).toBe("Delivery failed");
  });
});

describe("COMMUNICATION_CHANNEL_EVENTS", () => {
  it("covers the five events", () => {
    expect(COMMUNICATION_CHANNEL_EVENTS.map((e) => e.value)).toEqual([
      "claim_created",
      "evidence_received",
      "driver_link_used",
      "sync_completed",
      "delivery_failed",
    ]);
  });
});

describe("communicationDeliveryStatusLabel", () => {
  it("labels every status", () => {
    expect(communicationDeliveryStatusLabel("logged")).toBe("Logged");
    expect(communicationDeliveryStatusLabel("sent")).toBe("Sent");
    expect(communicationDeliveryStatusLabel("failed")).toBe("Failed");
  });
});

describe("COMMUNICATION_DELIVERY_STATUSES", () => {
  it("covers the three statuses", () => {
    expect(COMMUNICATION_DELIVERY_STATUSES.map((s) => s.value)).toEqual([
      "logged",
      "sent",
      "failed",
    ]);
  });
});

describe("provider client", () => {
  it("lists providers for the org", async () => {
    mockedApiFetch.mockResolvedValue({
      data: [
        {
          name: "mock",
          label: "Mock Channel",
          live_available: false,
          webhook_url_required: false,
          webhook_url_hint: "No webhook URL needed.",
        },
      ],
    });
    await expect(listCommunicationProviders("org-1")).resolves.toHaveLength(1);
    expect(mockedApiFetch).toHaveBeenCalledWith("/comms/providers", { orgId: "org-1" });
  });
});

describe("channel client", () => {
  it("lists channels for the org", async () => {
    mockedApiFetch.mockResolvedValue({ data: [] });
    await expect(listCommunicationChannels("org-1")).resolves.toEqual([]);
    expect(mockedApiFetch).toHaveBeenCalledWith("/comms/channels", { orgId: "org-1" });
  });

  it("creates a channel with the given settings", async () => {
    mockedApiFetch.mockResolvedValue({
      data: { id: "ch-1", provider: "mock", name: "Mock", status: "active" },
    });
    await createCommunicationChannel("org-1", {
      provider: "mock",
      name: "Mock",
      settings: { claim_created: true },
    });
    expect(mockedApiFetch).toHaveBeenCalledWith("/comms/channels", {
      method: "POST",
      orgId: "org-1",
      body: { provider: "mock", name: "Mock", settings: { claim_created: true } },
    });
  });

  it("gets one channel and tests it", async () => {
    mockedApiFetch.mockResolvedValue({ data: { id: "ch-1" } });
    await getCommunicationChannel("org-1", "ch-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/comms/channels/ch-1", { orgId: "org-1" });

    mockedApiFetch.mockResolvedValue({
      data: { ok: true, live_available: false, message: "Recorded as a test delivery.", delivery_id: "dl-1" },
    });
    await expect(testCommunicationChannel("org-1", "ch-1")).resolves.toMatchObject({ ok: true });
    expect(mockedApiFetch).toHaveBeenCalledWith("/comms/channels/ch-1/test", {
      method: "POST",
      orgId: "org-1",
    });
  });

  it("lists deliveries with filters", async () => {
    mockedApiFetch.mockResolvedValue({ data: [] });
    await listCommunicationDeliveries("org-1", { communication_channel_id: "ch-1", status: "failed" });
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/comms/deliveries?communication_channel_id=ch-1&status=failed",
      { orgId: "org-1" },
    );
  });
});

describe("driver link client", () => {
  it("lists links for the org", async () => {
    mockedApiFetch.mockResolvedValue({ data: [] });
    await expect(listDriverEvidenceLinks("org-1")).resolves.toEqual([]);
    expect(mockedApiFetch).toHaveBeenCalledWith("/comms/driver-links", { orgId: "org-1" });
  });

  it("creates a link and returns the token once", async () => {
    mockedApiFetch.mockResolvedValue({
      data: {
        id: "lk-1",
        token: "tok",
        submit_url: "http://localhost:3000/driver-evidence/tok",
        state: "usable",
      },
    });
    const created = await createDriverEvidenceLink("org-1", {
      shipment_id: "sh-1",
      ttl_hours: 72,
      max_uses: 1,
    });
    expect(created.token).toBe("tok");
    expect(mockedApiFetch).toHaveBeenCalledWith("/comms/driver-links", {
      method: "POST",
      orgId: "org-1",
      body: { shipment_id: "sh-1", ttl_hours: 72, max_uses: 1 },
    });
  });

  it("resends a link", async () => {
    mockedApiFetch.mockResolvedValue({ data: { id: "lk-1", token: "tok2" } });
    await resendDriverEvidenceLink("org-1", "lk-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/comms/driver-links/lk-1/resend", {
      method: "POST",
      orgId: "org-1",
    });
  });

  it("loads the public page without an org", async () => {
    mockedApiFetch.mockResolvedValue({
      data: { shipment_reference: "REF-1", state: "usable" },
    });
    await getDriverEvidencePage("tok");
    expect(mockedApiFetch).toHaveBeenCalledWith("/driver-evidence/tok", { withOrg: false });
  });

  it("submits evidence through the public upload endpoint", async () => {
    mockedApiUpload.mockResolvedValue({ data: { id: "sb-1" } });
    const form = new FormData();
    form.append("document_category", "delivery_receipt");
    await submitDriverEvidence("tok", form);
    expect(mockedApiUpload).toHaveBeenCalledWith(
      "/driver-evidence/tok/submit",
      form,
      { withOrg: false },
    );
  });
});
