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
| PUT | `/organizations/{organization}` | Yes | Update `name`, `timezone`, `settings`. Requires `manage-organization`. `settings` accepts only `default_currency` (3 letters), `date_format` (`Y-m-d`, `d/m/Y`, or `m/d/Y`), and `claim_reference_prefix` (up to 10 characters, uppercase letters, digits, and hyphens); unknown keys are rejected, and given settings merge over the stored ones. |
| POST | `/organizations/{organization}/switch` | Yes | Validates membership and returns the organization. The frontend stores the choice and sends it in the header afterwards. |

### Dashboard (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/dashboard` | Yes + org header | Organization overview: member counts, pending invitations, current role, member since, plus shipment, event, evidence, claim, and deadline counts, upcoming deadlines, claims by status, and per-currency financials (potential, submitted, accepted, recovered). Query: `date_from`, `date_to` (filter created_at of shipments and claims), `status` (adds a `claims_filtered` section with that status's count and requested amounts). `claims_submitted` counts status `submitted` only; accepted and partially accepted claims are under `claims_approved`. Potential money is labeled estimated, never recovered. |

### Onboarding (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/onboarding` | Yes + org header | Guided first-steps progress. Steps are derived live from the organization's data (invite a member, first shipment, first event, first evidence upload, first claim, first review); only the dismissal is stored. Returns `steps` (key, label, description, href, completed), `completed_count`, `total_count`, `all_completed`, `dismissed`. Any active member can read it. |
| POST | `/onboarding/dismiss` | Yes + org header | Dismiss the checklist for the whole organization. Requires `manage-organization`. |

### Saved views (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/saved-views` | Yes + org header | The member's saved views. Query: `resource_type` (`shipment` or `claim`). Views are personal: a member only sees their own. Requires the matching list view permission. |
| POST | `/saved-views` | Yes + org header | Save the current list filters as a named view. Body: `name`, `resource_type`, `filters` (a flat map of list query parameter names to text values; unknown keys are dropped). At most 25 views per member per resource type (201). Requires the matching list view permission. |
| PUT | `/saved-views/{savedView}` | Yes + org header | Rename a view or replace its filters. Only the owner of the view can change it; other members get 404. |
| DELETE | `/saved-views/{savedView}` | Yes + org header | Delete a view. Only the owner of the view can delete it; other members get 404. |

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
| GET | `/shipments` | Yes + org header | Paginated shipments (15 per page, max 100). Query: `search` (reference, broker, driver, carrier name, pickup/delivery location), `status`, `carrier_id`, `date_from`, `date_to` (pickup appointment range), `delivery_date_from`, `delivery_date_to` (delivery appointment range), `amount_min`, `amount_max` (base rate), `event_type` (event category slug), `broker` (broker/customer substring), `sort` (`created_at`, `pickup_appointment`, `delivery_appointment`, `status`), `direction`, `page`. Requires `view-shipments`. |
| POST | `/shipments` | Yes + org header | Create a shipment. Body: `internal_reference` (required, unique per organization), `broker_or_customer_name` (required), optional `external_load_reference`, `carrier_id`, `driver_name`, `driver_contact`, `pickup_location`, `delivery_location`, `pickup_appointment`, `delivery_appointment`, `actual_arrival_at`, `actual_departure_at`, `base_rate`, `currency` (default `USD`), `status` (`draft` or `planned`, default `draft`), `notes`, `custom_field_values` (validated against the active shipment custom fields) (201). Requires `create-shipments`. |
| GET | `/shipments/{shipment}` | Yes + org header | One shipment with carrier, creator, event count, and evidence count. Requires `view-shipments`. |
| PUT | `/shipments/{shipment}` | Yes + org header | Update a shipment. Status changes must follow the transition map (see below). `custom_field_values` merges over the stored values. Requires `edit-shipments`. Archived shipments return 403: they are read-only. |
| POST | `/shipments/{shipment}/archive` | Yes + org header | Archive a shipment. One-way; there is no unarchive. Requires `archive-shipments`. |
| POST | `/shipments/bulk-status` | Yes + org header | Move several shipments to the same status. Body: `ids` (1 to 100), `to_status`. Each shipment follows the transition map and the update policy; shipments that cannot move are reported in `failed` with a reason instead of failing the batch. Returns `updated` (ids) and `failed` (id + reason). Requires `edit-shipments`. |
| POST | `/shipments/bulk-archive` | Yes + org header | Archive several shipments at once. This is the bulk-delete equivalent: shipments are never hard-deleted. Body: `ids` (1 to 100). Returns `updated` and `failed`. Requires `archive-shipments`. |
| GET | `/shipments/export` | Yes + org header | Download the filtered list as CSV (same filters as the list). Query: `export_template_id` (optional shipment export template; without it the default 16 columns are used). Requires `export-shipments`. |

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
| POST | `/shipments/import/confirm` | Yes + org header | Body: `preview_token`, optional `row_indexes` (1-based; omit to import all valid rows). Returns `imported`, `skipped`, and `skipped_rows` with a reason, an `is_duplicate` flag, and the per-field `errors` for each skipped row, so the client can show exactly what was wrong. A token can only be confirmed once. |

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
| POST | `/shipments/{shipment}/events` | Yes + org header | Record an event. Body: `event_category_id` (required), `event_date` (required), optional `start_time`, `end_time` (HH:MM, end after start), `duration_minutes`, `location`, `description`, `estimated_amount`, `currency`, `notes` (201). Duration is computed from start and end when both are present. Events start as `potential`. Requires `create-events`. Archived shipments return 422. A disabled event category returns 422; when the category has a default estimated amount (and currency) and the event gives none, the default fills it in. |
| GET | `/events/{event}` | Yes + org header | One event. Requires `view-events`. |
| PUT | `/events/{event}` | Yes + org header | Update an event, including `status` (`potential`, `under_review`, `dismissed`). Duration is recomputed from the merged times. Requires `edit-events`. Events on archived shipments return 403. |
| DELETE | `/events/{event}` | Yes + org header | Delete an event. Evidence on the event keeps its shipment link. Requires `delete-events`. |

