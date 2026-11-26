import Link from "next/link";
import { MarketingLayout } from "@/components/layout/MarketingLayout";
import { marketingMetadata, softwareApplicationJsonLd } from "@/lib/seo";

export const metadata = marketingMetadata({
  title: "ClaimTrail - Every shipment. Every entitlement. Every dollar accounted for.",
  description:
    "ClaimTrail helps trucking companies track additional shipment charges, keep the evidence together, and follow each claim through to payment.",
  path: "/",
});

const WORKFLOW_STEPS = [
  {
    title: "Record the shipment",
    description:
      "Log the load, the broker, the rate, and the appointments in one place instead of across spreadsheets and messages.",
  },
  {
    title: "Capture the event",
    description:
      "Detention, layover, TONU, lumper, extra stops and extra miles are recorded against the shipment as potential opportunities, never as guaranteed money.",
  },
  {
    title: "Collect the evidence",
    description:
      "Rate confirmations, bills of lading, proof of delivery, receipts, and photos stay attached to the shipment and the claim.",
  },
  {
    title: "Prepare and track the claim",
    description:
      "Check what evidence is still missing, watch the submission deadline, generate the claim package, and record what was actually paid.",
  },
];

export default function Home() {
  const jsonLd = softwareApplicationJsonLd();

  return (
    <MarketingLayout>
      {jsonLd && (
        <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }}
        />
      )}
      <section className="border-b border-slate-200 bg-slate-50">
        <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6 sm:py-24">
          <h1 className="max-w-3xl text-4xl font-semibold leading-tight tracking-tight text-slate-900 sm:text-5xl">
            Every shipment. Every entitlement. Every dollar accounted for.
          </h1>
          <p className="mt-6 max-w-2xl text-lg leading-relaxed text-slate-600">
            ClaimTrail helps trucking companies and dispatchers keep track of
            additional shipment charges: detention, layover, TONU, lumper,
            and the rest. One workflow for the shipment, the event, the
            evidence, the deadline, and the payment.
          </p>
          <div className="mt-8 flex flex-wrap gap-3">
            <Link
              href="/register"
              className="rounded-md bg-brand-700 px-5 py-2.5 text-base font-medium text-white hover:bg-brand-800"
            >
              Create your organization
            </Link>
            <Link
              href="/demo"
              className="rounded-md border border-slate-300 bg-white px-5 py-2.5 text-base font-medium text-slate-700 hover:bg-slate-50"
            >
              Walk the demo
            </Link>
            <Link
              href="/pricing"
              className="rounded-md border border-slate-300 bg-white px-5 py-2.5 text-base font-medium text-slate-700 hover:bg-slate-50"
            >
              See pricing
            </Link>
          </div>
          <p className="mt-6 max-w-2xl text-sm text-slate-500">
            ClaimTrail tracks potential charges and the evidence behind them.
            It never guarantees that a charge is recoverable. Contractual
            eligibility and submission requirements are yours to verify.
          </p>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <h2 className="text-2xl font-semibold tracking-tight text-slate-900">
          How it works
        </h2>
        <div className="mt-8 grid gap-6 sm:grid-cols-2">
          {WORKFLOW_STEPS.map((step, index) => (
            <div
              key={step.title}
              className="rounded-lg border border-slate-200 bg-white p-6"
            >
              <p className="text-sm font-semibold text-brand-600">
                Step {index + 1}
              </p>
              <h3 className="mt-2 text-lg font-semibold text-slate-900">
                {step.title}
              </h3>
              <p className="mt-2 text-sm leading-relaxed text-slate-600">
                {step.description}
              </p>
            </div>
          ))}
        </div>
        <p className="mt-8 text-sm">
          <Link href="/product" className="font-medium text-brand-700 hover:underline">
            Read the full product overview
          </Link>
          {" or "}
          <Link href="/features" className="font-medium text-brand-700 hover:underline">
            browse the features
          </Link>
          .
        </p>
      </section>

      <section className="border-t border-slate-200 bg-slate-50">
        <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
          <h2 className="text-2xl font-semibold tracking-tight text-slate-900">
            Built for operations teams
          </h2>
          <p className="mt-3 max-w-2xl text-sm leading-relaxed text-slate-600">
            Owners, admins, operations managers, dispatchers, billing staff,
            and read-only viewers each get the access their role needs and
            nothing more. Every organization&apos;s data stays separate, and
            every important change is recorded in an audit trail.
          </p>
          <div className="mt-8 flex flex-wrap gap-3">
            <Link
              href="/pricing"
              className="rounded-md bg-brand-700 px-5 py-2.5 text-base font-medium text-white hover:bg-brand-800"
            >
              Pricing from $0, trial free for 30 days
            </Link>
            <Link
              href="/demo-request"
              className="rounded-md border border-slate-300 bg-white px-5 py-2.5 text-base font-medium text-slate-700 hover:bg-slate-50"
            >
              Request a demo
            </Link>
          </div>
        </div>
      </section>
    </MarketingLayout>
  );
}
