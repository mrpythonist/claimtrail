# ClaimTrail legal review — internal review, v1.2.3

**Status: internal review only. This is NOT legal advice and NOT a
substitute for a lawyer's review. The privacy policy, terms of
service, and data processing documentation have NOT been legally
reviewed and must not be described as compliant with any law.**

Reviewed 2026-11-26 against the application as it actually runs
(v1.2.3, mock billing provider, log mailer, log analytics). Every
statement below was checked against the code or the version docs;
where the code and a document disagreed, the code wins and the
disagreement is listed as a finding.

## What the app actually does with data

### Data collected and stored

| Data | Where it lives | Notes |
| --- | --- | --- |
| Account name, email, password | `users` | Password stored as a bcrypt hash (`password => 'hashed'` cast). Email verification status is tracked. |
| API tokens | `personal_access_tokens` (Sanctum) | **Tokens never expire.** Standing gap since v1.0.1. |
| Organization name, slug, settings | `organizations` | Settings hold default currency, date format, claim reference prefix, retention overrides. |
| Membership and roles | `organization_members`, `organization_invitations` | Six roles. Invitations hold an email address and a token. |
| Carrier contact details | `carriers` | Name, contact name, contact email, contact phone, DOT number, notes. **Carriers cannot be deleted.** |
| Shipment and claim records | `shipments`, `shipment_events`, `claims`, `claim_deadlines` | References, dates, amounts, statuses, deadlines, review notes. |
| Uploaded evidence | Private disk, `evidence_files` | SHA-256 checksum verified on download. **No malware scanning.** |
| Generated claim packages | Private disk, `generated_documents` | Ready packages are never purged by retention. |
| Billing (mock provider) | `subscriptions`, `invoices`, `billing_usage_records`, `billing_events`, `refunds` | Payment method brand and last four digits only. No card numbers are stored. The provider is in-process code; no payment data leaves the server. |
| Support tickets | `support_tickets`, `support_ticket_messages` | Subject, category, priority, messages, internal notes. Messages may contain customer data. |
| Marketing leads | `leads` | Name, email, company, fleet size, message, source. **No IP address or user agent is stored.** |
| Analytics events | Log only | Allow-listed event names. Log driver by default; no analytics provider is configured. |
| Audit and security records | `audit_logs`, `security_events`, `notifications`, `job_executions` | Audit rows hold before/after snapshots. Ticket bodies and messages are deliberately NOT written to the audit log. |
| Backups | Private disk, `backup_runs` | Full database copy plus stored files, with a SHA-256 manifest. **No application-level encryption;** protection is the server disk only. |

### Data shared with third parties

**None in the current deployment.** Billing is a mock provider
(in-process), the mailer defaults to `log` (no email leaves the
server unless a real mailer is configured), and analytics is
log-only. There is no subprocessor list because there are no
subprocessors yet. The moment a real Stripe account, a real mailer,
or a real analytics provider is connected, the privacy policy and
the data processing document must be updated first, with the
provider named and a data processing agreement in place where the
law requires one.

### Retention

Defaults in `config/retention.php`, overridable per organization
through PUT /retention: audit logs 365 days, notifications 90
days, job executions 30 days, failed documents 30 days, security
events 180 days. Ready claim packages are never purged. **Backups
are not covered by retention at all:** backup runs accumulate
until the operator deletes them. That is a finding, not a
feature.

### Security measures in place

- Passwords hashed with bcrypt; sessions use secure cookies in
  production; CSP and HSTS headers are set (v1.1.3).
- Tenant isolation is enforced by an organization scope on every
  query, with an automated cross-tenant sweep in the test suite.
- Six-role RBAC; permissions are checked per route.
- Evidence files are stored on a private disk and served only
  after an authorization check; file paths are guarded against
  traversal (`StorageKey::isSafe`).
- Audit log and security-event log record who did what.
- Backups are manifest-verified with SHA-256 (`backup:verify`).

### Security gaps that the documents must not hide

- Sanctum tokens never expire; the access token is stored in
  browser localStorage (both standing gaps since v1.0.1).
- No malware scanning on uploaded evidence.
- No application-level encryption for backups or stored files.
- No self-service account or organization deletion. Deletion
  requests go through a support ticket and are handled manually
  by the operator.

## Findings

| # | Finding | Document affected | Action |
| --- | --- | --- | --- |
| 1 | The privacy and terms pages were placeholders that said nothing about what data is collected. | Privacy, Terms | Replaced with reviewed drafts in v1.2.3 (still marked pending lawyer review). |
| 2 | No data processing document existed at all. | New | Created `docs/DATA-PROCESSING.md` from the table above. |
| 3 | No account or organization deletion flow exists. The documents must not promise deletion on request as a self-service feature. | Privacy, DATA-PROCESSING | Both documents say deletion is requested through a support ticket and handled manually. A deletion flow belongs on the roadmap before launch to customers who ask for it. |
| 4 | Backups are not covered by retention and are not encrypted by the app. `config/backup.php` sets `keep_days` to 30, but no command enforces it, so runs accumulate until an operator deletes them. | DATA-PROCESSING | Stated plainly. A backup retention policy (how long backup runs are kept, who may delete them) is an operator decision still open. |
| 5 | The app makes no uptime or availability promise, and the terms must not imply one. | Terms | The reviewed terms say the service is provided as-is with no uptime guarantee. |
| 6 | The app does not guarantee any charge is recoverable and does not give legal or contractual advice. | Terms | Carried over from the placeholder; kept in the reviewed terms. |
| 7 | No governing law, company entity, or contact address is stated anywhere in the app. The operator must supply these before the documents can be final. | Privacy, Terms | Both documents carry a "to be completed by the operator" note naming exactly what is missing. |
| 8 | The success-fee plan charges a percentage of amounts recorded as paid. The terms must state the fee, the cap, and the attribution rule, not just the marketing page. | Terms | Added: 15% of amounts recorded as paid, capped at $49 a month for the first year, only for claims created in ClaimTrail within 12 months of creation, net of refunds. |
| 9 | Refunds are processed against the mock provider today. The terms must not describe a real payment processor flow until Stripe is live. | Terms, BILLING.md | The refund process is documented as mock-provider behaviour; the terms say refunds are issued to the original payment method where the provider supports it. |
| 10 | Children: the app collects no age information and has no age gate. The documents must not claim an age restriction that is not enforced. | Privacy | The reviewed privacy policy says the service is intended for business users and does not knowingly collect data from children, without claiming an enforced age check. |

## What still needs a lawyer

- A full review of the privacy policy and terms against the laws
  of the operator's jurisdiction and of the customers'
  jurisdictions (the operator's location and company entity are
  not established in the app; see finding 7).
- Whether a data processing agreement is needed with customers
  who are themselves controllers, and its wording.
- The deletion flow (finding 3) and the backup retention policy
  (finding 4) once the operator decides them.
- Any consumer-protection or distance-selling rules that apply
  once real payments are taken through Stripe.

Until a lawyer signs off, every public legal page keeps the
banner: "Reviewed internally. Not legal advice. Pending legal
review." The banner is removed only after that review, never
because the internal review is done.
