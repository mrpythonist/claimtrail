import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type {
  FailedJob,
  OpsHealth,
  Organization,
  QueueStats,
  RecentErrors,
} from "@/lib/types";
import OperationsPage from "../page";

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

const ADMIN_ORG: Organization = {
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

const HEALTH: OpsHealth = {
  status: "degraded",
  checks: {
    database: { status: "ok", latency_ms: 12, detail: null },
    cache: { status: "ok", latency_ms: 3, detail: null },
    queue: { status: "degraded", latency_ms: 250, detail: "Queue worker lagging" },
    storage: { status: "ok", latency_ms: null, detail: null },
  },
  checked_at: "2026-11-18T06:00:00Z",
  version: "1.1.1",
  php_version: "8.3.6",
  laravel_version: "11.0",
  queue_totals: { pending: 2, reserved: 1, delayed: 0, failed: 3 },
  queue_supported: true,
};

const QUEUE: QueueStats = {
  supported: true,
  driver: "database",
  connection: "database",
  queues: [
    {
      queue: "default",
      pending: 2,
      reserved: 1,
      delayed: 0,
      oldest_pending_age_seconds: 195,
    },
    {
      queue: "emails",
      pending: 0,
      reserved: 0,
      delayed: 0,
      oldest_pending_age_seconds: null,
    },
  ],
  totals: { pending: 2, reserved: 1, delayed: 0, failed: 3 },
};

const QUEUE_UNSUPPORTED: QueueStats = {
  supported: false,
  driver: "sync",
  connection: "sync",
  queues: [],
  totals: { pending: 0, reserved: 0, delayed: 0, failed: 0 },
};

const JOBS: FailedJob[] = [
  {
    uuid: "uuid-1",
    connection: "database",
    queue: "default",
    job: "App\\Jobs\\GenerateClaimPackage",
    failed_at: "2026-11-18T05:00:00Z",
    exception:
      "RuntimeException: PDF generation failed after 3 attempts. The storage disk was unreachable at the time of the job run.",
  },
  {
    uuid: "uuid-2",
    connection: "database",
    queue: "emails",
    job: "App\\Jobs\\SendDeadlineReminder",
    failed_at: "2026-11-18T04:30:00Z",
    exception: "Swift_TransportException: Connection to the mail server timed out.",
  },
];

const ERRORS: RecentErrors = {
  entries: [
    {
      datetime: "2026-11-18T05:01:00Z",
      level: "error",
      message: "Failed to generate claim package",
      exception: "RuntimeException: PDF generation failed.",
      route: "/api/v1/claims/1/package",
      method: "POST",
      correlation_id: "corr-1",
    },
  ],
  scanned_lines: 120,
};

let queueFixture: QueueStats = QUEUE;

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

function failedJobsListCalls(): number {
  return mockedApiFetch.mock.calls.filter(([path]) =>
    (path as string).startsWith("/ops/failed-jobs?"),
  ).length;
}

beforeEach(() => {
  queueFixture = QUEUE;
  mockedApiFetch.mockReset();
  mockedApiFetch.mockImplementation(async (path: string, options?: unknown) => {
    if (path === "/ops/health") return { data: HEALTH };
    if (path === "/ops/queue") return { data: queueFixture };
    if (path.startsWith("/ops/failed-jobs?")) {
      return {
        data: JOBS,
        meta: { current_page: 1, last_page: 2, per_page: 15, total: 16 },
      };
    }
    if (path === "/ops/failed-jobs/uuid-1/retry") {
      return { message: "Job re-queued." };
    }
    if (path === "/ops/failed-jobs/uuid-1/forget") {
      return { message: "Job forgotten." };
    }
    if (path === "/ops/failed-jobs/retry-all") {
      return { data: { retried: 2, failed: [] } };
    }
    if (path === "/ops/failed-jobs/flush") {
      const body = (options as { body: { hours: number } }).body;
      expect(body.hours).toBeGreaterThan(0);
      return { data: { deleted: 5 } };
    }
    if (path.startsWith("/ops/errors?")) return { data: ERRORS };
    if (path === "/ops/monitoring") {
      return { data: { latest: null, history: [] } };
    }
    if (path === "/ops/monitoring/check") {
      return {
        data: {
          id: "chk-1",
          status: "ok",
          checks: {
            database: {
              status: "ok",
              latency_ms: 2,
              detail: "Database connection healthy.",
            },
            queue: { status: "ok", latency_ms: null, detail: null },
            storage: { status: "ok", latency_ms: null, detail: null },
            mailer: { status: "ok", latency_ms: null, detail: null },
            backup: { status: "ok", latency_ms: null, detail: null },
          },
          failed_jobs: 0,
          queue_depth: 0,
          checked_at: "2026-11-26T00:00:00Z",
        },
      };
    }

    throw new Error(`Unexpected call: ${path}`);
  });
  mockAuth(ADMIN_ORG);
});

describe("OperationsPage", () => {
  it("renders health, queue stats, failed jobs, and recent errors", async () => {
    render(<OperationsPage />);

    // Health card
    expect(await screen.findByText("System health")).toBeInTheDocument();
    expect(screen.getByText("Queue worker lagging")).toBeInTheDocument();
    expect(screen.getByText("12 ms")).toBeInTheDocument();
    expect(screen.getByText("8.3.6")).toBeInTheDocument();
    expect(screen.getByText("11.0")).toBeInTheDocument();
    expect(screen.getAllByText("Degraded").length).toBeGreaterThanOrEqual(1);

    // Queue stats (the "default" queue also appears in the failed-jobs table)
    expect(screen.getAllByText("default").length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText("3m 15s")).toBeInTheDocument();
    expect(screen.getByText("No pending jobs")).toBeInTheDocument();

    // Failed jobs
    expect(
      screen.getByText("App\\Jobs\\GenerateClaimPackage"),
    ).toBeInTheDocument();
    expect(
      screen.getByText("App\\Jobs\\SendDeadlineReminder"),
    ).toBeInTheDocument();
    expect(screen.getByText("Page 1 of 2 (16 jobs)")).toBeInTheDocument();

    // Recent errors
    expect(
      screen.getByText("Failed to generate claim package"),
    ).toBeInTheDocument();
    expect(screen.getByText("POST /api/v1/claims/1/package")).toBeInTheDocument();
    expect(screen.getByText("Correlation corr-1")).toBeInTheDocument();
  });

  it("expands a failed job's full exception text", async () => {
    const user = userEvent.setup();
    render(<OperationsPage />);
    const viewButtons = await screen.findAllByRole("button", {
      name: "View exception",
    });
    await user.click(viewButtons[0]);
    // The full text now shows both in the row and in the expanded block.
    expect(
      screen.getAllByText(
        "RuntimeException: PDF generation failed after 3 attempts. The storage disk was unreachable at the time of the job run.",
      ),
    ).toHaveLength(2);
    expect(
      screen.getByRole("button", { name: "Hide exception" }),
    ).toBeInTheDocument();
  });

  it("retries a failed job and refreshes the list", async () => {
    const user = userEvent.setup();
    render(<OperationsPage />);
    const retryButtons = await screen.findAllByRole("button", {
      name: "Retry",
    });
    expect(failedJobsListCalls()).toBe(1);

    await user.click(retryButtons[0]);

    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/ops/failed-jobs/uuid-1/retry",
      { method: "POST", orgId: "org-1" },
    );
    await waitFor(() => expect(failedJobsListCalls()).toBe(2));
    expect(await screen.findByText("Job re-queued.")).toBeInTheDocument();
  });

  it("forgets a failed job", async () => {
    const user = userEvent.setup();
    render(<OperationsPage />);
    const forgetButtons = await screen.findAllByRole("button", {
      name: "Forget",
    });

    await user.click(forgetButtons[0]);

    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/ops/failed-jobs/uuid-1/forget",
      { method: "POST", orgId: "org-1" },
    );
    expect(await screen.findByText("Job forgotten.")).toBeInTheDocument();
  });

  it("retries all failed jobs", async () => {
    const user = userEvent.setup();
    render(<OperationsPage />);

    await user.click(await screen.findByRole("button", { name: "Retry all" }));

    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/ops/failed-jobs/retry-all",
      { method: "POST", orgId: "org-1" },
    );
    expect(
      await screen.findByText("Retried 2 failed jobs."),
    ).toBeInTheDocument();
  });

  it("flushes old failed jobs with the hours from the input", async () => {
    const user = userEvent.setup();
    render(<OperationsPage />);
    const hoursInput = await screen.findByLabelText(
      "Flush older than (hours)",
    );
    expect(hoursInput).toHaveValue(168);
    await user.clear(hoursInput);
    await user.type(hoursInput, "24");

    await user.click(screen.getByRole("button", { name: "Flush old" }));

    expect(mockedApiFetch).toHaveBeenCalledWith("/ops/failed-jobs/flush", {
      method: "POST",
      orgId: "org-1",
      body: { hours: 24 },
    });
    expect(
      await screen.findByText(
        "Deleted 5 failed jobs older than 24 hours.",
      ),
    ).toBeInTheDocument();
  });

  it("shows the database-driver note when queue monitoring is unsupported", async () => {
    queueFixture = QUEUE_UNSUPPORTED;
    render(<OperationsPage />);

    expect(
      await screen.findByText(
        /Queue monitoring needs the database queue driver/,
      ),
    ).toBeInTheDocument();
    expect(screen.queryByText("Oldest pending")).not.toBeInTheDocument();
  });

  it("refreshes all sections from the Refresh button", async () => {
    const user = userEvent.setup();
    render(<OperationsPage />);
    await screen.findByText("System health");
    expect(failedJobsListCalls()).toBe(1);

    await user.click(screen.getByRole("button", { name: "Refresh" }));

    await waitFor(() => expect(failedJobsListCalls()).toBe(2));
  });

  it("hides the page from roles without view-operations", async () => {
    mockAuth({ ...ADMIN_ORG, current_user_role: "viewer" });
    render(<OperationsPage />);

    expect(
      await screen.findByText(
        "Operations are not available for your role in this organization.",
      ),
    ).toBeInTheDocument();
    expect(mockedApiFetch).not.toHaveBeenCalled();
  });
  it("shows the production monitoring card and runs a check on demand", async () => {
    const user = userEvent.setup();
    const check = {
      id: "chk-1",
      status: "ok",
      checks: {
        database: {
          status: "ok",
          latency_ms: 2,
          detail: "Database connection healthy.",
        },
        queue: { status: "ok", latency_ms: null, detail: null },
        storage: { status: "ok", latency_ms: null, detail: null },
        mailer: { status: "ok", latency_ms: null, detail: null },
        backup: { status: "ok", latency_ms: null, detail: null },
      },
      failed_jobs: 0,
      queue_depth: 0,
      checked_at: "2026-11-26T00:00:00Z",
    };
    // The card refreshes from GET /ops/monitoring after the run, so
    // the mock only reports the check once the POST has happened.
    let monitoringData: { latest: unknown; history: unknown[] } = {
      latest: null,
      history: [],
    };
    mockedApiFetch.mockImplementation(async (path: string) => {
      if (path === "/ops/health") return { data: HEALTH };
      if (path === "/ops/queue") return { data: queueFixture };
      if (path.startsWith("/ops/failed-jobs?")) {
        return {
          data: JOBS,
          meta: { current_page: 1, last_page: 2, per_page: 15, total: 16 },
        };
      }
      if (path.startsWith("/ops/errors?")) return { data: ERRORS };
      if (path === "/ops/monitoring") return { data: monitoringData };
      if (path === "/ops/monitoring/check") {
        monitoringData = { latest: check, history: [check] };
        return { data: check };
      }
      throw new Error(`Unexpected call: ${path}`);
    });
    render(<OperationsPage />);

    expect(await screen.findByText("Production monitoring")).toBeInTheDocument();
    expect(
      screen.getByText("No check has run yet. Run one now, or wait for the hourly schedule."),
    ).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Run check now" }));

    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith("/ops/monitoring/check", {
        method: "POST",
        orgId: "org-1",
      });
    });
    expect(await screen.findByText("database")).toBeInTheDocument();
    expect(screen.getByText("Database connection healthy.")).toBeInTheDocument();
  });

  it("shows the latest monitoring check with per-check statuses", async () => {
    mockedApiFetch.mockImplementation(async (path: string) => {
      if (path === "/ops/health") return { data: HEALTH };
      if (path === "/ops/queue") return { data: queueFixture };
      if (path.startsWith("/ops/failed-jobs?")) {
        return {
          data: JOBS,
          meta: { current_page: 1, last_page: 2, per_page: 15, total: 16 },
        };
      }
      if (path.startsWith("/ops/errors?")) return { data: ERRORS };
      if (path === "/ops/monitoring") {
        return {
          data: {
            latest: {
              id: "chk-1",
              status: "degraded",
              checks: {
                database: {
                  status: "ok",
                  latency_ms: 2,
                  detail: "Database connection healthy.",
                },
                queue: {
                  status: "degraded",
                  latency_ms: null,
                  detail: "3 jobs pending longer than 15 minutes.",
                },
              },
              failed_jobs: 1,
              queue_depth: 3,
              checked_at: "2026-11-26T00:00:00Z",
            },
            history: [],
          },
        };
      }
      throw new Error(`Unexpected call: ${path}`);
    });
    render(<OperationsPage />);

    expect(await screen.findByText("Production monitoring")).toBeInTheDocument();
    expect(screen.getByText("database")).toBeInTheDocument();
    expect(screen.getByText("queue")).toBeInTheDocument();
    expect(
      screen.getByText("3 jobs pending longer than 15 minutes."),
    ).toBeInTheDocument();
    expect(screen.getAllByText("Degraded").length).toBeGreaterThanOrEqual(2);
  });
});
