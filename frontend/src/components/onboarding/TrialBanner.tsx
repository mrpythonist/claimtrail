"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { ApiRequestError } from "@/lib/api";
import { getSubscription, startTrial } from "@/lib/billing";
import { planName } from "@/lib/marketing";
import { clearSignupPlan, getSignupPlan } from "@/lib/signup-plan";

/**
 * The trial banner on the onboarding page (v1.2.2).
 *
 * When a visitor chose a plan on the pricing page, the register
 * page remembered it. After the organization is created, this
 * banner offers the matching trial: the 30-day Growth trial, no
 * card required, through the existing POST /billing/trials
 * endpoint. It only shows for an organization on the Free plan
 * that is not already trialing; starting or dismissing it clears
 * the remembered choice.
 */
export function TrialBanner({ orgId }: { orgId: string }) {
  const [plan, setPlan] = useState<string | null>(null);
  const [show, setShow] = useState(false);
  const [starting, setStarting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [started, setStarted] = useState(false);

  useEffect(() => {
    const chosen = getSignupPlan();
    if (!chosen) return;
    let cancelled = false;
    getSubscription(orgId)
      .then((subscription) => {
        if (cancelled) return;
        setPlan(chosen);
        setShow(
          subscription.plan === "free" && subscription.status !== "trialing",
        );
      })
      .catch(() => {
        if (!cancelled) setShow(false);
      });
    return () => {
      cancelled = true;
    };
  }, [orgId]);

  async function handleStart() {
    setStarting(true);
    setError(null);
    try {
      await startTrial(orgId, null);
      void import("@/lib/analytics").then(({ trackEvent }) =>
        trackEvent("trial_started", "/onboarding"),
      );
      clearSignupPlan();
      setStarted(true);
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not start the trial. Please try again.",
      );
    } finally {
      setStarting(false);
    }
  }

  function handleDismiss() {
    clearSignupPlan();
    setShow(false);
  }

  if (started) {
    return (
      <Alert tone="success">
        Your 30-day Growth trial has started. No card is charged during
        the trial.{" "}
        <Link href="/settings/billing" className="font-medium hover:underline">
          See your billing page
        </Link>
        .
      </Alert>
    );
  }

  if (!show || !plan) return null;

  return (
    <div className="rounded-lg border border-brand-200 bg-brand-50 p-6">
      <h2 className="text-lg font-semibold text-slate-900">
        You chose the {planName(plan)} plan
      </h2>
      <p className="mt-2 text-sm leading-relaxed text-slate-600">
        Start your 30-day free trial on the Growth tier. No card is
        required, and the trial lapses back to Free if you do nothing.
        Your data stays either way.
      </p>
      {error && (
        <div className="mt-4">
          <Alert tone="error">{error}</Alert>
        </div>
      )}
      <div className="mt-4 flex flex-wrap gap-3">
        <Button onClick={handleStart} disabled={starting}>
          {starting ? "Starting..." : "Start the 30-day free trial"}
        </Button>
        <Button variant="secondary" onClick={handleDismiss}>
          Not now
        </Button>
      </div>
    </div>
  );
}
