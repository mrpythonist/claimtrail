import Link from "next/link";
import { MarketingLayout } from "@/components/layout/MarketingLayout";
import { PricingTable } from "@/components/marketing/PricingTable";
import { MARKETING_PLANS } from "@/lib/marketing";
import { marketingMetadata, softwareApplicationJsonLd as productJsonLd } from "@/lib/seo";

export const metadata = marketingMetadata({
  title: "Pricing - ClaimTrail",
  description:
    "Five tiers from Free to Agency $149 a month, plus a success-fee option. 30-day free trial on Growth, no card required. Annual billing is two months free.",
  path: "/pricing",
});

const FAQ = [
  {
    question: "How does the 30-day trial work?",
    answer:
      "A new organization starts on Free and can start a 30-day trial on the Growth tier with no card. At the end, it converts to paid Growth with a payment method, or lapses back to Free. Nothing is deleted either way.",
  },
  {
    question: "What happens when I go over a limit?",
    answer:
      "The action is refused with a message naming the cheapest plan that raises the limit, and an upgrade prompt. Claims, members, and files already created are never deleted by a limit or a downgrade.",
  },
  {
    question: "How does annual billing work?",
    answer:
      "Annual is ten times the monthly price: two months free. Upgrades apply immediately with a prorated credit; downgrades apply at the end of the current period.",
  },
  {
    question: "What is the success-fee plan?",
    answer:
      "$0 a month base, 15 percent of amounts recorded as paid, capped at $49 a month for the first year. It is offered at the point where price blocks a trial buyer, not on this page as a default, because a plan that only earns when you collect needs a conversation first.",
  },
  {
    question: "Can I cancel any time?",
    answer:
      "Yes. Monthly billing, cancel any time, no setup fee. Cancelling moves the organization to Free at the end of the current period, and the data stays.",
  },
];

export default function PricingPage() {
  const jsonLd = productJsonLd();

  return (
    <MarketingLayout>
      {jsonLd && (
        <script
          type="application/ld+json"
          dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }}
        />
      )}
      <section className="border-b border-slate-200 bg-slate-50">
        <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
          <h1 className="text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
            Pricing
          </h1>
          <p className="mt-4 max-w-2xl text-lg leading-relaxed text-slate-600">
            Tiered by fleet size, because that is how the trucking market
            already buys. Every paid tier starts with a 30-day free trial
            on Growth, no card required.
          </p>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <PricingTable plans={MARKETING_PLANS} />
        <p className="mt-6 max-w-3xl text-sm leading-relaxed text-slate-500">
          Prices are in USD. The tiers and limits are the pricing validated
          in the product&apos;s own research: they are hypotheses being
          tested with real carriers and agencies, not prices set in stone.
          The success-fee plan ($0 base, 15% of amounts recorded as paid,
          capped at $49 a month for the first year) is offered when price
          blocks a trial buyer, not as a default.
        </p>
      </section>

      <section className="border-t border-slate-200 bg-slate-50">
        <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
          <h2 className="text-2xl font-semibold tracking-tight text-slate-900">
            Questions
          </h2>
          <div className="mt-8 max-w-3xl space-y-8">
            {FAQ.map((item) => (
              <div key={item.question}>
                <h3 className="text-lg font-semibold text-slate-900">{item.question}</h3>
                <p className="mt-2 text-sm leading-relaxed text-slate-600">{item.answer}</p>
              </div>
            ))}
          </div>
          <p className="mt-10 text-sm">
            Still deciding?{" "}
            <Link href="/demo" className="font-medium text-brand-700 hover:underline">
              Walk the demo
            </Link>
            {" or "}
            <Link href="/demo-request" className="font-medium text-brand-700 hover:underline">
              request a live demo
            </Link>
            .
          </p>
        </div>
      </section>
    </MarketingLayout>
  );
}