### Event categories (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/event-categories` | Yes + org header | System categories plus the organization's custom ones. Requires `view-events`. |
| POST | `/event-categories` | Yes + org header | Add a custom category. Body: `label`, optional `description` (201). The slug must not collide with an existing visible category. Requires `create-events`. |
| PUT | `/event-categories/{category}` | Yes + org header | Update an organization-owned category: `label` (renaming recomputes the slug, with the same collision check), `description`, `is_active`, `default_estimated_amount`, `default_currency`. System categories and other organizations' categories return 404. Requires `manage-customization`. |
| DELETE | `/event-categories/{category}` | Yes + org header | Delete an organization-owned category. Categories with events return 422: disable them instead. System categories return 404. Requires `manage-customization`. |

### Evidence (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/evidence` | Yes + org header | Paginated evidence library (25 per page). Query: `shipment_id`, `shipment_event_id`, `document_category`, `verification_status`, `page`. Requires `view-evidence`. |
| POST | `/evidence` | Yes + org header | Multipart upload: `file` (max 20 MB; pdf, jpg, jpeg, png, webp, doc, docx, xls, xlsx, csv, txt), `shipment_id` (required), optional `shipment_event_id` (must belong to the shipment), `document_category` (required), `description` (201). Requires `upload-evidence`. Archived shipments return 422. |
| GET | `/evidence/{evidence}` | Yes + org header | One evidence record's metadata, including `previewable` (true for images and PDFs). The storage key is never exposed. Requires `view-evidence`. |
| GET | `/evidence/{evidence}/download` | Yes + org header | Download the file. Add `?preview=1` to serve it inline (for images and PDFs). On S3 this redirects to a 5-minute signed URL. Requires `view-evidence`. |
| GET | `/evidence/{evidence}/preview` | Yes + org header | Serve an image (JPEG, PNG, WebP) or PDF inline with the correct content type. Other file types return 422 with `previewable: false`; download those instead. On S3 this redirects to a 5-minute signed URL. Requires `view-evidence`. |
| PUT | `/evidence/{evidence}/verification` | Yes + org header | Set `verification_status` (`pending`, `verified`, `rejected`). Requires `verify-evidence`. |
| DELETE | `/evidence/{evidence}` | Yes + org header | Delete the record and the stored file. Requires `delete-evidence`. The deletion is written to the application log and to the audit trail. |

Document categories: `bill_of_lading`, `proof_of_delivery`,
`rate_confirmation`, `arrival_departure_record`,
`appointment_confirmation`, `broker_communication`, `driver_photo`,
`receipt`, `lumper_receipt`, `other`.

