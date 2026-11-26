"use client";

import { useState } from "react";
import { DEMO_STEPS } from "@/lib/marketing";

/**
 * The interactive demo walkthrough (v1.2.2): seven steps through
 * the PLT-1001 detention claim, from shipment to payment, using
 * the synthetic v0.6.0 pilot data. Nothing here is a real load,
 * broker, or claim.
 */
export function DemoWalkthrough() {
  const [step, setStep] = useState(0);
  const current = DEMO_STEPS[step];
  const last = DEMO_STEPS.length - 1;

  return (
    <div className="rounded-lg border border-slate-200 bg-white p-6">
      <ol className="flex flex-wrap gap-2" aria-label="Demo steps">
        {DEMO_STEPS.map((item, index) => (
          <li key={item.title}>
            <button
              type="button"
              onClick={() => setStep(index)}
              aria-current={index === step ? "step" : undefined}
              className={`rounded-full px-3 py-1 text-xs font-semibold ${
                index === step
                  ? "bg-brand-700 text-white"
                  : index < step
                    ? "bg-brand-100 text-brand-800"
                    : "bg-slate-100 text-slate-600 hover:bg-slate-200"
              }`}
            >
              {index + 1}. {item.title}
            </button>
          </li>
        ))}
      </ol>

      <h3 className="mt-6 text-xl font-semibold text-slate-900">
        Step {step + 1}: {current.title}
      </h3>
      <p className="mt-2 max-w-3xl text-sm leading-relaxed text-slate-600">
        {current.body}
      </p>

      <div className="mt-6 flex gap-3">
        <button
          type="button"
          onClick={() => setStep((value) => Math.max(0, value - 1))}
          disabled={step === 0}
          className="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
        >
          Previous
        </button>
        <button
          type="button"
          onClick={() => setStep((value) => Math.min(last, value + 1))}
          disabled={step === last}
          className="rounded-md bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800 disabled:opacity-50"
        >
          {step === last ? "End of the walkthrough" : "Next step"}
        </button>
      </div>
    </div>
  );
}
