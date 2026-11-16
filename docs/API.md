# API

Base URL: `http://localhost:8000/api/v1` in development. All endpoints return
JSON. A machine-readable definition is in `docs/openapi.yaml`.

## Authentication

Sanctum personal access tokens (Bearer). Register or log in to receive a
token, then send it on every authenticated request:

```text
Authorization: Bearer <token>
```

Tokens do not expire by default. Set `SANCTUM_TOKEN_EXPIRATION` (minutes)
once the frontend has a token refresh flow.

## Tenancy

Tenant-scoped endpoints require the organization header:

```text
X-Organization-Id: <organization uuid>
```

The backend verifies the organization exists and the user is an active
member. Responses:

- Missing header: `400` with "An organization must be selected."
- Unknown organization, or the user is not a member: `404`. The 404 is
  deliberate, so organization ids cannot be probed.
- Inactive member: `404`, same as a non-member.

## Correlation ids

Every API request gets a correlation id. Send one with the
`X-Correlation-Id` header (any sane string up to 64 characters) or let
the server generate a UUID. The id is echoed back on the response in
the same header and recorded on every audit row written during the
request, so a single request's trail can be followed end to end. The
hourly deadline refresh command sets a fresh id per run.

## Errors

Validation failures return `422` with Laravel's standard shape:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

Other errors return `{"message": "..."}` with the appropriate status:
`400` (missing organization header), `401` (missing or invalid token),
`403` (authenticated but not permitted), `404` (not found or not a member),
`429` (rate limited).

Unauthenticated API requests return JSON `401` even when the request
has no `Accept: application/json` header. The API never redirects to
a login page.

## Rate limits

- Register: 10 requests per minute per IP.
- Login, forgot password, reset password: 5 per minute per IP.
- Resend verification email: 3 per minute per user.

## Endpoints

### Auth

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| POST | `/auth/register` | No | Register. Body: `name`, `email`, `password`, `password_confirmation` (min 8 chars). Returns `user` and `token` (201). Sends a verification email. |
| POST | `/auth/login` | No | Log in. Body: `email`, `password`. Returns `user` and `token`. Wrong credentials return 422 with a generic message. |
| POST | `/auth/logout` | Yes | Revokes the token used for this request. |
| GET | `/auth/me` | Yes | The authenticated user with their organizations and roles. |
| PUT | `/auth/profile` | Yes | Update display name. Body: `name`. |
| PUT | `/auth/password` | Yes | Change password. Body: `current_password`, `password`, `password_confirmation`. |
| POST | `/auth/forgot-password` | No | Send a reset link. Always returns the same success message, even for unknown emails. |
| POST | `/auth/reset-password` | No | Reset with the emailed token. Body: `token`, `email`, `password`, `password_confirmation`. |

### Email verification

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/email/verify/{id}/{hash}` | Signed URL | Verifies the address and redirects to the frontend `/verify-email` page with `?status=verified` or `?status=invalid`. |
| POST | `/email/verification-notification` | Yes | Resends the verification email. |

### Organizations

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/organizations` | Yes | Organizations the user belongs to, with their role and status in each. |
| POST | `/organizations` | Yes | Create an organization. Body: `name`, optional `timezone`. The creator becomes owner (201). |
| GET | `/organizations/{organization}` | Yes | One organization. Members only (403 otherwise). |
| PUT | `/organizations/{organization}` | Yes | Update `name`, `timezone`, `settings`. Requires `manage-organization`. |
| POST | `/organizations/{organization}/switch` | Yes | Validates membership and returns the organization. The frontend stores the choice and sends it in the header afterwards. |

### Dashboard (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/dashboard` | Yes + org header | Organization overview: member counts, pending invitations, current role, member since, plus shipment, event, evidence, claim, and deadline counts, upcoming deadlines, claims by status, and per-currency financials (potential, submitted, accepted, recovered). Query: `date_from`, `date_to` (filter created_at of shipments and claims), `status` (adds a `claims_filtered` section with that status's count and requested amounts). `claims_submitted` counts status `submitted` only; accepted and partially accepted claims are under `claims_approved`. Potential money is labeled estimated, never recovered. |

