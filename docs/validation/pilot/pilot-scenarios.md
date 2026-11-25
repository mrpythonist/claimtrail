# ClaimTrail pilot scenarios: walk the shipment-to-claim workflow in under an hour

Companion to `sample-shipments.csv` in this folder and to
`../0.6.0-pilot-preparation.md`. All names, brokers, drivers, loads and
amounts are synthetic. Nothing here is a real load, a real broker, or a
real claim. The file exists so a pilot user (or Aqib, testing the
onboarding alone) can walk the full workflow once, end to end, with
realistic data instead of empty screens.

## Setup (10 minutes)

1. Create an organization. Name it something plainly synthetic, for
   example "Pilot Demo Org". Do not use a real carrier name.
2. Create the three carriers first, with these exact names, because the
   CSV importer resolves the `carrier` column by name within the
   organization and rejects a row whose carrier does not exist yet
   (`backend/app/Services/ShipmentCsvImporter.php`):
   - Prairie Line Transport
   - Bluebonnet Freight
   - Copper State Carriers
3. Import `sample-shipments.csv` through the shipment import flow.
   The importer previews first: it reports valid, invalid and
   duplicate rows before anything is created, and only valid,
   non-duplicate rows are created on confirm. Expect 12 valid rows, 0
   invalid, 0 duplicate. The file is capped well under the 1,000-row
   import limit.
4. Configure the two settings the workflow needs before the first
   claim, or the app will silently give you none of them:
   - Evidence requirements: require rate confirmation and arrival and
     departure record for detention, layover and TONU, and lumper
     receipt plus rate confirmation for lumper. The app ships no
     default requirements, so without this step readiness reports 100
     percent for a claim with no evidence at all (v0.3.0 gap 6).
   - A deadline setting: 30 days from claim creation is a reasonable
     demo default. Note the honest caveat from v0.3.0: the app
     measures the deadline from claim creation, not from delivery, so
     a claim assembled a week after delivery gets a week of extra
     time. Do not present the deadline to a pilot as a delivery-based
     deadline.
5. Evidence files in this walkthrough are placeholders. Upload plainly
   labelled sample files (for example a text file named
   `SAMPLE-rate-confirmation-PLT-1001.txt`) rather than anything
   dressed up to look like a real broker document. The point of the
   exercise is the workflow, not convincing anyone the documents are
   real.

Free time in every scenario below is 2 hours, the industry standard
from v0.1.0 (67 percent of carriers use 2 hours, ATRI). Detention
rates use the v0.1.0 range of $50-75 per hour; the scenarios use $60
per hour unless noted. Layover uses $300 per day (range $250-350).
TONU uses $250 (range $200-300). Lumper uses the receipt amount
(range $150-250). All amounts are in USD.

## The 12 shipments

### PLT-1001: detention at delivery (Prairie Line Transport)

- Load: reefer produce, Laredo TX to a Dallas TX grocery DC. Broker:
  FreshPoint Logistics. Driver: Marcus Webb. Base rate $1,850.
- Times: appointment 08:00 on 2026-11-10, arrived 07:45, departed
  13:20. Total wait 5 hours 35 minutes. Less 2 hours free time:
  3 hours 35 minutes billable.
- Event: detention, 2026-11-10, start 07:45, end 13:20, duration 335
  minutes, location Dallas TX grocery DC. Estimated amount $215
  (3.58 hours at $60 per hour).
- Claim: requested amount $215, category detention.
- Evidence to attach: rate confirmation (shows the 2-hour free time
  and $60 rate), arrival and departure record, bill of lading or
  proof of delivery with in and out times, driver photo of the gate
  or check-in screen.
- Walk it through: create the claim, move it to under review, check
  readiness, mark it ready to submit, generate the PDF package, then
  record it as submitted. Note what the app does not do: it does not
  send the package to the broker. Submission here means recording
  that a person sent it, which is v0.3.0 gap 1 and the pilot's most
  important honest limitation.

### PLT-1002: detention at pickup (Prairie Line Transport)

- Load: McAllen TX packing shed to Houston TX cold storage. Broker:
  Lone Star Freight Brokers. Driver: Dana Whitfield. Base rate $1,620.
- Times: arrived 06:50 on 2026-11-11, departed 12:40. Total wait
  5 hours 50 minutes. Less 2 hours free time: 3 hours 50 minutes
  billable.
- Event: detention, 2026-11-11, start 06:50, end 12:40, duration 350
  minutes. Estimated amount $230 (3.83 hours at $60 per hour).
- Claim: requested amount $230, category detention.
- Evidence to attach: rate confirmation, arrival and departure
  record, bill of lading, broker communication showing notice sent
  while the driver was still on site.
- Teaching point: detention happens at pickup too, not only at
  delivery. A pilot who only looks for delivery detention misses
  roughly half the billable waiting.

### PLT-1003: layover (Prairie Line Transport)

- Load: Edinburg TX to an Oklahoma City OK grocery DC. Broker: Gulf
  Coast Produce Brokers. Driver: Marcus Webb. Base rate $2,100.
