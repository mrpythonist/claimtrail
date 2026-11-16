import type { ReactNode } from "react";

type Tone = "error" | "success" | "info" | "warning";

const toneClasses: Record<Tone, string> = {
  error: "border-red-200 bg-red-50 text-red-800",
  success: "border-emerald-200 bg-emerald-50 text-emerald-800",
  info: "border-brand-200 bg-brand-50 text-brand-800",
  warning: "border-amber-200 bg-amber-50 text-amber-800",
};

export function Alert({ tone, children }: { tone: Tone; children: ReactNode }) {
  return (
    <div role="alert" className={`rounded-md border px-4 py-3 text-sm ${toneClasses[tone]}`}>
      {children}
    </div>
  );
}
