/**
 * The plan a visitor chose on the pricing page, carried into
 * signup (v1.2.2).
 *
 * The pricing page links to /register?plan=growth (and so on).
 * The register page stores the choice in localStorage, and the
 * in-app onboarding page reads it back to offer the matching
 * trial. Nothing here changes the billing rules: the trial is
 * still started by the existing POST /billing/trials endpoint,
 * and only from the Free plan.
 */

import { isListedPlanKey } from "./marketing";

const SIGNUP_PLAN_KEY = "claimtrail_signup_plan";

export function getSignupPlan(): string | null {
  if (typeof window === "undefined") return null;
  const stored = window.localStorage.getItem(SIGNUP_PLAN_KEY);
  return isListedPlanKey(stored) ? stored : null;
}

export function setSignupPlan(plan: string | null): void {
  if (typeof window === "undefined") return;
  if (plan === null || !isListedPlanKey(plan)) {
    window.localStorage.removeItem(SIGNUP_PLAN_KEY);
    return;
  }
  window.localStorage.setItem(SIGNUP_PLAN_KEY, plan);
}

export function clearSignupPlan(): void {
  if (typeof window === "undefined") return;
  window.localStorage.removeItem(SIGNUP_PLAN_KEY);
}
