import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type {
  Organization,
  TmsIntegration,
  TmsSyncRun,
} from "@/lib/types";
import TmsDetailPage from "../page";

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
  useParams: () => ({ id: "int-1" }),
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

const RUN: TmsSyncRun = {
  id: "run-1",
  tms_integration_id: "int-1",
  kind: "full",
  status: "completed",
  summary: {
    fetched: 3,
    created: 3,
    updated: 0,
    skipped: 0,
    failed: 0,
    documents_created: 3,
    kinds: {},
  },
  error_message: null,
  started_at: "2026-11-26T19:00:00Z",
  finished_at: "2026-11-26T19:00:01Z",
  created_at: "2026-11-26T19:00:00Z",
  updated_at: "2026-11-26T19:00:01Z",
};

function mockLoad(runs: TmsSyncRun[] = [RUN]) {
  mockedApiFetch.mockImplementation((url: string) => {
    if (url === "/tms/integrations/int-1") {
      return Promise.resolve({ data: INTEGRATION });
    }
    if (url.startsWith("/tms/sync-runs")) {
      return Promise.resolve({ data: runs });
    }
    return Promise.reject(new Error(`Unexpected URL ${url}`));
  });
}

beforeEach(() => {
  mockedApiFetch.mockReset();
  mockedUseAuth.mockReturnValue({ currentOrg: ORG } as ReturnType<typeof useAuth>);
});

describe("TmsDetailPage", () => {
  it("shows the integration, settings, and sync history", async () => {
    mockLoad();
    render(<TmsDetailPage />);

    await waitFor(() => {
      expect(screen.getByRole("heading", { name: "Mock TMS" })).toBeInTheDocument();
      expect(screen.getByText(/3 fetched, 3 created, 0 updated, 0 skipped/)).toBeInTheDocument();
    });

    expect(screen.getByText("Test connection")).toBeInTheDocument();
    expect(screen.getByText("Sync now")).toBeInTheDocument();
    expect(screen.getByText("Sync settings")).toBeInTheDocument();
  });

  it("shows an empty history when nothing has synced", async () => {
    mockLoad([]);
    render(<TmsDetailPage />);

    await waitFor(() => {
      expect(screen.getByText("No sync runs yet")).toBeInTheDocument();
    });
  });

  it("runs a sync and shows the result", async () => {
    mockLoad([]);
    const user = userEvent.setup();
    render(<TmsDetailPage />);

    await waitFor(() => {
      expect(screen.getByText("No sync runs yet")).toBeInTheDocument();
    });

    mockedApiFetch.mockImplementation((url: string, options?: { method?: string }) => {
      if (url === "/tms/integrations/int-1" && !options?.method) {
        return Promise.resolve({ data: INTEGRATION });
      }
      if (url === "/tms/integrations/int-1/sync" && options?.method === "POST") {
        return Promise.resolve({ data: RUN });
      }
      if (url.startsWith("/tms/sync-runs")) {
        return Promise.resolve({ data: [] });
      }
      return Promise.reject(new Error(`Unexpected URL ${url}`));
    });

    await user.click(screen.getByRole("button", { name: "Sync now" }));

    await waitFor(() => {
      expect(screen.getByText("3 loads fetched, 3 created, 0 updated, 0 skipped, 3 documents stored.")).toBeInTheDocument();
    });
  });
});
