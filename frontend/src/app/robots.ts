import type { MetadataRoute } from "next";
import { siteUrl } from "@/lib/seo";

/**
 * Robots for the public marketing site (v1.2.2). Everything is
 * crawlable. The sitemap is listed only when the site URL is
 * known, for the same reason the sitemap itself is empty without
 * one: a sitemap URL pointing at a placeholder domain would be
 * worse than no sitemap line.
 */
export default function robots(): MetadataRoute.Robots {
  const base = siteUrl();
  return {
    rules: [{ userAgent: "*", allow: "/" }],
    sitemap: base ? `${base}/sitemap.xml` : undefined,
  };
}
