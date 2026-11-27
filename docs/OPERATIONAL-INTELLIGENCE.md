# Operational Intelligence

Operational intelligence (v1.5.2, the last version of Phase 5)
is the layer above advanced analytics: instead of asking how
the organization did overall, it asks where the work is
going wrong and what to look at next. Detention trends,
facility performance, rejection reasons, missing-evidence
trends, broker-level trends, revenue leakage, and
operational recommendations.

## Definitions

Every endpoint takes optional `from` and `to` dates
(`YYYY-MM-DD`). Without them the range is the last 12
months. All endpoints need `view-analytics` and refuse the
Free plan with a 402, the same as analytics.

### Scope and the submitted population

The scope is claims created in the requested date range,
both ends inclusive. Rates use the submitted population:
claims whose current status reached submission (`submitted`,
`accepted`, `partially_accepted`, `rejected`, `paid`).
Claims still being prepared (`potential`,
`evidence_required`, `under_review`, `ready_to_submit`)
never enter a rate denominator. Averages carry their sample
size, and rates are null when their denominator is zero.

### Detention trends (`GET /operations/detention-trends`)

Detention claims only, with counts, recovery and rejection
rates, average submission and payment times, the monthly
series, and a comparison against the preceding window of
equal length. The definitions are the same as the analytics
overview, restricted to the detention category.

There is no detention-hours field on a claim, so the trend
is over detention claims and their recorded money, not over
hours waited. A rise in detention claims means more
detention claims were created, not necessarily more hours
spent waiting.

### Facility performance (`GET /operations/facilities`)

One row per facility, most claims first, with counts, money,
rates, and the average payment time. There is no separate
facility record in the app. The facility is the shipment's
delivery location where one is recorded, otherwise its
pickup location, otherwise "Unnamed facility". A shipment
with a delivery location in one city and a pickup in
another counts under the delivery location.

### Rejection reasons (`GET /operations/rejection-reasons`)

One row per recorded rejection reason over the rejected
claims in the range, most rejections first, with the
reason's share of all rejections and the requested amount
attached to those claims. The reason is the
`rejection_reason` recorded on the claim. Rejected claims
with nothing recorded group under "Not recorded". No
taxonomy is invented: if the organization records free-text
reasons, similar reasons spelled differently appear as
separate rows.

### Missing evidence (`GET /operations/missing-evidence`)

Which document categories claims are missing, how many
claims are incomplete, the average readiness, and the
monthly trend. This reuses the existing readiness
computation (`ReadinessService`): attached evidence against
the organization's configured requirements, per claim.

A claim counts as incomplete only when it has configured
requirements and at least one is still missing. A claim
with no configured requirements is not incomplete; it
simply has nothing to be missing, and it does not enter
the average readiness. The average readiness is the mean
of the readiness percentages over claims with requirements.

Readiness counts attached evidence against configured
requirements. It does not certify that a claim is valid or
will be accepted.

### Broker trends (`GET /operations/broker-trends`)

One row per broker or customer name, most claims first,
with the same performance columns as the analytics broker
table, plus the broker's own monthly series and its change
against the preceding window of equal length. Rate changes
are in percentage points and are null when either window
has no submitted claims for that broker.

### Revenue leakage (`GET /operations/revenue-leakage`)

The gaps between recorded amounts over the submitted
population, under the single v1.5.0 balance rule
(`ReconciliationService::balanceFor`): the amount due is
the accepted amount where one is recorded, otherwise the
requested amount; outstanding is the amount due minus
what was recorded as paid, floored at zero.

- Requested vs approved is what the broker cut at
  approval: requested minus approved, summed over claims
  with an accepted amount, with the count of claims that
  were cut.
- Approved vs paid is the outstanding balance.
- Overpaid is paid above the amount due.
- Legacy is money recorded as paid outside the ledger
  (recorded as received, but with no ledger row).
- Unsubmitted totals are the pipeline, not leakage: claims
  still being prepared have no approved amount yet, so
  their requested total is reported separately.

The response also breaks the totals down by balance status
(`unpaid`, `partial`, `paid`, `overpaid`), per currency,
and lists the five largest outstanding balances.

These are gaps between numbers the organization recorded.
They are not proven lost money: an outstanding balance
may be paid tomorrow, a cut at approval may be a
legitimate negotiation, and a legacy amount may be a
payment recorded before the ledger existed.

One difference to know: the analytics totals (overview,
detention trends, facility and broker rows) compute the
outstanding amount at the aggregate level — the amount due
is the total approved when any approved amount exists,
otherwise the total requested. The leakage endpoint
computes it per claim under the balance rule and sums the
results. The two can disagree when some claims have an
accepted amount and others do not, because the aggregate
rule applies one due to the whole group while the per-claim
rule applies each claim's own due. Both numbers are
correct under their own definition; the leakage figure is
the one to use when chasing individual balances.

### Recommendations (`GET /operations/recommendations`)

Fixed rules over the six analyses above. Each
recommendation carries the numbers it was derived from in
its `evidence` field, so the reader can check it. A rule
fires only when its stated minimum sample is met; when
nothing meets a rule, no recommendation is invented for
it. An empty list means no rule met its minimum, not that
the data is missing.

| Rule | Fires when | Minimum sample |
| ---- | ---------- | -------------- |
| Highest rejection broker | A broker has the highest rejection rate among brokers | At least 3 submitted claims for that broker, rejection rate above 0 |
| Lowest recovery facility | A facility has the lowest recovery rate vs requested | At least 3 submitted claims for that facility, recovery below 100% |
| Top rejection reason | One reason is the most common among rejections | At least 2 rejected claims in the range |
| Most missing evidence | One document category is missing on the most incomplete claims | At least 1 incomplete claim |
| Largest outstanding | Money is still outstanding on submitted claims | Outstanding total above zero |
| Detention trend rising | Detention claims rose against the preceding window | Any rise (change in claims above 0) |
| Detention rejection above average | Detention claims are rejected more often than claims overall | At least 3 submitted detention claims |
| Broker recovery fell | A broker's recovery fell sharply against the preceding window | At least 3 submitted claims this period, fall of at least 10 points |

Severity is a cutoff chosen for this report, not an
industry standard: `watch` when a rejection rate is at
least 50%, a recovery rate is below 50%, or money is
outstanding; `note` otherwise. Recommendations are
starting points for review, not instructions: they say
where the recorded numbers point, not what the
organization should do.

## Honest limits

- Everything is computed from the organization's own
  recorded claims, shipments, payments, and evidence.
  There is no external benchmark, no industry data, and no
  comparison with other organizations.
- Money is summed as recorded, with no currency
  conversion. A total that mixes currencies is labelled
  with the currencies it mixes, and per-currency rows are
  provided where the mix matters.
- Billing runs on a mock provider, so a paid claim means
  the organization recorded the money as received, not
  that a bank or processor confirmed it. No live payment
  verification is possible.
- Averages always carry their sample size. A rate over
  three claims is not presented as if it were a rate over
  three hundred.
- Recommendations are deterministic rules over the
  org's own numbers. They do not know why a broker
  rejected a claim, why a facility pays slowly, or
  whether a gap will close. Treat them as a list of
  things to look at, in order, with the numbers attached.
- There is no CSV export for the operations endpoints in
  this version. The analytics export covers the overview,
  broker, carrier, event-type, and monthly tables.

## Permissions and plans

Reading needs `view-analytics`: owner, admin, operations
manager, dispatcher, billing staff, viewer. The Free plan
is refused with a 402, the same as analytics and reports.
Cross-tenant isolation is enforced by the organization
header: a member of another organization sees only their
own (empty) operations.
