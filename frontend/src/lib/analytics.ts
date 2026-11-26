/**
 * Marketing-site analytics tracker (v1.2.2).
 *
 * Posts page-view and conversion events to the backend's public
 * analytics endpoint (POST /v1/analytics/events). The backend
 * driver decides where events go; the default is the Laravel log.
 * A real provider (GA4, Plausible) is not wired in: it needs a
 * measurement ID that only Aqib can supply.
 *
 * The tracker is off unless NEXT_PUBLIC_ANALYTICS_ENABLED is
 * "true": an opt-in, not a default. Sending is fire-and-forget
 * and never blocks rendering or navigation; failures are
 * swallowed, because analytics must never break the page.
 */

const API_URL =
  process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000/api/v1";

export const ANALYTICS_ENABLED =
  process.env.NEXT_PUBLIC_ANALYTICS_ENABLED === "true";

export type AnalyticsEventName =
  | "page_view"
  | "demo_request_submitted"
  | "trial_started"
  | "pricing_viewed";

/** The event names the backend accepts. Anything else is rejected there. */
export const ANALYTICS_EVENT_NAMES: AnalyticsEventName[] = [
  "page_view",
  "demo_request_submitted",
  "trial_started",
  "pricing_viewed",
];

export async function trackEvent(
  event: AnalyticsEventName,
  path: string,
  referrer?: string | null,
): Promise<void> {
  if (!ANALYTICS_ENABLED || typeof window === "undefined") return;

  try {
    await fetch(`${API_URL}/analytics/events`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        event,
        path,
        referrer: referrer ?? (document.referrer || null),
      }),
      keepalive: true,
    });
  } catch {
    // Analytics must never break the page.
  }
}

export function trackPageView(path: string): Promise<void> {
  return trackEvent("page_view", path);
}
