/**
 * Shared data for the public marketing site (v1.2.2).
 *
 * Prices and limits are the v0.5.0 section 8 tiers, the same
 * numbers config/billing.php serves at GET /v1/plans. The pricing
 * page is a static server component, so it reads them from here
 * instead of calling the API at request time; the smoke test
 * checks the two agree. Prices are hypotheses from the v0.5.0
 * validation, unvalidated until v0.2.0 interviews.
 *
 * Demo data is the v0.6.0 pilot sample: twelve synthetic
 * shipments across three fictional carriers, eleven claims
 * totalling $2,440 requested. Every name, broker, load and amount
 * is synthetic; nothing here is a real load, broker, or claim.
 */

export interface MarketingPlan {
  key: string;
  name: string;
  monthlyCents: number;
  /** Annual price in cents: 10x monthly (two months free). */
  annualCents: number;
  trucks: string;
  /** Extra carrier price for the Agency tier, cents per month. */
  perExtraCarrierCents: number;
  includedCarriers: number;
  description: string;
  features: string[];
  /** The plan the pricing page highlights as the default. */
  highlighted: boolean;
}

export const MARKETING_PLANS: MarketingPlan[] = [
  {
    key: "free",
    name: "Free",
    monthlyCents: 0,
    annualCents: 0,
    trucks: "1 truck",
    perExtraCarrierCents: 0,
    includedCarriers: 0,
    description: "One-truck owner-operator keeping a record.",
    features: [
      "5 claims per month",
      "1 member",
      "100 MB storage",
      "30 days of history",
      "Deadlines and readiness checks",
    ],
    highlighted: false,
  },
  {
    key: "starter",
    name: "Starter",
    monthlyCents: 1900,
    annualCents: 19000,
    trucks: "1-2 trucks",
    perExtraCarrierCents: 0,
    includedCarriers: 0,
    description: "Owner-operator or 2-truck fleet that bills detention regularly.",
    features: [
      "20 claims per month",
      "3 members",
      "1 GB storage",
      "PDF claim packages",
      "Full reports",
      "Deadlines and readiness checks",
    ],
    highlighted: false,
  },
  {
    key: "growth",
    name: "Growth",
    monthlyCents: 4900,
    annualCents: 49000,
    trucks: "3-10 trucks",
    perExtraCarrierCents: 0,
    includedCarriers: 0,
    description: "The 3-10 truck fleet and the small dispatcher. The default tier.",
    features: [
      "100 claims per month",
      "10 members",
      "10 GB storage",
      "Everything in Starter",
      "Custom fields and event categories",
      "Evidence templates and export templates",
      "Operations dashboard",
    ],
    highlighted: true,
  },
  {
    key: "fleet",
    name: "Fleet",
    monthlyCents: 9900,
    annualCents: 99000,
    trucks: "11-30 trucks",
    perExtraCarrierCents: 0,
    includedCarriers: 0,
    description: "The 11-30 truck carrier.",
    features: [
      "300 claims per month",
      "25 members",
      "50 GB storage",
      "Everything in Growth",
      "Priority support",
    ],
    highlighted: false,
  },
  {
    key: "agency",
    name: "Agency",
    monthlyCents: 14900,
    annualCents: 149000,
    trucks: "Up to 10 carriers",
    perExtraCarrierCents: 1200,
    includedCarriers: 10,
    description: "Dispatch agencies running claims for many carriers in one account.",
    features: [
      "100 claims per month, per carrier",
      "Unlimited members across carriers",
      "10 GB storage per carrier",
      "Everything in Growth",
      "$12 per month for each carrier beyond 10",
    ],
    highlighted: false,
  },
];

/** The plan keys the pricing page and signup flow accept. */
export const LISTED_PLAN_KEYS = MARKETING_PLANS.map((plan) => plan.key);

export function isListedPlanKey(value: string | null): value is string {
  return value !== null && LISTED_PLAN_KEYS.includes(value);
}

export function planName(key: string): string {
  return MARKETING_PLANS.find((plan) => plan.key === key)?.name ?? key;
}

/** Format integer cents as dollars, e.g. 4900 -> "$49", 244000 -> "$2,440". */
export function formatDollars(cents: number): string {
  return `$${(cents / 100).toLocaleString("en-US")}`;
}

/** The trial offer, from v0.5.0 section 7: 30 days, Growth, no card. */
export const TRIAL_OFFER = {
  plan: "growth",
  days: 30,
  requiresCard: false,
} as const;

export interface MarketingFeature {
  slug: string;
  title: string;
  short: string;
  sections: { heading: string; body: string }[];
  /** Honest limits, from the v0.3.0 workflow validation where they apply. */
  limits: string[];
}

