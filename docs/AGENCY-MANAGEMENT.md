# Agency Management (v2.1.0)

The first version of Phase 7 (Partner Ecosystem). Agency
management is the tooling a dispatch agency or reseller needs
to run several carrier organizations from one place: partner
accounts linking the agency to the carriers it manages, an
agency dashboard, per-carrier permission grants, consolidated
cross-carrier reporting, referral codes, commission rules and
earned commission, and reseller workflows (carrier onboarding
approval and plan assignment).

Everything here is gated by the `manage-agency` permission
(owner, admin, operations manager) and the Agency plan:
plans below Agency are refused with a 402 on every agency
endpoint. The Agency plan is $149 per month base plus $12
per extra carrier.

The frontend surface is the `/agency` page, with tabs for
Overview, Carriers, Claims, Reports, Referrals, Commissions,
and Reseller.

## What is recorded, what is computed

The distinction matters, and every endpoint and page states it:

- **Recorded** values are rows in this instance's own
  database: partner links, referral codes, commission rules,
  grants, the carriers' claims and payments. They are the
  only inputs anything here uses.
- **Computed** values are derived from those rows only:
  per-carrier and consolidated totals, outstanding balances,
  and earned commission. There are no external benchmarks,
  no live partner API, and no market data anywhere in this
  version.

## Partner accounts

A partner link connects an agency organization to a carrier
organization (`partner_links`). The agency creates the link;
the link starts pending, and only the carrier can approve or
reject it, from the carrier side.

- `POST /agency/partners` with `carrier_organization_id`,
  optional `referral_code`, optional `notes`. The carrier
  must exist, must not be the agency itself, and must have
  no existing non-removed link. A referral code, when given,
  must be one of the agency's active codes. The link is
  created pending.
- `GET /partner-links/pending` lists the pending links whose
  carrier organization the caller belongs to. This route is
  not tenant-scoped: the carrier answers from its own side.
- `POST /partner-links/{partnerLink}/approve` and
  `POST /partner-links/{partnerLink}/reject`. The caller must
  be an owner or admin of the CARRIER organization. The
  agency cannot approve or reject its own link.
- `GET /agency/partners` (optional `status` filter),
  `GET /agency/partners/{partnerLink}`.
- `POST /agency/partners/{partnerLink}/suspend`,
  `/reactivate`, `/remove`. Admin-only. A suspended link
  blocks carrier claim access until reactivated. A removed
  link keeps its row with status removed, never deleted, so
  the audit trail and referral attribution survive.

Every link change is written to the audit trail. Partner
links, referral codes, commission rules, and grants are all
auditable entities.

## Multiple carrier management

An agency sees its carriers' claims read-only. Writing a
claim stays with the carrier organization itself; the agency
manages the portfolio, not the claim.

- `GET /agency/carriers/{carrierOrganizationId}/claims`
  (optional `status` filter, 25 per page) and
  `GET /agency/carriers/{carrierOrganizationId}/claims/{claim}`.
- The link must be active. A suspended link refuses claim
  access with 403, and so does a removed or rejected one.
- A link belonging to another agency 404s: cross-tenant
  access is never revealed as a 403.

## Agency dashboard

`GET /agency/dashboard` returns, for every active link the
caller may see:

- Per-carrier stats: claim count, by-status counts, totals
  by currency, and earned commission for that carrier.
- Consolidated totals by currency, merged across the visible
  carriers. Amounts are decimal strings in their own
  currency, never converted.
- Link counts by status (total, pending, approved,
  suspended, rejected, removed).
- The referral summary and the whole-agency commission.

Outstanding is due minus recovered, where due is the accepted
total when it is above zero and the requested total otherwise,
floored at zero. The dashboard computes it on the bucket
totals, so it can differ from summing per-claim balances.

## Carrier-specific permissions

Agency members are scoped to specific carriers, not just to
the agency organization. Owners and admins of the agency see
every link; every other agency member sees only the carriers
they are granted.

- `GET /agency/partners/{partnerLink}/grants`,
  `POST /agency/partners/{partnerLink}/grants` with
  `user_id` and `scope` (`view` or `manage`), and
  `DELETE /agency/partners/{partnerLink}/grants/{grant}`.
  Admin-only.
