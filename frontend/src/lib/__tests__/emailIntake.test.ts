import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import {
  INBOUND_EMAIL_STATUSES,
  createInboundAddress,
  deactivateInboundAddress,
  dismissInboundEmail,
  getInboundEmail,
  inboundEmailStatusLabel,
  listInboundAddresses,
  listInboundEmails,
  matchInboundEmail,
  regenerateInboundAddress,
  unmatchInboundEmail,
} from "../emailIntake";

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
  return { apiFetch: vi.fn(), ApiRequestError };
});

const mockedApiFetch = vi.mocked(apiFetch);

beforeEach(() => {
  mockedApiFetch.mockReset();
});

describe("inboundEmailStatusLabel", () => {
  it("labels every status", () => {
    expect(inboundEmailStatusLabel("received")).toBe("Received");
    expect(inboundEmailStatusLabel("processed")).toBe("Processed");
    expect(inboundEmailStatusLabel("matched")).toBe("Matched");
    expect(inboundEmailStatusLabel("unmatched")).toBe("Unmatched");
    expect(inboundEmailStatusLabel("failed")).toBe("Failed");
    expect(inboundEmailStatusLabel("dismissed")).toBe("Dismissed");
  });
});

describe("INBOUND_EMAIL_STATUSES", () => {
  it("covers the six statuses", () => {
    expect(INBOUND_EMAIL_STATUSES.map((s) => s.value)).toEqual([
      "received",
      "processed",
      "matched",
      "unmatched",
      "failed",
      "dismissed",
    ]);
  });
});

describe("address client", () => {
  it("lists, creates, regenerates, and deactivates addresses", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: [{ id: "a-1" }] });
    await expect(listInboundAddresses("org-1")).resolves.toEqual([{ id: "a-1" }]);
    expect(mockedApiFetch).toHaveBeenCalledWith("/email-intake/addresses", {
      orgId: "org-1",
    });

    mockedApiFetch.mockResolvedValueOnce({ data: { id: "a-2" } });
    await createInboundAddress("org-1", "Dispatch inbox");
    expect(mockedApiFetch).toHaveBeenCalledWith("/email-intake/addresses", {
      method: "POST",
      orgId: "org-1",
      body: { label: "Dispatch inbox" },
    });

    mockedApiFetch.mockResolvedValueOnce({ data: { id: "a-1" } });
    await regenerateInboundAddress("org-1", "a-1");
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/email-intake/addresses/a-1/regenerate",
      { method: "POST", orgId: "org-1" },
    );

    mockedApiFetch.mockResolvedValueOnce({ data: { id: "a-1" } });
    await deactivateInboundAddress("org-1", "a-1");
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/email-intake/addresses/a-1/deactivate",
      { method: "POST", orgId: "org-1" },
    );
  });
});

describe("email client", () => {
  it("lists with status and matched filters", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: [], meta: {} });
    await listInboundEmails("org-1", "unmatched", false, 2, 10);
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/email-intake/emails?page=2&per_page=10&status=unmatched&matched=false",
      { orgId: "org-1" },
    );
  });

  it("lists without filters when status is all", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: [], meta: {} });
    await listInboundEmails("org-1", "all");
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/email-intake/emails?page=1&per_page=25",
      { orgId: "org-1" },
    );
  });

  it("gets one email and posts match, unmatch, and dismiss", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: { id: "e-1" } });
    await expect(getInboundEmail("org-1", "e-1")).resolves.toEqual({ id: "e-1" });
    expect(mockedApiFetch).toHaveBeenCalledWith("/email-intake/emails/e-1", {
      orgId: "org-1",
    });

    mockedApiFetch.mockResolvedValueOnce({ data: { id: "e-1" } });
    await matchInboundEmail("org-1", "e-1", "s-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/email-intake/emails/e-1/match", {
      method: "POST",
      orgId: "org-1",
      body: { shipment_id: "s-1" },
    });

    mockedApiFetch.mockResolvedValueOnce({ data: { id: "e-1" } });
    await unmatchInboundEmail("org-1", "e-1", "Wrong load");
    expect(mockedApiFetch).toHaveBeenCalledWith("/email-intake/emails/e-1/unmatch", {
      method: "POST",
      orgId: "org-1",
      body: { reason: "Wrong load" },
    });

    mockedApiFetch.mockResolvedValueOnce({ data: { id: "e-1" } });
    await dismissInboundEmail("org-1", "e-1", "Spam");
    expect(mockedApiFetch).toHaveBeenCalledWith("/email-intake/emails/e-1/dismiss", {
      method: "POST",
      orgId: "org-1",
      body: { reason: "Spam" },
    });
  });
});
