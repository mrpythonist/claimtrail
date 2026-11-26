"use client";

import { usePathname } from "next/navigation";
import { useEffect, useRef } from "react";
import { trackPageView } from "@/lib/analytics";

/**
 * Records a page view for every marketing page view (v1.2.2).
 * Mounted once in the marketing layout. The tracker is off
 * unless NEXT_PUBLIC_ANALYTICS_ENABLED is "true", and sending
 * never blocks rendering or navigation.
 */
export function AnalyticsTracker() {
  const pathname = usePathname();
  const firstRender = useRef(true);

  useEffect(() => {
    if (firstRender.current) {
      firstRender.current = false;
    }
    void trackPageView(pathname);
  }, [pathname]);

  return null;
}
