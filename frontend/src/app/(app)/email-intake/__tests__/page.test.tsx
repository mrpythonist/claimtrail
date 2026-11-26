import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type {
  InboundEmail,
  InboundEmailAddress,
  Organization,
} from "@/lib/types";
import EmailIntakePage from "../page";

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
  current_user_role: "admin",
  current_user_status: "active",
};

const ADDRESS: InboundEmailAddress = {
  id: "a-1",
  local_part: "acme-abc123",
  address: "acme-abc123@inbound.claimtrail.local",
  label: null,
  active: true,
  created_at: "2026-11-26T00:00:00Z",
  updated_at: "2026-11-26T00:00:00Z",
};

const EMAIL: InboundEmail = {
  id: "e-1",
  from: "broker@example.com",
  to: "acme-abc123@inbound.claimtrail.local",
  subject: "Detention paperwork for PLT-1001",
  text_body: "See PLT-1001 attached.",
  message_id: "<msg-1@example.com>",
  status: "unmatched",
  matched_shipment_id: null,
  matched_shipment: null,
  inbound_email_address_id: "a-1",
  extracted_references: [
    {
      value: "PLT-1001",
      source: "subject",
      matched_shipment_id: null,
      matched_field: null,
    },
  ],
  match_reason: "no shipment reference found",
  match_confidence: "none",
  dismissed_reason: null,
  processing_notes: null,
  created_at: "2026-11-26T00:00:00Z",
  updated_at: "2026-11-26T00:00:00Z",
  attachments: [
    {
      id: "att-1",
      filename: "detention-proof.pdf",
      mime_type: "application/pdf",
      size_bytes: 1234,
      evidence_file_id: null,
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
  mockedUseAuth.mockReset();
  mockAuth(ORG);
  mockedApiFetch.mockImplementation((url: string) => {
    if (url.startsWith("/email-intake/addresses")) {
      return Promise.resolve({ data: [ADDRESS] });
    }
    if (url.startsWith("/email-intake/emails")) {
      return Promise.resolve({
        data: [EMAIL],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
      });
    }
    return Promise.reject(new Error(`Unexpected URL ${url}`));
  });
});

describe("EmailIntakePage", () => {
  it("shows the inbound address and the email list", async () => {
    render(<EmailIntakePage />);

    await waitFor(() => {
      expect(
        screen.getByText("acme-abc123@inbound.claimtrail.local"),
      ).toBeInTheDocument();
    });
    expect(screen.getByText("Detention paperwork for PLT-1001")).toBeInTheDocument();
    // The badge and the status filter option both read Unmatched.
    expect(screen.getAllByText("Unmatched").length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText(/1 attachment/)).toBeInTheDocument();
  });

  it("filters by status", async () => {
    const user = userEvent.setup();
    render(<EmailIntakePage />);

    await waitFor(() => {
      expect(screen.getByText("Detention paperwork for PLT-1001")).toBeInTheDocument();
    });

    await user.selectOptions(screen.getByLabelText("Filter by status"), "matched");

    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith(
        expect.stringContaining("status=matched"),
        expect.objectContaining({ orgId: "org-1" }),
      );
    });
  });

  it("creates a new address from the label form", async () => {
    const user = userEvent.setup();
    const created: InboundEmailAddress = {
      ...ADDRESS,
      id: "a-2",
      local_part: "acme-xyz789",
      address: "acme-xyz789@inbound.claimtrail.local",
      label: "Dispatch inbox",
    };
    mockedApiFetch.mockImplementation((url: string, options?: { method?: string }) => {
      if (url === "/email-intake/addresses" && options?.method === "POST") {
        return Promise.resolve({ data: created });
      }
      if (url.startsWith("/email-intake/addresses")) {
        return Promise.resolve({ data: [ADDRESS] });
      }
      if (url.startsWith("/email-intake/emails")) {
        return Promise.resolve({
          data: [EMAIL],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        });
      }
      return Promise.reject(new Error(`Unexpected URL ${url}`));
    });

    render(<EmailIntakePage />);

    await waitFor(() => {
      expect(
        screen.getByText("acme-abc123@inbound.claimtrail.local"),
      ).toBeInTheDocument();
    });

    await user.type(screen.getByLabelText("Label for a new address"), "Dispatch inbox");
    await user.click(screen.getByRole("button", { name: "New address" }));

    await waitFor(() => {
      expect(
        screen.getByText("acme-xyz789@inbound.claimtrail.local"),
      ).toBeInTheDocument();
    });
  });

  it("shows an empty state when no email arrived yet", async () => {
    mockedApiFetch.mockImplementation((url: string) => {
      if (url.startsWith("/email-intake/addresses")) {
        return Promise.resolve({ data: [ADDRESS] });
      }
      if (url.startsWith("/email-intake/emails")) {
        return Promise.resolve({
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
        });
      }
      return Promise.reject(new Error(`Unexpected URL ${url}`));
    });

    render(<EmailIntakePage />);

    await waitFor(() => {
      expect(screen.getByText("No inbound emails")).toBeInTheDocument();
    });
  });
});
