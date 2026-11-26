import Link from "next/link";
import { MarketingLayout } from "@/components/layout/MarketingLayout";
import { DemoWalkthrough } from "@/components/marketing/DemoWalkthrough";
import {
  DEMO_SHIPMENTS,
  DEMO_TOTAL_REQUESTED_CENTS,
  formatDollars,
} from "@/lib/marketing";
import { marketingMetadata } from "@/lib/seo";

export const metadata = marketingMetadata({
  title: "Demo - ClaimTrail",
  description:
    "Walk one detention claim from shipment to payment in seven steps, using synthetic pilot data: twelve shipments, eleven claims, $2,440 requested.",
  path: "/demo",
});

export default function DemoPage() {
  return (
    <MarketingLayout>
      <section className="border-b border-slate-200 bg-slate-50">
        <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
          <h1 className="text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
            Walk one claim from shipment to payment
          </h1>
          <p className="mt-4 max-w-2xl text-lg leading-relaxed text-slate-600">
            Seven steps, one detention claim, under an hour in the real
            app. The data below is synthetic: twelve shipments across
            three fictional carriers, eleven claims totalling{" "}
            {formatDollars(DEMO_TOTAL_REQUESTED_CENTS)} requested. Nothing
            here is a real load, a real broker, or a real claim.
          </p>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <DemoWalkthrough />

        <h2 className="mt-16 text-2xl font-semibold tracking-tight text-slate-900">
          The full demo set
        </h2>
        <p className="mt-3 max-w-3xl text-sm leading-relaxed text-slate-600">
          The walkthrough follows PLT-1001. The other eleven shipments
          show the rest of the workflow: a claim paid in full, one
          rejected and reworked, one partially accepted, two submitted
          and awaiting a broker, one ready to submit, and one control
          row where the wait was inside free time and no claim was due.
        </p>
        <div className="mt-8 overflow-x-auto rounded-lg border border-slate-200">
          <table className="w-full min-w-[720px] text-left text-sm">
            <thead className="bg-slate-50">
              <tr>
                <th className="px-4 py-3 font-semibold text-slate-900">Shipment</th>
                <th className="px-4 py-3 font-semibold text-slate-900">Carrier</th>
                <th className="px-4 py-3 font-semibold text-slate-900">Load</th>
                <th className="px-4 py-3 font-semibold text-slate-900">Event</th>
                <th className="px-4 py-3 font-semibold text-slate-900">Requested</th>
                <th className="px-4 py-3 font-semibold text-slate-900">Outcome</th>
              </tr>
            </thead>
            <tbody>
              {DEMO_SHIPMENTS.map((shipment) => (
                <tr key={shipment.ref} className="border-t border-slate-200">
                  <td className="px-4 py-3 font-medium text-slate-900">{shipment.ref}</td>
                  <td className="px-4 py-3 text-slate-600">{shipment.carrier}</td>
                  <td className="px-4 py-3 text-slate-600">{shipment.load}</td>
                  <td className="px-4 py-3 text-slate-600">{shipment.event}</td>
                  <td className="px-4 py-3 text-slate-600">
                    {shipment.amountCents === 0
                      ? "$0"
                      : formatDollars(shipment.amountCents)}
                  </td>
                  <td className="px-4 py-3 text-slate-600">{shipment.outcome}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="mt-10 rounded-lg border border-amber-200 bg-amber-50 p-6">
          <h2 className="text-lg font-semibold text-slate-900">What the demo does not show</h2>
          <p className="mt-2 max-w-3xl text-sm leading-relaxed text-slate-700">
            The app does not send the claim package to the broker, and
            after submission there is no follow-up schedule. The demo
            shows the workflow as it is, including the step where a
            person sends the package and records that they did.
          </p>
        </div>

        <div className="mt-10 flex flex-wrap gap-3">
          <Link
            href="/register"
            className="rounded-md bg-brand-700 px-5 py-2.5 text-base font-medium text-white hover:bg-brand-800"
          >
            Try it yourself, 30-day free trial
          </Link>
          <Link
            href="/demo-request"
            className="rounded-md border border-slate-300 bg-white px-5 py-2.5 text-base font-medium text-slate-700 hover:bg-slate-50"
          >
            Request a live demo
          </Link>
        </div>
      </section>
    </MarketingLayout>
  );
}