### Claims (tenant-scoped)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/claims` | Yes + org header | Paginated claims (15 per page, max 100). Query: `search` (reference, description, broker reference, shipment reference or broker, carrier name), `status`, `claim_category`, `shipment_id`, `amount_min`, `amount_max` (requested amount), `deadline_from`, `deadline_to` (submission deadline range), `deadline_status` (`overdue`, `upcoming`, `none`; overdue/upcoming only count while the claim is still being prepared), `event_type` (event category slug), `broker` (shipment broker/customer substring), `carrier_id` (through the shipment), `sort` (`created_at`, `requested_amount`, `status`, `submission_deadline_at`), `direction`, `page`. Requires `view-claims`. |
| POST | `/claims` | Yes + org header | Create a claim. Body: `shipment_id` (required), optional `shipment_event_id` (must belong to the shipment), `claim_category` (inherited from the event when omitted), `description`, `requested_amount` (required, greater than zero), `currency` (default `USD`), `internal_notes`, `external_broker_reference`, `submission_deadline_at`, `custom_field_values` (validated against the active claim custom fields) (201). The reference is generated per organization (`<PREFIX>-YYYY-NNNN`, prefix from the organization settings, default `CLM`). When no `submission_deadline_at` is given, the organization deadline default applies when one matches (see Deadline settings). Requires `create-claims`. |
| GET | `/claims/export` | Yes + org header | Export the filtered claim list as CSV, using the same filters as the claim list. Query: `export_template_id` (optional claim export template; without it the default claim columns are used). Requires `export-reports`. |
| GET | `/claims/{claim}` | Yes + org header | One claim with shipment, event, active deadline, creator, updater, evidence count, and allowed transitions. Requires `view-claims`. |
| PUT | `/claims/{claim}` | Yes + org header | Update a claim. Amount, currency, category, and event link are frozen once the claim is submitted; notes and the broker reference stay editable until the claim is paid or closed. Status never changes here: use the transition endpoint. Requires `edit-claims`. |
| POST | `/claims/{claim}/transition` | Yes + org header | Move the claim to `to_status`. Body may include `reason`, `accepted_amount`, `rejection_reason`, `submitted_at`. See the transition rules below. The permission depends on the target (see below). |
| POST | `/claims/{claim}/review` | Yes + org header | Review a claim that is under review. Body: `decision` (`approve` or `request_changes`), optional `comment`. Approve moves it to `ready_to_submit`; requesting changes moves it back to `evidence_required` and requires a comment. The comment is recorded as a reviewer note and as the reason on the status history row. Requires `review-claims`. |
| GET | `/claims/{claim}/review-notes` | Yes + org header | Reviewer notes on a claim, oldest first. Requires `view-claims`. |
| POST | `/claims/{claim}/review-notes` | Yes + org header | Add a reviewer note. Body: `note` (required). Notes are append-only; closed claims take no more notes (201). Requires `review-claims`. |
| POST | `/claims/bulk-transition` | Yes + org header | Move several claims to the same status. Body: `ids` (1 to 100), `to_status`, optional `reason`, `accepted_amount`, `rejection_reason`, `submitted_at`. Each claim goes through the transition service with its own map, data rules, history row, and audit row; claims that cannot move are reported in `failed` with a reason. Moving to `paid` is always rejected here. Returns `updated` (id, claim reference, status) and `failed`. The permission depends on the target, as in the single transition endpoint. |
| POST | `/claims/bulk-delete` | Yes + org header | Delete several claims. Only pre-submission claims can be deleted; submitted or later claims are reported in `failed`. Body: `ids` (1 to 100). Returns `deleted` and `failed`. Requires `delete-claims` (owner, admin, operations manager, billing staff). |
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

### Custom fields (tenant-scoped)

Custom field definitions let an organization add its own fields to shipments and claims. Values are stored as a JSON map on the shipment or claim row, keyed by `field_key`, and returned as `custom_field_values` (an empty object when nothing is set). Only active definitions accept new values; values stored under a deactivated definition are kept but no longer validated. At most 20 active definitions are allowed per organization and resource type. All write routes require `manage-customization` (owner, admin, operations manager).

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/custom-fields?resource_type=` | Yes + org header | Definitions for one resource type (`shipment` or `claim`, required), in display order. Requires `view-shipments` or `view-claims` matching the resource. |
| POST | `/custom-fields` | Yes + org header | Create a definition. Body: `resource_type` (required), `label` (required), `field_type` (`text`, `number`, `date`, `select`, or `boolean`; required), optional `field_key` (auto-slugged from the label when omitted), `is_required`, `options` (required for `select`: 2 to 20 non-empty strings), `sort_order`, `is_active` (201). The key must be unique per organization and resource type. |
| GET | `/custom-fields/{field}` | Yes + org header | One definition. Another organization's definition returns 404. Requires the matching view permission. |
| PUT | `/custom-fields/{field}` | Yes + org header | Update `label`, `is_required`, `options`, `sort_order`, `is_active`. The key is immutable. Requires `manage-customization`. |
| DELETE | `/custom-fields/{field}` | Yes + org header | Delete the definition and strip its key from every shipment and claim value map in the organization. Requires `manage-customization`. |

Value validation on shipment and claim create/update: unknown keys are rejected with `custom_field_values.<key>`; required fields must be present and non-empty; `text` must be a string of at most 500 characters; `number` must be numeric; `date` must be a valid `Y-m-d` date; `select` must be one of the configured options; `boolean` accepts booleans, `0`/`1`, and the strings `"true"`/`"false"`, and is stored as a boolean. On update the supplied values merge over the stored ones, so a partial update keeps untouched fields.

### Evidence templates (tenant-scoped)

A reusable named set of evidence document requirements. All routes require `manage-customization` (owner, admin, operations manager).

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/evidence-templates` | Yes + org header | Paginated templates with their items, newest first. |
| POST | `/evidence-templates` | Yes + org header | Create a template. Body: `name` (required, unique per organization), optional `description`, `is_active`, `items` (required, 1 to 20 entries of `{ document_category, label, is_required, sort_order }`; `document_category` must be a valid evidence document category) (201). |
| GET | `/evidence-templates/{template}` | Yes + org header | One template with items. Another organization's template returns 404. |
| PUT | `/evidence-templates/{template}` | Yes + org header | Update `name`, `description`, `is_active`. When `items` is given it replaces the existing items wholesale. |
| DELETE | `/evidence-templates/{template}` | Yes + org header | Delete the template and its items. |
| POST | `/evidence-templates/{template}/apply-to-claim/{claim}` | Yes + org header | Apply the template to a claim in the same organization. For each distinct document category in the template that the claim does not already have a claim requirement row for, a row is created (required as configured, unfulfilled, snapshotted now). Returns `{ data: { created, skipped, template_id, claim_id } }`. Applying the same template twice creates nothing the second time. |

### Deadline settings (tenant-scoped)

