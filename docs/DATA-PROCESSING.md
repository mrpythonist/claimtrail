# ClaimTrail data processing documentation

**Status: internal documentation of actual practice, reviewed
2026-11-26 (v1.2.3). This is NOT legal advice and NOT a substitute
for a lawyer's review. It describes what the application does
today; it does not certify compliance with any law.**

## Roles

- **ClaimTrail (the operator)** processes customer data on behalf
  of the customer organization that enters it.
- **The customer organization** decides what shipment, carrier,
  claim, and evidence data it puts into the service, and which of
  its members may see it.

## What is processed

| Category | Examples | Purpose |
| --- | --- | --- |
| Account data | Name, email, password hash, email verification status | Sign-in, account administration, notifications. |
| Organization data | Organization name, settings (currency, date format, claim reference prefix, retention overrides) | Operating the customer's workspace. |
| Membership data | Member names and emails, roles, invitation emails | Access control; six roles from owner to viewer. |
| Carrier data | Carrier name, contact name, email, phone, DOT number, notes | Identifying the carrier a shipment or claim belongs to. |
| Shipment and claim data | Shipment references, dates, amounts, events, claim statuses, deadlines, review notes | The core service: tracking additional charges and the evidence behind them. |
| Uploaded evidence | Files uploaded by members (invoices, rate confirmations, photos, timestamps) | Supporting a claim. Files are stored privately and served only after an authorization check. |
| Generated documents | Claim packages (PDF) built from the claim record | The record of a submitted claim. |
| Billing data | Plan, subscription status, period, trial, invoices, usage records, payment method brand and last four digits | Charging for the service. No card numbers are stored. |
| Support data | Ticket subject, category, priority, messages, internal notes | Answering support requests. |
| Marketing leads | Name, email, company, fleet size, message, source | Following up on demo requests. No IP address or user agent is stored. |
| Operational records | Audit log entries, security events (failed logins, denied access), notifications, job executions | Security, troubleshooting, and accountability. Ticket bodies and messages are not copied into the audit log. |

## What is NOT processed

- No IP addresses or user agents are stored for marketing leads
  or analytics events.
- No payment card numbers are stored. The billing provider is a
  mock in the current deployment; when real Stripe billing is
  connected, card data is handled by Stripe, not by ClaimTrail.
- No location data is collected. The app has no GPS or ELD
  capture (a known product gap, not a privacy feature).
- No data is sold, and no data is used for advertising.

## Subprocessors

**None in the current deployment.** Billing is a mock provider
that runs inside the application, the mailer defaults to `log`
(no email leaves the server unless a real mailer is configured),
and analytics is log-only. Before any real provider is
connected (Stripe, a mail provider, an analytics provider), this
document and the privacy policy must be updated to name it, and
a data processing agreement must be in place where required.

## Where data lives

On the operator's server: the application database, a private
file disk for evidence and generated documents, and a private
backup disk holding full database copies plus stored files with
a SHA-256 manifest. There is no application-level encryption of
stored files or backups; protection is the server disk and
access control. `config/backup.php` sets `keep_days` to 30, but
no command enforces it, so backup runs accumulate until the
operator deletes them.

## Retention

Defaults, overridable per organization through the retention
settings: audit logs 365 days, notifications 90 days, job
executions 30 days, failed documents 30 days, security events
180 days. Ready claim packages are never deleted by retention.
Shipments, claims, carriers, and evidence have no automatic
expiry; they are kept until the organization deletes them or
asks for deletion.

## Access and security

- Every record belongs to one organization; queries are scoped
  to that organization, and cross-organization access is refused.
- Six roles control who may read, create, change, or delete
  records. Billing, security, support, and customization have
  their own permissions.
- Passwords are stored as bcrypt hashes. API tokens currently
  never expire (a known gap); access tokens are stored in the
  browser's local storage.
- Uploaded files are checked by SHA-256 checksum on download.
  Files are not scanned for malware (a known gap).
- The service sets CSP and HSTS headers and uses secure session
  cookies in production.

## Deletion

There is no self-service account or organization deletion yet,
and carriers cannot be deleted from the app. To request access,
correction, or deletion of data, open a support ticket from the
app; the operator handles the request manually. Deletion of an
organization removes its shipments, claims, evidence, tickets,
and billing records; audit and security records are kept for
their retention period because they are the record of what
happened, then purged on schedule. Backups containing deleted
data are removed when the backup itself is deleted, not before.

## Breaches and incidents

Security events (failed logins, authorization denials) are
logged and visible to organization admins on the security page.
The operator follows the incident response runbook in
`docs/INCIDENT-RESPONSE.md`. There is no automated breach
notification; affected organizations are contacted by the
operator directly.

## Changes

This document changes when the app's actual practices change:
a new provider, a new data category, a new retention rule, or a
new deletion flow. The version and date at the top say which
release it describes.
