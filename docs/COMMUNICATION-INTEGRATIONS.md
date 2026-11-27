# Communication Integrations

Slack and Microsoft Teams channels per organization, plus
driver evidence submission links and the email workflows
around them. See docs/API.md for the endpoint table and
docs/openapi.yaml for the machine-readable contract (tag
`Communications`, 17 operations).

## The honest state of this feature

No live Slack workspace, no live Teams tenant, and no real
mailer exist on this verification machine, and no live
delivery is claimed anywhere in v1.3.3.

- **Channels.** The mock provider records deliveries
  locally so the whole flow can be verified without a
  workspace. Slack and Teams validate the webhook URL
  shape and build the vendor's published payload shape
  (Slack Block Kit, Teams MessageCard), then record it
  as a `logged` delivery without sending it anywhere.
  Every provider reports `live_available: false`, on
  the catalogue, on the channel, and on every test.
- **Email.** Driver-link emails and notification emails
  go through Laravel mail. With no real mailer
  configured, verification is through `Mail::fake()` in
  tests and the log driver in smoke runs. A failed send
  is caught, logged, and notified (`comms.email_failed`);
  it never breaks the link, which stays usable.
- **Notifications.** In-app notification types exist for
  the communication events (see below). Email delivery
  of notifications follows the existing per-user
  preference and the existing queue; nothing new is
  sent outside the app without a mailer.

What a live workspace, tenant, or mailer still needs:
a real Slack incoming webhook URL (created in a Slack
workspace), a real Teams Office 365 connector webhook
URL (created in a Teams channel), a configured mailer
(SMTP or a transactional provider), and then flipping
the provider's `live_available` flag and wiring the
dispatcher to actually POST the payload. Until then
the app says, everywhere it matters, that delivery is
mock/log only.

## Providers

- **Mock Channel** — no webhook URL, no credentials.
  Every event records a `logged` delivery with the
  payload attached, so tests, smoke runs, and the UI
  can show the flow end to end.
- **Slack** — needs an incoming webhook URL starting
  with `https://hooks.slack.com/services/`. The payload
  is a Block Kit message: the title and body as text,
  plus a section block carrying the body. A malformed
  URL is refused at create/update time; a well-formed
  one passes the test with a live-unavailable note.
- **Microsoft Teams** — needs an Office 365 connector
  webhook URL starting with
  `https://outlook.office.com/webhook/` or
  `https://outlook.office365.com/webhook/`. The payload
  is a MessageCard with a summary and a facts section.
  Same validation and same live-unavailable note as
  Slack.

Webhook URLs are stored encrypted (`Crypt::encryptString`)
and are never returned by the API: the channel resource
carries no webhook field at all, and the create/update
responses omit it. The dispatcher validates the stored
URL again at send time; a stored URL that no longer
validates records a `failed` delivery instead of sending.

## Channels

A channel belongs to one organization and subscribes to
a subset of five events, stored as a settings object
(one boolean per event; unset events default to on):

- `claim_created` — a claim was created.
- `evidence_received` — evidence was attached to a
  shipment or claim (including through a driver link).
- `driver_link_used` — a driver submitted evidence
  through a link.
- `sync_completed` — a TMS sync run finished.
- `delivery_failed` — a channel delivery failed.

Creating, updating, deleting, and testing a channel
needs the `manage-communications` permission (owner,
admin, operations manager, dispatcher). Any member may
list channels and deliveries and read one of each.

Sending goes through one dispatcher
(`App\Comms\ChannelDispatcher`): for each active channel
that wants the event, it resolves the provider, builds
the payload, and records a delivery (`logged`, `sent`,
or `failed`) with its title, body, detail, and
payload. A disabled channel is never sent to, and a
send failure updates the channel's `last_error` and
notifies the organization (`comms` notifications,
below). Testing a channel (`POST
/comms/channels/{channel}/test`) records a test
delivery with event `test` and returns the same result
shape as a real send, plus the delivery id. A disabled
channel cannot be tested: it is refused with 422, like
a disabled TMS integration that fails a sync fast, and
no test delivery is recorded.

The dispatcher is called from the domain, not from the
controller: claim creation, evidence storage, driver
submission, TMS sync completion, and delivery failure
each fan out through `CommunicationService::sendEvent`.
That keeps one code path for every event, and means a
future live provider only changes the provider layer.

## Driver evidence submission links

A link lets a driver submit evidence for one shipment
or claim without an account. The link is a 48-character
random token, shown to the driver as
`/driver-evidence/{token}` on the frontend.

### Security model

- **Token storage.** Only the SHA-256 hash is stored
  (`token_hash`, unique). The raw token is returned
  once, at creation or at resend, and never again: not
  in list, not in show, not in logs. A resend rotates
  the token, so the old URL dies immediately. Resending
  needs a usable link with a driver email address;
  without one the resend is refused with 422.