Organization defaults for how soon after an event a claim must be submitted. A setting applies when a claim is created without an explicit `submission_deadline_at`: a `ClaimDeadline` row is created with `deadline_at` set to now in the organization timezone plus `submit_within_days` days, `source_reference` "Organization deadline default", and the setting's reminders, and the claim's `submission_deadline_at` is set to the same time. An explicit deadline always wins. All write routes require `manage-customization` (owner, admin, operations manager).

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/deadline-settings` | Yes + org header | Paginated deadline settings. |
| POST | `/deadline-settings` | Yes + org header | Create a setting. Body: `scope_type` (`global`, `claim_category`, or `event_category`; required), `scope_value` (category slug; required unless the scope is `global`), `submit_within_days` (1 to 365; required), `reminder_days_before` (integers 0 to 90) (201). Each scope may have only one setting per organization. |
| GET | `/deadline-settings/resolve?claim_category=&event_category=` | Yes + org header | The setting that would apply to a claim with the given categories, or `data: null` when none applies. Precedence: claim category match, then event category match, then the global setting. Requires `view-deadlines`. |
| GET | `/deadline-settings/{setting}` | Yes + org header | One deadline setting. Another organization's setting returns 404. |
| PUT | `/deadline-settings/{setting}` | Yes + org header | Update a deadline setting. |
| DELETE | `/deadline-settings/{setting}` | Yes + org header | Delete a deadline setting. |

### Export templates (tenant-scoped)

Saved column selections for shipment and claim CSV exports. Columns are fixed column keys or `custom:<field_key>` entries for active custom fields of the same resource type. At most one template per organization and resource type is the default. All routes require `manage-customization` (owner, admin, operations manager).

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/export-templates?resource_type=` | Yes + org header | Paginated templates, optionally filtered by resource type. |
| GET | `/export-templates/available-columns?resource_type=` | Yes + org header | The columns available for the resource type: the fixed columns plus one `custom:<field_key>` entry per active custom field. Each entry is `{ key, label }`. |
| POST | `/export-templates` | Yes + org header | Create a template. Body: `resource_type` (required), `name` (required, unique per organization and resource type), `columns` (required, 1 to 30 entries, each an available column), optional `is_default` (when true, clears every other default for the same organization and resource type) (201). |
| GET | `/export-templates/{template}` | Yes + org header | One template. Another organization's template returns 404. |
| PUT | `/export-templates/{template}` | Yes + org header | Update `name`, `columns`, `is_default`. The resource type never changes. |
| DELETE | `/export-templates/{template}` | Yes + org header | Delete a template. |
| POST | `/export-templates/{template}/set-default` | Yes + org header | Make the template the default for its organization and resource type, clearing every other default. |

Shipment export columns (default): `internal_reference`, `external_load_reference`, `broker_or_customer_name`, `carrier`, `driver_name`, `driver_contact`, `pickup_location`, `delivery_location`, `pickup_appointment`, `delivery_appointment`, `actual_arrival_at`, `actual_departure_at`, `base_rate`, `currency`, `status`, `notes`. Claim export columns (default): `claim_reference`, `shipment_reference`, `broker_or_customer_name`, `claim_category`, `description`, `requested_amount`, `accepted_amount`, `recovered_amount`, `currency`, `status`, `submission_deadline_at`, `submitted_at`, `created_at`. A column stored in a template that is no longer available is skipped defensively as an empty cell, so a stored template never breaks an export.

### Notification preferences (tenant-scoped)

Notification preferences already exist and are unchanged in v1.1.2: see the Notifications section above (`GET`/`PUT /notification-preferences`, per-member per-event `in_app`/`email` settings).

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

### Backups (tenant-scoped, v1.1.3)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/backups` | Yes + org header | Backup runs of the organization, newest first (25 per page). Requires `manage-security` (owner, admin). |
| POST | `/backups` | Yes + org header | Create a backup now. Returns the run row (201). An in-memory database or a missing `pg_dump` returns 422 with the reason instead of a fake success. Requires `manage-security`. |
| GET | `/backups/{backup}` | Yes + org header | One backup run. A run of another organization returns 404. Requires `manage-security`. |
| POST | `/backups/{backup}/verify` | Yes + org header | Re-verify the backup's checksums (database copy and every stored file against the manifest). Returns `{ "data": { "ok": bool, "mismatches": [...] } }`. Requires `manage-security`. |

Backups are deployment-wide: the whole database plus a copy of
every evidence and package file, with a `manifest.json` of SHA-256
checksums. A run is attributed to the organization that requested
it; scheduled runs (daily 02:00) have no organization and are not
listed here. Restores are deliberately not an API endpoint:
restoring replaces the live database, so it stays an operator
action (`backup:restore` on the server), which verifies every
manifest checksum first and refuses on any mismatch. See
docs/OPERATIONS.md for the runbook.

### Retention (tenant-scoped, v1.1.3)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/retention` | Yes + org header | Effective retention settings (config defaults merged with the organization's overrides), in days. Requires `manage-security`. |
| PUT | `/retention` | Yes + org header | Set per-organization retention overrides. Body: any of `audit_logs`, `notifications`, `job_executions`, `failed_documents`, `security_events`, each an integer 0 to 3650 (0 keeps forever) or null to clear the override and restore the default. Returns the effective settings. Requires `manage-security`. |

