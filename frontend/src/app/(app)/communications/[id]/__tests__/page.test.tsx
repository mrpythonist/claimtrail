import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type {
  CommunicationChannel,
  CommunicationDelivery,
  Organization,
} from "@/lib/types";
import ChannelDetailPage from "../page";

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

vi.mock("@/lib/auth", () => ({ useAuth: vi.fn() }));

vi.mock("next/navigation", () => ({
  useParams: () => ({ id: "ch-1" }),
  useRouter: () => ({ push: vi.fn() }),
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

const CHANNEL: CommunicationChannel = {
  id: "ch-1",
  provider: "mock",
  provider_label: "Mock Channel",
  live_available: false,
  name: "Mock Ops",
  status: "active",
  settings: {
    claim_created: true,
    evidence_received: true,
    driver_link_used: true,
    sync_completed: true,
    delivery_failed: true,
  },
  has_webhook_url: false,
  last_error: null,
  last_tested_at: null,
  created_at: "2026-11-27T00:00:00Z",
  updated_at: "2026-11-27T00:00:00Z",
};

const DELIVERY: CommunicationDelivery = {
  id: "dl-1",
  communication_channel_id: "ch-1",
  event: "test",
  title: "ClaimTrail channel test",
  body: "This is a test message.",
  status: "logged",
  detail: "Logged locally (mock channel): no live delivery.",
  payload: { mock: true },
  attempted_at: "2026-11-27T00:00:00Z",
  created_at: "2026-11-27T00:00:00Z",
  updated_at: "2026-11-27T00:00:00Z",
};

function mockLoad() {
  mockedApiFetch.mockImplementation((url: string, options?: { method?: string }) => {
    if (url === "/comms/channels/ch-1" && !options?.method) {
      return Promise.resolve({ data: CHANNEL });
    }
    if (url === "/comms/channels/ch-1/test") {
      return Promise.resolve({
        data: { ok: true, live_available: false, message: "Recorded as a test delivery.", delivery_id: "dl-1" },
      });
    }
    if (url === "/comms/channels/ch-1" && options?.method === "PUT") {
      return Promise.resolve({ data: { ...CHANNEL, settings: { ...CHANNEL.settings, evidence_received: false } } });
    }
    if (url.startsWith("/comms/deliveries")) {
      return Promise.resolve({ data: [DELIVERY] });
    }
    return Promise.reject(new Error(`Unexpected URL ${url}`));
  });
}

beforeEach(() => {
  mockedApiFetch.mockReset();
  mockedUseAuth.mockReturnValue({ currentOrg: ORG } as ReturnType<typeof useAuth>);
});

describe("ChannelDetailPage", () => {
  it("shows the channel, its events, and its deliveries", async () => {
    mockLoad();
    render(<ChannelDetailPage />);

    await waitFor(() => {
      expect(screen.getByText("ClaimTrail channel test")).toBeInTheDocument();
    });

    expect(screen.getByText("Test channel")).toBeInTheDocument();
    expect(screen.getByText("Save events")).toBeInTheDocument();
  });

  it("tests the channel and shows the result", async () => {
    mockLoad();
    const user = userEvent.setup();
    render(<ChannelDetailPage />);

    await waitFor(() => {
      expect(screen.getByText("Test channel")).toBeInTheDocument();
    });

    await user.click(screen.getByRole("button", { name: "Test channel" }));

    await waitFor(() => {
      expect(screen.getByText("Recorded as a test delivery.")).toBeInTheDocument();
    });
    expect(mockedApiFetch).toHaveBeenCalledWith("/comms/channels/ch-1/test", {
      method: "POST",
      orgId: "org-1",
    });
  });

  it("saves the chosen events", async () => {
    mockLoad();
    const user = userEvent.setup();
    render(<ChannelDetailPage />);

    await waitFor(() => {
      expect(screen.getByText("Save events")).toBeInTheDocument();
    });

    await user.click(screen.getByLabelText("Evidence received"));
    await user.click(screen.getByRole("button", { name: "Save events" }));

    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith(
        "/comms/channels/ch-1",
        expect.objectContaining({
          method: "PUT",
          body: expect.objectContaining({
            settings: expect.objectContaining({
              evidence_received: false,
            }),
          }),
        }),
      );
    });
  });
});