- **Scope.** A link needs a shipment, a claim, or both,
  and both must belong to the organization. A
  shipment+claim pair must match: the claim must
  belong to the shipment. Archived shipments are
  refused. Evidence from a claim-only link is filed
  against the claim's shipment: every claim belongs
  to a shipment, so a claim-scoped link accepts
  submissions too. The public page shows only the organization
  name, the shipment reference, the claim reference,
  the driver name, the expiry, and the uses left.
  Nothing else in the org is reachable through the
  link.
- **Expiry.** Links expire after `ttl_hours` (1-720,
  default 72). Expired links are detected on read, and
  the daily `comms:expire-links` command (05:30)
  notifies the organization once per expired link
  (`comms.driver_link_expired`). Links expired more
  than 30 days ago are not notified: they are old news.
- **Uses.** Links are single-use by default
  (`max_uses` 1-10). Each successful submission
  increments `used_count`; a used-up link refuses
  further submits.
- **Revocation.** `DELETE /comms/driver-links/{id}`
  revokes immediately and permanently. The link record
  stays for audit, with `revoked_at` set.
- **No oracle.** An unknown, expired, used-up, or
  revoked token returns the same 404 and the same
  message as a token that never existed, so the token
  alone reveals nothing. The public endpoints are
  throttled (30/min for the page, 10/min for submits).
- **Upload verification.** The submitted file is
  stored on the evidence disk under
  `driver-evidence/{org}/{shipment}/{uuid}.{ext}` and
  verified by existence, checksum (SHA-256), and size
  before it is accepted, following the evidence
  upload pattern. Allowed types: PDF, images, Office
  documents, CSV, text, max 20 MB. The evidence file is created
  with `uploaded_by` null (no user exists for a
  driver) and the submission row links it to the link.

### Email

When a link is created or resent with a `driver_email`,
the driver gets an email with the submit URL
(`DriverEvidenceLinkMail`, queued). If sending fails,
the failure is caught: the link stays usable, the
error is logged, and the organization is notified
(`comms.email_failed`). A successful send notifies
(`comms.email_sent`).

## Email workflows

- **Driver link sent** — the email above, on create
  and on resend.
- **Evidence received via a link** — the organization
  is notified in-app (`comms.driver_link_used`) and
  the subscribed channels get an `evidence_received`
  event. No email is sent to the driver: the submit
  page confirms receipt on screen.
- **Channel delivery failure** — the organization is
  notified in-app, and the subscribed channels get a
  `delivery_failed` event (a failing channel does not
  get its own failure event: the dispatcher skips the
  channel that just failed).

## Communication notifications

Seven new notification types, following the existing
pattern (actor excluded where the app already does
that; per-run unique dedupe keys for channel tests and
email sends, so repeats notify again):

- `comms.channel_connected` — a channel was created.
- `comms.channel_tested` — a channel was tested.
- `comms.driver_link_created` — a driver link was created.
- `comms.driver_link_used` — a driver submitted
  evidence through a link.
- `comms.driver_link_expired` — a link expired (once
  per link, via the daily command).
- `comms.email_sent` — a driver-link email was sent.
- `comms.email_failed` — a driver-link email failed
  to send.

All seven appear in the notification preferences list
(22 types total in v1.3.3) and follow the existing
per-user in-app/email preference.

## CLI

- `comms:list` — lists channels per organization
  (mirror of `tms:list`).
- `comms:test {channel}` — tests one channel by id
  and prints the result.
- `comms:expire-links` — notifies once per expired
  link. Scheduled daily at 05:30.

## Frontend

- `/communications` — providers, connect-a-channel
  form, channels, create-a-driver-link form (the token
  and submit URL are shown once, in an alert), driver
  links, recent deliveries.
- `/communications/{id}` — one channel: test, enable/
  disable, delete, event subscriptions, delivery
  history.
- `/driver-evidence/{token}` — the public submit page.
  No auth, no account. Shows the scoped shipment or
  claim, the expiry, and the remaining uses; the same
  invalid message for every dead token.

## What is not in v1.3.3

- No live Slack or Teams delivery. No real mailer.
  Both are mock/log only until a workspace, a tenant,
  and a mailer exist (see above).
- No Slack OAuth app or Teams bot: incoming webhooks
  only. Interactive buttons, slash commands, and
  threaded replies are not modelled.
- No per-driver identity: the driver name on a
  submission is self-reported text, not an account.
- No reprocess endpoint for failed deliveries, and no
  retry queue: a failed delivery is recorded and
  notified, not retried.
- No plan metering on links or deliveries.
