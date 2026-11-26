import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { Organization, SupportTicket } from "@/lib/types";
import SupportPage from "../page";

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
    if (path.startsWith("/support/tickets?")) {
      return {
        data: [TICKET],
        meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
      };
    }
    throw new Error(`Unexpected call: ${path}`);
  });
  mockAuth(ORG);
});

describe("SupportPage", () => {
  it("lists support tickets with their status and category", async () => {
    render(<SupportPage />);

    expect(await screen.findByText("Invoice question")).toBeInTheDocument();
    expect(screen.getByText("Open", { selector: "span" })).toBeInTheDocument();
    expect(screen.getByText("Billing")).toBeInTheDocument();
    expect(screen.getByText(/Amina/)).toBeInTheDocument();
  });

  it("creates a ticket and shows it in the list", async () => {
    const user = userEvent.setup();
    mockedApiFetch.mockImplementation(async (path: string) => {
      if (path.startsWith("/support/tickets?")) {
        return {
          data: [TICKET],
          meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 },
        };
      }
      if (path === "/support/tickets") {
        return { data: { ...TICKET, id: "t-2", subject: "Login problem" } };
      }
      throw new Error(`Unexpected call: ${path}`);
    });
    render(<SupportPage />);

    await screen.findByText("Invoice question");
    await user.click(screen.getByRole("button", { name: "New ticket" }));

    expect(
      await screen.findByText("Open a support ticket"),
    ).toBeInTheDocument();
    await user.type(screen.getByLabelText("Subject"), "Login problem");
    await user.type(
      screen.getByLabelText("Message"),
      "I cannot sign in after the password reset.",
    );
    await user.click(screen.getByRole("button", { name: "Create ticket" }));

    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith("/support/tickets", {
        method: "POST",
        orgId: "org-1",
        body: {
          subject: "Login problem",
          category: "technical",
          priority: "normal",
          message: "I cannot sign in after the password reset.",
        },
      });
    });
    expect(await screen.findByText("Login problem")).toBeInTheDocument();
  });
});
