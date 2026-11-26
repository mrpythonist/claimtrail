import { promises as fs } from "node:fs";
import path from "node:path";
import { Suspense } from "react";
import { connection } from "next/server";
import { MarketingLayout } from "@/components/layout/MarketingLayout";
import { ScreenshotGallery } from "@/components/marketing/ScreenshotGallery";
import { SCREENSHOT_ENTRIES } from "@/lib/marketing";
import { marketingMetadata } from "@/lib/seo";

export const metadata = marketingMetadata({
  title: "Screenshots - ClaimTrail",
  description:
    "Screens from the ClaimTrail app: dashboard, shipments, claim detail, evidence, deadlines, billing, and reports.",
  path: "/screenshots",
});

/**
 * Reads public/screenshots/ at request time: a capture dropped in
 * as <key>.png appears without a rebuild, and anything not yet
 * captured renders as a labelled placeholder. The `connection()`
 * call marks this part dynamic (the page shell still
 * prerenders); real captures need a browser run, and there is no
 * headless browser on the build VM, so the gallery ships with
 * placeholders and the capture process in docs/MARKETING.md.
 */
async function ScreenshotContent() {
  await connection();
  const dir = path.join(process.cwd(), "public", "screenshots");
  const files = await fs.readdir(dir).catch(() => [] as string[]);
  const available: Record<string, boolean> = {};
  for (const entry of SCREENSHOT_ENTRIES) {
    available[entry.key] = files.includes(`${entry.key}.png`);
  }

  return <ScreenshotGallery available={available} />;
}

export default function ScreenshotsPage() {
  return (
    <MarketingLayout>
      <section className="border-b border-slate-200 bg-slate-50">
        <div className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
          <h1 className="text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">
            Screenshots
          </h1>
          <p className="mt-4 max-w-2xl text-lg leading-relaxed text-slate-600">
            Screens from the app itself. The frames below are labelled
            placeholders until real captures replace them: a screenshot
            should show the running app, not a mockup.
          </p>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-16 sm:px-6">
        <Suspense fallback={<p className="text-sm text-slate-500">Loading screenshots...</p>}>
          <ScreenshotContent />
        </Suspense>
      </section>
    </MarketingLayout>
  );
}
