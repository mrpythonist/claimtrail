import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import {
  createBackup,
  formatBytes,
  getBackups,
  getRetention,
  getSecurityCheck,
  getSecurityEvents,
  retentionKindLabel,
  securityCheckLabel,
  securityCheckTone,
  securityEventSummary,
  updateRetention,
  verifyBackup,
} from "../security";
import type {
  BackupRun,
  RetentionSettings,
  SecurityCheckResult,
  SecurityEvent,
} from "@/lib/types";

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

const mockedApiFetch = vi.mocked(apiFetch);

beforeEach(() => {
  mockedApiFetch.mockReset();
});

describe("getSecurityCheck", () => {
  it("calls /security/check with the org id and unwraps data", async () => {
    const result: SecurityCheckResult = {
      overall: "warn",
      checks: [
        {
          id: "app_key",
          label: "APP_KEY is set",
          status: "pass",
          detail: "APP_KEY is set.",
        },
      ],
    };
    mockedApiFetch.mockResolvedValue({ data: result });
    await expect(getSecurityCheck("org-1")).resolves.toEqual(result);
    expect(mockedApiFetch).toHaveBeenCalledWith("/security/check", {
      orgId: "org-1",
    });
  });
});

describe("getBackups", () => {
  it("calls /backups with paging and unwraps data and meta", async () => {
    const run: BackupRun = {
      id: "run-1",
      organization_id: "org-1",
      driver: "sqlite",
      status: "completed",
      size_bytes: 1234,
      checksum: "abc",
      error_message: null,
      restored_at: null,
      created_at: "2026-11-20T00:00:00Z",
      updated_at: "2026-11-20T00:00:00Z",
    };
    mockedApiFetch.mockResolvedValue({
      data: [run],
      meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
    });
    const result = await getBackups("org-1");
    expect(result.data).toEqual([run]);
    expect(mockedApiFetch).toHaveBeenCalledWith("/backups?page=1&per_page=10", {
      orgId: "org-1",
    });
  });
});

describe("createBackup", () => {
  it("posts to /backups and unwraps the run", async () => {
    mockedApiFetch.mockResolvedValue({ data: { id: "run-2" } });
    const result = await createBackup("org-1");
    expect(result).toEqual({ id: "run-2" });
    expect(mockedApiFetch).toHaveBeenCalledWith("/backups", {
      method: "POST",
      orgId: "org-1",
    });
  });
});

describe("verifyBackup", () => {
  it("posts to /backups/{id}/verify and unwraps the result", async () => {
    mockedApiFetch.mockResolvedValue({
      data: { ok: true, mismatches: [] },
    });
    const result = await verifyBackup("org-1", "run-1");
    expect(result).toEqual({ ok: true, mismatches: [] });
    expect(mockedApiFetch).toHaveBeenCalledWith("/backups/run-1/verify", {
      method: "POST",
      orgId: "org-1",
    });
  });
});

describe("getRetention", () => {
  it("calls /retention with the org id and unwraps data", async () => {
    const settings: RetentionSettings = {
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
    mockedApiFetch.mockResolvedValue({ data: settings });
    await expect(getRetention("org-1")).resolves.toEqual(settings);
    expect(mockedApiFetch).toHaveBeenCalledWith("/retention", {
      orgId: "org-1",
    });
  });
});

describe("updateRetention", () => {
  it("puts the overrides to /retention and unwraps data", async () => {
    mockedApiFetch.mockResolvedValue({ data: { effective: {} } });
    await updateRetention("org-1", { audit_logs: 30 });
    expect(mockedApiFetch).toHaveBeenCalledWith("/retention", {
      method: "PUT",
      orgId: "org-1",
      body: { audit_logs: 30 },
    });
  });
});

describe("getSecurityEvents", () => {
  it("calls /security/events with the limit and unwraps data", async () => {
    const events: SecurityEvent[] = [
      {
        id: "evt-1",
        organization_id: "org-1",
        user_id: null,
        type: "login.failed",
        ip_address: "127.0.0.1",
        metadata: { email: "aqib@example.com" },
        created_at: "2026-11-20T00:00:00Z",
      },
    ];
    mockedApiFetch.mockResolvedValue({ data: events });
    await expect(getSecurityEvents("org-1")).resolves.toEqual(events);
    expect(mockedApiFetch).toHaveBeenCalledWith("/security/events?limit=50", {
      orgId: "org-1",
    });
  });
});

describe("securityCheckTone and securityCheckLabel", () => {
  it("maps pass, warn, and fail", () => {
    expect(securityCheckTone("pass")).toBe("success");
    expect(securityCheckTone("warn")).toBe("warning");
    expect(securityCheckTone("fail")).toBe("danger");
    expect(securityCheckLabel("pass")).toBe("Pass");
    expect(securityCheckLabel("warn")).toBe("Warning");
    expect(securityCheckLabel("fail")).toBe("Fail");
  });
});

describe("retentionKindLabel", () => {
  it("labels every kind", () => {
    expect(retentionKindLabel("audit_logs")).toBe("Audit logs");
    expect(retentionKindLabel("notifications")).toBe("Notifications");
    expect(retentionKindLabel("job_executions")).toBe("Job executions");
    expect(retentionKindLabel("failed_documents")).toBe("Failed documents");
    expect(retentionKindLabel("security_events")).toBe("Security events");
  });
});

describe("formatBytes", () => {
  it("formats bytes, kilobytes, and megabytes", () => {
    expect(formatBytes(null)).toBe("Unknown size");
    expect(formatBytes(512)).toBe("512 B");
    expect(formatBytes(2048)).toBe("2.0 KB");
    expect(formatBytes(3 * 1024 * 1024)).toBe("3.0 MB");
  });
});

describe("securityEventSummary", () => {
  it("summarizes failed logins with the email", () => {
    expect(
      securityEventSummary({
        id: "evt-1",
        organization_id: "org-1",
        user_id: null,
        type: "login.failed",
        ip_address: "127.0.0.1",
        metadata: { email: "aqib@example.com" },
        created_at: "2026-11-20T00:00:00Z",
      }),
    ).toBe("Failed login for aqib@example.com");
  });

  it("summarizes denials with method and path", () => {
    expect(
      securityEventSummary({
        id: "evt-2",
        organization_id: "org-1",
        user_id: 1,
        type: "authorization.denied",
        ip_address: "127.0.0.1",
        metadata: { method: "POST", path: "api/v1/shipments" },
        created_at: "2026-11-20T00:00:00Z",
      }),
    ).toBe("Denied POST api/v1/shipments");
  });

  it("falls back to the raw type for unknown events", () => {
    expect(
      securityEventSummary({
        id: "evt-3",
        organization_id: null,
        user_id: null,
        type: "something.else",
        ip_address: null,
        metadata: null,
        created_at: "2026-11-20T00:00:00Z",
      }),
    ).toBe("something.else");
  });
});
