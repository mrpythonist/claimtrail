# Email Intake

Version 1.3.0. Each organization gets its own inbound email
addresses. Mail forwarded to one arrives through a signed
webhook as a normalized JSON payload, its attachments are filed
as evidence, and the email is matched to the shipment its
references name. No real email provider is wired yet: the
webhook accepts the normalized payload, but nothing delivers
real mail to it. Wiring a provider is a future step, described
at the end of this document.

## Addresses

An address looks like `{org-slug}-{random6}@{domain}`. The
domain comes from `config/email_intake.php` (`EMAIL_INTAKE_DOMAIN`,
default `inbound.claimtrail.local`, a placeholder that resolves
nowhere). The six random characters keep the local part unique
even when two organizations share a slug stem; `local_part` is
unique across all organizations.

Provisioning: an organization gets its default address when it
is created (`OrganizationController` calls
`EmailIntakeService::provisionDefaultAddress`). Organizations
created before v1.3.0 have none, so the address list endpoint
calls `ensureDefaultAddress`, which creates one when the
organization has no active address. Both paths use the same
generator, so the shape is identical either way.

Endpoints (tenant-scoped, under the `organization` middleware):

- `GET /email-intake/addresses`: any member may list.
- `POST /email-intake/addresses`: create an additional address.
  Body: optional `label`. Needs `manage-email-intake`.
- `POST /email-intake/addresses/{address}/regenerate`: replace
  the local part with a fresh random one. The old address stops
  working immediately. Needs `manage-email-intake`.
- `POST /email-intake/addresses/{address}/deactivate`: needs
  `manage-email-intake`. The last active address cannot be
  deactivated (422), so an organization can never lose its inbox.
  The row stays for history.

The `manage-email-intake` permission is mapped to Owner, Admin,
Operations Manager, and Dispatcher. The Owner gets all
permissions already.

## The inbound webhook

`POST /api/v1/email-intake/inbound` is public: the provider is
not signed in. Authentication is the HMAC-SHA256 signature over
the raw request body, sent as the `X-Email-Intake-Signature`
header in the form `sha256=<hex>`, verified against
`EMAIL_INTAKE_WEBHOOK_SECRET` (`config/email_intake.php`). A
missing or wrong signature returns 401. An empty secret never
verifies: with no provider configured, no delivery is accepted.
The endpoint is rate-limited to 120 requests per minute with
its own throttle prefix, so inbound mail cannot share a bucket
with the marketing endpoints.

The payload is the normalized JSON an inbound parse provider
sends. Example:

```json
{
  "from": "broker@example.com",
  "to": "acme-abc123@inbound.claimtrail.local",
  "subject": "Detention paperwork for PLT-1001",
  "text_body": "Please find the detention proof for load PLT-1001 attached.",
  "html_body": "<p>Please find the detention proof attached.</p>",
  "message_id": "<msg-1@example.com>",
  "attachments": [
    {
      "filename": "detention-proof.pdf",
      "mime_type": "application/pdf",
      "content_base64": "JVBERi0xLjQK..."
    }
  ]
}
```

Required fields: `from`, `to`, `message_id`. `subject`,
`text_body`, and `html_body` are optional. `attachments` is
optional, at most 20 entries, each with `filename`,
`mime_type`, and `content_base64`.

Processing, in order:

1. The signature is verified against the raw body.
2. The payload is validated. An attachment that is not valid
   base64, or that breaks the evidence limits, rejects the
   whole delivery with 422 before anything is stored.
3. The `to` address is resolved to an active inbound address.
   An unknown or inactive address returns 422 with the generic
   message "The recipient address could not be accepted." The
   response never says whether an address exists.
4. A repeated `message_id` for the same organization is a
   redelivery: the stored email is returned with
   `duplicate: true` and is not processed twice. `message_id`
   is unique per organization, not globally.
5. The email row is stored with status `received`, the
   attachments are decoded onto the private disk, and the
   references are extracted and matched synchronously. A queued
   job, `ProcessInboundEmail`, wraps the same processor for
   reprocessing a received or failed email.

The response is 201 with `{id, status, matched_shipment_id,
duplicate: false}`, or 200 with `duplicate: true` for a
redelivery.

## Attachment extraction

Attachments use the same limits as evidence uploads
(`config/evidence.php`): at most 20480 KB, and only the
extensions pdf, jpg, jpeg, png, webp, doc, docx, xls, xlsx,
csv, txt. The MIME type is checked against the real content
for the extension, not just the filename, so a renamed
executable does not pass as a PDF.

Each attachment becomes an `inbound_email_attachments` row
(filename, MIME type, size, storage path on the private disk,
SHA-256 checksum) and is stored under
`email-intake/{organization_id}/{email_id}/{uuid}.{ext}`.

