import Link from "next/link";
import { MarketingLayout } from "@/components/layout/MarketingLayout";
import { DemoRequestForm } from "@/components/marketing/DemoRequestForm";
import { marketingMetadata } from "@/lib/seo";

export const metadata = marketingMetadata({
  title: "Request a demo - ClaimTrail",
  description:
    "Ask for a live demo of ClaimTrail with your own fleet in mind: a walkthrough of the claim workflow, the pricing, and the 30-day trial.",
  path: "/demo-request",
});

export default function DemoRequestPage() {
  return (
    <MarketingLayout>
      <section className="mx-auto max-w-3xl px-4 py-16 sm:px-6">
        <h1 className="text-3xl font-semibold tracking-tight text-slate-900">
          Request a demo
        </h1>
        <p className="mt-4 text-base leading-relaxed text-slate-600">
          A live walkthrough of the claim workflow with your fleet in
          mind: how detention, layover, TONU, and lumper claims move
          from shipment to payment, what the trial covers, and what it
          costs. Prefer to look around yourself first?{" "}
          <Link href="/demo" className="font-medium text-brand-700 hover:underline">
            Walk the interactive demo
          </Link>{" "}
          or{" "}
          <Link href="/register" className="font-medium text-brand-700 hover:underline">
            start a 30-day free trial
          </Link>
          .
        </p>
        <div className="mt-8 rounded-lg border border-slate-200 bg-white p-6">
          <DemoRequestForm />
        </div>
      </section>
    </MarketingLayout>
  );
}
