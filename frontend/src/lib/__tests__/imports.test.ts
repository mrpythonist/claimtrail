import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import {
  IMPORT_FREQUENCIES,
  IMPORT_ROW_STATUSES,
  IMPORT_RUN_SOURCES,
  IMPORT_RUN_STATUSES,
  SYNC_RUN_STATUSES,
  correctImportRow,
  createImportMapping,
  deleteImportMapping,
  getImportRun,
  importFrequencyLabel,
  importRowStatusLabel,
  importRunSourceLabel,
  importRunStatusLabel,
  listImportMappings,
  listImportRuns,
  listImportSchedules,
  listSyncRuns,
  runImportSchedule,
  runSyncRun,
  syncRunStatusLabel,
} from "../imports";

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

describe("status labels", () => {
  it("labels every run status", () => {
    expect(importRunStatusLabel("pending")).toBe("Pending");
    expect(importRunStatusLabel("running")).toBe("Running");
    expect(importRunStatusLabel("completed")).toBe("Completed");
    expect(importRunStatusLabel("partial")).toBe("Partial");
    expect(importRunStatusLabel("failed")).toBe("Failed");
  });

  it("labels every source", () => {
    expect(importRunSourceLabel("upload")).toBe("Upload");
    expect(importRunSourceLabel("schedule")).toBe("Scheduled");
    expect(importRunSourceLabel("sync")).toBe("Sync");
  });

  it("labels every row status", () => {
    expect(importRowStatusLabel("imported")).toBe("Imported");
    expect(importRowStatusLabel("skipped")).toBe("Skipped");
    expect(importRowStatusLabel("duplicate")).toBe("Duplicate");
    expect(importRowStatusLabel("failed")).toBe("Failed");
    expect(importRowStatusLabel("corrected")).toBe("Corrected");
  });

  it("labels every sync status and frequency", () => {
    expect(syncRunStatusLabel("pending")).toBe("Pending");
    expect(syncRunStatusLabel("running")).toBe("Running");
    expect(syncRunStatusLabel("completed")).toBe("Completed");
    expect(syncRunStatusLabel("failed")).toBe("Failed");
    expect(importFrequencyLabel("daily")).toBe("Daily");
    expect(importFrequencyLabel("weekly")).toBe("Weekly");
    expect(importFrequencyLabel("manual")).toBe("Manual");
  });
});

describe("status lists", () => {
  it("covers the five run statuses, three sources, five row statuses, four sync statuses, and three frequencies", () => {
    expect(IMPORT_RUN_STATUSES.map((s) => s.value)).toEqual([
      "pending",
      "running",
      "completed",
      "partial",
      "failed",
    ]);
    expect(IMPORT_RUN_SOURCES.map((s) => s.value)).toEqual(["upload", "schedule", "sync"]);
    expect(IMPORT_ROW_STATUSES.map((s) => s.value)).toEqual([
      "imported",
      "skipped",
      "duplicate",
      "failed",
      "corrected",
    ]);
    expect(SYNC_RUN_STATUSES.map((s) => s.value)).toEqual([
      "pending",
      "running",
      "completed",
      "failed",
    ]);
    expect(IMPORT_FREQUENCIES.map((f) => f.value)).toEqual(["daily", "weekly", "manual"]);
  });
});

describe("mapping client", () => {
  it("lists mappings for the organization", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: [] });
    await expect(listImportMappings("org-1")).resolves.toEqual([]);
    expect(mockedApiFetch).toHaveBeenCalledWith("/imports/mappings", { orgId: "org-1" });
  });

  it("creates a mapping", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: { id: "m-1" } });
    await createImportMapping("org-1", {
      name: "Carrier export",
      mapping: { "Load ID": "internal_reference" },
      is_default: true,
    });
    expect(mockedApiFetch).toHaveBeenCalledWith("/imports/mappings", {
      method: "POST",
      orgId: "org-1",
      body: {
        name: "Carrier export",
        mapping: { "Load ID": "internal_reference" },
        is_default: true,
      },
    });
  });

  it("deletes a mapping", async () => {
    mockedApiFetch.mockResolvedValueOnce(undefined);
    await deleteImportMapping("org-1", "m-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/imports/mappings/m-1", {
      method: "DELETE",
      orgId: "org-1",
    });
  });
});

describe("run client", () => {
  it("lists runs with status and source filters", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } });
    await listImportRuns("org-1", { status: "partial", source: "upload", page: 2 });
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/imports/runs?status=partial&source=upload&page=2",
      { orgId: "org-1" },
    );
  });

  it("lists runs without filters", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 } });
    await listImportRuns("org-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/imports/runs", { orgId: "org-1" });
  });

  it("gets one run", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: { id: "r-1" } });
    await getImportRun("org-1", "r-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/imports/runs/r-1", { orgId: "org-1" });
  });

  it("corrects a row with the new field values", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: { id: "row-1", status: "corrected" } });
    await correctImportRow("org-1", "r-1", "row-1", { internal_reference: "CT-FIXED-1" });
    expect(mockedApiFetch).toHaveBeenCalledWith("/imports/runs/r-1/rows/row-1/correct", {
      method: "POST",
      orgId: "org-1",
      body: { data: { internal_reference: "CT-FIXED-1" } },
    });
  });
});

describe("schedule and sync clients", () => {
  it("lists schedules and sync runs", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: [] });
    await listImportSchedules("org-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/imports/schedules", { orgId: "org-1" });

    mockedApiFetch.mockResolvedValueOnce({ data: [] });
    await listSyncRuns("org-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/imports/sync-runs", { orgId: "org-1" });
  });

  it("runs a schedule and a sync workflow", async () => {
    mockedApiFetch.mockResolvedValueOnce({ data: { id: "r-1" } });
    await runImportSchedule("org-1", "s-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/imports/schedules/s-1/run", {
      method: "POST",
      orgId: "org-1",
    });

    mockedApiFetch.mockResolvedValueOnce({ data: { id: "sync-1" } });
    await runSyncRun("org-1", "sync-1");
    expect(mockedApiFetch).toHaveBeenCalledWith("/imports/sync-runs/sync-1/run", {
      method: "POST",
      orgId: "org-1",
    });
  });
});
