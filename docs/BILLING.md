# ClaimTrail Billing Runbook

Subscription billing for ClaimTrail (v1.2.0). Written for the person
running the deployment and for the developer who swaps the provider
later, not for end users.

## The mock provider

Billing runs against a **mock provider** by decision, not by
accident: no real Stripe calls, no Stripe keys. The provider sits
behind the `App\Billing\BillingProvider` interface
(`backend/app/Billing/BillingProvider.php`), implemented by
`App\Billing\MockBillingProvider`. The driver is selected in
`backend/config/billing.php` (`BILLING_PROVIDER`, default `mock`).
Only `mock` is implemented in v1.2.0; `stripe` is the swap-out
target, not a working driver: setting `BILLING_PROVIDER=stripe`
today throws, because no Stripe provider class exists yet.

What the mock does:

- **Customers**: fake ids (`cus_mock_...`), one per organization.
- **Checkout**: sessions are opened by the API and completed in-app
  (`POST /billing/checkout/{session}/complete`). There is no hosted
  checkout page.
- **Cards**: two Stripe test numbers, as tokens or raw numbers.
  `pm_mock_visa` / `4242424242424242` (Visa ending 4242) always
  succeeds; `pm_mock_declined` / `4000000000000002` (ending 0002)
  always declines. Anything else is rejected as an unknown payment
  method. No real money moves.
- **Charges**: fake ids (`ch_mock_...`), with a process-local
  idempotency cache keyed on the idempotency key the service passes
  (a retry with the same key returns the first result, never a
  second charge).
- **Webhooks**: the mock signs payloads with HMAC-SHA256
  (`X-Mock-Signature: sha256=<hex>`) using `BILLING_WEBHOOK_SECRET`
  (default `mock-webhook-secret`). The webhook endpoint verifies the
  signature before handling anything.
- **Portal**: `POST /billing/portal` returns a URL and a token. The
  URL points at the frontend billing page
  (`/settings/billing?portal_session=...`); the token opens the
  billing summary at `GET /billing/portal/{token}` and expires after
  30 minutes.

## Plans

From `backend/config/billing.php`, per v0.5.0 section 8. Monthly
prices in USD cents; annual is 10x monthly (two months free).

| Plan | Monthly | Annual | Notes |
| --- | --- | --- | --- |
| Free | $0 | $0 | 1 truck, 5 claims/mo, 1 member, 100 MB |
| Starter | $19 | $190 | 1-2 trucks, 20 claims/mo, 3 members, 1 GB |
| Growth | $49 | $490 | 3-10 trucks, 100 claims/mo, 10 members, 10 GB. The default tier. |
| Fleet | $99 | $990 | 11-30 trucks, 300 claims/mo, 25 members, 50 GB |
| Agency | $149 base + $12 per extra carrier | $1,490 base | Up to 10 carriers included; 100 claims/mo and 10 GB per carrier |
| Success Fee | $0 + 15% of amounts recorded as paid | same | Unlisted. Capped at $49/mo for the first year. |

Plan limits are shown on the billing page but **not enforced** in
v1.2.0. Enforcement is v1.2.1.

## How a subscription moves

- **Free** is the starting state, created lazily on first read.
  Nothing is charged and no payment method is needed.
- **Trial**: 30 days on Growth, no card required
  (`POST /billing/trials`). At the end of the trial the renewal
  converts it to paid Growth when a card is on file; otherwise it
  lapses to Free.
- **Checkout** (`POST /billing/checkout`, then
  `POST /billing/checkout/{session}/complete`) moves off Free, off
  Success Fee, or converts a trial early. A paid plan needs a
  payment method. A declined card leaves the session open for a
  retry; a successful charge activates the subscription and issues
  a paid invoice.
- **Upgrade** (a move to a higher monthly-equivalent price) applies
  immediately: the buyer is credited for the unused days of the old
  plan and charged for the remaining days of the new plan, both at
  daily rates over the current period. The net is charged at once;
  a negative net (rounding only, for a true upgrade) becomes credit
  on the subscription. `POST /billing/subscription/change-plan`
  with `preview=true` returns the proration without changing
  anything.
- **Downgrade** (a move to a lower monthly-equivalent price) is
  scheduled for the next renewal: the buyer keeps the current plan
  until the period ends, then the new plan starts, charged at the
  new price.
