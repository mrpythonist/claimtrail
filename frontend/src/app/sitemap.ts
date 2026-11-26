import type { MetadataRoute } from "next";
import { MARKETING_ROUTES } from "@/lib/marketing";
import { siteUrl } from "@/lib/seo";

/**
 * Sitemap for the public marketing site (v1.2.2).
 *
 * Absolute URLs need NEXT_PUBLIC_SITE_URL. Without it the entries
 * carry relative URLs, which a sitemap must not do, so the
 * sitemap is empty until the site has a domain. That is honest:
 * a sitemap pointing at a placeholder domain would be worse.
 */
export default function sitemap(): MetadataRoute.Sitemap {
  const base = siteUrl();
  if (!base) return [];

  return MARKETING_ROUTES.map((route) => ({
    url: `${base}${route.path}`,
    lastModified: new Date(),
    changeFrequency:
      route.path === "/" ? "weekly" : ("monthly" as const),
    priority: route.priority,
  }));
}
