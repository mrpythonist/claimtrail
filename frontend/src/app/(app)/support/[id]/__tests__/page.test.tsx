import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { Organization, SupportTicket } from "@/lib/types";
import SupportTicketPage from "../page";

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

vi.mock("@/lib/auth", () => ({ useAuth: vi.fn() }));

vi.mock("next/navigation", () => ({
  useParams: () => ({ id: "t-1" }),
}));

const mockedApiFetch = vi.mocked(apiFetch);
const mockedUseAuth = vi.mocked(useAuth);

const ORG: Organization = {
  id: "org-1",
  name: "Acme Trucking",
  slug: "acme",
  timezone: "UTC",
  settings: null,
  created_at: "2026-11-01T00:00:00Z",
  updated_at: "2026-11-01T00:00:00Z",
  current_user_role: "dispatcher",
  current_user_status: "active",
};

const TICKET: SupportTicket = {
  id: "t-1",
  subject: "Invoice question",
  category: "billing",
  priority: "normal",
  status: "open",
  requester: { id: "u-1", name: "Amina", email: "amina@example.com" },
  resolved_at: null,
  closed_at: null,
  created_at: "2026-11-26T00:00:00Z",
  updated_at: "2026-11-26T00:00:00Z",
  messages: [
    {
      id: "m-1",
      user: { id: "u-1", name: "Amina" },
      body: "Why was I charged twice?",
      internal: false,
      created_at: "2026-11-26T00:00:00Z",
    },
  ],
};

function mockAuth(org: Organization | null) {
  mockedUseAuth.mockReturnValue({
    user: null,
    organizations: org ? [org] : [],
    currentOrg: org,
    loading: false,
    login: vi.fn(),
    register: vi.fn(),
    logout: vi.fn(),
    refreshUser: vi.fn(),
    selectOrganization: vi.fn(),
    createOrganization: vi.fn(),
  });
}

beforeEach(() => {
  mockedApiFetch.mockReset();
  mockedApiFetch.mockImplementation(async (path: string) => {
    if (path === "/support/tickets/t-1") return { data: TICKET };
    throw new Error(`Unexpected call: ${path}`);
  });
  mockAuth(ORG);
});

describe("SupportTicketPage", () => {
  it("shows the ticket subject, status, and conversation", async () => {
    render(<SupportTicketPage />);

    expect(await screen.findByText("Invoice question")).toBeInTheDocument();
    expect(screen.getByText("Open")).toBeInTheDocument();
    expect(screen.getByText("Billing")).toBeInTheDocument();
    expect(screen.getByText("Why was I charged twice?")).toBeInTheDocument();
    expect(screen.getByText("Reply")).toBeInTheDocument();
  });

  it("sends a reply and shows the new message", async () => {
    const user = userEvent.setup();
    const replyMessage = {
      id: "m-2",
      user: { id: "u-2", name: "Omar" },
      body: "Looking into it now.",
      internal: false,
      created_at: "2026-11-26T01:00:00Z",
    };
    mockedApiFetch.mockImplementation(async (path: string) => {
      if (path === "/support/tickets/t-1") return { data: TICKET };
      if (path === "/support/tickets/t-1/messages") {
        return {
          data: {
            id: replyMessage.id,
            body: replyMessage.body,
            internal: false,
            created_at: replyMessage.created_at,
            ticket: {
              ...TICKET,
              status: "in_progress",
              messages: [...TICKET.messages, replyMessage],
            },
          },
        };
      }
      throw new Error(`Unexpected call: ${path}`);
    });
    render(<SupportTicketPage />);

    await screen.findByText("Invoice question");
    await user.type(screen.getByLabelText("Message"), "Looking into it now.");
    await user.click(screen.getByRole("button", { name: "Send reply" }));

    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith(
        "/support/tickets/t-1/messages",
        {
          method: "POST",
          orgId: "org-1",
          body: { body: "Looking into it now.", internal: false },
        },
      );
    });
    expect(await screen.findByText("Looking into it now.")).toBeInTheDocument();
    expect(screen.getByText("In progress")).toBeInTheDocument();
  });
});
