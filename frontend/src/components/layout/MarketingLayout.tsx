"use client";

import Link from "next/link";
import type { ReactNode } from "react";
import { AnalyticsTracker } from "@/components/analytics/AnalyticsTracker";

const NAV_LINKS = [
  { href: "/product", label: "Product" },
  { href: "/features", label: "Features" },
  { href: "/pricing", label: "Pricing" },
  { href: "/demo", label: "Demo" },
  { href: "/screenshots", label: "Screenshots" },
  { href: "/getting-started", label: "Getting started" },
];

/**
 * Shared header and footer for the public marketing pages
 * (v1.2.2). The authenticated app has its own shell; the
 * marketing pages share this one so the nav, the calls to action,
 * and the honest footer note stay the same everywhere.
 */
export function MarketingLayout({ children }: { children: ReactNode }) {
  return (
    <div className="flex min-h-screen flex-col bg-white">
      <AnalyticsTracker />
      <header className="border-b border-slate-200">
        <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6">
          <Link href="/" className="flex items-center gap-2">
            <span className="flex h-8 w-8 items-center justify-center rounded-md bg-amber-500 text-sm font-bold text-brand-950">
              CT
            </span>
            <span className="text-lg font-semibold tracking-tight text-slate-900">
              ClaimTrail
            </span>
          </Link>
          <nav className="flex flex-wrap items-center gap-1">
            {NAV_LINKS.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                className="rounded-md px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
              >
                {link.label}
              </Link>
            ))}
          </nav>
          <nav className="flex items-center gap-3">
            <Link
              href="/login"
              className="rounded-md px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
            >
              Sign in
            </Link>
            <Link
              href="/register"
              className="rounded-md bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800"
            >
              Get started
            </Link>
          </nav>
        </div>
      </header>

      <main className="flex-1">{children}</main>

      <footer className="border-t border-slate-200 bg-slate-50">
        <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6">
          <div className="grid gap-8 sm:grid-cols-3">
            <div>
              <p className="text-sm font-semibold text-slate-900">Product</p>
              <ul className="mt-3 space-y-2 text-sm">
                <li>
                  <Link href="/product" className="text-slate-600 hover:text-slate-900">
                    Overview
                  </Link>
                </li>
                <li>
                  <Link href="/features" className="text-slate-600 hover:text-slate-900">
                    Features
                  </Link>
                </li>
                <li>
                  <Link href="/pricing" className="text-slate-600 hover:text-slate-900">
                    Pricing
                  </Link>
                </li>
                <li>
                  <Link href="/demo" className="text-slate-600 hover:text-slate-900">
                    Demo
                  </Link>
                </li>
                <li>
                  <Link href="/screenshots" className="text-slate-600 hover:text-slate-900">
                    Screenshots
                  </Link>
                </li>
              </ul>
            </div>
            <div>
              <p className="text-sm font-semibold text-slate-900">Start</p>
              <ul className="mt-3 space-y-2 text-sm">
                <li>
                  <Link href="/getting-started" className="text-slate-600 hover:text-slate-900">
                    Getting started
                  </Link>
                </li>
                <li>
                  <Link href="/help" className="text-slate-600 hover:text-slate-900">
                    Help
                  </Link>
                </li>
                <li>
                  <Link href="/demo-request" className="text-slate-600 hover:text-slate-900">
                    Request a demo
                  </Link>
                </li>
                <li>
                  <Link href="/register" className="text-slate-600 hover:text-slate-900">
                    Create your organization
                  </Link>
                </li>
                <li>
                  <Link href="/login" className="text-slate-600 hover:text-slate-900">
                    Sign in
                  </Link>
                </li>
              </ul>
            </div>
            <div>
              <p className="text-sm font-semibold text-slate-900">Legal</p>
              <ul className="mt-3 space-y-2 text-sm">
                <li>
                  <Link href="/privacy" className="text-slate-600 hover:text-slate-900">
                    Privacy
                  </Link>
                </li>
                <li>
                  <Link href="/terms" className="text-slate-600 hover:text-slate-900">
                    Terms
                  </Link>
                </li>
              </ul>
            </div>
          </div>
          <p className="mt-8 text-sm text-slate-500">
            ClaimTrail v1.3.2. Transportation revenue recovery, done carefully.
            ClaimTrail tracks potential charges and the evidence behind them.
            It never guarantees that a charge is recoverable.
          </p>
        </div>
      </footer>
    </div>
  );
}