The weekly purge (`retention:purge`, Sundays 03:00) deletes
audit logs, notifications, failed generated documents, and
security events older than the cutoff, per organization, and
writes one `retention.purged` audit row per organization with the
counts. Job executions and unattributed security events are global
and are purged by the all-organizations run only. Ready claim
packages are never deleted by retention: only failed documents
are purged, with their stored object when one exists.

### Security (tenant-scoped, v1.1.3)

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/security/check` | Yes + org header | Production-readiness checks. Returns `{ "data": { "overall": "pass" \| "warn" \| "fail", "checks": [{ "id", "label", "status", "detail" }] } }`. The same ten checks as the `security:check` artisan command. Requires `manage-security`. |
| GET | `/security/events` | Yes + org header | Security events of the organization, newest first (50 per call, `limit` 1-100). Requires `manage-security`. |

Security events are failed logins (`login.failed`, with the email
in the metadata; the password is never stored) and authorization
denials (`authorization.denied`, with method and path). A failed
login has no organization and is only visible through the
all-organizations retention purge, not through this endpoint. See
docs/SECURITY.md and docs/INCIDENT-RESPONSE.md.

### Billing (tenant-scoped, v1.2.0)

Subscription billing against a mock provider (see docs/BILLING.md).
All routes require `manage-billing` (owner, admin, billing staff),
except the plan catalog, which any member may read, and the two
public routes at the bottom. The provider is the mock: checkout is
completed in-app, cards are the Stripe test numbers, and no real
money moves. Plan limits are shown but not enforced until v1.2.1.

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/billing/plans` | Yes + org header | The plan catalog: Free, Starter $19, Growth $49, Fleet $99, Agency $149 base + $12 per extra carrier, and the unlisted Success Fee plan. |
| GET | `/billing/subscription` | Yes + org header | The current subscription, with the current period's metered usage. A Free subscription is created lazily on first read. |
| POST | `/billing/checkout` | Yes + org header | Open a mock checkout session. Body: `plan` (required), `interval` (`monthly`/`annual`, default `monthly`), `extra_carriers` (0-90, Agency only) (201). Only from Free, Success Fee, or a trial; a past-due subscription must pay its invoice first. |
| POST | `/billing/checkout/{session}/complete` | Yes + org header | Complete the checkout: the mock "customer pays" step. Body: `payment_method` (nullable; required for a paid plan). A declined card leaves the session open for a retry. On success the subscription activates and a paid invoice is issued. |
| POST | `/billing/trials` | Yes + org header | Start the 30-day no-card Growth trial (201). Converts to paid Growth when a card is on file; otherwise lapses to Free. |
| POST | `/billing/subscription/change-plan` | Yes + org header | Change plan. Body: `plan` (required), `interval`, `extra_carriers`, `preview` (boolean). With `preview=true` returns the proration preview without changing anything. Upgrades apply immediately with proration (credit for unused days, charge for remaining days); downgrades are scheduled for the next renewal. |
| POST | `/billing/subscription/cancel` | Yes + org header | Cancel. Body: `immediate` (default false: cancel at period end). |
| POST | `/billing/subscription/resume` | Yes + org header | Undo a cancel-at-period-end. |
| GET | `/billing/invoices` | Yes + org header | Invoice history, newest first. Query: `page`, `per_page` (default 15). |
| GET | `/billing/invoices/{invoice}` | Yes + org header | One invoice with its lines. Another organization's invoice returns 404. |
| POST | `/billing/invoices/{invoice}/pay` | Yes + org header | Pay an open or past-due invoice with the stored method, or one supplied now. A paid past-due invoice returns the subscription to active. |
| POST | `/billing/payment-method` | Yes + org header | Store a dummy payment method. Body: `payment_method` (required; `pm_mock_visa` succeeds, `pm_mock_declined` declines). |
| POST | `/billing/portal` | Yes + org header | Open a mock billing portal session (201). Returns the portal URL (the frontend billing page) and a token that opens the summary at `GET /billing/portal/{token}`. |
| GET | `/billing/usage` | Yes + org header | The current period's metered usage: uninvoiced Success Fee records, amounts recovered, the accrued 15% fee, and the first-year $49/mo cap with what remains of it. |
| POST | `/billing/webhooks/simulate` | Yes + org header | Fire a mock webhook event through the normal pipeline: sign, verify, handle (201). Body: `type` (required), `data`. |
| POST | `/billing/webhooks` | Public, HMAC | Provider webhook endpoint. No Sanctum token: authenticity is verified by HMAC-SHA256 (`X-Mock-Signature: sha256=<hex>`) over the raw body. Unknown types are recorded as unprocessed; an exact redelivery is recorded as a duplicate and never applied twice. |
| GET | `/billing/portal/{token}` | Public | Billing summary for a portal token. The token in the URL is the bearer secret; unknown or expired tokens return 404. |

