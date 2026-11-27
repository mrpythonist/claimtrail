"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Input } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { Table, TableCell } from "@/components/ui/Table";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { canManageWorkflows } from "@/lib/permissions";
import type { Organization, WorkflowCondition, WorkflowRun, WorkflowStep } from "@/lib/types";
import {
  WORKFLOW_CONDITION_FIELDS,
  WORKFLOW_CONDITION_OPERATORS,
  WORKFLOW_STEP_TYPES,
  WORKFLOW_TRIGGERS,
  approveWorkflowRun,
  createWorkflow,
  deleteWorkflow,
  listWorkflowRuns,
  listWorkflows,
  rejectWorkflowRun,
  retryWorkflowRun,
  testWorkflow,
  updateWorkflow,
} from "@/lib/workflows";

/**
 * Workflow engine for one organization: configuration, dry-run
 * evaluation, and execution history with approval checkpoints
 * and manual retries.
 *
 * Workflows act only on the organization's own recorded data
 * and stay inside the app: no step sends a claim package to a
 * broker or calls an outside service. A prepared package is a
 * draft stored locally, and the run history records it as one.
 * Creating or changing a workflow needs manage-workflows and
 * the Starter plan or better; the Free plan is refused with a
 * 402. Any member may read workflows and runs.
 */

const STEP_LABELS: Record<string, string> = Object.fromEntries(
  WORKFLOW_STEP_TYPES.map((step) => [step.value, step.label]),
);

const TRIGGER_LABELS: Record<string, string> = Object.fromEntries(
  WORKFLOW_TRIGGERS.map((trigger) => [trigger.value, trigger.label]),
);

function conditionLabel(condition: WorkflowCondition): string {
  const field = WORKFLOW_CONDITION_FIELDS.find((item) => item.value === condition.field)?.label ?? condition.field;
  const operator =
    WORKFLOW_CONDITION_OPERATORS.find((item) => item.value === condition.operator)?.label ?? condition.operator;
  const value = Array.isArray(condition.value) ? condition.value.join(", ") : String(condition.value ?? "");
  return `${field} ${operator} ${value}`;
}

function stepLabel(step: WorkflowStep): string {
  const label = STEP_LABELS[step.type] ?? step.type;
  if (step.type === "approval" || step.type === "escalate") return `${label} (${step.role ?? "no role"})`;
  if (step.type === "transition") return `${label} to ${step.to_status ?? "?"}`;
  if (step.message) return `${label}: ${step.message}`;
  return label;
}

