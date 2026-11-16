import { barWidth } from "@/lib/reporting";

/**
 * A simple CSS bar next to a count, for the reports and dashboard
 * tables. No chart dependency: the bar width is the count as a
 * percentage of the largest count in the set.
 */
export function CountBar({
  label,
  count,
  maxCount,
}: {
  label: string;
  count: number;
  maxCount: number;
}) {
  const width = barWidth(count, maxCount);
  return (
    <div className="flex items-center gap-3">
      <span className="w-40 shrink-0 truncate text-slate-700">{label}</span>
      <div
        className="h-2.5 flex-1 overflow-hidden rounded-full bg-slate-100"
        role="img"
        aria-label={`${label}: ${count}`}
      >
        <div
          className="h-full rounded-full bg-brand-600"
          style={{ width: `${width}%` }}
        />
      </div>
      <span className="w-10 shrink-0 text-right font-medium text-slate-900">
        {count}
      </span>
    </div>
  );
}
