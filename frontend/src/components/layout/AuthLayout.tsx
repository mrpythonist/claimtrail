import Link from "next/link";
import type { ReactNode } from "react";

export function AuthLayout({
  title,
  description,
  children,
}: {
  title: string;
  description?: string;
  children: ReactNode;
}) {
  return (
    <div className="flex min-h-screen flex-col bg-slate-50">
      <header className="mx-auto w-full max-w-6xl px-4 py-4 sm:px-6">
        <Link href="/" className="inline-flex items-center gap-2">
          <span className="flex h-8 w-8 items-center justify-center rounded-md bg-amber-500 text-sm font-bold text-brand-950">
            CT
          </span>
          <span className="text-lg font-semibold tracking-tight text-slate-900">
            ClaimTrail
          </span>
        </Link>
      </header>
      <main className="flex flex-1 items-center justify-center px-4 py-8">
        <div className="w-full max-w-md rounded-lg border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
          <h1 className="text-xl font-semibold text-slate-900">{title}</h1>
          {description && <p className="mt-1 text-sm text-slate-500">{description}</p>}
          <div className="mt-6">{children}</div>
        </div>
      </main>
      <footer className="mx-auto w-full max-w-6xl px-4 py-6 text-center text-xs text-slate-500 sm:px-6">
        <Link href="/privacy" className="hover:text-slate-900">
          Privacy
        </Link>
        {" | "}
        <Link href="/terms" className="hover:text-slate-900">
          Terms
        </Link>
      </footer>
    </div>
  );
}
