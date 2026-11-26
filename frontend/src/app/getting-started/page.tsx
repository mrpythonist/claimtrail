import Link from "next/link";
import { MarketingLayout } from "@/components/layout/MarketingLayout";
import { marketingMetadata } from "@/lib/seo";

export const metadata = marketingMetadata({
  title: "Getting started - ClaimTrail",
  description:
    "From signup to your first claim in a day: register, create your organization, start the 30-day Growth trial, and work through the seven first steps.",
  path: "/getting-started",
});

const FIRST_STEPS = [
  {
    title: "Create your organization",
    body: "Your organization is the workspace for your company. Everything in ClaimTrail, shipments, claims, evidence, belongs to one organization.",
  },
  {
    title: "Invite a member",
    body: "Invite the people who work on claims with you. Each member gets a role that decides what they can see and change.",
  },
  {
    title: "Create a shipment",
    body: "A shipment is one load. Add the broker, the carrier, and the pickup and delivery appointments so events and claims have somewhere to live.",
  },
  {
    title: "Add an event",
    body: "An event is something chargeable that happened on a load, for example detention at a dock. Events are what claims are built from.",
  },
  {
    title: "Upload evidence",
    body: "Upload the documents that prove the charge: rate confirmations, bills of lading, receipts, and photos. Claims need evidence before they can be submitted.",
  },
  {
    title: "Create a claim",
    body: "A claim asks a broker to pay for one event. Set the amount, link the event, and attach the evidence that supports it.",
  },
  {
    title: "Review the claim",
    body: "Review checks that the claim has everything it needs. Once the evidence is complete, the claim is ready to send to the broker.",
  },
];

const TIMELINE = [
  {
    when: "Day 0",
    title: "Register and create your organization",
    body: "Register with your work email, then create your organization. You start on the Free plan.",
  },
  {
    when: "Day 0",
    title: "Start the 30-day Growth trial",
    body: "From the onboarding page, start the trial: 30 days on the Growth tier, no card required. The trial is the whole product, not a cut-down version.",
  },
  {
    when: "Day 1",
    title: "Work the seven first steps",
    body: "The onboarding checklist in the app walks through the steps below, in order. Each one takes a few minutes. Configure your evidence requirements and a deadline setting first, or readiness will report 100 percent for a claim with no evidence.",
  },
  {
    when: "Days 1-7",
    title: "File your first real claims",
    body: "Bring a week's real loads: the detention that already happened, the lumper receipt in the glove box. A claim paid during the trial is the trial working.",
  },
  {
    when: "Day 30",
    title: "Decide",
    body: "With a payment method, the trial converts to paid Growth. Without one, it lapses back to Free. Nothing is deleted either way, and the success-fee plan is there if price is the blocker.",
  },
];

export default function GettingStartedPage() {
  return (
    <MarketingLayout>
      <section className="border-b border-slate-200 bg-slate-50">
        <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
          <h1 className="text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
            Getting started
          </h1>
          <p className="mt-4 max-w-2xl text-lg leading-relaxed text-slate-600">
            From signup to your first claim in a day, and to a decision
            in 30 days. The trial is the whole product: if a claim gets
            paid during it, the trial did its job.
          </p>
          <div className="mt-8 flex flex-wrap gap-3">
            <Link
              href="/register"
              className="rounded-md bg-brand-700 px-5 py-2.5 text-base font-medium text-white hover:bg-brand-800"
            >
              Register and start the trial
            </Link>
            <Link
              href="/demo"
              className="rounded-md border border-slate-300 bg-white px-5 py-2.5 text-base font-medium text-slate-700 hover:bg-slate-50"
            >
              Walk the demo first
            </Link>
          </div>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <h2 className="text-2xl font-semibold tracking-tight text-slate-900">
          The 30-day path
        </h2>
        <ol className="mt-8 space-y-8">
          {TIMELINE.map((item) => (
            <li key={item.title} className="flex gap-4">
              <span className="w-16 shrink-0 text-sm font-semibold text-brand-700">
                {item.when}
              </span>
              <div>
                <h3 className="text-lg font-semibold text-slate-900">{item.title}</h3>
                <p className="mt-1 max-w-2xl text-sm leading-relaxed text-slate-600">
                  {item.body}
                </p>
              </div>
            </li>
          ))}
        </ol>
      </section>

      <section className="border-t border-slate-200 bg-slate-50">
        <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
          <h2 className="text-2xl font-semibold tracking-tight text-slate-900">
            The seven first steps
          </h2>
          <p className="mt-3 max-w-2xl text-sm leading-relaxed text-slate-600">
            The same checklist the app shows after you create your
            organization. Work through them in order.
          </p>
          <ol className="mt-8 space-y-6">
            {FIRST_STEPS.map((step, index) => (
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
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <h2 className="text-2xl font-semibold tracking-tight text-slate-900">
          What to have ready
        </h2>
        <ul className="mt-6 max-w-2xl list-disc space-y-2 pl-5 text-sm leading-relaxed text-slate-600">
          <li>Rate confirmations for the loads you want to claim on, showing free time and rates.</li>
          <li>Bills of lading or proofs of delivery with in and out times.</li>
          <li>Lumper receipts, if the driver paid at the dock.</li>
          <li>The broker communications for any cancelled load (TONU needs the cancellation message and its time).</li>
          <li>Your deadline policy: how many days you have to submit each kind of claim.</li>
        </ul>
        <div className="mt-10 flex flex-wrap gap-3">
          <Link
            href="/register"
            className="rounded-md bg-brand-700 px-5 py-2.5 text-base font-medium text-white hover:bg-brand-800"
          >
            Register
          </Link>
          <Link
            href="/demo-request"
            className="rounded-md border border-slate-300 bg-white px-5 py-2.5 text-base font-medium text-slate-700 hover:bg-slate-50"
          >
            Request a demo instead
          </Link>
        </div>
      </section>
    </MarketingLayout>
  );
}
