import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import {
  SUPPORT_CATEGORIES,
  SUPPORT_PRIORITIES,
  SUPPORT_STATUSES,
  createTicket,
  getTicket,
  listTickets,
  replyToTicket,
  setTicketStatus,
  supportCategoryLabel,
  supportStatusLabel,
} from "../support";

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

describe("supportStatusLabel", () => {
  it("labels every status", () => {
    expect(supportStatusLabel("open")).toBe("Open");
    expect(supportStatusLabel("in_progress")).toBe("In progress");
    expect(supportStatusLabel("waiting_on_customer")).toBe(
      "Waiting on customer",
    );
    expect(supportStatusLabel("resolved")).toBe("Resolved");
    expect(supportStatusLabel("closed")).toBe("Closed");
  });
});

describe("supportCategoryLabel", () => {
  it("labels every category and passes unknown ones through", () => {
    expect(supportCategoryLabel("billing")).toBe("Billing");
    expect(supportCategoryLabel("technical")).toBe("Technical");
    expect(supportCategoryLabel("feature_request")).toBe("Feature request");
    expect(supportCategoryLabel("account")).toBe("Account");
    expect(supportCategoryLabel("other")).toBe("Other");
    // @ts-expect-error an unknown category passes through unchanged
    expect(supportCategoryLabel("mystery")).toBe("mystery");
  });
});

describe("SUPPORT_CATEGORIES / SUPPORT_PRIORITIES / SUPPORT_STATUSES", () => {
  it("covers the five categories, four priorities, and five statuses", () => {
    expect(SUPPORT_CATEGORIES.map((c) => c.value)).toEqual([
      "billing",
      "technical",
      "feature_request",
      "account",
      "other",
    ]);
    expect(SUPPORT_PRIORITIES.map((p) => p.value)).toEqual([
      "low",
      "normal",
      "high",
      "urgent",
    ]);
    expect(SUPPORT_STATUSES.map((s) => s.value)).toEqual([
      "open",
      "in_progress",
      "waiting_on_customer",
      "resolved",
      "closed",
    ]);
  });
});

describe("listTickets", () => {
  it("calls /support/tickets with status, pagination, and unwraps data and meta", async () => {
    const tickets = [
      {
        id: "t-1",
        subject: "Invoice question",
        status: "open",
        category: "billing",
        priority: "normal",
        created_at: "2026-11-26T00:00:00Z",
      },
    ];
    mockedApiFetch.mockResolvedValue({
      data: tickets,
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    });
    await expect(listTickets("org-1", "open", 1)).resolves.toEqual({
      data: tickets,
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    });
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/support/tickets?page=1&per_page=25&status=open",
      { orgId: "org-1" },
    );
  });

  it("omits the status parameter for the all filter", async () => {
    mockedApiFetch.mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
    });
    await listTickets("org-1", "all", 1);
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/support/tickets?page=1&per_page=25",
      { orgId: "org-1" },
    );
  });
});

describe("getTicket", () => {
  it("calls /support/tickets/:id and unwraps data", async () => {
    mockedApiFetch.mockResolvedValue({ data: { id: "t-1" } });
    await expect(getTicket("org-1", "t-1")).resolves.toEqual({ id: "t-1" });
    expect(mockedApiFetch).toHaveBeenCalledWith("/support/tickets/t-1", {
      orgId: "org-1",
    });
  });
});

describe("createTicket", () => {
  it("posts the subject, category, priority, and message", async () => {
    mockedApiFetch.mockResolvedValue({ data: { id: "t-1" } });
    await createTicket("org-1", {
      subject: "Invoice question",
      category: "billing",
      priority: "high",
      message: "Why was I charged twice?",
    });
    expect(mockedApiFetch).toHaveBeenCalledWith("/support/tickets", {
      method: "POST",
      orgId: "org-1",
      body: {
        subject: "Invoice question",
        category: "billing",
        priority: "high",
        message: "Why was I charged twice?",
      },
    });
  });
});

describe("replyToTicket", () => {
  it("posts the reply body and unwraps the message and ticket", async () => {
    const payload = {
      id: "m-1",
      body: "Thanks, that helps.",
      internal: false,
      created_at: "2026-11-26T00:00:00Z",
      ticket: { id: "t-1", status: "in_progress" },
    };
    mockedApiFetch.mockResolvedValue({ data: payload });
    await expect(
      replyToTicket("org-1", "t-1", "Thanks, that helps."),
    ).resolves.toEqual(payload);
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/support/tickets/t-1/messages",
      {
        method: "POST",
        orgId: "org-1",
        body: { body: "Thanks, that helps.", internal: false },
      },
    );
  });

  it("sends internal true for an internal note", async () => {
    mockedApiFetch.mockResolvedValue({
      data: {
        id: "m-1",
        body: "Check the ledger.",
        internal: true,
        created_at: "2026-11-26T00:00:00Z",
        ticket: { id: "t-1" },
      },
    });
    await replyToTicket("org-1", "t-1", "Check the ledger.", true);
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/support/tickets/t-1/messages",
      {
        method: "POST",
        orgId: "org-1",
        body: { body: "Check the ledger.", internal: true },
      },
    );
  });
});

describe("setTicketStatus", () => {
  it("posts the new status to the status endpoint and unwraps the ticket", async () => {
    mockedApiFetch.mockResolvedValue({ data: { id: "t-1", status: "closed" } });
    await expect(setTicketStatus("org-1", "t-1", "closed")).resolves.toEqual({
      id: "t-1",
      status: "closed",
    });
    expect(mockedApiFetch).toHaveBeenCalledWith("/support/tickets/t-1/status", {
      method: "POST",
      orgId: "org-1",
      body: { status: "closed" },
    });
  });
});