### Members (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/members` | Yes + org header | Paginated members (25 per page). Query: `search`, `role`, `status`, `page`. Requires `view-members`. |
| PUT | `/members/{member}` | Yes + org header | Change `role` or `status`. Requires `manage-members`. Owner protections apply (see below). |
| DELETE | `/members/{member}` | Yes + org header | Remove a member. Requires `manage-members`. Owner protections apply. |

Owner protections: only an owner can change another owner's membership or
grant the owner role, and the last active owner cannot be demoted,
deactivated, or removed.

### Invitations (tenant-scoped, except accept)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/invitations` | Yes + org header | Paginated invitations. Requires `manage-invitations`. |
| POST | `/invitations` | Yes + org header | Invite an email address. Body: `email`, `role`. Only an owner can invite an owner. Sends the invitation email (201). |
| POST | `/invitations/accept` | Yes, no org header | Accept with the token from the email. Body: `token`. The invitation must have been sent to the signed-in user's email. |
| DELETE | `/invitations/{invitation}` | Yes + org header | Revoke a pending invitation. Requires `manage-invitations`. |

### Carriers (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/carriers` | Yes + org header | Paginated carriers (25 per page). Query: `search`, `page`. Requires `view-shipments`. |
| POST | `/carriers` | Yes + org header | Create a carrier. Body: `name`, optional `contact_name`, `contact_email`, `contact_phone`, `dot_number`, `notes` (201). Requires `create-shipments`. |
| PUT | `/carriers/{carrier}` | Yes + org header | Update a carrier. Requires `edit-shipments`. There is no carrier delete endpoint yet. |

### Shipments (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/shipments` | Yes + org header | Paginated shipments (15 per page, max 100). Query: `search` (reference, broker, driver), `status`, `carrier_id`, `date_from`, `date_to` (pickup appointment range), `sort` (`created_at`, `pickup_appointment`, `delivery_appointment`, `status`), `direction`, `page`. Requires `view-shipments`. |
| POST | `/shipments` | Yes + org header | Create a shipment. Body: `internal_reference` (required, unique per organization), `broker_or_customer_name` (required), optional `external_load_reference`, `carrier_id`, `driver_name`, `driver_contact`, `pickup_location`, `delivery_location`, `pickup_appointment`, `delivery_appointment`, `actual_arrival_at`, `actual_departure_at`, `base_rate`, `currency` (default `USD`), `status` (`draft` or `planned`, default `draft`), `notes` (201). Requires `create-shipments`. |
| GET | `/shipments/{shipment}` | Yes + org header | One shipment with carrier, creator, event count, and evidence count. Requires `view-shipments`. |
| PUT | `/shipments/{shipment}` | Yes + org header | Update a shipment. Status changes must follow the transition map (see below). Requires `edit-shipments`. Archived shipments return 403: they are read-only. |
| POST | `/shipments/{shipment}/archive` | Yes + org header | Archive a shipment. One-way; there is no unarchive. Requires `archive-shipments`. |
| GET | `/shipments/export` | Yes + org header | Download the filtered list as CSV (same filters as the list). Requires `export-shipments`. |

Status transitions move forward one step at a time: `draft` to
`planned` to `in_transit` to `delivered` to `completed`. Any
non-archived status may move to `archived`, and `archived` is terminal.
An invalid transition returns 422 with a message naming both statuses.

### Shipment CSV import (tenant-scoped)

Two steps. Preview parses and validates without creating anything;
confirm creates only the valid, non-duplicate rows from the server-side
preview. Both steps require `import-shipments`.

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| POST | `/shipments/import/preview` | Yes + org header | Multipart upload: `file` (CSV, max 5 MB) and optional `mapping` (JSON object of exact header text to field name). Returns `preview_token`, `summary` (`total`, `valid`, `invalid`, `duplicate`), `columns`, and `rows` with per-row `data`, `errors`, and `is_duplicate`. Previews expire after 30 minutes. |
| POST | `/shipments/import/confirm` | Yes + org header | Body: `preview_token`, optional `row_indexes` (1-based; omit to import all valid rows). Returns `imported`, `skipped`, and `skipped_rows` with a reason per row. A token can only be confirmed once. |