- **Cancel**: at the end of the current period
  (`immediate=false`), or immediately (`immediate=true`, which
  moves the organization to Free at once). A cancel-at-period-end
  can be undone with `POST /billing/subscription/resume` before
  the period ends. A canceled subscription cannot change plans;
  it checks out again to subscribe.
- **Renewal**: `billing:renew` runs daily at 03:30. For each
  subscription whose period has ended it applies a pending
  downgrade, converts or lapses a trial, cancels at period end,
  invoices metered Success Fee usage in arrears, or charges the
  next period. A paid plan with no payment method cannot renew:
  the invoice goes past due and dunning takes over.
- **Failed payments / dunning**: `billing:dunning` runs daily at
  04:00. A failed renewal leaves the invoice past due with the
  next retry in 3 days. Dunning retries due invoices (+3 days,
  then +7 days). An invoice 14 or more days past its due date
  downgrades the organization to Free. Data is never deleted:
  the downgrade only changes the plan.
- **Paying an invoice**: `POST /billing/invoices/{invoice}/pay`
  pays an open or past-due invoice with the stored method, or one
  supplied now. A paid past-due invoice returns the subscription
  to active.

## Metered Success Fee billing

The Success Fee plan is the metered-billing decision from v0.5.0
section 6, implemented as invoicing in arrears (not Stripe metered
billing, which is deferred with real Stripe).

- Recording a payment on an accepted (or partially accepted) claim
  creates a usage record: 15% of the amount recorded as paid.
  Only payments on claims created in ClaimTrail count, and only
  within 12 months of the claim's creation, net of refunds.
  Payments on claims created elsewhere are never charged.
- The record is created inside the record-payment transaction, so
  a paid claim is never metered twice or missed.
- At renewal, `invoiceMeteredUsage` invoices every uninvoiced
  usage record: one success-fee line per record (claim reference,
  recovered amount, fee), plus a cap-adjustment line when the
  first-year $49/mo cap bites. The cap is applied in the billing
  code: the total can never exceed $49 in the first year.
  Example: $1,000 recovered in a month is a $150 fee, capped to
  $49: lines $150.00 and -$101.00, total $49.00.
- The arrears invoice is open, not auto-charged: the organization
  pays it from the billing page or the portal. After it is paid,
  the usage summary for the new period is empty.
- The first-year cap window is `success_fee_cap_until` on the
  subscription (12 months from the first Success Fee period). After
  it passes, the fee is uncapped.

## Entitlements (v1.2.1)

Plan limits from v0.5.0 section 8 are enforced in the backend, not
just shown. The frontend shows usage and upgrade prompts; the
backend refuses the action.

- **Limits per plan** (`config/billing.php`): claims per month Free
  5, Starter 20, Growth 100, Fleet 300, Agency 100 per carrier
  (carrier count is the org's carriers; the Success Fee plan has no
  claim cap); members Free 1, Starter 3, Growth 10, Fleet 25,
  Agency unlimited; storage Free 100 MB, Starter 1 GB, Growth
  10 GB, Fleet 50 GB, Agency 10 GB per carrier; PDF claim packages
  are not on Free, unlimited on every paid plan; reports are
  dashboard-only on Free and full on Starter and above. **Shipments
  are not capped**: a shipment is not the billed unit, the claim
  cap is the binding limit.
- **The current plan** is read from the org's subscription
  (`EntitlementService::effectivePlan`): a trialing org is on the
  Growth trial, a canceled subscription falls back to Free, an org
  with no subscription is Free.
- **Refusals are 402** with `error: "plan_limit_exceeded"` and a
  `limit` block: the resource, the limit, how much is used, the
  plan, and the cheapest listed plan that raises the limit (plus
  its name). When no listed plan raises it the upgrade fields are
  null: a Fleet org with one carrier cannot buy more claims, since
  Agency gives 100 per carrier. The frontend renders the message
  with a link to the billing page.
- **Guarded actions**: claim creation, member invitations
  (pending invitations count toward the member cap), evidence
  uploads (evidence plus generated documents count toward
  storage), PDF package generation, full reports. The upload check
  runs before the file is stored.
- **Caps never delete data**: claims already created stay
  listable, and a downgrade keeps everything. After a downgrade
  the lower cap blocks only new claims above it, and the billing
  page warns the org. A claim created above the new cap by a
  downgrade still counts toward the new cap's usage.