- Times: arrived 08:30 on 2026-11-13, receiver closed early Friday,
  departed 07:15 on 2026-11-14. Forced overnight hold.
- Event: layover, 2026-11-13 to 2026-11-14, duration 1 day.
  Estimated amount $300 (1 day at $300 per day).
- Claim: requested amount $300, category layover.
- Evidence to attach: rate confirmation (shows the layover rate),
  arrival and departure record showing the overnight hold, broker
  communication showing the broker knew the truck was held,
  appointment confirmation for the next-day appointment.
- Teaching point: the layover claim is about the overnight hold, not
  the hours. Do not also file a detention claim for the same wait;
  the two charges cover the same time.

### PLT-1004: lumper fee (Prairie Line Transport)

- Load: Pharr TX to a Fort Worth TX grocery DC. Broker: FreshPoint
  Logistics. Driver: Rosa Delgado. Base rate $1,740.
- Times: arrived 13:40 on 2026-11-16, departed 16:05. The wait
  itself is inside free time, so there is no detention here. The
  claim is the lumper payment only.
- Event: lumper, 2026-11-16, estimated amount $180 (the receipt
  amount).
- Claim: requested amount $180, category lumper.
- Evidence to attach: the lumper receipt (the paid receipt with the
  load number on it), rate confirmation (shows lumper authorization
  or reimbursement), proof of delivery.
- Teaching point: lumper is the simplest claim in the set and the
  easiest to prove, because the receipt is the whole claim. It is
  also the charge most often paid out of pocket and never reclaimed.

### BBF-2001: detention at delivery (Bluebonnet Freight)

- Load: Tyler TX distribution center to a Memphis TN retail DC.
  Broker: Red River Logistics. Driver: James Okafor. Base rate $1,980.
- Times: arrived 09:50 on 2026-11-10, departed 15:10. Total wait
  5 hours 20 minutes. Less 2 hours free time: 3 hours 20 minutes
  billable.
- Event: detention, 2026-11-10, start 09:50, end 15:10, duration 320
  minutes. Estimated amount $200 (3.33 hours at $60 per hour).
- Claim: requested amount $200, category detention.
- Evidence to attach: rate confirmation, arrival and departure
  record, proof of delivery, driver photo.
- Walk it through to `accepted` with an accepted amount of $200,
  then record a payment of $200 with a payment date, so the pilot
  sees the full lifecycle: potential, under review, ready to submit,
  submitted, accepted, paid, closed. This is the one claim in the
  set that should reach `paid` during the walkthrough.

### BBF-2002: TONU, load cancelled after dispatch (Bluebonnet Freight)

- Load: Dallas TX to Little Rock AR. Broker: Red River Logistics.
  Driver: Priya Nair. Base rate $1,450. The broker cancelled after
  the truck was dispatched. There are no arrival or departure times
  because the truck never reached the shipper.
- Event: TONU, 2026-11-11, estimated amount $250.
- Claim: requested amount $250, category TONU.
- Evidence to attach: rate confirmation (shows the TONU amount),
  broker communication (the cancellation message with its time).
- Teaching point, and a real app gap: shipments have no cancelled
  status, so this load sits in the app as `planned` forever (v0.3.0
  gap 9). Note it, do not work around it by marking the shipment
  delivered. Walk the claim through to `rejected` with a written
  reason (for example, broker claims the cancellation came before
  dispatch), then back to under review, to show the rejection and
  rework path. A rejected claim is not a dead claim.

### BBF-2003: detention at pickup (Bluebonnet Freight)

- Load: Waco TX manufacturer to a Nashville TN warehouse. Broker:
  Trinity Freight Partners. Driver: James Okafor. Base rate $2,210.
- Times: arrived 07:15 on 2026-11-12, departed 11:55. Total wait
  4 hours 40 minutes. Less 2 hours free time: 2 hours 40 minutes
  billable.
- Event: detention, 2026-11-12, start 07:15, end 11:55, duration 280
  minutes. Estimated amount $160 (2.67 hours at $60 per hour).
- Claim: requested amount $160, category detention.
- Evidence to attach: rate confirmation, arrival and departure
  record, bill of lading.
- Walk it through to `partially_accepted`: the broker agrees to 2
  hours ($120) and disputes the rest. Record the accepted amount as
  $120, then record a payment of $120. This shows the partial
  acceptance path and the visible gap between requested, accepted
  and recovered amounts, which is the leakage story in numbers.

### BBF-2004: layover (Bluebonnet Freight)

- Load: Fort Worth TX to an Atlanta GA distribution center. Broker:
  Trinity Freight Partners. Driver: Priya Nair. Base rate $2,450.
- Times: arrived 08:45 on 2026-11-14, appointment pushed to the next
  day, departed 08:00 on 2026-11-15. Forced overnight hold.
- Event: layover, 2026-11-14 to 2026-11-15, duration 1 day.
  Estimated amount $300.
- Claim: requested amount $300, category layover.
- Evidence to attach: rate confirmation, arrival and departure
  record, broker communication, appointment confirmation for the
  next-day appointment.