Column mapping accepts the canonical field names and common aliases
(case-insensitive, so "Internal Reference" and "internal_reference"
both work). The `carrier` column resolves a carrier by name within the
organization; an unknown carrier is a row error. Duplicates are
detected against existing references in the organization and within the
file. Files are limited to 1000 rows. Imported shipments may carry any
non-archived status; the create endpoint only accepts `draft` and
`planned`.

### Shipment events (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/shipments/{shipment}/events` | Yes + org header | Paginated events for a shipment, newest first. Requires `view-events`. |
| POST | `/shipments/{shipment}/events` | Yes + org header | Record an event. Body: `event_category_id` (required), `event_date` (required), optional `start_time`, `end_time` (HH:MM, end after start), `duration_minutes`, `location`, `description`, `estimated_amount`, `currency`, `notes` (201). Duration is computed from start and end when both are present. Events start as `potential`. Requires `create-events`. Archived shipments return 422. |
| GET | `/events/{event}` | Yes + org header | One event. Requires `view-events`. |
| PUT | `/events/{event}` | Yes + org header | Update an event, including `status` (`potential`, `under_review`, `dismissed`). Duration is recomputed from the merged times. Requires `edit-events`. Events on archived shipments return 403. |
| DELETE | `/events/{event}` | Yes + org header | Delete an event. Evidence on the event keeps its shipment link. Requires `delete-events`. |

### Event categories (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/event-categories` | Yes + org header | System categories plus the organization's custom ones. Requires `view-events`. |
| POST | `/event-categories` | Yes + org header | Add a custom category. Body: `label`, optional `description` (201). The slug must not collide with an existing visible category. Requires `create-events`. |

### Evidence (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/evidence` | Yes + org header | Paginated evidence library (25 per page). Query: `shipment_id`, `shipment_event_id`, `document_category`, `verification_status`, `page`. Requires `view-evidence`. |
| POST | `/evidence` | Yes + org header | Multipart upload: `file` (max 20 MB; pdf, jpg, jpeg, png, webp, doc, docx, xls, xlsx, csv, txt), `shipment_id` (required), optional `shipment_event_id` (must belong to the shipment), `document_category` (required), `description` (201). Requires `upload-evidence`. Archived shipments return 422. |
| GET | `/evidence/{evidence}` | Yes + org header | One evidence record's metadata. The storage key is never exposed. Requires `view-evidence`. |
| GET | `/evidence/{evidence}/download` | Yes + org header | Download the file. Add `?preview=1` to serve it inline (for images and PDFs). On S3 this redirects to a 5-minute signed URL. Requires `view-evidence`. |
| PUT | `/evidence/{evidence}/verification` | Yes + org header | Set `verification_status` (`pending`, `verified`, `rejected`). Requires `verify-evidence`. |
| DELETE | `/evidence/{evidence}` | Yes + org header | Delete the record and the stored file. Requires `delete-evidence`. The deletion is written to the application log and to the audit trail. |

Document categories: `bill_of_lading`, `proof_of_delivery`,
`rate_confirmation`, `arrival_departure_record`,
`appointment_confirmation`, `broker_communication`, `driver_photo`,
`receipt`, `lumper_receipt`, `other`.

