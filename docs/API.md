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
| GET | `/dashboard` | Yes + org header | Organization overview: member counts, pending invitations, current role, member since, plus shipment, event, and evidence counts. No claim or financial figures exist yet. |

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
| DELETE | `/evidence/{evidence}` | Yes + org header | Delete the record and the stored file. Requires `delete-evidence`. The deletion is written to the application log; the full audit trail arrives in Milestone 4. |

Document categories: `bill_of_lading`, `proof_of_delivery`,
`rate_confirmation`, `arrival_departure_record`,
`appointment_confirmation`, `broker_communication`, `driver_photo`,
`receipt`, `lumper_receipt`, `other`.

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