When the email is matched to a shipment, each attachment
becomes an `EvidenceFile` row on that shipment. The stored
object is reused, never copied: the evidence row points at the
same storage path. The category is `other` with a description
naming the source email ("Received by email from {from} on
{date}, message {message_id}."). There is no email category in
`EvidenceDocumentCategory` (bill_of_lading, proof_of_delivery,
rate_confirmation, arrival_departure_record,
appointment_confirmation, broker_communication, driver_photo,
receipt, lumper_receipt, other), and inventing one would change
the evidence vocabulary; `other` with the source in the
description is the mapping.

Plan limits apply: a delivery whose attachments would take the
organization past its storage cap is refused with 402 before
anything is stored, the same check evidence uploads use.

## Shipment reference extraction

`ShipmentReferenceExtractor` pulls candidate references from
the subject, the text body, and the attachment filenames. A
candidate is a token shaped like a reference (two or more
letters, then hyphen or underscore separated groups of letters
and digits, for example `PLT-1001`), and any of the
organization's own stored references appearing verbatim in the
combined text is reported too.

A match is exact: the candidate equals the shipment's
`internal_reference` or `external_load_reference`,
case-insensitive. Nothing is guessed from a broker name or a
date. The extracted candidates are stored on the email as JSON,
each with its source and the shipment it matched, if any, so
the detail page shows what was found and what matched.

## Automatic shipment matching

- Exactly one distinct shipment matches: the email is
  auto-matched (status `matched`, `match_reason` "exact
  reference match", `match_confidence` "high"), its attachments
  become evidence on that shipment, and an
  `email_intake.auto_matched` audit row is written.
- Zero matches: status `unmatched`, `match_reason` "no shipment
  reference found", confidence "none".
- Several shipments match: status `unmatched`, `match_reason`
  "multiple shipments match the extracted references",
  confidence "low". A person decides which shipment it belongs
  to.

Email bodies are never written to the audit log; only the
status change and the matched shipment are.

## Manual matching

Endpoints (tenant-scoped):

- `GET /email-intake/emails`: the processing history, newest
  first. Filters: `status`, `matched` (true/false). Paginated
  (default 25 per page). Any member may list.
- `GET /email-intake/emails/{email}`: one email with its
  extracted references, attachments, and matched shipment. Any
  member may read.
- `POST /email-intake/emails/{email}/match`: body
  `shipment_id`. Links the email and files its attachments as
  evidence. Needs `manage-email-intake`. A dismissed email
  cannot be matched (422).
- `POST /email-intake/emails/{email}/unmatch`: returns the
  email to unmatched, with an optional `reason`. Evidence
  already filed on the old shipment stays filed. Needs
  `manage-email-intake`.
- `POST /email-intake/emails/{email}/dismiss`: body `reason`
  (required). Marks the email reviewed and set aside. A matched
  email cannot be dismissed; unmatch it first (422). Needs
  `manage-email-intake`.

The app pages are `/email-intake` (addresses, status filter,
history) and `/email-intake/[id]` (message, references,
attachments, shipment search, match, unmatch, dismiss), linked
from the sidebar for every member; the management actions are
gated on `canManageEmailIntake`.

Audit events: `email_intake.received` (at ingest),
`email_intake.auto_matched`, `email_intake.unmatched`,
`email_intake.manually_matched`, `email_intake.dismissed`,
`email_intake.failed` (processing threw; the error is kept in
`processing_notes`).

Operator command: `php artisan email-intake:list
[--status=all] [--organization=<uuid or slug>] [--limit=20]`
lists inbound emails across organizations, newest first.

## What is not built yet

- No real inbound provider is wired. No SendGrid, Mailgun, or
  SES client exists, no provider credentials are stored, and no
  real mail is delivered. To wire one: set
  `EMAIL_INTAKE_DOMAIN` to the real inbound domain, set
  `EMAIL_INTAKE_WEBHOOK_SECRET` to the shared secret, and point
  the provider's inbound parse webhook at
  `POST /api/v1/email-intake/inbound` with the normalized JSON
  payload above and the `X-Email-Intake-Signature` header. The
  provider must send the raw body unchanged, or the signature
  will not verify.
- No OCR. Text is read from the subject, the text body, and
  attachment filenames only; the contents of a scanned PDF or
  photo are not read. That is v2.0.1.
- No mailer change. Outbound mail still uses the log driver;
  intake is inbound only.
- The webhook processes synchronously. A large attachment or a
  slow disk delays the provider's response; the queued job
  exists for reprocessing, not as the primary path.
