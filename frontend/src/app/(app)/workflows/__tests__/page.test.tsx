import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { useAuth } from "@/lib/auth";
import {
  approveWorkflowRun,
  createWorkflow,
  listWorkflowRuns,
  listWorkflows,
} from "@/lib/workflows";
import type { Organization, Workflow, WorkflowRun } from "@/lib/types";
import WorkflowsPage from "../page";

vi.mock("@/lib/workflows", () => ({
  WORKFLOW_TRIGGERS: [{ value: "claim.created", label: "Claim created" }],
  WORKFLOW_STEP_TYPES: [
    { value: "note", label: "Add a review note" },
    { value: "approval", label: "Human approval checkpoint" },
  ],
  WORKFLOW_CONDITION_FIELDS: [{ value: "claim.status", label: "Claim status" }],
  WORKFLOW_CONDITION_OPERATORS: [{ value: "equals", label: "equals" }],
  listWorkflows: vi.fn(),
  getWorkflow: vi.fn(),
  createWorkflow: vi.fn(),
  updateWorkflow: vi.fn(),
  deleteWorkflow: vi.fn(),
  testWorkflow: vi.fn(),
  listWorkflowRuns: vi.fn(),
  getWorkflowRun: vi.fn(),
  retryWorkflowRun: vi.fn(),
  approveWorkflowRun: vi.fn(),
  rejectWorkflowRun: vi.fn(),
}));

vi.mock("@/lib/auth", () => ({ useAuth: vi.fn() }));

const mockedUseAuth = vi.mocked(useAuth);
const mockedListWorkflows = vi.mocked(listWorkflows);
const mockedCreateWorkflow = vi.mocked(createWorkflow);
const mockedListRuns = vi.mocked(listWorkflowRuns);
const mockedApprove = vi.mocked(approveWorkflowRun);

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

const WORKFLOW: Workflow = {
  id: "wf-1",
  name: "High value review",
  description: null,
  trigger: "claim.created",
  trigger_label: "Claim created",
  is_active: true,
  conditions: [],
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
      result: null,
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

beforeEach(() => {
  vi.clearAllMocks();
  mockedUseAuth.mockReturnValue({
    user: null,
    organizations: [ORG],
    currentOrg: ORG,
    loading: false,
    login: vi.fn(),
    register: vi.fn(),
    logout: vi.fn(),
    refreshUser: vi.fn(),
    selectOrganization: vi.fn(),
    createOrganization: vi.fn(),
  } as unknown as ReturnType<typeof useAuth>);
  mockedListWorkflows.mockResolvedValue([WORKFLOW]);
  mockedListRuns.mockResolvedValue([RUN]);
});

describe("Workflows page", () => {
  it("lists workflows and shows runs for the selected workflow", async () => {
    render(<WorkflowsPage />);

    await waitFor(() => {
      expect(screen.getByText("High value review")).toBeTruthy();
    });

    fireEvent.click(screen.getByText("View"));

    await waitFor(() => {
      expect(screen.getByText("CLM-2026-0001")).toBeTruthy();
      expect(screen.getAllByText(/Waiting for approval/).length).toBeGreaterThan(0);
    });
    expect(mockedListRuns).toHaveBeenCalledWith({ workflow_id: "wf-1" });
  });

  it("shows the create form for a manager and creates a workflow", async () => {
    mockedCreateWorkflow.mockResolvedValue(WORKFLOW);
    render(<WorkflowsPage />);

    await waitFor(() => {
      expect(screen.getByText("High value review")).toBeTruthy();
    });

    fireEvent.change(screen.getByLabelText("Name"), { target: { value: "High value review" } });
    fireEvent.change(screen.getByLabelText("Message"), { target: { value: "Checked by workflow." } });
    fireEvent.click(screen.getByText("Add step"));
    fireEvent.click(screen.getByText("Create workflow"));

    await waitFor(() => {
      expect(mockedCreateWorkflow).toHaveBeenCalledWith(
        expect.objectContaining({ name: "High value review", trigger: "claim.created" }),
      );
    });
  });

  it("approves a waiting run from the runs table", async () => {
    mockedApprove.mockResolvedValue({ ...RUN, status: "completed", status_label: "Completed" });
    render(<WorkflowsPage />);

    await waitFor(() => {
      expect(screen.getByText("High value review")).toBeTruthy();
    });

    fireEvent.click(screen.getByText("View"));

    await waitFor(() => {
      expect(screen.getByText("Approve")).toBeTruthy();
    });

    fireEvent.click(screen.getByText("Approve"));

    await waitFor(() => {
      expect(mockedApprove).toHaveBeenCalledWith("run-1");
    });
  });

  it("hides the create form from a viewer", async () => {
    mockedUseAuth.mockReturnValue({
      user: null,
      organizations: [ORG],
      currentOrg: { ...ORG, current_user_role: "viewer" },
      loading: false,
      login: vi.fn(),
      register: vi.fn(),
      logout: vi.fn(),
      refreshUser: vi.fn(),
      selectOrganization: vi.fn(),
      createOrganization: vi.fn(),
    } as unknown as ReturnType<typeof useAuth>);
    render(<WorkflowsPage />);

    await waitFor(() => {
      expect(screen.getByText("High value review")).toBeTruthy();
    });

    expect(screen.queryByText("Create a workflow")).toBeNull();
  });
});
