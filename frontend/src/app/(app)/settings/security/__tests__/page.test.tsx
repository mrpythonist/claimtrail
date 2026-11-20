import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type {
  BackupRun,
  Organization,
  RetentionSettings,
  SecurityCheckResult,
  SecurityEvent,
} from "@/lib/types";
import SecurityPage from "../page";

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

const VIEWER_ORG: Organization = {
  ...ADMIN_ORG,
  current_user_role: "viewer",
};

const CHECK: SecurityCheckResult = {
  overall: "warn",
  checks: [
    {
      id: "app_key",
      label: "APP_KEY is set",
      status: "pass",
      detail: "APP_KEY is set.",
    },
    {
      id: "sanctum_expiration",
      label: "API tokens expire",
      status: "warn",
      detail: "SANCTUM_TOKEN_EXPIRATION is not set.",
    },
  ],
};

const RUNS: BackupRun[] = [
  {
    id: "run-1",
    organization_id: "org-1",
    driver: "sqlite",
    status: "completed",
    size_bytes: 2048,
    checksum: "abc123",
    error_message: null,
    restored_at: null,
    created_at: "2026-11-20T06:00:00Z",
    updated_at: "2026-11-20T06:00:00Z",
  },
];

const RETENTION: RetentionSettings = {
  effective: {
    audit_logs: 365,
    notifications: 90,
    job_executions: 30,
    failed_documents: 30,
    security_events: 180,
  },
  defaults: {
    audit_logs: 365,
    notifications: 90,
    job_executions: 30,
    failed_documents: 30,
    security_events: 180,
  },
  overrides: {},
};

const EVENTS: SecurityEvent[] = [
  {
    id: "evt-1",
    organization_id: "org-1",
    user_id: null,
    type: "login.failed",
    ip_address: "127.0.0.1",
    metadata: { email: "aqib@example.com" },
    created_at: "2026-11-20T05:00:00Z",
  },
];

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
    if (path === "/security/check") return { data: CHECK };
    if (path.startsWith("/backups?")) {
      return {
        data: RUNS,
        meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
      };
    }
    if (path === "/backups") {
      return { data: { ...RUNS[0], id: "run-2" } };
    }
    if (path === "/backups/run-1/verify") {
      return { data: { ok: true, mismatches: [] } };
    }
    if (path === "/retention") return { data: RETENTION };
    if (path.startsWith("/security/events?")) return { data: EVENTS };
    throw new Error(`Unexpected call: ${path}`);
  });
  mockAuth(ADMIN_ORG);
});

describe("SecurityPage", () => {
  it("renders checks, backups, retention, and security events", async () => {
    render(<SecurityPage />);

    expect(
      await screen.findByText("Production-readiness checks"),
    ).toBeInTheDocument();
    expect(screen.getByText("APP_KEY is set")).toBeInTheDocument();
    expect(screen.getByText("SANCTUM_TOKEN_EXPIRATION is not set.")).toBeInTheDocument();
    expect(screen.getAllByText("Warning").length).toBeGreaterThanOrEqual(1);

    expect(screen.getByText("Backups")).toBeInTheDocument();
    expect(screen.getByText("2.0 KB")).toBeInTheDocument();
    expect(screen.getByText("Completed")).toBeInTheDocument();

    expect(screen.getByText("Data retention")).toBeInTheDocument();
    expect(screen.getByLabelText("Audit logs")).toHaveValue(365);

    expect(screen.getAllByText("Security events").length).toBeGreaterThanOrEqual(1);
    expect(
      screen.getByText("Failed login for aqib@example.com"),
    ).toBeInTheDocument();
  });

  it("shows an access-denied alert for viewers", async () => {
    mockAuth(VIEWER_ORG);
    render(<SecurityPage />);

    expect(
      await screen.findByText(
        "Security settings are not available for your role in this organization.",
      ),
    ).toBeInTheDocument();
    expect(mockedApiFetch).not.toHaveBeenCalled();
  });

  it("creates a backup and shows the result", async () => {
    const user = userEvent.setup();
    render(<SecurityPage />);

    await screen.findByText("Backups");
    await user.click(
      screen.getByRole("button", { name: "Create backup now" }),
    );

    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith("/backups", {
        method: "POST",
        orgId: "org-1",
      });
    });
    expect(await screen.findByText(/Backup run-2 created/)).toBeInTheDocument();
  });

  it("verifies a backup and reports the result", async () => {
    const user = userEvent.setup();
    render(<SecurityPage />);

    await screen.findByText("Backups");
    await user.click(screen.getByRole("button", { name: "Verify" }));

    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith("/backups/run-1/verify", {
        method: "POST",
        orgId: "org-1",
      });
    });
    expect(
      await screen.findByText(
        "Backup verified: every checksum matches the manifest.",
      ),
    ).toBeInTheDocument();
  });

  it("saves retention settings", async () => {
    const user = userEvent.setup();
    mockedApiFetch.mockImplementation(async (path: string, options?: unknown) => {
      if (path === "/security/check") return { data: CHECK };
      if (path.startsWith("/backups?")) {
        return {
          data: RUNS,
          meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
        };
      }
      if (path === "/retention" && (options as { method?: string })?.method === "PUT") {
        return {
          data: {
            ...RETENTION,
            effective: { ...RETENTION.effective, audit_logs: 30 },
            overrides: { audit_logs: 30 },
          },
        };
      }
      if (path === "/retention") return { data: RETENTION };
      if (path.startsWith("/security/events?")) return { data: EVENTS };
      throw new Error(`Unexpected call: ${path}`);
    });
    render(<SecurityPage />);

    const input = await screen.findByLabelText("Audit logs");
    await user.clear(input);
    await user.type(input, "30");
    await user.click(
      screen.getByRole("button", { name: "Save retention settings" }),
    );

    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith("/retention", {
        method: "PUT",
        orgId: "org-1",
        body: {
          audit_logs: 30,
          notifications: 90,
          job_executions: 30,
          failed_documents: 30,
          security_events: 180,
        },
      });
    });
    expect(
      await screen.findByText("Retention settings saved."),
    ).toBeInTheDocument();
  });
});
