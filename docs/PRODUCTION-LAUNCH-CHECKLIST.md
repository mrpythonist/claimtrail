# ClaimTrail production launch checklist

The checklist for taking ClaimTrail from a working build to a
service real customers pay for. Each item says whether it is
done in the code, and what still needs a person.

## Done in the application (v1.2.3)

- [x] **Core product** — shipments, events, claims with ten
  statuses, evidence with checksums, readiness, deadlines, PDF
  claim packages, reports, dashboard (v1.0.1).
- [x] **Multi-tenancy and RBAC** — organization-scoped queries,
  six roles, tenant isolation sweep in the test suite (v1.0.1,
  audited v1.1.3).
- [x] **Security headers and checks** — CSP, HSTS, secure session
  cookies in production, `security:check` with ten checks
  (v1.1.3).
- [x] **Backups** — daily at 02:00, manifest with SHA-256
  checksums, restore with manifest verification (v1.1.3).
- [x] **Backup verification** — `backup:verify` re-hashes the
  latest completed run against its manifest; weekly on Sundays
  at 04:00; the verify button is on the security page (v1.2.3).
- [x] **Production monitoring** — `monitoring:check` runs hourly:
  health checks, failed job count, queue depth, stored for the
  operations page (v1.2.3).
- [x] **Subscription billing** — five plans plus the success-fee
  plan, trials, proration, dunning, invoice history, mocked
  billing portal (v1.2.0, mock provider).
- [x] **Plan limits** — claim, member, storage, PDF, and report
  limits enforced with 402 and an upgrade prompt; downgrade
  carryover for one period (v1.2.1).
- [x] **Refunds and cancellations** — refund endpoint with full
  and partial refunds (a full refund marks the invoice refunded,
  a partial refund marks it partially refunded), audit row and
  billing event per refund; cancellation from the billing page
  or by support ticket (v1.2.3).
- [x] **Subscription administration** — `billing:admin` shows a
  subscription, invoices, refunds, and billing events for the
  operator; `GET /billing/events` exposes the same log in the
  app (v1.2.3).
- [x] **Customer support** — tickets any member can open, staff
  triage and internal notes for owner and admin, reopen on
  customer reply, closed tickets take no more messages;
  operator commands `support:list` and `support:show` (v1.2.3).
- [x] **Help documentation** — public help page (`/help`) and
  `docs/HELP.md` covering the product, billing, refunds, roles,
  data, and what the app does not do (v1.2.3).
- [x] **Privacy, terms, data processing** — internally reviewed
  against the app's actual data practices
  (`docs/legal-review-1.2.3.md`, `docs/DATA-PROCESSING.md`);
  the public pages carry the review date and the banner that
  they are not legal advice and not lawyer-reviewed (v1.2.3).
- [x] **Marketing site** — product, features, pricing, demo,
  screenshots (placeholders), getting started, SEO basics
  (v1.2.2).
- [x] **Retention** — per-organization overrides, weekly purge
  (v1.1.3).

## Still needs a person before real customers

- [ ] **Lawyer review** of the privacy policy, terms of service,
  and data processing documentation. The internal review is
  done; it is not a substitute. Also needed: the operating
  company name, governing law, and a contact address, which are
  not in the app anywhere.
- [ ] **Real Stripe account** and keys, then the mock provider
  swap. Until then no real money moves and the terms must keep
  saying so. When Stripe goes live: update the privacy policy
  and data processing documentation to name Stripe, put a data
  processing agreement in place, and re-test checkout, renewal,
  dunning, and refunds against the real provider.
- [ ] **Real mailer** (SMTP or a transactional provider). The
  default is the log driver: no email leaves the server, which
  means verification emails, notifications, and dunning emails
  do not reach anyone. Name the provider in the privacy policy
  when it is connected.
- [ ] **Production hosting** — a server or VPS with PHP, a real
  PostgreSQL database, Redis for queues and cache, and TLS.
  Nothing in this version has been verified against live
  Postgres, live Redis, or Docker. The first deploy must run
  the migrations, the queue worker, and the scheduler on real
  infrastructure, then re-run the smoke test against it.
- [ ] **A domain and site URL** — set `NEXT_PUBLIC_SITE_URL` so
  the sitemap is populated, metadata has absolute URLs, and the
  marketing pages stop rendering placeholder screenshots as the
  only visuals. Real product screenshots still need capturing;
  the gallery is placeholders.
- [ ] **Backup retention policy** — `config/backup.php` sets
  `keep_days` to 30, but no command enforces it, so backup runs
  accumulate until the operator deletes them. Decide how long
  backup runs are kept and who deletes them, then write it into
  `docs/DATA-PROCESSING.md`.
- [ ] **Account deletion flow** — there is no self-service
  account or organization deletion, and carriers cannot be
  deleted. Until a flow exists, deletion requests are handled
  manually through support tickets; the privacy policy says so.
- [ ] **Alerting channel** — monitoring writes failures to the
  log and the operations page, but nobody is paged. Decide
  where a failing check should notify (email, chat, phone) and
  build it before the first paying customer.
- [ ] **Analytics provider** (optional) — analytics is log-only.
  If GA4 or Plausible is added, set the analytics ID, update
  the privacy policy, and check the cookie/consent position
  with the lawyer.
- [ ] **CI workflow and release** — add `.github/workflows/ci.yml`
  from `~/workspace/claimtrail-ci.yml` via the GitHub web UI
  (the API cannot push workflow files) and create the v1.0.1
  tag and release. Both are still open from v1.0.1.

## Known gaps that stay open after launch

These are documented limitations, not launch blockers, but they
must not be promised to customers:

- Sanctum tokens never expire; access tokens live in browser
  localStorage.
- No malware scanning on uploaded evidence.
- No application-level encryption for backups or stored files.
- No submission channel: the app never sends a claim package
  to a broker; submitted is a status the member sets by hand.
- No GPS or ELD time capture; the deadline clock runs from
  claim creation, not delivery.
- No vendor dashboard surface for usage; no per-claim cap on
  evidence files.
- The mock billing portal points at the frontend billing page;
  the mock's process-local idempotency cache does not survive a
  restart.

## Launch decision

When the items above are done, the remaining decision is the
operator's: open signups to real customers, or run a closed
pilot first with the three pilot profiles from
`docs/validation/0.6.0-pilot-preparation.md`. The pilot
preparation (scorecard, onboarding runbook, success metrics) is
ready; no pilot customer has been recruited.
