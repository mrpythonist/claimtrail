# Advanced Analytics

ClaimTrail v1.5.1. Read-only analytics computed from the
organization's own recorded claims, shipments, and payments.
Nothing here is invented, benchmarked, or verified against an
outside source.

## What it answers

- How much of the money we asked for did we actually get
  back (recovery rate), against what we requested and against
  what was approved?
- How many submitted claims were rejected (rejection rate)?
- How long does a claim take to get submitted, and how long
  after approval does the money arrive (average times)?
- Which brokers and carriers recover best, and which event
  types (detention, layover, TONU, lumper, and the rest)
  perform best?
- How does this month or year compare with the last one?

## Metric definitions

Every metric is computed over claims created in the selected
range (default: the last 12 months), for the organization
named in the `X-Organization-Id` header. All money is summed
as recorded, in the currencies recorded; amounts in different
currencies are never converted or added together, and the
overview lists the money per currency separately.

### Populations

- **Submitted population**: a claim enters every rate
  denominator the moment its current status reached
  submission: `submitted`, `accepted`, `partially_accepted`,
  `rejected`, or `paid`. Claims still being prepared
  (`draft`, `ready`, or any earlier status) never enter a
  rate denominator. A claim that was submitted and later
  moved on still counts as submitted.
- **Paid**: a claim whose current status is `paid`.
- **Rejected**: a claim whose current status is `rejected`.
  Denied claims are the same thing: the app has one
  rejection status, `rejected`.

### Recovery rate

- **vs requested**: total `recovered_amount` on paid claims
  divided by total `requested_amount` over the submitted
  population, times 100.
- **vs approved**: total `recovered_amount` on paid claims
  divided by total `approved_amount` over the submitted
  population, times 100. The approved total is the sum of
  recorded `approved_amount` values; a claim with no
  approval recorded contributes nothing to it.
- Both are `null` (shown as an em dash) when the submitted
  population is empty or the denominator is zero. A rate
  above 100% means the org recorded more paid than
  requested or approved, which the ledger permits as an
  overpayment; it is shown as-is, not capped.

### Rejection rate

Rejected claims divided by submitted claims, times 100.
`null` when nothing was submitted in the range.

### Average submission time

For each submitted claim, `submitted_at` minus `created_at`,
in days, averaged over the submitted claims that have a
`submitted_at` timestamp. The sample size is shown next to
every average (`n=3`); an average over zero claims is `null`.

### Average payment time

For each paid claim, `paid_at` minus `approved_at` where the
claim has an `approved_at` (set when the claim is accepted),
otherwise `paid_at` minus `submitted_at`, in days, averaged
over the paid claims that have both timestamps. `paid_at` is
the date the claim moved to `paid` (`paid_at` on the claim,
set when the claim is marked paid); it is not the date of any
single ledger payment, because a claim can be paid in several
ledger payments and the ledger records what the org says it
received, not a bank-confirmed transfer date. The sample size
is shown next to every average; an average over zero claims
is `null`.

Averages are the mean of the durations in days, rounded to
two decimals. Averages over tiny samples are labelled as such
by the sample size; do not quote "1 day" from a single claim
as if it were a trend.

### Broker performance

One row per broker or customer name on the claims'
shipments (`broker_or_customer_name`), most claims first.
Each row carries the same counts, money, recovery rate,
rejection rate, and average payment time as the overview,
computed over that broker's claims only. Shipments with no
broker name group under "Unnamed broker".

### Carrier performance

One row per carrier on the claims' shipments, most claims
first, with the same columns as broker performance. A
shipment whose carrier is a carrier record groups under
that carrier's name; a shipment with no carrier groups
under "No carrier assigned".

### Event-type performance

One row per claim category (`detention`, `layover`, `tonu`,
`lumper`, `additional_stop`, `additional_miles`, `other`), with
the same columns. Categories with no claims in the range
still appear, with zeros and null rates, so a gap is visible
rather than hidden.

### Monthly and yearly comparisons

- **Monthly**: one point per calendar month in the range,
  with claims created, submitted, paid, rejected, requested,
  approved, and paid amounts, and the recovery rate vs
  requested. Months are keyed by the claim's `created_at`.
- **Yearly**: the same, one point per calendar year.
- **Period comparison** (in the overview): the selected range
  is compared with the immediately preceding window of the
  same length. The change block reports the difference in
  claims, submitted, paid, rejected, requested and paid
  amounts, and the recovery and rejection rates in
  percentage points. When the preceding window has no
  submitted claims, the rate change is `null`.

## Charts

The frontend renders the monthly and yearly series with the
existing `CountBar` CSS bar component: one bar per month or
year, scaled to the largest count in the set. There is no
chart library and no chart image export. The API returns the
series as labels plus values, so any client can draw them.

## Exports

CSV downloads only, no PDF or Excel:

- `GET /analytics/export?type=overview` — the overview
  totals, rates, and averages for the range.
- `?type=brokers`, `?type=carriers`, `?type=event-types` —
  the matching performance table.
- `?type=monthly` — the monthly series.

Exports need `export-reports` (owner, admin, operations
manager, dispatcher, billing staff) and respect the same
`from`/`to` range as the tables. The file name is
`claimtrail-analytics-<type>-YYYY-MM-DD.csv`.

## Access

- Reading any analytics endpoint needs the `view-analytics`
  permission: owner, admin, operations manager, dispatcher,
  billing staff, viewer. That is the same set as
  `view-claims`; a dispatcher can view analytics even though
  it cannot view reports.
- Free-plan organizations are refused with 402, the same as
  reports: analytics is a paid-plan feature.
- Every query is tenant-scoped to the organization header.
  A claim from another organization is never counted and
  never returned.
- There is no write endpoint and no new notification type:
  analytics reads what the app already recorded. Nothing is
  audited beyond the audit trail the underlying records
  already produce.

## Honest limits

- Analytics are computed only from the organization's own
  recorded claims, payments, and reconciliation data. There
  is no external benchmark, no industry data, and no
  comparison with other organizations.
- There is no live payment verification. Billing runs on a
  mock provider, and the ledger records what the
  organization says it received. A "paid" claim means the
  organization recorded the money as received, not that a
  bank or processor confirmed it.
- Averages over tiny samples are labelled with their sample
  size and must not be read as trends. A rate from one claim
  is 0% or 100% and says almost nothing.
- Money is summed as recorded with no currency conversion.
  An organization recording claims in two currencies sees
  per-currency totals in the overview and must not read the
  combined total as a single-currency figure.
- Claims still being prepared never enter a rate, so the
  recovery rate describes submitted claims only, not the
  whole pipeline.
- The submission and payment clocks use the timestamps the
  app recorded (`created_at`, `submitted_at`, `paid_at`).
  A claim submitted by hand and marked submitted later
  measures the recording date, not the day the package
  actually went out, because the app has no submission
  channel and cannot know when a package was sent.
- Standing unverified, same as the rest of the app: browser
  end-to-end tests, live PostgreSQL, live Redis, Docker, and
  a real mailer.

## API

`GET /analytics/overview`, `GET /analytics/brokers`,
`GET /analytics/carriers`, `GET /analytics/event-types`,
`GET /analytics/monthly`, `GET /analytics/yearly`, and
`GET /analytics/export`. All take optional `from` and `to`
dates (`YYYY-MM-DD`); without them the range is the last
12 months. See docs/API.md for the field lists and
docs/openapi.yaml for the schemas.
