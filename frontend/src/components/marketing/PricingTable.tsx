"use client";

import Link from "next/link";
import { useState } from "react";
import { formatDollars, MARKETING_PLANS, type MarketingPlan } from "@/lib/marketing";

/**
 * The pricing table (v1.2.2). Prices and limits are the v0.5.0
 * section 8 tiers, shared with the backend catalog at
 * GET /v1/plans. Annual is 10x monthly (two months free).
 */
export function PricingTable({ plans }: { plans: MarketingPlan[] }) {
  const [interval, setInterval] = useState<"monthly" | "annual">("monthly");

  return (
    <div>
      <div className="flex items-center gap-2" role="group" aria-label="Billing interval">
        <button
          type="button"
          onClick={() => setInterval("monthly")}
          aria-pressed={interval === "monthly"}
          className={`rounded-md px-4 py-2 text-sm font-medium ${
            interval === "monthly"
              ? "bg-brand-700 text-white"
              : "border border-slate-300 bg-white text-slate-700 hover:bg-slate-50"
          }`}
        >
          Monthly
        </button>
        <button
          type="button"
          onClick={() => setInterval("annual")}
          aria-pressed={interval === "annual"}
          className={`rounded-md px-4 py-2 text-sm font-medium ${
            interval === "annual"
              ? "bg-brand-700 text-white"
              : "border border-slate-300 bg-white text-slate-700 hover:bg-slate-50"
          }`}
        >
          Annual (two months free)
        </button>
      </div>

      <div className="mt-8 grid gap-6 lg:grid-cols-3">
        {plans.map((plan) => {
          const priceCents =
            interval === "monthly" ? plan.monthlyCents : plan.annualCents;
          const perMonth =
            interval === "annual" && plan.monthlyCents > 0
              ? `(${formatDollars(plan.monthlyCents)} per month, billed annually)`
              : null;
          return (
            <div
              key={plan.key}
              className={`flex flex-col rounded-lg border bg-white p-6 ${
                plan.highlighted
                  ? "border-brand-600 shadow-md"
                  : "border-slate-200"
              }`}
            >
              <div className="flex items-center justify-between">
                <h3 className="text-lg font-semibold text-slate-900">{plan.name}</h3>
                {plan.highlighted && (
                  <span className="rounded-full bg-brand-100 px-3 py-1 text-xs font-semibold text-brand-800">
                    Default
                  </span>
                )}
              </div>
              <p className="mt-1 text-sm text-slate-500">{plan.trucks}</p>
              <p className="mt-4 text-3xl font-semibold tracking-tight text-slate-900">
                {formatDollars(priceCents)}
                <span className="text-base font-normal text-slate-500">
                  {interval === "monthly" ? " / month" : " / year"}
                </span>
              </p>
              {perMonth && <p className="mt-1 text-xs text-slate-500">{perMonth}</p>}
              {plan.key === "agency" && (
                <p className="mt-1 text-xs text-slate-500">
                  Base for up to 10 carriers, then $12 per extra carrier per month.
                </p>
              )}
              <p className="mt-3 text-sm leading-relaxed text-slate-600">
                {plan.description}
              </p>
              <ul className="mt-4 flex-1 list-disc space-y-2 pl-5 text-sm text-slate-600">
                {plan.features.map((feature) => (
                  <li key={feature}>{feature}</li>
                ))}
              </ul>
              <Link
                href={`/register?plan=${plan.key}`}
                className={`mt-6 rounded-md px-4 py-2.5 text-center text-sm font-medium ${
                  plan.highlighted
                    ? "bg-brand-700 text-white hover:bg-brand-800"
                    : "border border-slate-300 bg-white text-slate-700 hover:bg-slate-50"
                }`}
              >
                {plan.key === "free"
                  ? "Start for free"
                  : "Start a 30-day free trial"}
              </Link>
            </div>
          );
        })}
      </div>
    </div>
  );
}