- **The downgrade carryover**: a renewal starts a fresh billing
  period, so claims created in the old period would stop counting
  the moment a downgrade applies, and the lower cap would hand
  out a fresh allowance at once. Instead, when a move to a lower
  plan takes effect (scheduled downgrade, cancellation, dunning
  downgrade, trial lapse, immediate cancel), the claims created
  since the old period began are carried into the new period
  (`claims_carryover` on the subscription) and still count toward
  the new cap. The carryover covers one period only: the next
  renewal clears it, and the following period starts with a clean
  allowance. A same-plan renewal also clears it, so a normal
  renewal still resets the monthly allowance.
- **Usage** (`GET /billing/usage`, `entitlements` block, also
  included in `GET /billing/subscription`): claims created in the
  current period plus the carryover, active members, pending
  invitations, storage used, and per-resource upgrade options
  (`{key, name}` of the cheapest listed plan that raises the
  limit, null when none does). There is no vendor dashboard
  surface for usage: the operations dashboard is per-org queue
  monitoring with no org-listing view, so vendor visibility is
  not built in v1.2.1.
- **Limits not specified in v0.5.0 section 8 are not enforced**:
  there is no per-claim cap on evidence files (storage is the
  governor), and no per-claim cap on generated documents.

## Webhooks

The provider posts to `POST /api/v1/billing/webhooks`. It is
public (no Sanctum token): authenticity is verified by HMAC-SHA256
over the raw body, header `X-Mock-Signature: sha256=<hex>`, secret
`BILLING_WEBHOOK_SECRET`. A missing or wrong signature returns 401
and nothing is recorded.

Every delivered event is recorded in `billing_events` with its
provider event id. An exact redelivery (same provider event id)
is recorded as `duplicate` and never applied twice. Unknown types
are recorded as `unprocessed`. A handler that throws is recorded
as `failed` with the error message.

Handled types:

| Type | Effect |
| --- | --- |
| `checkout.session.completed` | Marks the checkout session completed |
| `invoice.paid` | Marks the invoice paid |
| `invoice.payment_failed` | Marks the invoice past due, schedules a retry |
| `customer.subscription.updated` | Syncs the subscription state |
| `customer.subscription.deleted` | Cancels the subscription |

To fire an event by hand, use the simulate endpoint
(`POST /billing/webhooks/simulate`, requires manage-billing): it
signs the event, verifies the signature, then handles it exactly
like a delivered webhook. Body: `{ "type": "...", "data": {...} }`.

## Invoices

Invoice numbers are per-organization, per-year:
`INV-2026-0001` is each organization's first invoice of the year.
The uniqueness is `(organization_id, number)`, not global: a
global unique on `number` would stop the second organization
from ever being invoiced (found by the v1.2.0 live smoke test,
regression-tested in `BillingTest`).

Invoice statuses: `draft`, `open`, `paid`, `past_due`, `void`,
`uncollectible`. Line kinds: `subscription`, `proration_credit`,
`proration_charge`, `success_fee`, `cap_adjustment`,
`credit_applied`. Negative lines are credits and cap adjustments.

## Refunds (v1.2.3)

Refunds are issued by the operator, not by the customer
self-service: the customer opens a support ticket within 14
days of the charge, stating the invoice number and the reason.
The operator reviews the request and records the refund with
`POST /billing/invoices/{id}/refund` (manage-billing permission)
or from the billing page's Refund button on a paid invoice. The
reason is required: until a written refund policy is published,
refunds are issued case by case, and the reason is the record
of why.

- No `amount_cents` in the request means a full refund of the
  remaining paid amount. A full refund sets the invoice status
  to `refunded`.
- An `amount_cents` refunds that many cents in part; the invoice
  status becomes `partially_refunded`, and it can be refunded
  again until nothing remains.
- An amount above the remaining paid amount, a refund on an
  unpaid invoice, or a negative amount is rejected with 422.
- Every refund is a `refunds` row (invoice, amount, reason,
  status, provider refund id, who requested it), an audit row
  (`billing.invoice_refunded`), and a billing event
  (`invoice.refunded`), so the billing events log
  (`GET /billing/events`) shows the full history. The endpoint
  returns 201 with the refund record.
