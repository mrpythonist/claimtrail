import Link from "next/link";
import { MarketingLayout } from "@/components/layout/MarketingLayout";
import { MARKETING_FEATURES } from "@/lib/marketing";
import { marketingMetadata } from "@/lib/seo";

export const metadata = marketingMetadata({
  title: "Product overview - ClaimTrail",
  description:
    "What ClaimTrail does: one workflow for the shipment, the event, the evidence, the deadline, and the payment, for trucking companies and dispatch agencies.",
  path: "/product",
});

const WORKFLOW = [
  {
    title: "Shipment",
    body: "One load: the broker, the carrier, the driver, the rate, and the pickup and delivery appointments. Shipments can be added one at a time or imported from CSV.",
  },
  {
    title: "Event",
    body: "Something chargeable happened on the load: detention at a dock, a forced overnight layover, a truck ordered not used, a lumper fee paid at the dock. The event records what happened, when, and what it is worth.",
  },
  {
    title: "Evidence",
    body: "The documents that prove the charge: rate confirmations, bills of lading, proof of delivery, receipts, photos. Evidence is checked against the requirements the organization set for that event type.",
  },
  {
    title: "Claim",
    body: "The claim asks the broker to pay for one event. It moves through ten statuses from potential to paid, with requested, accepted, and recovered amounts kept separate.",
  },
  {
    title: "Deadline",
    body: "Every claim has a submission deadline, set by the organization per claim type and compared in the organization's timezone.",
  },
  {
    title: "Package",
    body: "When the evidence is complete, the claim package is generated: claim, event, and evidence in one PDF with a manifest and checksums.",
  },
  {
    title: "Payment",
    body: "When the broker pays, the payment is recorded with its date. The claim moves to paid and then closed, and the recovery shows up in the reports.",
  },
];

export default function ProductPage() {
  return (
    <MarketingLayout>
      <section className="border-b border-slate-200 bg-slate-50">
        <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
          <h1 className="max-w-3xl text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
            The desk where detention, layover, TONU, and lumper claims get
            filed, tracked, and paid.
          </h1>
          <p className="mt-6 max-w-2xl text-lg leading-relaxed text-slate-600">
            ClaimTrail is built for the two buyers the validation work
            identified: dispatch agencies running claims for many carriers
            in one account, and 2 to 20 truck fleets running reefer or
            spot freight, where a truck that waits is a truck that should
            be paid for waiting.
          </p>
          <div className="mt-8 flex flex-wrap gap-3">
            <Link
              href="/register"
              className="rounded-md bg-brand-700 px-5 py-2.5 text-base font-medium text-white hover:bg-brand-800"
            >
              Start a 30-day free trial
            </Link>
            <Link
              href="/demo"
              className="rounded-md border border-slate-300 bg-white px-5 py-2.5 text-base font-medium text-slate-700 hover:bg-slate-50"
            >
              Walk the demo
            </Link>
          </div>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <h2 className="text-2xl font-semibold tracking-tight text-slate-900">
          One workflow, seven steps
        </h2>
        <ol className="mt-8 space-y-6">
          {WORKFLOW.map((step, index) => (
            <li key={step.title} className="flex gap-4">
              <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-700 text-sm font-semibold text-white">
                {index + 1}
              </span>
              <div>
                <h3 className="text-lg font-semibold text-slate-900">{step.title}</h3>
                <p className="mt-1 max-w-2xl text-sm leading-relaxed text-slate-600">
                  {step.body}
                </p>
              </div>
            </li>
          ))}
        </ol>
      </section>

      <section className="border-t border-slate-200 bg-slate-50">
        <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
          <h2 className="text-2xl font-semibold tracking-tight text-slate-900">
            What it is, and what it is not
          </h2>
          <div className="mt-8 grid gap-6 sm:grid-cols-2">
            <div className="rounded-lg border border-slate-200 bg-white p-6">
              <h3 className="text-lg font-semibold text-slate-900">What it is</h3>
              <ul className="mt-3 list-disc space-y-2 pl-5 text-sm leading-relaxed text-slate-600">
                <li>A record of every chargeable event, with its evidence.</li>
                <li>A claim lifecycle with readiness checks and deadlines.</li>
                <li>A generated package to send to the broker.</li>
                <li>A record of what was actually paid, against what was asked.</li>
                <li>One account per organization, with roles and an audit trail.</li>
              </ul>
            </div>
            <div className="rounded-lg border border-slate-200 bg-white p-6">
              <h3 className="text-lg font-semibold text-slate-900">What it is not</h3>
              <ul className="mt-3 list-disc space-y-2 pl-5 text-sm leading-relaxed text-slate-600">
                <li>It does not send claims to brokers. Submission is recorded by a person.</li>
                <li>It does not guarantee a charge is recoverable. Eligibility is contractual and yours to verify.</li>
                <li>It does not capture GPS or ELD times. Times are entered by the people who were there.</li>
                <li>It does not chase brokers after submission. There is no follow-up schedule yet.</li>
              </ul>
            </div>
          </div>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <h2 className="text-2xl font-semibold tracking-tight text-slate-900">
          Features
        </h2>
        <div className="mt-8 grid gap-6 sm:grid-cols-2">
          {MARKETING_FEATURES.map((feature) => (
            <Link
              key={feature.slug}
              href={`/features/${feature.slug}`}
              className="rounded-lg border border-slate-200 bg-white p-6 hover:border-brand-400"
            >
              <h3 className="text-lg font-semibold text-slate-900">{feature.title}</h3>
              <p className="mt-2 text-sm leading-relaxed text-slate-600">{feature.short}</p>
              <p className="mt-3 text-sm font-medium text-brand-700">Read more</p>
            </Link>
          ))}
        </div>
        <div className="mt-10 flex flex-wrap gap-3">
          <Link
            href="/pricing"
            className="rounded-md bg-brand-700 px-5 py-2.5 text-base font-medium text-white hover:bg-brand-800"
          >
            See pricing
          </Link>
          <Link
            href="/getting-started"
            className="rounded-md border border-slate-300 bg-white px-5 py-2.5 text-base font-medium text-slate-700 hover:bg-slate-50"
          >
            How to get started
          </Link>
        </div>
      </section>
    </MarketingLayout>
  );
}