export const MARKETING_FEATURES: MarketingFeature[] = [
  {
    slug: "claims",
    title: "Claims",
    short:
      "One claim per event, with a ten-status lifecycle from potential to paid, and the requested, accepted, and recovered amounts kept separate.",
    sections: [
      {
        heading: "The claim is the object",
        body: "A claim asks a broker to pay for one event: detention, layover, TONU, lumper, an extra stop, or extra miles. It carries the amount requested, the amount the broker accepted, and the amount actually paid, so the gap between requested and recovered is visible instead of hidden.",
      },
      {
        heading: "A lifecycle, not a status field",
        body: "Claims move through ten statuses: potential, under review, ready to submit, submitted, accepted, partially accepted, rejected, paid, closed, and archived. A rejected claim is not a dead claim: it goes back to under review with a written reason, so rework is part of the workflow.",
      },
      {
        heading: "Readiness before submission",
        body: "Before a claim is marked ready to submit, ClaimTrail checks the evidence the organization requires for that event type. A claim with missing evidence cannot be called ready.",
      },
    ],
    limits: [
      "ClaimTrail does not send the claim package to the broker. Submission means recording that a person sent it; there is no submission channel yet.",
      "After submission there is no follow-up schedule and no payment-overdue state. A claim waiting on a broker waits quietly.",
      "One claim links to at most one event. A load with two chargeable events needs two claims.",
    ],
  },
  {
    slug: "evidence",
    title: "Evidence",
    short:
      "Rate confirmations, bills of lading, receipts, and photos attached to the shipment and the claim, with requirements per event type and SHA-256 verification.",
    sections: [
      {
        heading: "Evidence requirements per event type",
        body: "An organization decides what proves each kind of charge: a rate confirmation and an arrival and departure record for detention, a lumper receipt for lumper. The requirements are checked at readiness, so a claim is only ready when its evidence is complete.",
      },
      {
        heading: "Verified on download",
        body: "Every upload is stored with a SHA-256 checksum, and the checksum is verified again on download and preview. A file that changed since upload is reported, not served silently.",
      },
      {
        heading: "Templates for repeat work",
        body: "Evidence templates list the documents a claim of a given type usually needs, so the dispatcher does not rebuild the checklist for every load.",
      },
    ],
    limits: [
      "The app ships no default evidence requirements. Until an organization configures them, readiness can report 100 percent for a claim with no evidence at all.",
      "Uploaded files are not scanned for malware. That check belongs to the organization before upload.",
    ],
  },
  {
    slug: "deadlines",
    title: "Deadlines",
    short:
      "Configurable submission deadlines per claim type, refreshed hourly, compared in the organization's timezone, with a list of what is due soon.",
    sections: [
      {
        heading: "Deadlines as a first-class concept",
        body: "An organization sets how long it has to submit each kind of claim: 30 days is a reasonable default. The deadline list shows what is due soon, and the status refreshes every hour.",
      },
      {
        heading: "Timezone-aware",
        body: "Deadlines are compared in the organization's own timezone, not the server's, so a carrier in Texas and a dispatcher in another state see the same due date.",
      },
    ],
    limits: [
      "The deadline clock runs from claim creation, not from delivery. A claim assembled a week after delivery gets a week of extra time.",
      "When an organization configures no deadline setting, a claim has no deadline at all.",
    ],
  },
  {
    slug: "pdf-packages",
    title: "PDF claim packages",
    short:
      "A generated claim package: the claim, its event, and its evidence listed in one document, with a manifest and a checksum.",
    sections: [
      {
        heading: "One document per claim",
        body: "Generating a package assembles the claim, the event it is built on, and the evidence attached to it into a single PDF, with a manifest that lists every file and its SHA-256 checksum.",
      },
      {
        heading: "Ready packages are never purged",
        body: "Retention policies clean up old files, but a generated package that is ready to send is kept. A claim that is ready does not lose its package to a cleanup job.",
      },
    ],
    limits: [
      "PDF packages are not available on the Free plan.",
      "The package is a document to send, not a submission: the app does not email it to the broker.",
    ],
  },
  {
    slug: "reports",
    title: "Reports",
    short:
      "Recovery by status, type, broker, and carrier, with the requested, accepted, and recovered amounts side by side.",
    sections: [
      {
        heading: "Recovery in numbers",
        body: "Reports break claims down by status, charge type, broker, and carrier, and show requested, accepted, and recovered amounts together, so the leakage between what was asked and what was paid is a number, not a feeling.",
      },
      {
        heading: "Exports for the books",
        body: "Reports and lists export to CSV with the organization's own custom fields and export templates, so the numbers can leave ClaimTrail and enter the accounting workflow.",
      },
    ],
    limits: [
      "The Free plan shows the dashboard only. Full reports need Starter or above.",
      "Reports count what was recorded in ClaimTrail. Money collected outside the app only appears if someone records it.",
    ],
  },
  {
    slug: "multi-tenancy",
    title: "Multi-tenancy and roles",
    short:
      "One account per organization, six roles from owner to read-only viewer, and an audit trail of every important change.",
    sections: [
      {
        heading: "Every organization's data stays separate",
        body: "Shipments, claims, evidence, and members belong to one organization. Cross-tenant access is refused, and a tenant isolation audit runs against the code and the routes.",
      },
      {
        heading: "Six roles, least privilege",
        body: "Owner, admin, operations manager, dispatcher, billing staff, and read-only viewer. Each role gets the access its job needs and nothing more, and the role matrix is tested.",
      },
      {
        heading: "An audit trail",
        body: "Important changes are recorded with who made them and what changed, including destination changes in the audit log. The trail is append-only.",
      },
    ],
    limits: [
      "There is no per-carrier role inside an agency account yet: the agency dashboard is a later version.",
      "Session tokens do not expire on their own. That is a known gap, listed with the other security items.",
    ],
  },
  {
    slug: "billing",
    title: "Billing and trials",
    short:
      "Five tiers plus a success-fee option, a 30-day no-card Growth trial, proration on upgrades, and dunning that never deletes data.",
    sections: [
      {
        heading: "The 30-day trial",
        body: "A new organization starts on Free and can start a 30-day trial on the Growth tier with no card. At the end, it converts to paid Growth with a payment method, or lapses back to Free. Nothing is deleted either way.",
      },
      {
        heading: "Upgrades prorate, downgrades wait",
        body: "Upgrades apply immediately with a prorated credit for the unused part of the old plan. Downgrades apply at the end of the current period, and a downgrade blocks new claims above the lower limit while keeping everything already created.",
      },
      {
        heading: "The success-fee option",
        body: "For buyers blocked by price, there is a $0 base plan that charges 15 percent of amounts recorded as paid, capped at $49 a month for the first year. It is offered at the point where price blocks a trial buyer, not as the default.",
      },
    ],
    limits: [
      "Billing runs on a mock provider for now: no real Stripe calls and no real money moves. Real Stripe is a later provider swap.",
      "A failed payment is retried, then the organization is downgraded to Free after 14 days past due. Data is never deleted by billing.",
    ],
  },
];