### Claims (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/claims` | Yes + org header | Paginated claims (15 per page, max 100). Query: `search` (reference, description, broker reference, shipment reference or broker), `status`, `claim_category`, `shipment_id`, `sort` (`created_at`, `requested_amount`, `status`, `submission_deadline_at`), `direction`, `page`. Requires `view-claims`. |
| POST | `/claims` | Yes + org header | Create a claim. Body: `shipment_id` (required), optional `shipment_event_id` (must belong to the shipment), `claim_category` (inherited from the event when omitted), `description`, `requested_amount` (required, greater than zero), `currency` (default `USD`), `internal_notes`, `external_broker_reference`, `submission_deadline_at` (201). The reference is generated per organization (`CLM-YYYY-NNNN`). Requires `create-claims`. |
| GET | `/claims/{claim}` | Yes + org header | One claim with shipment, event, active deadline, creator, updater, evidence count, and allowed transitions. Requires `view-claims`. |
| PUT | `/claims/{claim}` | Yes + org header | Update a claim. Amount, currency, category, and event link are frozen once the claim is submitted; notes and the broker reference stay editable until the claim is paid or closed. Status never changes here: use the transition endpoint. Requires `edit-claims`. |
| POST | `/claims/{claim}/transition` | Yes + org header | Move the claim to `to_status`. Body may include `reason`, `accepted_amount`, `rejection_reason`, `submitted_at`. See the transition rules below. The permission depends on the target (see below). |
| POST | `/claims/{claim}/record-payment` | Yes + org header | Record a payment and move an accepted or partially accepted claim to `paid`. Body: `recovered_amount` (required, greater than zero), `payment_date` (required), optional `payment_reference`, `reason`. Requires `record-claim-payments`. This is the only route to `paid`. |
| GET | `/claims/{claim}/history` | Yes + org header | Append-only status history, oldest first. Requires `view-claims`. |
| GET | `/claims/{claim}/evidence` | Yes + org header | Evidence files attached to the claim. Requires `view-claims`. |
| POST | `/claims/{claim}/evidence` | Yes + org header | Attach an evidence file. Body: `evidence_file_id`. The file must belong to the claim's shipment (201). Requires `attach-claim-evidence`. |
| DELETE | `/claims/{claim}/evidence/{evidence}` | Yes + org header | Detach an evidence file. The file itself is not deleted. Requires `attach-claim-evidence`. |

Claim statuses: `potential`, `evidence_required`, `under_review`,
`ready_to_submit`, `submitted`, `accepted`, `partially_accepted`,
`rejected`, `paid`, `closed`. Claim categories match the event
category slugs: `detention`, `layover`, `tonu`, `lumper`,
`additional_stop`, `additional_miles`, `other`.

Transition map: `potential` to `evidence_required`, `under_review`,
`closed`; `evidence_required` to `under_review`, `closed`;
`under_review` to `ready_to_submit`, `evidence_required`, `closed`;
`ready_to_submit` to `submitted`, `under_review`, `closed`;
`submitted` to `accepted`, `partially_accepted`, `rejected`;
`rejected` to `closed`, `under_review`; `paid` to `closed`; `closed`
is terminal. Accepted and partially accepted never move by generic
transition: they move to `paid` only through payment recording.

Per-transition rules: submitting stamps `submitted_at` when none is
given; accepting needs an explicit `accepted_amount` (never defaulted
from requested); partial acceptance needs an accepted amount below the
requested amount; rejecting needs a `rejection_reason`; closing before
submission needs a `reason`. An invalid transition or missing data
returns 422.

Transition permissions: submitting needs `submit-claims`; marking
ready, accepting, partially accepting, and rejecting need
`review-claims`; the remaining moves need `edit-claims`. Moving to
`paid` through this endpoint is always rejected (403).

Financial fields are kept separate on purpose: `requested_amount` is
what was asked, `accepted_amount` is what the broker agreed, and
`recovered_amount` is the cash actually received. Never present one as
another.

### Evidence readiness (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/claims/{claim}/readiness` | Yes + org header | Live readiness breakdown: `required`, `optional`, `collected`, `missing`, `optional_collected` (value/label pairs), `readiness_percentage`, `readiness_status` (`not_started`, `partial`, `ready`), `has_requirements`, `is_overridden`, `disclaimer`, and `snapshots` (the latest snapshot rows). Requires `view-claims`. |
| POST | `/claims/{claim}/readiness/override` | Yes + org header | Override the assessment with a mandatory `reason`. Records the reviewer and reason on the snapshot rows and writes a history entry. Requires `review-claims`. |

Readiness counts required document categories against the evidence
attached to the claim. A claim with no configured requirements reports
100 percent with `has_requirements: false`. Readiness is informational
only: it never certifies that a claim is valid or will be accepted.
Snapshots are rewritten when a claim is marked ready to submit;
override information survives re-snapshots.

### Evidence requirements (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/evidence-requirements` | Yes + org header | The organization's requirements, global first. Requires `view-claims`. |
| POST | `/evidence-requirements` | Yes + org header | Create a requirement. Body: `event_category_id` (nullable: null means every event type), `document_category` (required), optional `is_required` (default true), `notes` (201). Requires `manage-evidence-requirements`. |
| PUT | `/evidence-requirements/{requirement}` | Yes + org header | Update a requirement. Requires `manage-evidence-requirements`. |
| DELETE | `/evidence-requirements/{requirement}` | Yes + org header | Delete a requirement. Requires `manage-evidence-requirements`. |

