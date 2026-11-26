import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { InboundEmail, Organization, Shipment } from "@/lib/types";
import EmailIntakeDetailPage from "../page";

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
  useParams: () => ({ id: "e-1" }),
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
  current_user_role: "admin",
  current_user_status: "active",
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

const SHIPMENT = {
  id: "s-1",
  internal_reference: "PLT-1001",
  external_load_reference: "EXT-7788",
  broker_or_customer_name: "Test Broker Co.",
} as Shipment;

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
});

describe("EmailIntakeDetailPage", () => {
  it("shows the message, references, and attachments", async () => {
    mockedApiFetch.mockImplementation((url: string) => {
      if (url === "/email-intake/emails/e-1") {
        return Promise.resolve({ data: EMAIL });
      }
      return Promise.reject(new Error(`Unexpected URL ${url}`));
    });

    render(<EmailIntakeDetailPage />);

    await waitFor(() => {
      expect(screen.getByText("Detention paperwork for PLT-1001")).toBeInTheDocument();
    });
    expect(screen.getByText("broker@example.com")).toBeInTheDocument();
    expect(screen.getByText("See PLT-1001 attached.")).toBeInTheDocument();
    expect(screen.getByText("PLT-1001")).toBeInTheDocument();
    expect(screen.getByText("detention-proof.pdf")).toBeInTheDocument();
    expect(screen.getByText("Match to a shipment")).toBeInTheDocument();
  });

  it("searches shipments and matches the email", async () => {
    const user = userEvent.setup();
    const matched: InboundEmail = {
      ...EMAIL,
      status: "matched",
      matched_shipment_id: "s-1",
      matched_shipment: {
        id: "s-1",
        internal_reference: "PLT-1001",
        external_load_reference: "EXT-7788",
        broker_or_customer_name: "Test Broker Co.",
      },
      attachments: [
        { ...EMAIL.attachments[0], evidence_file_id: "ev-1" },
      ],
    };
    mockedApiFetch.mockImplementation(
      (url: string, options?: { method?: string }) => {
        if (url === "/email-intake/emails/e-1") {
          return Promise.resolve({ data: EMAIL });
        }
        if (url.startsWith("/shipments?")) {
          return Promise.resolve({
            data: [SHIPMENT],
            meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
          });
        }
        if (url === "/email-intake/emails/e-1/match" && options?.method === "POST") {
          return Promise.resolve({ data: matched });
        }
        return Promise.reject(new Error(`Unexpected URL ${url}`));
      },
    );

    render(<EmailIntakeDetailPage />);

    await waitFor(() => {
      expect(screen.getByText("Detention paperwork for PLT-1001")).toBeInTheDocument();
    });

    await user.type(
      screen.getByLabelText("Search shipments by reference"),
      "PLT-1001",
    );
    await user.click(screen.getByRole("button", { name: "Search" }));

    await waitFor(() => {
      expect(screen.getByText(/Test Broker Co\./)).toBeInTheDocument();
    });

    await user.click(screen.getByRole("button", { name: "Match to this shipment" }));

    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith(
        "/email-intake/emails/e-1/match",
        expect.objectContaining({
          method: "POST",
          body: { shipment_id: "s-1" },
        }),
      );
    });
    await waitFor(() => {
      expect(screen.getByText("Matched shipment")).toBeInTheDocument();
    });
  });

  it("dismisses the email with a reason", async () => {
    const user = userEvent.setup();
    const dismissed: InboundEmail = {
      ...EMAIL,
      status: "dismissed",
      dismissed_reason: "Not shipment paperwork",
    };
    mockedApiFetch.mockImplementation(
      (url: string, options?: { method?: string }) => {
        if (url === "/email-intake/emails/e-1") {
          return Promise.resolve({ data: EMAIL });
        }
        if (url === "/email-intake/emails/e-1/dismiss" && options?.method === "POST") {
          return Promise.resolve({ data: dismissed });
        }
        return Promise.reject(new Error(`Unexpected URL ${url}`));
      },
    );

    render(<EmailIntakeDetailPage />);

    await waitFor(() => {
      expect(screen.getByText("Detention paperwork for PLT-1001")).toBeInTheDocument();
    });

    await user.click(screen.getByRole("button", { name: "Dismiss this email" }));
    await user.type(
      screen.getByLabelText("Reason"),
      "Not shipment paperwork",
    );
    await user.click(screen.getByRole("button", { name: "Dismiss email" }));

    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith(
        "/email-intake/emails/e-1/dismiss",
        expect.objectContaining({
          method: "POST",
          body: { reason: "Not shipment paperwork" },
        }),
      );
    });
    await waitFor(() => {
      expect(screen.getByText("Not shipment paperwork")).toBeInTheDocument();
    });
  });
});
