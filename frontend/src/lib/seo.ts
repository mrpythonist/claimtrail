import type { Metadata } from "next";

/**
 * SEO metadata for the public marketing pages (v1.2.2).
 *
 * The site has no live domain yet, so the canonical and Open
 * Graph URLs are absolute only when NEXT_PUBLIC_SITE_URL is set.
 * Set it to the production origin (for example
 * https://claimtrail.example.com) when the marketing site is
 * deployed; until then the pages still carry title, description,
 * and Twitter card metadata, and the sitemap and robots routes
 * omit absolute URLs they cannot build honestly.
 */
export function siteUrl(): string | null {
  const raw = process.env.NEXT_PUBLIC_SITE_URL;
  if (!raw) return null;
  return raw.replace(/\/$/, "");
}

export function marketingMetadata(options: {
  title: string;
  description: string;
  path: string;
}): Metadata {
  const base = siteUrl();
  const url = base ? `${base}${options.path}` : undefined;

  return {
    title: options.title,
    description: options.description,
    alternates: url ? { canonical: url } : undefined,
    openGraph: {
      title: options.title,
      description: options.description,
      type: "website",
      url,
      siteName: "ClaimTrail",
    },
    twitter: {
      card: "summary",
      title: options.title,
      description: options.description,
    },
  };
}

/**
 * JSON-LD for the software product, embedded on the home and
 * pricing pages. The URL is included only when the site URL is
 * known; a placeholder domain in structured data would be worse
 * than no URL at all.
 */
export function softwareApplicationJsonLd(): Record<string, unknown> | null {
  const base = siteUrl();
  if (!base) return null;
  return {
    "@context": "https://schema.org",
    "@type": "SoftwareApplication",
    name: "ClaimTrail",
    applicationCategory: "BusinessApplication",
    operatingSystem: "Web",
    url: base,
    description:
      "ClaimTrail helps trucking companies and dispatchers track additional shipment charges: detention, layover, TONU, lumper, and the rest.",
    offers: {
      "@type": "AggregateOffer",
      lowPrice: "0",
      highPrice: "149",
      priceCurrency: "USD",
    },
  };
}
