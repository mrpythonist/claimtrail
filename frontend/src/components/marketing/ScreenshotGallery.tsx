import { SCREENSHOT_ENTRIES } from "@/lib/marketing";

/**
 * The screenshots gallery (v1.2.2).
 *
 * Each entry renders the real image when a capture exists in
 * public/screenshots/, and an honest placeholder frame when it
 * does not. The frames are labelled placeholders on purpose:
 * a CSS mockup dressed up as a screenshot would be a fabrication.
 * See docs/MARKETING.md for the capture process.
 */
export function ScreenshotGallery({
  available,
}: {
  available: Record<string, boolean>;
}) {
  return (
    <div className="grid gap-6 sm:grid-cols-2">
      {SCREENSHOT_ENTRIES.map((entry) => {
        const exists = available[entry.key] === true;
        return (
          <div
            key={entry.key}
            className="overflow-hidden rounded-lg border border-slate-200 bg-white"
          >
            {exists ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={`/screenshots/${entry.key}.png`}
                alt={`${entry.title} screen in ClaimTrail`}
                className="w-full"
              />
            ) : (
              <div className="flex aspect-video flex-col items-center justify-center gap-2 bg-slate-100 px-6 text-center">
                <p className="text-sm font-semibold text-slate-700">
                  Screenshot placeholder
                </p>
                <p className="text-xs leading-relaxed text-slate-500">
                  A real capture of the {entry.title.toLowerCase()} screen
                  goes here. No screenshot exists yet.
                </p>
              </div>
            )}
            <div className="p-4">
              <h3 className="text-base font-semibold text-slate-900">{entry.title}</h3>
              <p className="mt-1 text-sm leading-relaxed text-slate-600">
                {entry.description}
              </p>
            </div>
          </div>
        );
      })}
    </div>
  );
}
