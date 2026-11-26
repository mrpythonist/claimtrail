import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

describe("analytics tracker", () => {
  beforeEach(() => {
    vi.resetModules();
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue({}));
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.unstubAllEnvs();
  });

  it("does nothing when analytics is disabled", async () => {
    vi.stubEnv("NEXT_PUBLIC_ANALYTICS_ENABLED", "false");
    const { trackPageView } = await import("@/lib/analytics");

    await trackPageView("/pricing");

    expect(fetch).not.toHaveBeenCalled();
  });

  it("posts a page view to the backend when enabled", async () => {
    vi.stubEnv("NEXT_PUBLIC_ANALYTICS_ENABLED", "true");
    vi.stubEnv(
      "NEXT_PUBLIC_API_URL",
      "http://localhost:8000/api/v1",
    );
    const { trackPageView } = await import("@/lib/analytics");

    await trackPageView("/pricing");

    expect(fetch).toHaveBeenCalledWith(
      "http://localhost:8000/api/v1/analytics/events",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({
          event: "page_view",
          path: "/pricing",
          referrer: null,
        }),
      }),
    );
  });

  it("swallows a network failure instead of breaking the page", async () => {
    vi.stubEnv("NEXT_PUBLIC_ANALYTICS_ENABLED", "true");
    vi.mocked(fetch).mockRejectedValue(new Error("network down"));
    const { trackPageView } = await import("@/lib/analytics");

    await expect(trackPageView("/pricing")).resolves.toBeUndefined();
  });
});