- The provider is asked first, with an idempotency key tied to
  the refund row, so a retry never refunds twice. A provider
  failure is recorded on the row and reported; the invoice is
  untouched.
- A refund does not change metered success-fee usage: the fee is
  on claim payments recorded as paid, not on subscription
  invoices, so refunding a fee invoice is just money back.

With the mock provider the refund is recorded locally and no
real money moves. When real Stripe is connected, the same
endpoint calls the provider's refund; the terms already promise
refunds to the original payment method where the provider
supports it. A charge that never happened (no provider charge
id, a zero-amount invoice) cannot be refunded: the mock reports
"nothing to refund".

## Cancellation (v1.2.3)

Cancellation is unchanged from v1.2.0 (cancel from the billing
page, or resume before the period ends), plus two administration
paths: the customer may cancel by support ticket, and the
operator may cancel from the subscription administration view
(`billing:admin`). Cancelling moves the organization to Free at
the end of the current period; cancelling immediately moves it
to Free at once. Either way the data stays, and the trial or
paid period's invoices remain payable or refundable as above.
Dunning still applies to unpaid invoices after a cancellation at
period end until the period closes.

## Subscription administration (v1.2.3)

The customer billing hub (`/settings/billing`) is for the
customer. The operator's view is the `billing:admin` command
and the billing events log:

```sh
php artisan billing:admin <organization uuid or slug>
php artisan billing:admin <slug> --invoices=10 --events=20
```

It prints the plan, status, interval, period, trial, payment
method, credit balance, recent invoices (with refunded amounts),
and recent billing events. It changes nothing. The same event
history is available in the app at `GET /billing/events`
(manage-billing), shown on the billing page under "Billing
events". Use it when a support ticket asks about a subscription:
the answer should come from this view, not from memory.

## Scheduled commands

| Command | Schedule | What it does |
| --- | --- | --- |
| `billing:renew` | daily 03:30 | Renews subscriptions whose period ended (see above) |
| `billing:dunning` | daily 04:00 | Retries past-due invoices; downgrades after 14 days past due |

Run either by hand with `php artisan billing:renew` /
`php artisan billing:dunning`. Both print a one-line summary of
counts (renewed, trials converted/lapsed, canceled, downgrades,
metered invoices, failed).

## Swapping in real Stripe

The swap is a provider implementation, not a redesign. Steps:

1. Add the Stripe PHP SDK (`composer require stripe/stripe-php`).
2. Implement `App\Billing\StripeBillingProvider` against the
   `BillingProvider` interface: `name`, `createCustomer`,
   `createCheckoutSession`, `charge` (with idempotency key),
   `createPortalSession`, `verifyWebhookSignature`,
   `signWebhookPayload`. Keep the same return shapes the mock
   uses (session ids, charge ids, portal URLs, HMAC verification).
3. Register it in `AppServiceProvider`: `BILLING_PROVIDER=stripe`
   binds the Stripe provider; anything else still throws.
4. Supply `STRIPE_SECRET_KEY` and `STRIPE_WEBHOOK_SECRET`
   (never commit them). The webhook secret replaces
   `BILLING_WEBHOOK_SECRET` for verification.
5. Revisit the metered-billing decision (v0.5.0 section 6): the
   mock invoices Success Fee usage in arrears from ClaimTrail's
   own usage records. With real Stripe, either keep that model
   (ClaimTrail calculates and invoices) or switch to Stripe
   metered billing (report usage records to Stripe, Stripe
   invoices monthly in arrears). The $49/mo first-year cap must
   still be enforced in ClaimTrail's billing code either way:
   Stripe does not cap natively.
6. Re-verify end to end against Stripe test mode before touching
   live keys: checkout, trial conversion, upgrade proration,
   downgrade scheduling, renewal, failed-payment dunning,
   webhook delivery and redelivery, portal sessions.

Known gaps to close with the swap: the mock portal URL points at
the frontend billing page, not a Stripe-hosted page; the mock
charge has no real payment-method storage (cards are tokens, not
Stripe payment methods); the mock's process-local idempotency
cache does not survive a restart (a real provider dedupes on its
own idempotency keys).

## What is NOT in v1.2.0

- Plan limit enforcement (v1.2.1).
- Real Stripe (see above).
- Browser end-to-end tests, live Postgres, live Redis, Docker,
  real mailer: standing environment blockers on this VM, same as
  every version before this one.