export interface DemoStep {
  title: string;
  body: string;
}

export const DEMO_STEPS: DemoStep[] = [
  {
    title: "The shipment",
    body: "PLT-1001: a reefer produce load from Laredo TX to a Dallas TX grocery DC, broker FreshPoint Logistics, driver Marcus Webb, base rate $1,850. Appointment 08:00 on 2026-11-10.",
  },
  {
    title: "The event",
    body: "The truck arrived 07:45 and departed 13:20: 5 hours 35 minutes at the dock. Less 2 hours free time, 3 hours 35 minutes are billable. The detention event is recorded against the shipment at $60 an hour: $215 estimated.",
  },
  {
    title: "The evidence",
    body: "The rate confirmation (2-hour free time, $60 rate), the arrival and departure record, and the bill of lading are attached. The organization requires rate confirmation and arrival and departure records for detention, so readiness is checked against those requirements.",
  },
  {
    title: "The claim",
    body: "A claim is created for the detention event: $215 requested. The claim moves from potential to under review, and the readiness check shows what is still missing, if anything.",
  },
  {
    title: "Review and package",
    body: "With the evidence complete, the claim is marked ready to submit and the PDF package is generated: claim, event, and evidence in one document with a manifest and checksums.",
  },
  {
    title: "Submission, recorded",
    body: "The claim is marked submitted. ClaimTrail does not send the package to the broker: submission here means recording that a person sent it. That is the honest limit of the current version.",
  },
  {
    title: "Payment",
    body: "When the broker pays, the payment is recorded against the claim with its date, and the claim moves to paid and then closed. Requested, accepted, and recovered stay separate, so the gap is visible.",
  },
];

export interface DemoShipment {
  ref: string;
  carrier: string;
  load: string;
  event: string;
  amountCents: number;
  outcome: string;
}

