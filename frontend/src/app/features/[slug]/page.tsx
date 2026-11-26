import Link from "next/link";
import { notFound } from "next/navigation";
import { MarketingLayout } from "@/components/layout/MarketingLayout";
import { MARKETING_FEATURES } from "@/lib/marketing";
import { marketingMetadata } from "@/lib/seo";

export function generateStaticParams() {
  return MARKETING_FEATURES.map((feature) => ({ slug: feature.slug }));
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  const { slug } = await params;
  const feature = MARKETING_FEATURES.find((item) => item.slug === slug);
  if (!feature) return {};
  return marketingMetadata({
    title: `${feature.title} - ClaimTrail`,
    description: feature.short,
    path: `/features/${feature.slug}`,
  });
}

export default async function FeaturePage({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  const { slug } = await params;
  const feature = MARKETING_FEATURES.find((item) => item.slug === slug);
  if (!feature) notFound();

  const others = MARKETING_FEATURES.filter((item) => item.slug !== slug);

  return (
    <MarketingLayout>
      <section className="border-b border-slate-200 bg-slate-50">
        <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
          <p className="text-sm">
            <Link href="/features" className="font-medium text-brand-700 hover:underline">
              All features
            </Link>
          </p>
          <h1 className="mt-3 text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
            {feature.title}
          </h1>
          <p className="mt-4 max-w-2xl text-lg leading-relaxed text-slate-600">
            {feature.short}
          </p>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <div className="max-w-3xl space-y-10">
          {feature.sections.map((section) => (
            <div key={section.heading}>
              <h2 className="text-xl font-semibold text-slate-900">{section.heading}</h2>
              <p className="mt-2 text-sm leading-relaxed text-slate-600">{section.body}</p>
            </div>
          ))}

          <div className="rounded-lg border border-amber-200 bg-amber-50 p-6">
            <h2 className="text-lg font-semibold text-slate-900">Honest limits</h2>
            <ul className="mt-3 list-disc space-y-2 pl-5 text-sm leading-relaxed text-slate-700">
              {feature.limits.map((limit) => (
                <li key={limit}>{limit}</li>
              ))}
            </ul>
          </div>
        </div>

        <div className="mt-12 flex flex-wrap gap-3">
          <Link
            href="/register"
            className="rounded-md bg-brand-700 px-5 py-2.5 text-base font-medium text-white hover:bg-brand-800"
          >
            Start a 30-day free trial
          </Link>
          <Link
            href="/pricing"
            className="rounded-md border border-slate-300 bg-white px-5 py-2.5 text-base font-medium text-slate-700 hover:bg-slate-50"
          >
            See pricing
          </Link>
        </div>

        <h2 className="mt-16 text-xl font-semibold text-slate-900">More features</h2>
        <div className="mt-6 grid gap-6 sm:grid-cols-2">
          {others.map((other) => (
            <Link
              key={other.slug}
              href={`/features/${other.slug}`}
              className="rounded-lg border border-slate-200 bg-white p-6 hover:border-brand-400"
            >
              <h3 className="text-lg font-semibold text-slate-900">{other.title}</h3>
              <p className="mt-2 text-sm leading-relaxed text-slate-600">{other.short}</p>
            </Link>
          ))}
        </div>
      </section>
    </MarketingLayout>
  );
}