A requirement is unique per organization, event category, and document
category. Seeded defaults (detention needs appointment confirmation,
arrival/departure record, and broker communication; lumper needs a
lumper receipt; TONU needs broker communication) are illustrative
starting points the customer must verify, not contractual rules.

### Deadlines (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/deadlines` | Yes + org header | Active deadlines (25 per page). Query: `status`, `upcoming_days` (deadline within that many days), `page`. Requires `view-deadlines`. |
| GET | `/deadlines/{deadline}` | Yes + org header | One deadline with its claim. Requires `view-deadlines`. |
| PUT | `/deadlines/{deadline}` | Yes + org header | Update `deadline_at`, `timezone`, `source_reference`, `reminder_days_before`. Superseded rows cannot be edited. Requires `manage-deadlines`. |
| POST | `/claims/{claim}/deadline` | Yes + org header | Set the deadline for a claim (201). Body: `deadline_at` (required), `timezone` (required, a real IANA identifier), optional `source_reference`, `reminder_days_before` (array of integers 0-90). Setting a deadline when one is active supersedes the old row and creates a new one. Requires `manage-deadlines`. |

Deadline statuses: `upcoming`, `due_soon`, `overdue`, `met`, `missed`.
Statuses are recomputed hourly by
`claimtrail:refresh-deadline-statuses` and on every create/update, in
the deadline's own timezone: submitted on time is `met`, submitted late
is `missed`, closed without submission after the deadline is `missed`,
past deadline unsubmitted is `overdue`, inside the reminder window
(largest reminder, or 7 days) is `due_soon`, otherwise `upcoming`.
Deadlines are customer-supplied only: the system never infers one from
an event type. The refresh command notifies members with `view-claims`
about due soon and overdue deadlines (at most once per deadline per
day per type, through the notification dedupe keys) and logs the
counts. Met, missed, and far-future upcoming deadlines do not notify.

### Claim packages (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| POST | `/claims/{claim}/package` | Yes + org header | Generate (or regenerate) the claim package PDF. Body: optional `include_notes` (default false). Creates a queued document record and dispatches the rendering job (202). Requires `generate-claim-packages`. |
| GET | `/claims/{claim}/package` | Yes + org header | The latest generated document for the claim, or `data: null` when none exists. Requires `view-claims`. |
| GET | `/documents/{document}/download` | Yes + org header | Download the PDF. Returns 409 while the document is still queued or failed. On S3 this redirects to a 5-minute signed URL. Requires `view-claims`. |

The package contains the claim reference, organization and shipment
details, event details and timeline, requested and accepted amounts,
and an evidence index (filenames, categories, verification status;
originals are referenced, not embedded). Internal notes are included
only when `include_notes` is true. The footer states the package is
supporting evidence, not a guarantee of acceptance.

### Audit trail (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/audit-logs` | Yes + org header | Paginated audit rows, newest first (25 per page). Query: `entity_type` (e.g. `Shipment`, `Claim`), `action` (e.g. `shipment.created`, `claim.status_changed`), `actor_id`, `date_from`, `date_to`, `page`. Requires `view-audit-logs` (owner, admin, operations manager). |

Each row records the organization, the actor (`user` with a user id, or
`system` for queue and console work), the action, the subject entity
type and id, `before` and `after` values holding only the changed
scalar fields, the correlation id, the request IP, and `created_at`.
There is no `updated_at`: the trail is append-only, with no update or
delete endpoints, and the model cancels updates and deletes in code.
Passwords, tokens, storage keys, and internal notes are never written
to audit rows. Claim status changes write one explicit
`claim.status_changed` row per transition (with from and to status);
the observer skips claim status so a transition is never recorded
twice. Payment recording writes `claim.payment_recorded`, and package
generation writes `claim_package.generated` with the member who
requested the package as the actor.

