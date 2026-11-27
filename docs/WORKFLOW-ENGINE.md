# Workflow Engine (v2.0.0)

Automate the routine parts of a claim: evidence checks, draft
claim packages, escalations, and human approvals. A workflow
belongs to one organization, fires on a trigger, checks its
conditions against the claim as recorded, and runs its steps
in order. Every run is recorded step by step, with approvals
and failures, and can be listed per workflow, per claim, and
per organization.

## What a workflow is

- **Trigger.** The event that starts a run: `claim.created`,
  `claim.submitted`, `status.changed`, `deadline.approaching`,
  `deadline.overdue`, `evidence.completed`,
  `evidence.incomplete`, or `payment.recorded`. Deadline
  triggers are evaluated by the daily `workflows:run-due`
  command (06:30); the rest fire synchronously from the
  claim, payment, and evidence endpoints.
- **Conditions.** Zero or more rules, all of which must match
  for the workflow to run. A condition names a field, an
  operator, and a value. The thirteen fields are the claim
  (`claim.status`, `claim.claim_category`, `claim.currency`,
  `claim.rejection_reason`, `claim.requested_amount`,
  `claim.days_since_created`), the shipment
  (`shipment.broker_or_customer_name`, `shipment.carrier_id`),
  the computed evidence readiness (`readiness.percentage`,
  `readiness.status`, `readiness.missing_count`), and the
  active deadline (`deadline.status`, `deadline.days_until`,
  negative when overdue, null when the claim has no
  deadline). Operators: `equals`, `not_equals`, `in`,
  `not_in`, `greater_than`, `less_than`,
  `greater_than_or_equal`, `less_than_or_equal`,
  `contains`. A workflow with no conditions runs on every
  matching trigger. An unknown field is rejected with 422.
- **Steps.** Ordered actions. Each step records its own
  outcome in the run history, so a run shows exactly which
  steps ran and what each one did.

## Step types

| Step | What it does |
| ---- | ------------ |
| `evidence_check` | Runs the existing readiness computation for the claim and records the percentage and any missing categories. It reads recorded evidence only; it never uploads, scans, or fetches anything. |
| `prepare_package` | Assembles a draft claim package from the claim, shipment, events, and evidence as recorded, and stores it under `workflow-drafts/{organization}/{claim}/`. The draft is labelled a draft. Nothing is sent anywhere. |
| `notify` | Sends an in-app notification of type `workflow.notification` to every member of the organization. |
| `escalate` | Reassigns the claim to the named role (operations manager, admin, or dispatcher) and sends an in-app notification of type `workflow.escalated`. |
| `approval` | Pauses the run at a human checkpoint. A member holding the named role, or the organization owner, approves or rejects it; the run then continues or stops. Nothing after the checkpoint runs until the decision is made. |
| `note` | Records a workflow note on the claim, visible in the claim activity. |
| `transition` | Moves the claim through the existing claim state machine (`ClaimTransitionService`), so the same guards, audit entries, and side effects apply as a manual status change. Allowed targets: `under_review`, `evidence_required`, `ready_to_submit`, `submitted`. |

## Human approval checkpoints

A run pauses at an `approval` step and waits. The approval
records who decided, when, and any note. Approving resumes
the run from the next step; rejecting stops the run with
status `rejected` and no further step runs. An approver must
hold the named role or be the organization owner; anyone
else is refused with 422. A run waiting on approval cannot
be retried until it is decided.

## Execution history

Every run records its workflow, claim, trigger, status,
started and finished times, and one row per step with its
attempts, result, and error. Runs are listable per workflow and per claim through
`GET /workflows/runs` (`workflow_id`, `claim_id`, and
`status` filters). A run's steps and approvals are
returned with it, so the full story of a run is one read.

Run statuses: `pending`, `running`, `waiting_approval`,
`completed`, `failed`, `rejected`.

## Failure handling and retries

A step that throws a transient error (a locked row, a
temporary database error) is retried by the queued job with
bounded backoff: 3 tries, delays of 30, 120, then 600
seconds, inside a 24-hour window. A permanent failure (a
validation error, a missing record) fails the step at once.
When the job's tries are exhausted the run is marked
`failed` with the reason recorded, and a
`workflow.run_failed` notification is sent. A failed run can
be retried manually (`POST /workflows/runs/{run}/retry`),
which resumes from the failed step; a run is never retried
forever and never silently dropped.

## Permissions and plans

- Reading workflows and runs needs `view-workflows`
  (owner, admin, operations manager, dispatcher, viewer).
- Creating, updating, deleting, testing, and retrying runs
  needs `manage-workflows` (owner, admin, operations
  manager, dispatcher). Approving a checkpoint needs the
  named role or owner, regardless of `manage-workflows`.
- Workflows are a paid feature: creating, updating, or
  testing a workflow on the Free plan is refused with 402.
  Reading and running existing workflows is not gated.

## Honest limits

- **No submission channel.** This app does not send a claim
  package to a broker. `prepare_package` assembles a draft
  from recorded data and stores it locally; the draft is
  labelled a draft and nothing leaves the app. The missing
  submission channel is documented in
  PRODUCTION-LAUNCH-CHECKLIST.md and was ranked first among
  roadmap proposals in v0.4.0.
- **Recorded data only.** Conditions, evidence checks, and
  package drafts read the organization's own recorded
  claims, shipments, events, and evidence. No step calls an
  external service: no live mailer, no TMS, no OCR.
- **Deadline rules use the existing deadline clock.**
  Deadlines are computed from claim creation by the
  existing deadline rules; workflows add no new deadline
  model. `deadline.approaching` fires once per workflow per
  claim per day, `deadline_days_before` defaults to 7, and
  `deadline.overdue` fires once per day while a claim is
  overdue.
- **One active run per workflow per claim.** Starting a run
  while one is already pending, running, or waiting for
  approval for the same workflow and claim returns the
  existing run instead of starting a second.
- **Approval steps are the only pause.** A workflow cannot
  wait on an external event; it either runs its steps or
  pauses at a checkpoint for a human decision.
- **Notifications are in-app only.** Workflow notifications
  use the existing in-app notification system; no email is
  sent by a workflow step.
- **Notifications:** `workflow.notification`,
  `workflow.escalated`, `workflow.approval_needed`,
  `workflow.run_failed`. All four can be muted per member
  in notification preferences.
