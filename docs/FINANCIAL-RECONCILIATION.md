# Financial Reconciliation (v1.5.0)

The claim payment ledger: what the organization says it received,
per claim, with outstanding balances and reconciliation reports.

## What it is

- **Payment ledger** (`claim_payments`): one row per payment an
  organization records against a claim, in the claim's currency.
  Each row carries the amount, the payment method (wire transfer,
  ACH, cheque, cash, card, other), an optional payment reference
  (wire, cheque, or ACH reference number), the payment date, and
  optional notes.
- **Partial payments are normal.** A claim stays accepted while
  money is still outstanding. Only the payment that brings the
  paid total up to the amount due moves the claim to Paid, and it
  does so through the existing record-payment flow
  (`ClaimTransitionService::recordPayment`), so the success-fee
  metering from v1.2.0 still runs exactly once.
- **Balances.** The outstanding rule lives in one place,
  `ReconciliationService::balanceFor`, and nowhere else:

  ```
  due         = accepted_amount ?? requested_amount
  paid        = recovered_amount
  outstanding = max(0, due - paid)
  ```

  `accepted_amount` is the approved amount. Where no approval is
  recorded, the requested amount is the amount due. `paid` is the
  claim's `recovered_amount`, the authoritative paid total; the
  ledger rows are the detail of that total. A claim paid before
  the ledger existed can show a paid total with no ledger rows;
  the difference is reported as a legacy amount, never silently
  rewritten.
- **Payment history** per claim (`GET /reconciliation/claims/{claim}`)
  and per organization (`GET /reconciliation/ledger`, with
  `claim_id`, `payment_method`, `from`, and `to` filters).
- **Reconciliation reports.** A report snapshots one period:
  requested, approved, paid, and outstanding totals, claim and
  payment counts, and the discrepancies found at generation time.
  Reports are stored (`reconciliation_reports`) so they can be
  listed and re-read. The `reconciliation:run` command generates
  the current month's report for every organization with ledger
  payments this month, skipping one already generated today, and
  runs daily at 06:00.

## The outstanding rule, precisely

- A claim with no payments is `unpaid`.
- A claim with some payment but less than due is `partial`.
- A claim whose paid total reaches due is `paid`.
- A claim whose paid total exceeds due is `overpaid`, with
  outstanding still reported as zero. Overpayment is flagged in
  reports, never hidden.
- A claim with no requested and no approved amount has due zero
  and is `unpaid` with zero outstanding.

## Discrepancies (flagged, never silently fixed)

Reports flag four kinds:

- `overpaid`: paid above the amount due.
- `legacy_payment`: part of the paid total was recorded before the
  payment ledger existed and has no ledger row. This is expected
  for claims paid through the pre-ledger record-payment endpoint;
  it is information, not an error.
- `paid_but_outstanding`: the claim status is Paid but the
  outstanding balance is above zero. This should never happen; if
  it appears, the ledger and the claim disagree and a person must
  look.
- `payments_on_unsubmitted`: ledger payments exist on a claim that
  was never submitted. The record endpoint refuses this, so it
  should never happen either.

## Voiding a payment

A payment can be voided (`DELETE /reconciliation/ledger/{payment}`).
Voiding removes the row and takes its amount back out of the
claim's paid total, with an audit record
(`reconciliation.payment_voided`). A payment on a paid or closed
claim cannot be voided: the claim is settled and possibly metered
for the success fee, and Paid only moves to Closed.

## Permissions and notifications

- Any member may read the ledger, balances, summary, and reports.
- Recording a payment, voiding one, and generating a report need
  `manage-reconciliation` (owner, admin, operations manager,
  dispatcher).
- A partial payment notifies members who can view claims
  (`reconciliation.payment_recorded`, actor excluded). The final
  payment fires the existing `payment.recorded` notification
  through the record-payment flow. Generating a report notifies
  members who can manage reconciliation
  (`reconciliation.completed`), deduplicated per organization
  and period.

## Honest limits

- **No real money moves here.** Billing runs on a mock provider
  behind an interface. The ledger records what the organization
  says it received; it does not talk to any bank, Stripe, or
  payment processor, and no payment is verified against an
  external source. A payment reference is a string the
  organization types in, not a confirmed transfer.
- **No live payment verification is possible on this VM.**
  There is no live Postgres, Redis, or Docker here, and no
  real mailer. Notification emails are queued and faked in
  tests, as everywhere else.
- **Mixed currencies are summed as recorded.** Report and
  summary totals add amounts across claims without converting
  currencies. The summary lists the currencies involved; treat
  a mixed-currency total as a rough figure, not an exact one.
- **The ledger does not replace the subscription billing.**
  Subscription invoices, the success-fee metering, and refunds
  stay in `BillingService`. The ledger tracks claim payments;
  the success fee is metered from the claim's paid total when
  the claim moves to Paid, exactly as before.
- **No delivery retry or reprocess.** A voided payment is gone
  from the ledger (the audit row is the record of it). There is
  no edit-in-place for a payment: void it and record the
  corrected one.

## API

| Method | Path | Auth | Description |
| ------ | ---- | ---- | ----------- |
| GET | `/reconciliation/summary` | Yes + org header | Organization totals: requested, approved, paid, outstanding, counts by balance status. Any member may read. |
| GET | `/reconciliation/ledger` | Yes + org header | Ledger payments, newest first. Query: `claim_id`, `payment_method`, `from`, `to`. Any member may list. |
| GET | `/reconciliation/ledger/{payment}` | Yes + org header | One ledger payment. Any member may read. |
| POST | `/reconciliation/claims/{claim}/payments` | Yes + org header | Record a payment. Body: `amount` (min 0.01), `payment_method`, `paid_at` (not in the future), optional `payment_reference`, `notes`. Returns the payment plus the updated balance. Requires `manage-reconciliation`. |
| DELETE | `/reconciliation/ledger/{payment}` | Yes + org header | Void a payment. Refused with 422 on a paid or closed claim. Requires `manage-reconciliation`. |
| GET | `/reconciliation/claims/{claim}` | Yes + org header | One claim balance with payment history. Any member may read. |
| GET | `/reconciliation/reports` | Yes + org header | Generated reports, newest first. Any member may list. |
| POST | `/reconciliation/reports` | Yes + org header | Generate a report for a period (both dates default to the current month). Requires `manage-reconciliation`. |
| GET | `/reconciliation/reports/{report}` | Yes + org header | One generated report. Any member may read. |

## Console

- `reconciliation:run [--organization=ID] [--period=YYYY-MM]`:
  generate the month's report for organizations with ledger
  payments that month. Skips an organization that already has
  a report generated today for the same period. Scheduled daily
  at 06:00.