- The member must be an active member of the agency
  organization. One grant per member per link: granting
  again replaces the scope. Manage covers view.
- A grant is needed to read a carrier's claims (view) and to
  create or update a per-carrier commission rule (manage).
  A member with no grant for a carrier gets 403 on that
  carrier and does not see it on the dashboard.
- Default commission rules are admin-only; per-carrier rules
  need manage scope for that carrier.

## Consolidated reporting

`GET /agency/reports/consolidated` with optional
`date_from`, `date_to`, and `carrier_organization_id`.
One row per active linked carrier the caller may see, plus
totals by currency.

- Claims are filtered by created date, payments by paid
  date, and the report always states the window it covers.
- An optional carrier filter 404s when the agency has no
  active link for that carrier.
- Computed only from the linked carriers' own recorded
  claims and payments. No external benchmarks exist here,
  and the report says so.

## Referral tracking

Referral codes attribute referred carriers to the agency
that brought them in. Recorded only: no reward is paid,
no payout is triggered.

- `GET /agency/referrals`, `POST /agency/referrals`
  (optional `notes`), `GET /agency/referrals/{referralCode}`,
  `POST /agency/referrals/{referralCode}/deactivate`.
- A code is the configured prefix (`REF`) plus an 8-character
  random suffix. Creation retries on a collision.
- The detail endpoint lists the partner links attributed to
  the code. Deactivating a code stops new links from using
  it; attribution of links already created is preserved.
- `uses_count` is derived from the links citing the code,
  not stored.

## Commission tracking

Commission rules and earned commission, computed only from
recorded payments. A recorded figure only: nothing here pays
a partner out, and billing runs on the mock provider.

- `GET /agency/commission-rules` (default rule first),
  `POST /agency/commission-rules` with `rate_percent`
  (0-100), optional `partner_link_id`, optional `cap_cents`
  (minor units, per currency), optional `effective_from`.
- Only one active default rule may exist. A per-carrier rule
  needs an active link and manage scope for that carrier.
- `PUT /agency/commission-rules/{commissionRule}` updates
  the rate, cap, active flag, or effective date. The rule's
  carrier never changes. `DELETE` removes the rule; earnings
  computed afterwards fall back to the default rule, or to
  the configured default rate (10%) when no rule applies.
- `GET /agency/commissions` (optional `date_from`,
  `date_to`) returns one row per active linked carrier the
  caller may see: the applicable rule and rate, payments and
  earned per currency, whether the cap applied, plus totals
  by currency.
- Earned is payments times the rule rate, on the paid basis,
  capped per currency when the rule sets a cap.
- `php artisan agency:commissions` prints the same figures
  for one agency (`--organization`) or for every agency
  (`--date-from`, `--date-to`).

## Reseller workflows

The reseller flow is onboarding approval plus plan
assignment, with the commission rules above as the third
piece. White-label branding is v2.1.1, not this version.

- **Onboarding:** the agency creates a partner link
  (optionally with a referral code); the carrier approves or
  rejects it from its own side; the agency suspends,
  reactivates, or removes the link afterwards. The link's
  lifecycle is on the audit trail.
- **Plan assignment:** `POST /agency/partners/{partnerLink}/plan`
  with `plan` changes the linked carrier's subscription
  through the mock billing provider and records the assigned
  plan on the link. The link must be approved. Admin-only.
- **Commission setup:** the agency creates a default rule,
  then per-carrier rules where a carrier earns a different
  rate, and watches earned commission on the Commissions tab.

## What is still not verified

- Docker builds, migrations on live Postgres, the Redis
  queue worker, S3, a real mailer, and browser end-to-end
  tests: none of these were run for this version, same as
  every version before it.
- There is still no submission channel: the app never sends
  a claim package to a broker, and agency management does
  not add one.
- There is no live partner API and no real Stripe: billing
  runs on the mock provider, and commission is a recorded
  figure, never a payout.
- Sanctum tokens never expire and the token lives in
  localStorage, same as before. No malware scanning, no
  carrier deletion, no pen test.