function WorkflowsContent({ org }: { org: Organization }) {
  const [workflows, setWorkflows] = useState<Awaited<ReturnType<typeof listWorkflows>>>([]);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [runs, setRuns] = useState<WorkflowRun[]>([]);
  const [loading, setLoading] = useState(true);
  const [runsLoading, setRunsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [busyRunId, setBusyRunId] = useState<string | null>(null);

  const [name, setName] = useState("");
  const [trigger, setTrigger] = useState("claim.created");
  const [deadlineDays, setDeadlineDays] = useState("");
  const [conditionField, setConditionField] = useState("");
  const [conditionOperator, setConditionOperator] = useState("equals");
  const [conditionValue, setConditionValue] = useState("");
  const [stepType, setStepType] = useState("note");
  const [stepMessage, setStepMessage] = useState("");
  const [stepRole, setStepRole] = useState("operations_manager");
  const [stepStatus, setStepStatus] = useState("under_review");
  const [steps, setSteps] = useState<WorkflowStep[]>([]);
  const [testClaimId, setTestClaimId] = useState("");
  const [testResult, setTestResult] = useState<string | null>(null);

  const isManager = canManageWorkflows(org.current_user_role);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    listWorkflows()
      .then((items) => {
        if (!cancelled) setWorkflows(items);
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setError(err instanceof ApiRequestError ? err.message : "Could not load workflows.");
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    if (selectedId === null) {
      setRuns([]);
      return;
    }
    let cancelled = false;
    setRunsLoading(true);
    listWorkflowRuns({ workflow_id: selectedId })
      .then((items) => {
        if (!cancelled) setRuns(items);
      })
      .catch(() => {
        if (!cancelled) setRuns([]);
      })
      .finally(() => {
        if (!cancelled) setRunsLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [selectedId]);

  const selected = workflows.find((workflow) => workflow.id === selectedId) ?? null;

  function addStep() {
    const step: WorkflowStep = { type: stepType };
    if (stepType === "note" || stepType === "notify") step.message = stepMessage.trim();
    if (stepType === "escalate" || stepType === "approval") {
      step.role = stepRole;
      if (stepMessage.trim()) step.message = stepMessage.trim();
    }
    if (stepType === "transition") step.to_status = stepStatus;
    if ((stepType === "note" || stepType === "notify") && !step.message) {
      setFormError("A note or notify step needs a message.");
      return;
    }
    setFormError(null);
    setSteps((current) => [...current, step]);
    setStepMessage("");
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    setFormError(null);
    if (!name.trim()) {
      setFormError("A workflow needs a name.");
      return;
    }
    if (steps.length === 0) {
      setFormError("A workflow needs at least one step.");
      return;
    }
    const conditions: WorkflowCondition[] = [];
    if (conditionField) {
      const raw = conditionValue.trim();
      const value =
        conditionOperator === "in" || conditionOperator === "not_in"
          ? raw.split(",").map((part) => part.trim()).filter(Boolean)
          : raw === ""
            ? null
            : Number.isNaN(Number(raw))
              ? raw
              : Number(raw);
      conditions.push({ field: conditionField, operator: conditionOperator, value });
    }
    setBusy(true);
    try {
      const created = await createWorkflow({
        name: name.trim(),
        trigger,
        is_active: true,
        conditions,
        steps,
        deadline_days_before: deadlineDays ? Number(deadlineDays) : null,
      });
      setWorkflows((current) => [created, ...current]);
      setSelectedId(created.id);
      setName("");
      setDeadlineDays("");
      setConditionField("");
      setConditionValue("");
      setSteps([]);
    } catch (err) {
      if (err instanceof ApiRequestError) {
        setFormError(err.message);
      } else {
        setFormError("Could not save the workflow.");
      }
    } finally {
      setBusy(false);
    }
  }

  async function toggleActive(workflowId: string, isActive: boolean) {
    const workflow = workflows.find((item) => item.id === workflowId);
    if (!workflow) return;
    setBusy(true);
    try {
      const updated = await updateWorkflow(workflowId, {
        name: workflow.name,
        description: workflow.description,
        trigger: workflow.trigger,
        is_active: isActive,
        conditions: workflow.conditions,
        steps: workflow.steps,
        deadline_days_before: workflow.deadline_days_before,
      });
      setWorkflows((current) => current.map((item) => (item.id === workflowId ? updated : item)));
    } catch (err) {
      setFormError(err instanceof ApiRequestError ? err.message : "Could not update the workflow.");
    } finally {
      setBusy(false);
    }
  }

  async function remove(workflowId: string) {
    setBusy(true);
    try {
      await deleteWorkflow(workflowId);
      setWorkflows((current) => current.filter((item) => item.id !== workflowId));
      if (selectedId === workflowId) setSelectedId(null);
    } catch (err) {
      setFormError(err instanceof ApiRequestError ? err.message : "Could not delete the workflow.");
    } finally {
      setBusy(false);
    }
  }

  async function runTest() {
    if (!selected || !testClaimId.trim()) return;
    setTestResult(null);
    try {
      const evaluation = await testWorkflow(selected.id, testClaimId.trim());
      setTestResult(
        evaluation.conditions_match
          ? `This workflow would run for that claim (${evaluation.steps.length} step${evaluation.steps.length === 1 ? "" : "s"}).`
          : "This workflow would not run for that claim: its conditions do not match.",
      );
    } catch (err) {
      setTestResult(err instanceof ApiRequestError ? err.message : "Could not test the workflow.");
    }
  }

  async function decide(runId: string, action: "approve" | "reject") {
    setBusyRunId(runId);
    try {
      const updated = action === "approve" ? await approveWorkflowRun(runId) : await rejectWorkflowRun(runId);
      setRuns((current) => current.map((run) => (run.id === runId ? updated : run)));
    } catch (err) {
      setFormError(err instanceof ApiRequestError ? err.message : "Could not decide the checkpoint.");
    } finally {
      setBusyRunId(null);
    }
  }

  async function retry(runId: string) {
    setBusyRunId(runId);
    try {
      const updated = await retryWorkflowRun(runId);
      setRuns((current) => current.map((run) => (run.id === runId ? updated : run)));
    } catch (err) {
      setFormError(err instanceof ApiRequestError ? err.message : "Could not retry the run.");
    } finally {
      setBusyRunId(null);
    }
  }

  if (loading) return <Spinner />;

  return (
    <div className="space-y-6">
      {error ? <Alert tone="error">{error}</Alert> : null}
      {formError ? <Alert tone="error">{formError}</Alert> : null}

      {isManager ? (
        <Card>
          <CardHeader
            title="Create a workflow"
            description="Pick a trigger, add an optional condition, then add steps in the order they should run. An approval step pauses the run until a member with the named role approves it."
          />
          <CardBody>
            <form onSubmit={submit} className="space-y-4">
              <Input id="workflow-name" label="Name" value={name} onChange={(event) => setName(event.target.value)} />
              <label className="block text-sm font-medium">
                Trigger
                <select
                  className="mt-1 block w-full rounded border px-2 py-2"
                  value={trigger}
                  onChange={(event) => setTrigger(event.target.value)}
                >
                  {WORKFLOW_TRIGGERS.map((item) => (
                    <option key={item.value} value={item.value}>
                      {item.label}
                    </option>
                  ))}
                </select>
              </label>
              {(trigger === "deadline.approaching" || trigger === "deadline.overdue") && (
                <Input
                  id="deadline-days"
                  label="Days before the deadline (approaching only, 1 to 90; blank means 7)"
                  value={deadlineDays}
                  onChange={(event) => setDeadlineDays(event.target.value)}
                />
              )}
              <div className="grid gap-3 sm:grid-cols-3">
                <label className="block text-sm font-medium">
                  Condition field (optional)
                  <select
                    className="mt-1 block w-full rounded border px-2 py-2"
                    value={conditionField}
                    onChange={(event) => setConditionField(event.target.value)}
                  >
                    <option value="">No condition</option>
                    {WORKFLOW_CONDITION_FIELDS.map((item) => (
                      <option key={item.value} value={item.value}>
                        {item.label}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="block text-sm font-medium">
                  Operator
                  <select
                    className="mt-1 block w-full rounded border px-2 py-2"
                    value={conditionOperator}
                    onChange={(event) => setConditionOperator(event.target.value)}
                  >
                    {WORKFLOW_CONDITION_OPERATORS.map((item) => (
                      <option key={item.value} value={item.value}>
                        {item.label}
                      </option>
                    ))}
                  </select>
                </label>
                <Input id="condition-value" label="Value" value={conditionValue} onChange={(event) => setConditionValue(event.target.value)} />
              </div>
              <div className="grid gap-3 sm:grid-cols-3">
                <label className="block text-sm font-medium">
                  Step type
                  <select
                    className="mt-1 block w-full rounded border px-2 py-2"
                    value={stepType}
                    onChange={(event) => setStepType(event.target.value)}
                  >
                    {WORKFLOW_STEP_TYPES.map((item) => (
                      <option key={item.value} value={item.value}>
                        {item.label}
                      </option>
                    ))}
                  </select>
                </label>
                {(stepType === "note" || stepType === "notify" || stepType === "escalate" || stepType === "approval") && (
                  <Input id="step-message" label="Message" value={stepMessage} onChange={(event) => setStepMessage(event.target.value)} />
                )}
                {(stepType === "escalate" || stepType === "approval") && (
                  <label className="block text-sm font-medium">
                    Role
                    <select
                      className="mt-1 block w-full rounded border px-2 py-2"
                      value={stepRole}
                      onChange={(event) => setStepRole(event.target.value)}
                    >
                      <option value="operations_manager">Operations Manager</option>
                      <option value="admin">Admin</option>
                      <option value="dispatcher">Dispatcher</option>
                    </select>
                  </label>
                )}
                {stepType === "transition" && (
                  <label className="block text-sm font-medium">
                    Move to
                    <select
                      className="mt-1 block w-full rounded border px-2 py-2"
                      value={stepStatus}
                      onChange={(event) => setStepStatus(event.target.value)}
                    >
                      <option value="under_review">Under review</option>
                      <option value="evidence_required">Evidence required</option>
                      <option value="ready_to_submit">Ready to submit</option>
                      <option value="submitted">Submitted</option>
                    </select>
                  </label>
                )}
              </div>
              <div className="flex gap-2">
                <Button type="button" variant="secondary" onClick={addStep}>
                  Add step
                </Button>
                <Button type="submit" disabled={busy}>
                  {busy ? "Saving…" : "Create workflow"}
                </Button>
              </div>
              {steps.length > 0 ? (
                <ul className="list-decimal space-y-1 pl-5 text-sm">
                  {steps.map((step, index) => (
                    <li key={`${step.type}-${index}`}>{stepLabel(step)}</li>
                  ))}
                </ul>
              ) : null}
            </form>
          </CardBody>
        </Card>
      ) : null}

      <Card>
        <CardHeader title="Workflows" description="Select a workflow to see its steps and runs." />
        <CardBody>
          {workflows.length === 0 ? (
            <EmptyState title="No workflows yet" description="Create a workflow to automate a repeated claim routine." />
          ) : (
            <Table headers={["Name", "Trigger", "Steps", "Active", ""]}>
              {workflows.map((workflow) => (
                <tr key={workflow.id}>
                  <TableCell>{workflow.name}</TableCell>
                  <TableCell>{workflow.trigger_label}</TableCell>
                  <TableCell>{workflow.steps.length}</TableCell>
                  <TableCell>{workflow.is_active ? "Yes" : "No"}</TableCell>
                  <TableCell>
                    <div className="flex gap-2">
                      <Button type="button" variant="secondary" onClick={() => setSelectedId(workflow.id)}>
                        View
                      </Button>
                      {isManager ? (
                        <>
                          <Button
                            type="button"
                            variant="secondary"
                            disabled={busy}
                            onClick={() => toggleActive(workflow.id, !workflow.is_active)}
                          >
                            {workflow.is_active ? "Deactivate" : "Activate"}
                          </Button>
                          <Button type="button" variant="secondary" disabled={busy} onClick={() => remove(workflow.id)}>
                            Delete
                          </Button>
                        </>
                      ) : null}
                    </div>
                  </TableCell>
                </tr>
              ))}
            </Table>
          )}
        </CardBody>
      </Card>

      {selected ? (
        <Card>
          <CardHeader
            title={selected.name}
            description={`${selected.trigger_label} · ${selected.steps.length} step${selected.steps.length === 1 ? "" : "s"}${selected.conditions.length > 0 ? ` · Conditions: ${selected.conditions.map(conditionLabel).join(" and ")}` : ""}`}
          />
          <CardBody>
            <div className="space-y-4">
              <div>
                <h3 className="text-sm font-medium">Steps</h3>
                <ul className="list-decimal space-y-1 pl-5 text-sm">
                  {selected.steps.map((step, index) => (
                    <li key={`${step.type}-${index}`}>{stepLabel(step)}</li>
                  ))}
                </ul>
              </div>
              {isManager ? (
                <div className="flex flex-wrap items-end gap-2">
                  <Input
                    id="test-claim-id"
                    label="Test against a claim id"
                    value={testClaimId}
                    onChange={(event) => setTestClaimId(event.target.value)}
                  />
                  <Button type="button" variant="secondary" onClick={runTest}>
                    Test
                  </Button>
                  {testResult ? <p className="text-sm">{testResult}</p> : null}
                </div>
              ) : null}
              <div>
                <h3 className="text-sm font-medium">Runs</h3>
                {runsLoading ? (
                  <Spinner />
                ) : runs.length === 0 ? (
                  <EmptyState title="No runs yet" description="Runs appear here when this workflow fires." />
                ) : (
                  <Table headers={["Claim", "Trigger", "Status", "Steps", ""]}>
                    {runs.map((run) => (
                      <tr key={run.id}>
                        <TableCell>{run.claim_reference ?? run.claim_id}</TableCell>
                        <TableCell>{run.trigger_label}</TableCell>
                        <TableCell>
                          {run.status_label}
                          {run.failure_reason ? ` — ${run.failure_reason}` : ""}
                        </TableCell>
                        <TableCell>
                          {run.steps.map((step) => `${step.step_label}: ${step.status_label}`).join(" · ")}
                        </TableCell>
                        <TableCell>
                          <div className="flex gap-2">
                            {run.status === "waiting_approval" ? (
                              <>
                                <Button
                                  type="button"
                                  variant="secondary"
                                  disabled={busyRunId === run.id}
                                  onClick={() => decide(run.id, "approve")}
                                >
                                  Approve
                                </Button>
                                <Button
                                  type="button"
                                  variant="secondary"
                                  disabled={busyRunId === run.id}
                                  onClick={() => decide(run.id, "reject")}
                                >
                                  Reject
                                </Button>
                              </>
                            ) : null}
                            {run.status === "failed" && isManager ? (
                              <Button
                                type="button"
                                variant="secondary"
                                disabled={busyRunId === run.id}
                                onClick={() => retry(run.id)}
                              >
                                Retry
                              </Button>
                            ) : null}
                          </div>
                        </TableCell>
                      </tr>
                    ))}
                  </Table>
                )}
              </div>
            </div>
          </CardBody>
        </Card>
      ) : null}
    </div>
  );
}

export default function WorkflowsPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Workflows"
        description="Automate the routine parts of a claim: evidence checks, draft packages, escalations, and approvals. Every run is recorded step by step, and nothing a workflow does sends a claim package anywhere."
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Workflows" },
        ]}
      />
      {currentOrg ? (
        <WorkflowsContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to use workflows.</Alert>
      )}
    </div>
  );
}
