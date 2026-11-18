import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import {
  flushFailedJobs,
  forgetFailedJob,
  getFailedJobs,
  getOpsHealth,
  getQueueStats,
  getRecentErrors,
  retryAllFailedJobs,
  retryFailedJob,
} from "../ops";

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

describe("getOpsHealth", () => {
  it("calls /ops/health with the org id and unwraps data", async () => {
    mockedApiFetch.mockResolvedValue({ data: { status: "ok" } });
    const result = await getOpsHealth("org-1");
    expect(result).toEqual({ status: "ok" });
    expect(mockedApiFetch).toHaveBeenCalledWith("/ops/health", {
      orgId: "org-1",
    });
  });
});

describe("getQueueStats", () => {
  it("calls /ops/queue with the org id and unwraps data", async () => {
    mockedApiFetch.mockResolvedValue({ data: { supported: true } });
    const result = await getQueueStats("org-1");
    expect(result).toEqual({ supported: true });
    expect(mockedApiFetch).toHaveBeenCalledWith("/ops/queue", {
      orgId: "org-1",
    });
  });
});

describe("getFailedJobs", () => {
  it("defaults to page 1, 15 per page", async () => {
    mockedApiFetch.mockResolvedValue({ data: [], meta: {} });
    await getFailedJobs("org-1");
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/ops/failed-jobs?page=1&per_page=15",
      { orgId: "org-1" },
    );
  });

  it("passes the requested page and per-page values", async () => {
    mockedApiFetch.mockResolvedValue({ data: [], meta: {} });
    await getFailedJobs("org-1", 3, 25);
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/ops/failed-jobs?page=3&per_page=25",
      { orgId: "org-1" },
    );
  });
});

describe("retryFailedJob", () => {
  it("POSTs to the retry endpoint for the uuid", async () => {
    mockedApiFetch.mockResolvedValue({ message: "Job retried." });
    const result = await retryFailedJob("org-1", "uuid-1");
    expect(result).toEqual({ message: "Job retried." });
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/ops/failed-jobs/uuid-1/retry",
      { method: "POST", orgId: "org-1" },
    );
  });
});

describe("forgetFailedJob", () => {
  it("POSTs to the forget endpoint for the uuid", async () => {
    mockedApiFetch.mockResolvedValue({ message: "Job forgotten." });
    const result = await forgetFailedJob("org-1", "uuid-1");
    expect(result).toEqual({ message: "Job forgotten." });
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/ops/failed-jobs/uuid-1/forget",
      { method: "POST", orgId: "org-1" },
    );
  });
});

describe("retryAllFailedJobs", () => {
  it("POSTs to retry-all and unwraps the counts", async () => {
    mockedApiFetch.mockResolvedValue({
      data: { retried: 2, failed: ["uuid-9"] },
    });
    const result = await retryAllFailedJobs("org-1");
    expect(result).toEqual({ retried: 2, failed: ["uuid-9"] });
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/ops/failed-jobs/retry-all",
      { method: "POST", orgId: "org-1" },
    );
  });
});

describe("flushFailedJobs", () => {
  it("POSTs the default 168 hours and unwraps the deleted count", async () => {
    mockedApiFetch.mockResolvedValue({ data: { deleted: 4 } });
    const result = await flushFailedJobs("org-1");
    expect(result).toEqual({ deleted: 4 });
    expect(mockedApiFetch).toHaveBeenCalledWith("/ops/failed-jobs/flush", {
      method: "POST",
      orgId: "org-1",
      body: { hours: 168 },
    });
  });

  it("POSTs the requested hours", async () => {
    mockedApiFetch.mockResolvedValue({ data: { deleted: 1 } });
    await flushFailedJobs("org-1", 24);
    expect(mockedApiFetch).toHaveBeenCalledWith("/ops/failed-jobs/flush", {
      method: "POST",
      orgId: "org-1",
      body: { hours: 24 },
    });
  });
});

describe("getRecentErrors", () => {
  it("defaults to a limit of 50 and unwraps data", async () => {
    mockedApiFetch.mockResolvedValue({
      data: { entries: [], scanned_lines: 0 },
    });
    const result = await getRecentErrors("org-1");
    expect(result).toEqual({ entries: [], scanned_lines: 0 });
    expect(mockedApiFetch).toHaveBeenCalledWith("/ops/errors?limit=50", {
      orgId: "org-1",
    });
  });

  it("passes the requested limit", async () => {
    mockedApiFetch.mockResolvedValue({
      data: { entries: [], scanned_lines: 0 },
    });
    await getRecentErrors("org-1", 10);
    expect(mockedApiFetch).toHaveBeenCalledWith("/ops/errors?limit=10", {
      orgId: "org-1",
    });
  });
});
