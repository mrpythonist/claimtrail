import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type {
  Organization,
  TmsIntegration,
  TmsProvider,
} from "@/lib/types";
import TmsPage from "../page";

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

const PROVIDERS: TmsProvider[] = [
  {
    name: "mock",
    label: "Mock TMS",
    live_available: false,
    capabilities: {
      shipments: true,
      status: true,
      appointments: true,
      documents: true,
      webhooks: false,
    },
    credential_fields: [],
    live_note: "Mock provider: syncs run against deterministic dummy data. No live TMS is contacted.",
  },
  {
    name: "trimble-tmw",
    label: "Trimble TMW.Suite",
    live_available: false,
    capabilities: {
      shipments: true,
      status: true,
      appointments: true,
      documents: true,
      webhooks: false,
    },
    credential_fields: ["client_id", "client_secret", "account_id"],
    live_note: "Schema-only: live access needs a TMW customer with an on-prem Cloud Hub Connector.",
  },
];

const INTEGRATION: TmsIntegration = {
  id: "int-1",
  provider: "mock",
  provider_label: "Mock TMS",
  live_available: false,
  name: "Mock TMS",
  status: "active",
  settings: {
    sync_shipments: true,
    sync_status: true,
    sync_appointments: true,
    sync_documents: true,
    auto_sync: false,
    sync_interval_hours: 24,
  },
  has_credentials: false,
  credential_fields_present: [],
  last_sync_at: "2026-11-26T19:00:00Z",
  last_error: null,
  created_at: "2026-11-26T00:00:00Z",
  updated_at: "2026-11-26T00:00:00Z",
};

function mockLoad(integrations: TmsIntegration[] = [INTEGRATION]) {
  mockedApiFetch.mockImplementation((url: string) => {
    if (url.startsWith("/tms/providers")) {
      return Promise.resolve({ data: PROVIDERS });
    }
    if (url.startsWith("/tms/integrations")) {
      return Promise.resolve({ data: integrations });
    }
    return Promise.reject(new Error(`Unexpected URL ${url}`));
  });
}

beforeEach(() => {
  mockedApiFetch.mockReset();
  mockedUseAuth.mockReturnValue({ currentOrg: ORG } as ReturnType<typeof useAuth>);
});

describe("TmsPage", () => {
  it("shows providers and integrations", async () => {
    mockLoad();
    render(<TmsPage />);

    await waitFor(() => {
      expect(screen.getAllByText("Trimble TMW.Suite").length).toBeGreaterThanOrEqual(1);
      expect(screen.getByRole("link", { name: "Mock TMS" })).toBeInTheDocument();
    });

    expect(screen.getAllByText("Not live").length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText("Connect a TMS")).toBeInTheDocument();
  });

  it("shows empty states when nothing exists", async () => {
    mockLoad([]);
    render(<TmsPage />);

    await waitFor(() => {
      expect(screen.getByText("No TMS integrations yet")).toBeInTheDocument();
    });
  });

  it("creates a mock integration from the form", async () => {
    mockLoad([]);
    const user = userEvent.setup();
    render(<TmsPage />);

    await waitFor(() => {
      expect(screen.getByText("No TMS integrations yet")).toBeInTheDocument();
    });

    mockedApiFetch.mockImplementation((url: string, options?: { method?: string }) => {
      if (url.startsWith("/tms/providers")) {
        return Promise.resolve({ data: PROVIDERS });
      }
      if (url === "/tms/integrations" && options?.method === "POST") {
        return Promise.resolve({ data: INTEGRATION });
      }
      if (url.startsWith("/tms/integrations")) {
        return Promise.resolve({ data: [] });
      }
      return Promise.reject(new Error(`Unexpected URL ${url}`));
    });

    await user.type(screen.getByLabelText("Name"), "Mock TMS");
    await user.click(screen.getByRole("button", { name: "Connect" }));

    await waitFor(() => {
      expect(screen.getByRole("link", { name: "Mock TMS" })).toBeInTheDocument();
    });
  });
});
