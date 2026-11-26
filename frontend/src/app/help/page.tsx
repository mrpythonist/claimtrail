import Link from "next/link";
import { MarketingLayout } from "@/components/layout/MarketingLayout";
import { marketingMetadata } from "@/lib/seo";

export const metadata = marketingMetadata({
  title: "Help - ClaimTrail",
  description:
    "How ClaimTrail works, what each part does, how billing and refunds work, and how to get support.",
  path: "/help",
});

const SECTIONS = [
  {
    title: "Getting started",
    body: "Register, create your organization, and work through the seven first steps: organization, member, shipment, event, evidence, claim, and submission. The full walkthrough is on the getting started page, and the demo page shows a complete detention claim from shipment to package.",
  },
  {
    title: "Shipments, events, and claims",
    body: "A shipment is one load. An event is something chargeable that happened on that load, for example detention at a dock. A claim is built from one event and moves through ten statuses from draft to paid. A claim can only be submitted when its evidence requirements are met; the readiness check on the claim page says exactly what is still missing.",
  },
  {
    title: "Evidence",
    body: "Upload files as evidence for a claim: rate confirmations, invoices, photos, timestamps. Files are stored privately and checked by checksum when you download them. A ready claim package is a PDF built from the claim record; it is the document you send to the broker. ClaimTrail does not submit claims for you.",
  },
  {
    title: "Deadlines",
    body: "Deadlines come from your organization's settings: how long after delivery a claim must be filed, and how long after that it must be followed up. The deadlines page lists what is due soon and what is overdue. Deadlines are recomputed hourly.",
  },
  {
    title: "Plans and limits",
    body: "Free allows 5 claims a month, Starter $19 allows 20, Growth $49 allows 100, Fleet $99 allows 300, and Agency $149 a month covers up to 10 carriers at 100 claims per carrier. When you hit a limit, the action is refused with a message naming the cheapest plan that raises it. Claims, members, and files already created are never deleted by a limit or a downgrade.",
  },
  {
    title: "Billing, cancellation, and refunds",
    body: "Monthly billing is charged at the start of the period; annual billing is ten times the monthly price. Upgrades apply immediately with a prorated credit; downgrades apply at the end of the current period. Cancel from the billing page: your organization moves to Free at the end of the current period and the data stays. For a refund, open a support ticket within 14 days of the charge with the invoice number and the reason. Refunds are issued for the unused part of a period after a duplicate charge or a billing error.",
  },
  {
    title: "Roles and permissions",
    body: "Six roles control what members can do: owner, admin, operations manager, dispatcher, billing staff, and viewer. Only owner and admin manage members, security, operations, and support tickets. Billing staff manage billing but not claims. Viewers can read reports but cannot change anything.",
  },
  {
    title: "Your data",
    body: "Your organization's data stays inside your organization. Audit logs are kept 365 days, notifications 90 days, and security events 180 days. There is no self-service account deletion yet; to request access, correction, or deletion of your data, open a support ticket and the operator handles it manually. The privacy policy and the data processing documentation describe this in full.",
  },
  {
    title: "What ClaimTrail does not do",
    body: "ClaimTrail does not guarantee that any charge is recoverable, does not capture GPS or ELD times, does not submit claims to brokers, and does not give legal or contractual advice. You are responsible for verifying contractual eligibility, submission requirements, and deadlines with your brokers and customers.",
  },
];

export default function HelpPage() {
  return (
    <MarketingLayout>
      <div className="mx-auto max-w-3xl px-4 py-12 sm:px-6">
        <h1 className="text-3xl font-semibold text-slate-900">Help</h1>
        <p className="mt-3 text-base leading-relaxed text-slate-600">
          How ClaimTrail works, what each part does, and how to get
          support. If something here does not match what you see in
          the app, open a support ticket from inside the app and tell
          us; the help text is wrong, not you.
        </p>
        <div className="mt-8 space-y-8">
          {SECTIONS.map((section) => (
            <section key={section.title}>
              <h2 className="text-lg font-semibold text-slate-900">
                {section.title}
              </h2>
              <p className="mt-2 text-sm leading-relaxed text-slate-600">
                {section.body}
              </p>
            </section>
          ))}
          <section>
            <h2 className="text-lg font-semibold text-slate-900">
              Getting support
            </h2>
            <p className="mt-2 text-sm leading-relaxed text-slate-600">
              Sign in, go to Support in the sidebar, and open a ticket
              with a subject, a category, and a description of what
              happened and what you expected. An owner or admin of your
              organization answers tickets. A reply on a resolved
              ticket reopens it; a closed ticket takes no more
              messages, so open a new one if the issue comes back.
            </p>
            <p className="mt-4 text-sm leading-relaxed text-slate-600">
              <Link href="/getting-started" className="text-brand-700 hover:underline">
                Getting started
              </Link>
              {" · "}
              <Link href="/demo" className="text-brand-700 hover:underline">
                See the demo
              </Link>
              {" · "}
              <Link href="/pricing" className="text-brand-700 hover:underline">
                Pricing
              </Link>
              {" · "}
              <Link href="/privacy" className="text-brand-700 hover:underline">
                Privacy policy
              </Link>
              {" · "}
              <Link href="/terms" className="text-brand-700 hover:underline">
                Terms of service
              </Link>
            </p>
          </section>
        </div>
      </div>
    </MarketingLayout>
  );
}
