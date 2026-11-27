import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import {
  approveWorkflowRun,
  createWorkflow,
  deleteWorkflow,
  getWorkflowRun,
  listWorkflowRuns,
  listWorkflows,
  rejectWorkflowRun,
  retryWorkflowRun,
  testWorkflow,
  updateWorkflow,
} from "../workflows";
import type { Workflow, WorkflowRun } from "@/lib/types";

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

const mockedApiFetch = vi.mocked(apiFetch);

beforeEach(() => {
  mockedApiFetch.mockReset();
});

const WORKFLOW: Workflow = {
  id: "wf-1",
  name: "High value review",
  description: null,
  trigger: "claim.created",
  trigger_label: "Claim created",
  is_active: true,
  conditions: [{ field: "claim.requested_amount", operator: "greater_than", value: 1000 }],
  steps: [
    { type: "approval", role: "operations_manager", message: "Check the amounts." },
    { type: "note", message: "Approved and continued." },
  ],
  deadline_days_before: null,
  created_by: "user-1",
  updated_by: "user-1",
  created_at: "2026-11-27T00:00:00Z",
  updated_at: "2026-11-27T00:00:00Z",
};

const RUN: WorkflowRun = {
  id: "run-1",
  workflow_id: "wf-1",
  workflow_name: "High value review",
  claim_id: "claim-1",
  claim_reference: "CLM-2026-0001",
  trigger: "claim.created",
  trigger_label: "Claim created",
  status: "waiting_approval",
  status_label: "Waiting for approval",
  actor_user_id: "user-1",
  current_step_index: 0,
  failure_reason: null,
  context: null,
  started_at: "2026-11-27T00:00:00Z",
  finished_at: null,
  created_at: "2026-11-27T00:00:00Z",
  steps: [
    {
      id: "step-1",
      step_index: 0,
      step_type: "approval",
      step_label: "Human approval checkpoint",
      status: "waiting_approval",
      status_label: "Waiting for approval",
      attempts: 1,
      result: { paused: true, role: "operations_manager" },
      error: null,
      started_at: "2026-11-27T00:00:00Z",
      finished_at: null,
    },
    {
      id: "step-2",
      step_index: 1,
      step_type: "note",
      step_label: "Add a review note",
      status: "pending",
      status_label: "Pending",
      attempts: 0,
      result: null,
      error: null,
      started_at: null,
      finished_at: null,
    },
  ],
  approvals: [
    {
      id: "appr-1",
      step_index: 0,
      role: "operations_manager",
      status: "pending",
      decided_by: null,
      note: null,
      decided_at: null,
      created_at: "2026-11-27T00:00:00Z",
    },
  ],
};

describe("workflows client", () => {
  it("lists workflows", async () => {
    mockedApiFetch.mockResolvedValue({ data: [WORKFLOW] });
    await expect(listWorkflows()).resolves.toEqual([WORKFLOW]);
    expect(mockedApiFetch).toHaveBeenCalledWith("/workflows");
  });

  it("creates a workflow with its conditions and steps", async () => {
    mockedApiFetch.mockResolvedValue({ data: WORKFLOW });
    await expect(
      createWorkflow({
        name: WORKFLOW.name,
        trigger: WORKFLOW.trigger,
        is_active: true,
        conditions: WORKFLOW.conditions,
        steps: WORKFLOW.steps,
      }),
    ).resolves.toEqual(WORKFLOW);
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/workflows",
      expect.objectContaining({ method: "POST" }),
    );
  });

  it("updates and deletes a workflow", async () => {
    mockedApiFetch.mockResolvedValue({ data: { ...WORKFLOW, is_active: false } });
    await expect(
      updateWorkflow("wf-1", {
        name: WORKFLOW.name,
        trigger: WORKFLOW.trigger,
        is_active: false,
        conditions: [],
        steps: WORKFLOW.steps,
      }),
    ).resolves.toMatchObject({ is_active: false });
    mockedApiFetch.mockResolvedValue(undefined);
    await expect(deleteWorkflow("wf-1")).resolves.toBeUndefined();
    expect(mockedApiFetch).toHaveBeenCalledWith("/workflows/wf-1", expect.objectContaining({ method: "DELETE" }));
  });

  it("dry-runs a workflow against a claim", async () => {
    mockedApiFetch.mockResolvedValue({
      data: {
        workflow_id: "wf-1",
        claim_id: "claim-1",
        trigger: "claim.created",
        conditions_match: true,
        would_run: true,
        steps: [
          { index: 0, type: "approval", label: "Human approval checkpoint" },
          { index: 1, type: "note", label: "Add a review note" },
        ],
      },
    });
    await expect(testWorkflow("wf-1", "claim-1")).resolves.toMatchObject({
      conditions_match: true,
      would_run: true,
    });
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/workflows/wf-1/test",
      expect.objectContaining({ method: "POST" }),
    );
  });

  it("lists runs with filters and fetches one run", async () => {
    mockedApiFetch.mockResolvedValue({ data: [RUN] });
    await expect(listWorkflowRuns({ workflow_id: "wf-1", status: "waiting_approval" })).resolves.toEqual([RUN]);
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/workflows/runs?workflow_id=wf-1&status=waiting_approval",
    );
    mockedApiFetch.mockResolvedValue({ data: RUN });
    await expect(getWorkflowRun("run-1")).resolves.toEqual(RUN);
  });

  it("approves, rejects, and retries a run", async () => {
    mockedApiFetch.mockResolvedValue({ data: { ...RUN, status: "completed" } });
    await expect(approveWorkflowRun("run-1", "Checked.")).resolves.toMatchObject({ status: "completed" });
    mockedApiFetch.mockResolvedValue({ data: { ...RUN, status: "rejected" } });
    await expect(rejectWorkflowRun("run-1", "Not ready.")).resolves.toMatchObject({ status: "rejected" });
    mockedApiFetch.mockResolvedValue({ data: { ...RUN, status: "running" } });
    await expect(retryWorkflowRun("run-1")).resolves.toMatchObject({ status: "running" });
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/workflows/runs/run-1/approve",
      expect.objectContaining({ method: "POST" }),
    );
  });
});