Invoice numbers are per-organization, per-year (`INV-2026-0001` for
each organization's first invoice of the year). The Success Fee plan
is metered: recording a payment on an accepted claim creates a usage
record (15% of the amount), and the renewal invoices all uninvoiced
records in arrears with the $49/mo first-year cap applied in the
billing code. Failed renewals go past due; `billing:dunning` retries
(+3 days, then +7 days) and downgrades to Free after 14 days past
due, never deleting data. See docs/BILLING.md.

### Marketing (public, v1.2.2)

Public endpoints for the marketing site (see docs/MARKETING.md).
No authentication; each is rate-limited per IP.

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/plans` | Public (30/min) | The listed plans from config/billing.php (Free, Starter $19, Growth $49, Fleet $99, Agency $149 base + $12 per extra carrier), plus the trial offer (30 days on Growth, no card). The unlisted Success Fee plan is not returned. |
| POST | `/leads` | Public (5/min) | Capture a lead from a marketing form. Body: `name` (required), `email` (required), `company`, `fleet_size`, `message`, `source` (`demo_request`, `pricing`, `getting_started`, default `demo_request`). Idempotent by email and source: a second submission within 90 days updates the existing lead and returns 200; otherwise 201. No IP address or user agent is stored. |
| POST | `/analytics/events` | Public (60/min) | Accept one anonymous marketing event. Body: `event` (`page_view`, `demo_request_submitted`, `trial_started`, `pricing_viewed`), `path`, `referrer`. Always 202: recording is best-effort. Events go to the driver in config/analytics.php (the Laravel log by default, or nowhere when the driver is `none`). |

### Email Intake (v1.3.0)

Organization-specific inbound email addresses and the manual
matching interface. See docs/EMAIL-INTAKE.md for the full
runbook (address format, webhook contract, HMAC scheme,
matching rules, provider setup as a future step). No real
provider is wired yet.

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/email-intake/addresses` | Yes + org header | The organization's inbound addresses, active first. Listing provisions the default address for organizations created before v1.3.0. Any member may list. |
| POST | `/email-intake/addresses` | Yes + org header | Create an additional address. Body: optional `label`. Requires `manage-email-intake` (owner, admin, operations manager, dispatcher). |
| POST | `/email-intake/addresses/{address}/regenerate` | Yes + org header | Replace the address with a fresh random local part; the old address stops working. Requires `manage-email-intake`. |
| POST | `/email-intake/addresses/{address}/deactivate` | Yes + org header | Deactivate the address. The last active address cannot be deactivated (422). Requires `manage-email-intake`. |
| GET | `/email-intake/emails` | Yes + org header | The processing history, newest first. Query: `status` (`received`, `processed`, `matched`, `unmatched`, `failed`, `dismissed`), `matched` (true/false), `page`, `per_page`. Any member may list. |
| GET | `/email-intake/emails/{email}` | Yes + org header | One email with its extracted references, attachments, and matched shipment. Any member may read. |
| POST | `/email-intake/emails/{email}/match` | Yes + org header | Manually match the email to a shipment. Body: `shipment_id`. Attachments become evidence on the shipment (category `other`, source email named in the description). A dismissed email cannot be matched (422). Requires `manage-email-intake`. |
| POST | `/email-intake/emails/{email}/unmatch` | Yes + org header | Return the email to unmatched. Body: optional `reason`. Evidence already filed stays filed. Requires `manage-email-intake`. |
| POST | `/email-intake/emails/{email}/dismiss` | Yes + org header | Dismiss the email after review. Body: `reason` (required). A matched email cannot be dismissed (422). Requires `manage-email-intake`. |
| POST | `/email-intake/inbound` | Public, HMAC (120/min) | Inbound email webhook. No Sanctum token: the provider signs the raw body with HMAC-SHA256 (`X-Email-Intake-Signature: sha256=<hex>`) using `EMAIL_INTAKE_WEBHOOK_SECRET`. Body: normalized JSON (`from`, `to`, `subject`, `text_body`, `html_body`, `message_id`, `attachments` as base64 with `filename` and `mime_type`). Unknown `to` returns 422 with a generic message. A repeated `message_id` for the same organization returns the stored email with `duplicate: true`. |

### Advanced Imports (v1.3.1)

Saved CSV mappings, import history with row-level error
correction, scheduled imports, import notifications, and
synchronization workflows over stored files. See
docs/IMPORTS.md for the full runbook (mapping rules,
duplicate detection, run statuses, schedule and sync
semantics). No live SFTP, URL, or provider source; no TMS
integration (that is v1.3.2). The shipment import preview
and confirm endpoints accept an optional `mapping_id`.

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/imports/mappings` | Yes + org header | Saved CSV mappings, defaults first. Any member may list. |
| POST | `/imports/mappings` | Yes + org header | Save a mapping. Body: `name`, `target` (`shipments`), `mapping` (exact header text to shipment field), `is_default`. Every field must be one a mapping may target; names are unique per organization. A new default unsets the previous default. Requires `manage-imports` (owner, admin, operations manager, dispatcher). |
| GET | `/imports/mappings/{mapping}` | Yes + org header | One saved mapping. Any member may read. |
| PUT | `/imports/mappings/{mapping}` | Yes + org header | Update a saved mapping. Requires `manage-imports`. |
| DELETE | `/imports/mappings/{mapping}` | Yes + org header | Delete a saved mapping. Runs, schedules, and sync workflows keep working with the link nulled. Requires `manage-imports`. |
| GET | `/imports/runs` | Yes + org header | Import history, newest first. Query: `status` (`pending`, `running`, `completed`, `partial`, `failed`), `source` (`upload`, `schedule`, `sync`), `page`, `per_page`. Any member may list. |
| GET | `/imports/runs/{run}` | Yes + org header | One import run with its rows. Any member may read. |
| GET | `/imports/runs/{run}/rows` | Yes + org header | Rows of one run, in file order. Query: `status` (`imported`, `skipped`, `duplicate`, `failed`, `corrected`), `page`, `per_page`. Any member may list. |
| POST | `/imports/runs/{run}/rows/{row}/correct` | Yes + org header | Correct one failed or duplicate row and retry it. Body: `data` (field values merged over the stored row, re-validated, reference re-checked). A valid row creates the shipment and becomes `corrected`; run counts refresh. An already imported row cannot be corrected (422). Requires `manage-imports`. |
| GET | `/imports/schedules` | Yes + org header | Scheduled imports, newest first. Any member may list. |
| POST | `/imports/schedules` | Yes + org header | Create a schedule with its source file (multipart: `name`, optional `import_mapping_id`, `file` CSV/TXT max 5120 KB, `frequency` `daily`/`weekly`/`manual`, optional `next_run_at`, `active`). Daily defaults to tomorrow, weekly to next week. Requires `manage-imports`. |
| GET | `/imports/schedules/{schedule}` | Yes + org header | One scheduled import. Any member may read. |
| PUT | `/imports/schedules/{schedule}` | Yes + org header | Update a schedule. A new file replaces the stored one. Setting the frequency to `manual` clears `next_run_at`. Requires `manage-imports`. |
| DELETE | `/imports/schedules/{schedule}` | Yes + org header | Delete a schedule and its stored file. Runs it produced stay in the history. Requires `manage-imports`. |
| POST | `/imports/schedules/{schedule}/run` | Yes + org header | Run a schedule now with its stored file and saved mapping. Returns the import run. Requires `manage-imports`. |
| GET | `/imports/sync-runs` | Yes + org header | Synchronization workflows, newest first. Any member may list. |
| POST | `/imports/sync-runs` | Yes + org header | Create a sync workflow with its source file (multipart: `name`, optional `import_mapping_id`, `file` CSV/TXT max 5120 KB). Requires `manage-imports`. |
| GET | `/imports/sync-runs/{syncRun}` | Yes + org header | One synchronization workflow. Any member may read. |
| POST | `/imports/sync-runs/{syncRun}/run` | Yes + org header | Run a sync workflow now. Moves it through `running` to `completed` or `failed` and returns it with the linked import run. Requires `manage-imports`. |
| DELETE | `/imports/sync-runs/{syncRun}` | Yes + org header | Delete a sync workflow and its stored file. Runs it produced stay in the history. Requires `manage-imports`. |

### TMS Integrations (v1.3.2)

A generic TMS provider adapter with a mock provider and
Trimble TMW.Suite modelled schema-only. See
docs/TMS-INTEGRATIONS.md for the full runbook (provider
catalogue, credential handling, sync kinds, monitoring).
No live TMS is contacted: the mock syncs three fixed
dummy loads, and the Trimble provider fails every fetch
with a live-unavailable message.

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/tms/providers` | Yes + org header | Provider catalogue: label, live availability, capabilities, credential fields. Any member may read. |
| GET | `/tms/integrations` | Yes + org header | TMS integrations, newest first. Any member may list. Credentials are never returned. |
| POST | `/tms/integrations` | Yes + org header | Create an integration. Body: `provider` (`mock`, `trimble-tmw`), `name`, optional `credentials`, optional `settings` (sync kinds, `auto_sync`, `sync_interval_hours` 1-168). Trimble needs `client_id`, `client_secret`, `account_id`. Requires `manage-tms-integrations` (owner, admin, operations manager, dispatcher). |
| GET | `/tms/integrations/{integration}` | Yes + org header | One TMS integration. Any member may read. |
| PUT | `/tms/integrations/{integration}` | Yes + org header | Update an integration. Credentials are only replaced when supplied; settings merge over the stored ones. Requires `manage-tms-integrations`. |
| DELETE | `/tms/integrations/{integration}` | Yes + org header | Delete an integration. Its sync runs go with it; shipments and evidence created by syncs stay. Requires `manage-tms-integrations`. |
| POST | `/tms/integrations/{integration}/test` | Yes + org header | Test the connection without syncing. The mock always succeeds; Trimble succeeds when the credentials are well-formed. Both report `live_available: false`. Requires `manage-tms-integrations`. |
| POST | `/tms/integrations/{integration}/sync` | Yes + org header | Run a sync now. Body: optional `kind` (`shipments`, `status`, `appointments`, `documents`, `full`). Returns the recorded sync run. A run over a schema-only provider fails with the live-unavailable message. Requires `manage-tms-integrations`. |
| GET | `/tms/sync-runs` | Yes + org header | TMS sync runs, newest first. Query: `tms_integration_id`, `kind`, `status`. Any member may list. |
| GET | `/tms/sync-runs/{syncRun}` | Yes + org header | One TMS sync run. Any member may read. |

### Communication Integrations (v1.3.3)

Slack and Microsoft Teams channels per organization, plus
driver evidence submission links and the email workflows
around them. See docs/COMMUNICATION-INTEGRATIONS.md for
the full runbook (provider catalogue, webhook handling,
link security model, mock/log delivery). No live Slack
workspace, Teams tenant, or real mailer is contacted:
the mock records deliveries locally, and Slack and Teams
record a well-formed payload without sending it.

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/comms/providers` | Yes + org header | Provider catalogue: label, live availability, webhook URL requirement and hint. Any member may read. |
| GET | `/comms/channels` | Yes + org header | Communication channels, newest first. Any member may list. Webhook URLs are never returned. |
| POST | `/comms/channels` | Yes + org header | Create a channel. Body: `provider` (`mock`, `slack`, `teams`), `name` (max 100), optional `webhook_url`, optional `settings` (one boolean per event; unset events default to on). Slack and Teams need a well-formed webhook URL. Requires `manage-communications` (owner, admin, operations manager, dispatcher). |
| GET | `/comms/channels/{channel}` | Yes + org header | One communication channel. Any member may read. |
| PUT | `/comms/channels/{channel}` | Yes + org header | Update a channel. Webhook URL is only replaced when supplied; settings merge over the stored ones. Requires `manage-communications`. |
| DELETE | `/comms/channels/{channel}` | Yes + org header | Delete a channel. Its deliveries go with it. Requires `manage-communications`. |
| POST | `/comms/channels/{channel}/test` | Yes + org header | Test the channel without sending live. Records a test delivery and returns `ok`, `live_available: false`, `message`, `delivery_id`. A disabled channel is refused with 422. Requires `manage-communications`. |
| GET | `/comms/deliveries` | Yes + org header | Communication deliveries, newest first. Query: `communication_channel_id`, `event`, `status`. Any member may list. |
| GET | `/comms/deliveries/{delivery}` | Yes + org header | One communication delivery. Any member may read. |
| GET | `/comms/driver-links` | Yes + org header | Driver evidence links, newest first. Query: `shipment_id`, `claim_id`, `state`. Any member may list. The token is never returned. |
| POST | `/comms/driver-links` | Yes + org header | Create a driver link. Body: `shipment_id` and/or `claim_id` (at least one, both must belong to the org), optional `driver_name`, `driver_email`, `ttl_hours` 1-720 (default 72), `max_uses` 1-10 (default 1). Returns the link plus `token` and `submit_url` once. Requires `manage-communications`. |
| GET | `/comms/driver-links/{driverLink}` | Yes + org header | One driver evidence link. Any member may read. |
| DELETE | `/comms/driver-links/{driverLink}` | Yes + org header | Revoke a driver link immediately and permanently. Requires `manage-communications`. |
| POST | `/comms/driver-links/{driverLink}/resend` | Yes + org header | Resend a driver link. Rotates the token: the old URL dies, a fresh one is returned once. The link must be usable and have a driver email address, or the resend is refused with 422. Requires `manage-communications`. |
| GET | `/comms/driver-links/{driverLink}/submissions` | Yes + org header | Submissions for a driver link, newest first. Any member may list. |
| GET | `/driver-evidence/{token}` | Public, token in URL | Driver evidence page for a link token. Shows only the scoped shipment/claim, expiry, and use count. Unknown, expired, used-up, or revoked tokens all return 404 with the same message. Throttled. |
| POST | `/driver-evidence/{token}/submit` | Public, token in URL | Submit evidence through a link (multipart: `document_category`, `file` max 20 MB, optional `driver_name`, `notes`). The file is verified by checksum and size before it is accepted. A single-use link stops working after this submit. Throttled. |

### Financial Reconciliation (v1.5.0)

The claim payment ledger: what the organization says it
received, per claim, with outstanding balances and
reconciliation reports. See docs/FINANCIAL-RECONCILIATION.md
for the full runbook (the outstanding rule, discrepancies,
voiding). Nothing here moves real money: billing runs on a
mock provider, so a ledger payment is a record, not a
verified transfer. Partial payments are normal; a claim
moves to Paid only when the paid total reaches the amount
due.

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/reconciliation/summary` | Yes + org header | Organization totals: requested, approved, paid, outstanding, counts by balance status. Any member may read. |
| GET | `/reconciliation/ledger` | Yes + org header | Ledger payments, newest first. Query: `claim_id`, `payment_method`, `from`, `to`. Any member may list. |
| GET | `/reconciliation/ledger/{payment}` | Yes + org header | One ledger payment. Any member may read. |
| POST | `/reconciliation/claims/{claim}/payments` | Yes + org header | Record a payment. Body: `amount` (min 0.01), `payment_method` (`wire_transfer`, `ach`, `cheque`, `cash`, `card`, `other`), `paid_at` (not in the future), optional `payment_reference`, `notes`. Returns the payment plus the updated balance. Requires `manage-reconciliation` (owner, admin, operations manager, dispatcher). |
| DELETE | `/reconciliation/ledger/{payment}` | Yes + org header | Void a payment. Refused with 422 on a paid or closed claim. Requires `manage-reconciliation`. |
| GET | `/reconciliation/claims/{claim}` | Yes + org header | One claim balance with payment history. Any member may read. |
| GET | `/reconciliation/reports` | Yes + org header | Generated reports, newest first. Any member may list. |
| POST | `/reconciliation/reports` | Yes + org header | Generate a report for a period (both dates default to the current month). Requires `manage-reconciliation`. |
| GET | `/reconciliation/reports/{report}` | Yes + org header | One generated report. Any member may read. |

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
