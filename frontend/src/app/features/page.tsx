import Link from "next/link";
import { MarketingLayout } from "@/components/layout/MarketingLayout";
import { MARKETING_FEATURES } from "@/lib/marketing";
import { marketingMetadata } from "@/lib/seo";

export const metadata = marketingMetadata({
  title: "Features - ClaimTrail",
  description:
    "Claims, evidence, deadlines, PDF packages, reports, multi-tenancy, and billing: what ClaimTrail does and what it honestly does not do yet.",
  path: "/features",
});

export default function FeaturesPage() {
  return (
    <MarketingLayout>
      <section className="border-b border-slate-200 bg-slate-50">
        <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
          <h1 className="text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
            Features
          </h1>
          <p className="mt-4 max-w-2xl text-lg leading-relaxed text-slate-600">
            Everything ClaimTrail does, and the limits of each piece. The
            limits are listed on purpose: a claim tool that hides what it
            cannot do is a tool that files claims brokers reject.
          </p>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <div className="grid gap-6 sm:grid-cols-2">
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
      </section>
    </MarketingLayout>
  );
}
