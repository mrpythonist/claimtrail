"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useRef, useState, type ReactNode } from "react";
import { useAuth } from "@/lib/auth";
import { Badge } from "@/components/ui/Badge";

const NAV_ITEMS = [
  { label: "Dashboard", href: "/dashboard" },
  { label: "Shipments", href: "/shipments" },
  { label: "Evidence", href: "/evidence" },
  { label: "Members", href: "/settings/members" },
  { label: "Organization", href: "/settings/organization" },
  { label: "Profile", href: "/settings/profile" },
];

// Modules that arrive in later milestones. Shown as plain text, not links,
// so nothing in the navigation pretends to work before it exists.
const UPCOMING_ITEMS = ["Claims", "Deadlines", "Reports"];

function SidebarContent({ onNavigate }: { onNavigate?: () => void }) {
  const pathname = usePathname();
  const { organizations, currentOrg, selectOrganization } = useAuth();
  const router = useRouter();

  return (
    <div className="flex h-full flex-col">
      <Link
        href="/dashboard"
        onClick={onNavigate}
        className="flex items-center gap-2 px-5 py-5"
      >
        <span className="flex h-8 w-8 items-center justify-center rounded-md bg-amber-500 text-sm font-bold text-brand-950">
          CT
        </span>
        <span className="text-lg font-semibold tracking-tight text-white">
          ClaimTrail
        </span>
      </Link>

      <div className="px-4 pb-4">
        <label
          htmlFor="org-switcher"
          className="mb-1 block text-xs font-medium uppercase tracking-wide text-brand-300"
        >
          Organization
        </label>
        {organizations.length > 0 ? (
          <select
            id="org-switcher"
            value={currentOrg?.id ?? ""}
            onChange={(event) => {
              selectOrganization(event.target.value);
              router.refresh();
            }}
            className="w-full rounded-md border border-brand-700 bg-brand-900 px-2.5 py-2 text-sm text-white focus:border-brand-400 focus:outline-none"
          >
            {organizations.map((org) => (
              <option key={org.id} value={org.id}>
                {org.name}
              </option>
            ))}
          </select>
        ) : (
          <p className="text-sm text-brand-200">No organization yet.</p>
        )}
        <Link
          href="/settings/organizations/new"
          onClick={onNavigate}
          className="mt-2 inline-block text-sm text-brand-200 underline hover:text-white"
        >
          Create an organization
        </Link>
      </div>

      <nav aria-label="Main" className="flex-1 overflow-y-auto px-3 pb-4">
        <ul className="space-y-1">
          {NAV_ITEMS.map((item) => {
            const active =
              pathname === item.href || pathname.startsWith(`${item.href}/`);
            return (
              <li key={item.href}>
                <Link
                  href={item.href}
                  onClick={onNavigate}
                  aria-current={active ? "page" : undefined}
                  className={`block rounded-md px-3 py-2 text-sm font-medium ${
                    active
                      ? "bg-brand-800 text-white"
                      : "text-brand-100 hover:bg-brand-900 hover:text-white"
                  }`}
                >
                  {item.label}
                </Link>
              </li>
            );
          })}
        </ul>

        <p className="mt-6 px-3 text-xs font-medium uppercase tracking-wide text-brand-300">
          Later milestones
        </p>
        <ul className="mt-2 space-y-1">
          {UPCOMING_ITEMS.map((item) => (
            <li
              key={item}
              className="flex items-center justify-between rounded-md px-3 py-2 text-sm text-brand-300"
            >
              {item}
              <Badge tone="neutral">Soon</Badge>
            </li>
          ))}
        </ul>
      </nav>

      <div className="border-t border-brand-800 px-5 py-4">
        <p className="text-xs leading-relaxed text-brand-300">
          Every shipment. Every entitlement. Every dollar accounted for.
        </p>
      </div>
    </div>
  );
}

export function AppShell({ children }: { children: ReactNode }) {
  const { user, logout } = useAuth();
  const [menuOpen, setMenuOpen] = useState(false);
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!menuOpen) return;
    const onClick = (event: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
        setMenuOpen(false);
      }
    };
    document.addEventListener("mousedown", onClick);
    return () => document.removeEventListener("mousedown", onClick);
  }, [menuOpen]);

  return (
    <div className="flex min-h-screen bg-slate-50">
      {/* Desktop sidebar */}
      <aside className="fixed inset-y-0 left-0 hidden w-64 bg-brand-950 md:block">
        <SidebarContent />
      </aside>

      {/* Mobile sidebar drawer */}
      {sidebarOpen && (
        <div className="fixed inset-0 z-40 md:hidden">
          <div
            className="absolute inset-0 bg-slate-950/50"
            onClick={() => setSidebarOpen(false)}
            role="presentation"
          />
          <aside className="absolute inset-y-0 left-0 w-64 bg-brand-950">
            <SidebarContent onNavigate={() => setSidebarOpen(false)} />
          </aside>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col md:pl-64">
        <header className="sticky top-0 z-30 flex items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 py-3 sm:px-6">
          <button
            type="button"
            className="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 md:hidden"
            onClick={() => setSidebarOpen(true)}
            aria-label="Open navigation"
          >
            Menu
          </button>
          <p className="hidden text-sm text-slate-500 md:block">
            ClaimTrail v1.0.1
          </p>

          <div className="relative" ref={menuRef}>
            <button
              type="button"
              onClick={() => setMenuOpen((open) => !open)}
              aria-expanded={menuOpen}
              aria-haspopup="menu"
              className="flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
            >
              <span className="flex h-6 w-6 items-center justify-center rounded-full bg-brand-700 text-xs font-semibold text-white">
                {user?.name?.charAt(0).toUpperCase() ?? "?"}
              </span>
              <span className="max-w-40 truncate">{user?.name ?? "Account"}</span>
            </button>
            {menuOpen && (
              <div
                role="menu"
                className="absolute right-0 mt-2 w-56 rounded-md border border-slate-200 bg-white py-1 shadow-lg"
              >
                <p className="truncate px-4 py-2 text-xs text-slate-500">
                  {user?.email}
                </p>
                <Link
                  href="/settings/profile"
                  role="menuitem"
                  onClick={() => setMenuOpen(false)}
                  className="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50"
                >
                  Profile settings
                </Link>
                <button
                  type="button"
                  role="menuitem"
                  onClick={() => {
                    setMenuOpen(false);
                    logout();
                  }}
                  className="block w-full px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
                >
                  Sign out
                </button>
              </div>
            )}
          </div>
        </header>

        <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:px-6">
          {children}
        </main>

        <footer className="border-t border-slate-200 px-4 py-4 text-center text-xs text-slate-500 sm:px-6">
          ClaimTrail v1.0.1. Claim amounts shown anywhere in the product are
          potential amounts, never a guarantee of recovery.
        </footer>
      </div>
    </div>
  );
}
