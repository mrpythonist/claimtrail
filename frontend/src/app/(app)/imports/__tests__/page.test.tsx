import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type {
  ImportRun,
  ImportSchedule,
  Organization,
  SyncRun,
} from "@/lib/types";
import ImportsPage from "../page";

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

const RUN: ImportRun = {
  id: "r-1",
  source: "upload",
  import_mapping_id: null,
  import_schedule_id: null,
  sync_run_id: null,
  filename: "shipments.csv",
  status: "partial",
  total_rows: 3,
  imported_rows: 1,
  skipped_rows: 2,
  duplicate_rows: 1,
  error_rows: 1,
  summary: null,
  error_message: null,
  started_at: "2026-11-26T10:00:00Z",
  finished_at: "2026-11-26T10:00:00Z",
  created_at: "2026-11-26T10:00:00Z",
};

const SCHEDULE: ImportSchedule = {
  id: "s-1",
  name: "Daily carrier file",
  import_mapping_id: null,
  frequency: "daily",
  next_run_at: "2026-11-27T04:30:00Z",
  last_run_at: null,
  last_import_run_id: null,
  active: true,
  created_at: "2026-11-26T00:00:00Z",
  updated_at: "2026-11-26T00:00:00Z",
};

const SYNC: SyncRun = {
  id: "sync-1",
  name: "Nightly broker sync",
  import_mapping_id: null,
  status: "pending",
  import_run_id: null,
  summary: null,
  error_message: null,
  started_at: null,
  finished_at: null,
  created_at: "2026-11-26T00:00:00Z",
  updated_at: "2026-11-26T00:00:00Z",
};

function mockLoad() {
  mockedApiFetch.mockImplementation((url: string) => {
    if (url.startsWith("/imports/runs")) {
      return Promise.resolve({
        data: [RUN],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
      });
    }
    if (url.startsWith("/imports/schedules")) {
      return Promise.resolve({ data: [SCHEDULE] });
    }
    if (url.startsWith("/imports/sync-runs")) {
      return Promise.resolve({ data: [SYNC] });
    }
    return Promise.reject(new Error(`Unexpected URL ${url}`));
  });
}

beforeEach(() => {
  mockedApiFetch.mockReset();
  mockedUseAuth.mockReturnValue({ currentOrg: ORG } as ReturnType<typeof useAuth>);
});

describe("ImportsPage", () => {
  it("shows history, schedules, and sync workflows", async () => {
    mockLoad();
    render(<ImportsPage />);

    await waitFor(() => {
      expect(screen.getByText("shipments.csv")).toBeInTheDocument();
      expect(screen.getByText("Daily carrier file")).toBeInTheDocument();
      expect(screen.getByText("Nightly broker sync")).toBeInTheDocument();
    });

    expect(screen.getAllByText("Partial").length).toBeGreaterThanOrEqual(1);
    expect(screen.getAllByText("Pending").length).toBeGreaterThanOrEqual(1);
  });

  it("shows empty states when nothing exists", async () => {
    mockedApiFetch.mockImplementation((url: string) => {
      if (url.startsWith("/imports/runs")) {
        return Promise.resolve({
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
        });
      }
      return Promise.resolve({ data: [] });
    });
    render(<ImportsPage />);

    await waitFor(() => {
      expect(screen.getByText("No imports yet")).toBeInTheDocument();
      expect(screen.getByText("No scheduled imports")).toBeInTheDocument();
      expect(screen.getByText("No sync workflows")).toBeInTheDocument();
    });
  });

  it("runs a schedule and shows the new run", async () => {
    const user = userEvent.setup();
    mockedApiFetch.mockImplementation((url: string) => {
      if (url === "/imports/schedules/s-1/run") {
        return Promise.resolve({
          data: { ...RUN, id: "r-2", source: "schedule", status: "completed", filename: "shipments.csv" },
        });
      }
      if (url.startsWith("/imports/runs")) {
        return Promise.resolve({
          data: [RUN],
          meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        });
      }
      if (url.startsWith("/imports/schedules")) {
        return Promise.resolve({ data: [SCHEDULE] });
      }
      if (url.startsWith("/imports/sync-runs")) {
        return Promise.resolve({ data: [SYNC] });
      }
      return Promise.reject(new Error(`Unexpected URL ${url}`));
    });
    render(<ImportsPage />);

    await waitFor(() => {
      expect(screen.getByText("Daily carrier file")).toBeInTheDocument();
    });

    await user.click(screen.getAllByRole("button", { name: "Run now" })[0]);

    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith("/imports/schedules/s-1/run", {
        method: "POST",
        orgId: "org-1",
      });
    });
  });
});
