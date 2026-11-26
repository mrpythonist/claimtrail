# ClaimTrail help documentation

Source for the public help page (`/help`) and for the in-app
support workflow. The public page carries the same content in
shorter form; this document is the full version the operator
uses when answering tickets.

## Getting started

Register, create an organization, and work through the seven
first steps: organization, member, shipment, event, evidence,
claim, and submission. The full walkthrough is on the getting
started page (`/getting-started`); the demo page (`/demo`)
shows a complete detention claim from shipment to package.

## Shipments, events, and claims

- A **shipment** is one load: broker, carrier, pickup and
  delivery appointments.
- An **event** is something chargeable that happened on that
  load, for example detention at a dock. Events are what claims
  are built from. One claim links to at most one event.
- A **claim** moves through ten statuses from draft to paid.
  A claim can only be submitted when its evidence requirements
  are met; the readiness check on the claim page says exactly
  what is still missing.
- Known gaps (from the v0.3.0 workflow validation): the app has
  no submission channel (submitted is a status the member sets
  by hand; the PDF package is never sent), no GPS or ELD time
  capture, and the deadline clock runs from claim creation, not
  delivery. Help answers must not promise any of these.

## Evidence

Upload files as evidence for a claim: rate confirmations,
invoices, photos, timestamps. Files are stored privately and
checked by SHA-256 checksum on download. Files are not scanned
for malware. A ready claim package is a PDF built from the
claim record; it is the document the customer sends to the
broker. ClaimTrail does not submit claims.

## Deadlines

Deadlines come from the organization's settings: how long after
delivery a claim must be filed, and how long after that it must
be followed up. The deadlines page lists what is due soon and
what is overdue. Deadlines are recomputed hourly. When the
organization configures no deadline setting, there is no
deadline at all.

## Plans and limits

Free 5 claims a month, Starter $19 for 20, Growth $49 for 100,
Fleet $99 for 300, Agency $149 a month for up to 10 carriers at
100 claims per carrier. Member, storage, PDF package, and report
limits differ by plan; the billing page shows the organization's
usage against its limits. When a limit is hit, the action is
refused with a 402 naming the cheapest plan that raises it.
Claims, members, and files already created are never deleted by
a limit or a downgrade. Shipments are not capped.

## Billing, cancellation, and refunds

- Monthly billing is charged at the start of the period; annual
  billing is ten times the monthly price (two months free).
- Upgrades apply immediately with a prorated credit; downgrades
  apply at the end of the current period.
- The 30-day Growth trial needs no card. It converts to paid
  Growth only when a payment method is added; otherwise the
  organization returns to Free and nothing is deleted.
- The success-fee plan is $0 a month plus 15% of amounts recorded
  as paid against claims created in ClaimTrail, within 12 months
  of the claim's creation, net of refunds, capped at $49 a month
  for the first year. It is offered at the point where price
  blocks a trial buyer, not as a default.
- **Cancellation:** from the billing page (cancel moves the
  organization to Free at the end of the current period; the
  data stays) or by support ticket. The operator can also cancel
  from the subscription administration view (`billing:admin`).
- **Refund:** the customer opens a support ticket within 14 days
  of the charge, stating the invoice number and the reason. The
  operator reviews it and, where the provider supports it,
  refunds to the original payment method. Refunds are issued for
  the unused part of a period after a duplicate charge or a
  billing error; a change of mind after the service was used is
  not refunded. The operator records the refund with
  `POST /billing/invoices/{id}/refund` (full refund when no
  amount is given; partial refund when an amount in cents is
  given). A full refund voids the invoice. Every refund is an
  audit row and a billing event.
- Billing currently runs on a mock provider. No real money moves;
  the process above is the process that will run against Stripe
  once it is connected.

## Roles and permissions

Six roles: owner, admin, operations manager, dispatcher, billing
staff, viewer. Only owner and admin manage members, security,
operations, and support tickets. Billing staff manage billing
but not claims. Viewers can read reports but cannot change
anything. The full matrix is in `docs/API.md` (permissions
section) and `config/permissions.php`.

## Support tickets

Any member may open a ticket from Support in the sidebar and
reply on it. Owner and admin triage: they change statuses and
write internal notes, which only they see. A staff reply moves
an open or resolved ticket to in progress. A customer reply on
a resolved ticket reopens it to open. A closed ticket takes no
more messages. Ticket bodies and messages are never written to
the audit log; status changes are audit rows without the message
text. The operator works tickets across organizations with
`support:list` and `support:show`.

## Your data

Customer data stays inside the customer's organization. Audit
logs are kept 365 days, notifications 90 days, security events
180 days. There is no self-service account deletion yet; access,
correction, and deletion requests go through a support ticket
and are handled manually by the operator. See the privacy policy
(`/privacy`), the terms (`/terms`), and
`docs/DATA-PROCESSING.md`.

## Operations

- Backups run daily at 02:00 and are verified weekly
  (`backup:verify`, Sundays 04:00). The operations page shows
  the latest backup and its verification result.
- Monitoring runs hourly (`monitoring:check`) and the operations
  page shows the latest run: health checks, failed job count,
  queue depth. A failing check writes a warning to the log;
  there is no alerting channel yet.
- The security page runs ten production-readiness checks
  (`security:check`) and lists security events.

## What ClaimTrail does not do

ClaimTrail does not guarantee that any charge is recoverable,
does not capture GPS or ELD times, does not submit claims to
brokers, and does not give legal or contractual advice. The
customer is responsible for verifying contractual eligibility,
submission requirements, and deadlines with their brokers and
customers.
