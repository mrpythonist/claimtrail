import { apiFetch } from "./api";
import type {
  Workflow,
  WorkflowCondition,
  WorkflowEvaluation,
  WorkflowRun,
  WorkflowStep,
} from "./types";

/**
 * Typed client for the workflow engine endpoints (v2.0.0):
 * workflow configuration, dry-run evaluation, and execution
 * history with approval checkpoints and manual retries.
 *
 * Workflows act only on the organization's own recorded data
 * and stay inside the app: no step sends a claim package to a
 * broker or calls an outside service. A prepared package is a
 * draft stored locally. Creating or changing a workflow needs
 * manage-workflows and the Starter plan or better; the backend
 * refuses the Free plan with a 402. The UI gates on
 * canManageWorkflows and still handles 403 and 402 responses
 * gracefully.
 */

export interface WorkflowInput {
  name: string;
  description?: string | null;
  trigger: string;
  is_active?: boolean;
  conditions?: WorkflowCondition[];
  steps: WorkflowStep[];
  deadline_days_before?: number | null;
}

export const WORKFLOW_TRIGGERS: { value: string; label: string }[] = [
  { value: "claim.created", label: "Claim created" },
  { value: "claim.submitted", label: "Claim submitted" },
  { value: "status.changed", label: "Claim status changed" },
  { value: "deadline.approaching", label: "Deadline approaching" },
  { value: "deadline.overdue", label: "Deadline overdue" },
  { value: "evidence.completed", label: "Evidence became complete" },
  { value: "evidence.incomplete", label: "Evidence became incomplete" },
  { value: "payment.recorded", label: "Payment recorded" },
];

export const WORKFLOW_STEP_TYPES: { value: string; label: string }[] = [
  { value: "evidence_check", label: "Automated evidence check" },
  { value: "prepare_package", label: "Prepare claim package draft" },
  { value: "notify", label: "Send notification" },
  { value: "escalate", label: "Escalate to a role" },
  { value: "approval", label: "Human approval checkpoint" },
  { value: "note", label: "Add a review note" },
  { value: "transition", label: "Change claim status" },
];

export const WORKFLOW_CONDITION_FIELDS = [
  { value: "claim.status", label: "Claim status" },
  { value: "claim.claim_category", label: "Claim category" },
  { value: "claim.currency", label: "Claim currency" },
  { value: "claim.rejection_reason", label: "Rejection reason" },
  { value: "claim.requested_amount", label: "Requested amount" },
  { value: "claim.days_since_created", label: "Days since created" },
  { value: "shipment.broker_or_customer_name", label: "Broker or customer" },
  { value: "shipment.carrier_id", label: "Carrier" },
  { value: "readiness.percentage", label: "Evidence readiness %" },
  { value: "readiness.status", label: "Evidence readiness status" },
  { value: "readiness.missing_count", label: "Missing evidence count" },
  { value: "deadline.status", label: "Deadline status" },
  { value: "deadline.days_until", label: "Days until deadline" },
];

export const WORKFLOW_CONDITION_OPERATORS: { value: string; label: string }[] = [
  { value: "equals", label: "equals" },
  { value: "not_equals", label: "does not equal" },
  { value: "greater_than", label: "greater than" },
  { value: "less_than", label: "less than" },
  { value: "greater_than_or_equal", label: "at least" },
  { value: "less_than_or_equal", label: "at most" },
  { value: "contains", label: "contains" },
  { value: "in", label: "is one of" },
  { value: "not_in", label: "is not one of" },
];

export async function listWorkflows(): Promise<Workflow[]> {
  const res = await apiFetch<{ data: Workflow[] }>("/workflows");
  return res.data;
}

export async function getWorkflow(id: string): Promise<Workflow> {
  const res = await apiFetch<{ data: Workflow }>(`/workflows/${id}`);
  return res.data;
}

export async function createWorkflow(input: WorkflowInput): Promise<Workflow> {
  const res = await apiFetch<{ data: Workflow }>("/workflows", {
    method: "POST",
    body: JSON.stringify(input),
  });
  return res.data;
}

export async function updateWorkflow(id: string, input: WorkflowInput): Promise<Workflow> {
  const res = await apiFetch<{ data: Workflow }>(`/workflows/${id}`, {
    method: "PUT",
    body: JSON.stringify(input),
  });
  return res.data;
}

export async function deleteWorkflow(id: string): Promise<void> {
  await apiFetch(`/workflows/${id}`, { method: "DELETE" });
}

export async function testWorkflow(id: string, claimId: string): Promise<WorkflowEvaluation> {
  const res = await apiFetch<{ data: WorkflowEvaluation }>(`/workflows/${id}/test`, {
    method: "POST",
    body: JSON.stringify({ claim_id: claimId }),
  });
  return res.data;
}

export async function listWorkflowRuns(query?: {
  workflow_id?: string;
  claim_id?: string;
  status?: string;
}): Promise<WorkflowRun[]> {
  const params = new URLSearchParams();
  if (query?.workflow_id) params.set("workflow_id", query.workflow_id);
  if (query?.claim_id) params.set("claim_id", query.claim_id);
  if (query?.status) params.set("status", query.status);
  const suffix = params.toString() ? `?${params.toString()}` : "";
  const res = await apiFetch<{ data: WorkflowRun[] }>(`/workflows/runs${suffix}`);
  return res.data;
}

export async function getWorkflowRun(id: string): Promise<WorkflowRun> {
  const res = await apiFetch<{ data: WorkflowRun }>(`/workflows/runs/${id}`);
  return res.data;
}

export async function retryWorkflowRun(id: string): Promise<WorkflowRun> {
  const res = await apiFetch<{ data: WorkflowRun }>(`/workflows/runs/${id}/retry`, {
    method: "POST",
    body: JSON.stringify({}),
  });
  return res.data;
}

export async function approveWorkflowRun(id: string, note?: string): Promise<WorkflowRun> {
  const res = await apiFetch<{ data: WorkflowRun }>(`/workflows/runs/${id}/approve`, {
    method: "POST",
    body: JSON.stringify({ note: note ?? null }),
  });
  return res.data;
}

export async function rejectWorkflowRun(id: string, note?: string): Promise<WorkflowRun> {
  const res = await apiFetch<{ data: WorkflowRun }>(`/workflows/runs/${id}/reject`, {
    method: "POST",
    body: JSON.stringify({ note: note ?? null }),
  });
  return res.data;
}