export const DEMO_SHIPMENTS: DemoShipment[] = [
  { ref: "PLT-1001", carrier: "Prairie Line Transport", load: "Laredo TX to Dallas TX, reefer produce", event: "Detention at delivery", amountCents: 21500, outcome: "Walked through above" },
  { ref: "PLT-1002", carrier: "Prairie Line Transport", load: "McAllen TX to Houston TX, cold storage", event: "Detention at pickup", amountCents: 23000, outcome: "Claim created" },
  { ref: "PLT-1003", carrier: "Prairie Line Transport", load: "Edinburg TX to Oklahoma City OK", event: "Layover, forced overnight", amountCents: 30000, outcome: "Claim created" },
  { ref: "PLT-1004", carrier: "Prairie Line Transport", load: "Pharr TX to Fort Worth TX", event: "Lumper fee, receipt $180", amountCents: 18000, outcome: "Claim created" },
  { ref: "BBF-2001", carrier: "Bluebonnet Freight", load: "Tyler TX to Memphis TN, retail DC", event: "Detention at delivery", amountCents: 20000, outcome: "Paid in full" },
  { ref: "BBF-2002", carrier: "Bluebonnet Freight", load: "Dallas TX to Little Rock AR", event: "TONU, cancelled after dispatch", amountCents: 25000, outcome: "Rejected, then reworked" },
  { ref: "BBF-2003", carrier: "Bluebonnet Freight", load: "Waco TX to Nashville TN", event: "Detention at pickup", amountCents: 16000, outcome: "Partially accepted, $120 paid" },
  { ref: "BBF-2004", carrier: "Bluebonnet Freight", load: "Fort Worth TX to Atlanta GA", event: "Layover, appointment pushed", amountCents: 30000, outcome: "Submitted, awaiting broker" },
  { ref: "CSC-3001", carrier: "Copper State Carriers", load: "El Paso TX to Phoenix AZ, grocery DC", event: "Detention at delivery", amountCents: 15500, outcome: "Claim created" },
  { ref: "CSC-3002", carrier: "Copper State Carriers", load: "Tucson AZ to San Antonio TX", event: "Lumper fee, receipt $200", amountCents: 20000, outcome: "Ready to submit" },
  { ref: "CSC-3003", carrier: "Copper State Carriers", load: "Phoenix AZ to Albuquerque NM", event: "TONU, cancelled same day", amountCents: 25000, outcome: "Submitted, awaiting broker" },
  { ref: "CSC-3004", carrier: "Copper State Carriers", load: "Flagstaff AZ to Las Vegas NV", event: "Detention, 1h 25m inside free time", amountCents: 0, outcome: "No claim due, event dismissed" },
];

/** Total requested across the eleven claims in the demo set, in cents. */
export const DEMO_TOTAL_REQUESTED_CENTS = DEMO_SHIPMENTS.reduce(
  (sum, shipment) => sum + shipment.amountCents,
  0,
);

export interface ScreenshotEntry {
  key: string;
  title: string;
  description: string;
  /** File in public/screenshots/, or null until a real capture exists. */
  file: string | null;
}

export const SCREENSHOT_ENTRIES: ScreenshotEntry[] = [
  { key: "dashboard", title: "Dashboard", description: "Claims by status, amounts requested and recovered, and what is due soon.", file: null },
  { key: "shipments", title: "Shipments", description: "The load list with carriers, brokers, appointments, and statuses.", file: null },
  { key: "claim-detail", title: "Claim detail", description: "One claim with its event, evidence, readiness, and status history.", file: null },
  { key: "evidence", title: "Evidence", description: "Uploaded documents with requirements, previews, and checksum verification.", file: null },
  { key: "deadlines", title: "Deadlines", description: "The deadline list: what is due soon and what is overdue.", file: null },
  { key: "billing", title: "Billing", description: "The current plan, usage against limits, invoices, and the trial.", file: null },
  { key: "reports", title: "Reports", description: "Recovery by status, type, broker, and carrier.", file: null },
];

/** Public marketing routes, for the sitemap and the nav. */
export const MARKETING_ROUTES = [
  { path: "/", priority: 1.0 },
  { path: "/product", priority: 0.9 },
  { path: "/features", priority: 0.9 },
  ...MARKETING_FEATURES.map((feature) => ({
    path: `/features/${feature.slug}`,
    priority: 0.8,
  })),
  { path: "/pricing", priority: 0.9 },
  { path: "/demo", priority: 0.8 },
  { path: "/demo-request", priority: 0.7 },
  { path: "/screenshots", priority: 0.7 },
  { path: "/getting-started", priority: 0.8 },
  { path: "/help", priority: 0.7 },
  { path: "/privacy", priority: 0.3 },
  { path: "/terms", priority: 0.3 },
  { path: "/login", priority: 0.5 },
  { path: "/register", priority: 0.7 },
];