- Walk it through to `submitted` and leave it there, so the pilot
  ends with a claim awaiting a broker response. That is the honest
  state of most real claims at any given moment, and it shows the
  app's weakest spot: after submission there is no follow-up
  schedule, no payment-overdue state, and no record of broker
  silence (v0.3.0 gaps 8 and 13).

### CSC-3001: detention at delivery (Copper State Carriers)

- Load: El Paso TX to a Phoenix AZ grocery DC. Broker: Desert Sky
  Brokers. Driver: Tom Becker. Base rate $1,560.
- Times: arrived 07:55 on 2026-11-11, departed 12:30. Total wait
  4 hours 35 minutes. Less 2 hours free time: 2 hours 35 minutes
  billable.
- Event: detention, 2026-11-11, start 07:55, end 12:30, duration 275
  minutes. Estimated amount $155 (2.58 hours at $60 per hour).
- Claim: requested amount $155, category detention.
- Evidence to attach: rate confirmation, arrival and departure
  record, proof of delivery.
- Teaching point for the smallest pilot profile: Copper State
  Carriers is a 3-truck fleet, the Starter tier buyer. One claim
  like this at $155 pays for about eight months of Starter at $19
  per month. That is the value sentence the pilot should be asked
  to judge in their own words, not told in advance.

### CSC-3002: lumper fee (Copper State Carriers)

- Load: Tucson AZ produce shipper to a San Antonio TX retail DC.
  Broker: Desert Sky Brokers. Driver: Ana Ruiz. Base rate $1,890.
- Times: arrived 09:50 on 2026-11-13, departed 12:10. No detention:
  the wait is inside free time.
- Event: lumper, 2026-11-13, estimated amount $200 (the receipt
  amount).
- Claim: requested amount $200, category lumper.
- Evidence to attach: lumper receipt, rate confirmation, proof of
  delivery.
- Walk it through to `ready_to_submit` and stop there, with the
  readiness check showing ready. This is the claim to use when
  demonstrating the readiness checklist to a pilot: attach the
  evidence, watch readiness move, remove one file, watch it drop.

### CSC-3003: TONU, cancelled same day (Copper State Carriers)

- Load: Phoenix AZ to Albuquerque NM. Broker: Sun Belt Freight.
  Driver: Tom Becker. Base rate $1,320. Cancelled the same day after
  dispatch; no arrival or departure times.
- Event: TONU, 2026-11-16, estimated amount $250.
- Claim: requested amount $250, category TONU.
- Evidence to attach: rate confirmation, broker communication (the
  cancellation message with its time).
- Walk it through to `submitted` and leave it there alongside
  BBF-2004, so the pilot's dashboard shows two claims awaiting a
  broker response at the end of the hour.

### CSC-3004: the control row, no claim due (Copper State Carriers)

- Load: Flagstaff AZ to a Las Vegas NV distribution center. Broker:
  Sun Belt Freight. Driver: Ana Ruiz. Base rate $1,480.
- Times: arrived 07:55 on 2026-11-17, departed 09:20. Total wait
  1 hour 25 minutes, inside the 2-hour free time. Nothing is owed.
- Event: detention, 2026-11-17, start 07:55, end 09:20, duration 85
  minutes. Estimated amount $0.
- Do not create a claim. Move the event to `dismissed`. This is the
  most important row in the file for teaching purposes: a product
  that turns every wait into a claim is a product that files claims
  brokers reject, and rejected claims cost the relationship. The
  dismiss path is part of the workflow, not a failure of it.

## Totals for the set [estimate]

Eleven claims from twelve shipments, one event dismissed:

| Event type | Claims | Requested total |
| --- | --- | --- |
| Detention | 5 | $960 |
| Layover | 2 | $600 |
| TONU | 2 | $500 |
| Lumper | 2 | $380 |
| Total | 11 | $2,440 |

The $2,440 is the requested total, not recovered money. In the
walkthrough, one claim is paid in full ($200), one is partially
accepted and paid ($120), two sit submitted awaiting a response, one
is rejected and reworked, and the rest stop at ready to submit or
earlier. That spread is deliberate: it mirrors the real world, where
fewer than 50 percent of billed detention invoices are paid (v0.1.0),
and it stops the pilot from reading a demo as a promise.

## What the walkthrough deliberately does not show

- No GPS capture. Every time in the file was typed in, exactly as a
  pilot's times would be typed in. Say so out loud when demoing.
- No sending. The PDF package is generated and never emailed. The
  pilot sends it themselves from their own mailbox.
- No payment collection. Payments are recorded by hand after the
  broker pays outside the app.
- No multi-carrier agency view. The three carriers sit in one
  organization here for demo convenience. A real agency runs one
  organization per carrier; the agency dashboard is a v2.1.0
  feature, not a pilot feature. Do not demo the single-organization
  shortcut as the agency product.

## After the walkthrough

Export the reports CSV (`GET /api/v1/reports/export`) and keep it
with the pilot's notes: it is the baseline for the metrics in
`0.6.0-pilot-preparation.md` section 6. Delete the demo organization
before a real pilot starts, or start the real pilot in a fresh
organization. Demo data and pilot data must not mix, because every
pilot metric is measured from the same reports the demo just filled.
