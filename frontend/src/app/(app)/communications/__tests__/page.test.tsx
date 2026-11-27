import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type {
  CommunicationChannel,
  CommunicationDelivery,
  CommunicationProvider,
  DriverEvidenceLink,
  Organization,
} from "@/lib/types";
import CommunicationsPage from "../page";

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

const PROVIDERS: CommunicationProvider[] = [
  {
    name: "mock",
    label: "Mock Channel",
    live_available: false,
    webhook_url_required: false,
    webhook_url_hint: "No webhook URL needed. Deliveries are recorded locally.",
  },
  {
    name: "slack",
    label: "Slack",
    live_available: false,
    webhook_url_required: true,
    webhook_url_hint: "An incoming webhook URL from Slack, starting with https://hooks.slack.com/services/.",
  },
];

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

const LINK: DriverEvidenceLink = {
  id: "lk-1",
  shipment_id: "sh-1",
  claim_id: null,
  shipment_reference: "REF-1",
  claim_reference: null,
  driver_name: "Sam Driver",
  driver_email: "sam@example.com",
  state: "usable",
  expires_at: "2026-11-30T00:00:00Z",
  max_uses: 1,
  used_count: 0,
  uses_left: 1,
  revoked_at: null,
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

function mockLoad(
  channels: CommunicationChannel[] = [CHANNEL],
  links: DriverEvidenceLink[] = [LINK],
  deliveries: CommunicationDelivery[] = [DELIVERY],
) {
  mockedApiFetch.mockImplementation((url: string, options?: { method?: string }) => {
    if (url.startsWith("/comms/providers")) {
      return Promise.resolve({ data: PROVIDERS });
    }
    if (url.startsWith("/comms/channels") && options?.method === "POST") {
      return Promise.resolve({ data: CHANNEL });
    }
    if (url.startsWith("/comms/channels")) {
      return Promise.resolve({ data: channels });
    }
    if (url.startsWith("/comms/driver-links") && options?.method === "POST") {
      return Promise.resolve({
        data: { ...LINK, token: "tok-1", submit_url: "http://localhost:3000/driver-evidence/tok-1" },
      });
    }
    if (url.startsWith("/comms/driver-links")) {
      return Promise.resolve({ data: links });
    }
    if (url.startsWith("/comms/deliveries")) {
      return Promise.resolve({ data: deliveries });
    }
    return Promise.reject(new Error(`Unexpected URL ${url}`));
  });
}

beforeEach(() => {
  mockedApiFetch.mockReset();
  mockedUseAuth.mockReturnValue({ currentOrg: ORG } as ReturnType<typeof useAuth>);
});

describe("CommunicationsPage", () => {
  it("shows providers, channels, links, and deliveries", async () => {
    mockLoad();
    render(<CommunicationsPage />);

    await waitFor(() => {
      expect(screen.getByRole("link", { name: "Mock Ops" })).toBeInTheDocument();
      expect(screen.getByText("Sam Driver")).toBeInTheDocument();
    });

    expect(screen.getByText("Slack", { selector: "span" })).toBeInTheDocument();
    expect(screen.getAllByText("Not live").length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText("Connect a channel")).toBeInTheDocument();
    expect(screen.getByText("Create a driver evidence link")).toBeInTheDocument();
  });

  it("shows empty states when nothing exists", async () => {
    mockLoad([], [], []);
    render(<CommunicationsPage />);

    await waitFor(() => {
      expect(screen.getByText("No channels yet")).toBeInTheDocument();
      expect(screen.getByText("No driver links yet")).toBeInTheDocument();
      expect(screen.getByText("No deliveries yet")).toBeInTheDocument();
    });
  });

  it("creates a mock channel from the form", async () => {
    mockLoad([], [], []);
    const user = userEvent.setup();
    render(<CommunicationsPage />);

    await waitFor(() => {
      expect(screen.getByText("Connect a channel")).toBeInTheDocument();
    });

    await user.type(screen.getByLabelText("Name"), "Mock Ops");
    await user.click(screen.getByRole("button", { name: "Connect" }));

    await waitFor(() => {
      expect(screen.getByRole("link", { name: "Mock Ops" })).toBeInTheDocument();
    });
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/comms/channels",
      expect.objectContaining({ method: "POST" }),
    );
  });

  it("creates a driver link and shows the token once", async () => {
    mockLoad([], [], []);
    const user = userEvent.setup();
    render(<CommunicationsPage />);

    await waitFor(() => {
      expect(screen.getByText("Create a driver evidence link")).toBeInTheDocument();
    });

    await user.type(screen.getByLabelText("Shipment ID"), "sh-1");
    await user.type(screen.getByLabelText("Driver email"), "sam@example.com");
    await user.click(screen.getByRole("button", { name: "Create link" }));

    await waitFor(() => {
      expect(screen.getByText(/shown once/)).toBeInTheDocument();
      expect(screen.getByText("http://localhost:3000/driver-evidence/tok-1")).toBeInTheDocument();
    });
  });

  it("refuses a driver link with no shipment or claim", async () => {
    mockLoad([], [], []);
    const user = userEvent.setup();
    render(<CommunicationsPage />);

    await waitFor(() => {
      expect(screen.getByText("Create a driver evidence link")).toBeInTheDocument();
    });

    await user.click(screen.getByRole("button", { name: "Create link" }));

    await waitFor(() => {
      expect(screen.getByText("A driver link needs a shipment or a claim.")).toBeInTheDocument();
    });
  });
});