### Notifications (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/notifications` | Yes + org header | The member's own notifications, newest first (25 per page). Query: `unread=1` for unread only, `page`. The `meta` block carries `unread_count` alongside the pagination fields. |
| GET | `/notifications/unread-count` | Yes + org header | `{ "data": { "unread_count": n } }`, for the badge in the app shell. |
| POST | `/notifications/{notification}/read` | Yes + org header | Mark one notification read. Another member's notification, or one from another organization, returns 404. |
| POST | `/notifications/read-all` | Yes + org header | Mark every unread notification read. Returns `{ "data": { "marked_read": n } }`. |
| GET | `/notification-preferences` | Yes + org header | The member's preferences for all ten event types, each with `in_app` and `email` booleans. A missing row means both channels on. |
| PUT | `/notification-preferences` | Yes + org header | Save preferences for one or more event types. Body: `preferences`, an array (at least one entry) of `{ event_type, in_app, email }`; `event_type` must be one of the ten types and both channel flags are required per entry. Returns the full preference list. |

Notification types: `claim.created`, `claim.evidence_missing`,
`claim.ready_for_review`, `deadline.upcoming`, `deadline.overdue`,
`claim.submitted`, `claim.accepted`, `claim.rejected`,
`payment.recorded`, `organization.invitation`. Claim events notify
members with `view-claims` except the actor. Evidence-missing
notifications are sent at most once per claim per day; deadline
notifications at most once per deadline per day per type. Each
notification row carries a `data` payload with entity links
(`claim_id`, `shipment_id`, and friends) so the frontend can link
into the underlying record.

Delivery honors preferences per recipient per event type: in-app off
means no row, email off means no mail. Email goes through a queued
mailable (3 tries, backoff 60/300/900 seconds). Invitation
notifications are created only for users who already have an account;
an invitation to an unknown email sends the invitation email only and
no notification row. There is no SMS or WhatsApp delivery.

### Reports (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/reports/summary` | Yes + org header | Counts by claim status, counts by event type, and the six money figures per currency. Query: `date_from`, `date_to` (filter created_at of claims and events). Requires `view-reports`. |
| GET | `/reports/recovery-history` | Yes + org header | Recovered amounts per month per currency over the filtered range (default: last 12 months). Query: `date_from`, `date_to`. Requires `view-reports`. |
| GET | `/reports/export` | Yes + org header | The summary plus one row per claim as a streaming CSV download, honoring the same date filters. Requires `export-reports`. |

Financial definitions (the single source is `ReportService`; the same
figures feed the dashboard). Every figure is grouped by currency:
amounts in different currencies are never added together, and there is
no conversion and no invented rate.

- **Potential**: sum of `requested_amount` on claims in `potential`,
  `evidence_required`, `under_review`, or `ready_to_submit`. Estimated
  only, never presented as recovered.
- **Submitted**: sum of `requested_amount` on claims in `submitted`,
  `accepted`, `partially_accepted`, `rejected`, or `paid`, plus claims
  in `closed` that have a `submitted_at` timestamp. A claim closed
  without submission counts nowhere.
- **Accepted**: sum of `accepted_amount` on claims in `accepted`,
  `partially_accepted`, or `paid`.
- **Recovered**: sum of `recovered_amount` on claims in `paid` only.
- **Rejected**: sum of `requested_amount` on claims in `rejected`.
- **Outstanding**: accepted minus recovered, floored at 0, computed per
  claim over claims in `accepted`, `partially_accepted`, or `paid`,
  then summed per currency.

## Example: register, create an organization, invite someone

```sh
API=http://localhost:8000/api/v1

# Register and capture the token
TOKEN=$(curl -s -X POST $API/auth/register \
  -H 'Content-Type: application/json' \
  -d '{"name":"Ada","email":"ada@example.com","password":"password123","password_confirmation":"password123"}' \
  | python3 -c 'import sys,json; print(json.load(sys.stdin)["token"])')

# Create an organization
ORG=$(curl -s -X POST $API/organizations \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"Acme Trucking Co.","timezone":"America/Chicago"}' \
  | python3 -c 'import sys,json; print(json.load(sys.stdin)["data"]["id"])')

# Invite a dispatcher
curl -s -X POST $API/invitations \
  -H "Authorization: Bearer $TOKEN" -H "X-Organization-Id: $ORG" \
  -H 'Content-Type: application/json' \
  -d '{"email":"dispatcher@example.com","role":"dispatcher"}'
```
