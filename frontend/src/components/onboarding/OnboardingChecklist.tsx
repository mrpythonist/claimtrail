"use client";

import Link from "next/link";
import { Button } from "@/components/ui/Button";
import type { OnboardingProgress } from "@/lib/types";

/**
 * Guided onboarding checklist. Shows each step with a checkmark when
 * done and a link to where the step is completed. The parent owns
 * fetching and dismissing; this component only renders.
 */
export function OnboardingChecklist({
  progress,
  canDismiss,
  dismissing,
  onDismiss,
}: {
  progress: OnboardingProgress;
  canDismiss: boolean;
  dismissing?: boolean;
  onDismiss?: () => void;
}) {
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-slate-700">
          <span className="text-lg font-semibold text-slate-900">
            {progress.completed_count} of {progress.total_count} done
          </span>
        </p>
        <div
          className="h-2.5 w-full overflow-hidden rounded-full bg-slate-200"
          role="progressbar"
          aria-valuenow={progress.completed_count}
          aria-valuemin={0}
          aria-valuemax={progress.total_count}
          aria-label="Onboarding progress"
        >
          <div
            className="h-full rounded-full bg-brand-600"
            style={{
              width: `${progress.total_count === 0 ? 0 : (progress.completed_count / progress.total_count) * 100}%`,
            }}
          />
        </div>
      </div>

      <ol className="space-y-3">
        {progress.steps.map((step) => (
          <li
            key={step.key}
            className="flex items-start gap-3 rounded-md border border-slate-200 px-4 py-3"
          >
            <span
              aria-hidden="true"
              className={`mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold ${
                step.completed
                  ? "bg-emerald-100 text-emerald-700"
                  : "bg-slate-100 text-slate-500"
              }`}
            >
              {step.completed ? "✓" : "•"}
            </span>
            <div className="min-w-0">
              <p className="text-sm font-medium text-slate-900">
                {step.completed ? (
                  <span className="line-through decoration-slate-400">
                    {step.label}
                  </span>
                ) : (
                  step.label
                )}
                {step.completed && (
                  <span className="ml-2 text-xs font-normal text-emerald-700">
                    Done
                  </span>
                )}
              </p>
              <p className="mt-0.5 text-sm text-slate-500">{step.description}</p>
              {!step.completed && (
                <p className="mt-1 text-sm">
                  <Link
                    href={step.href}
                    className="font-medium text-brand-700 hover:underline"
                  >
                    Go to this step
                  </Link>
                </p>
              )}
            </div>
          </li>
        ))}
      </ol>

      {canDismiss && onDismiss && (
        <div className="flex justify-end">
          <Button
            variant="ghost"
            size="sm"
            loading={dismissing}
            onClick={onDismiss}
          >
            Dismiss onboarding
          </Button>
        </div>
      )}
    </div>
  );
}
