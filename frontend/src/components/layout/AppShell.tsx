"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useRef, useState, type ReactNode } from "react";
import { useAuth } from "@/lib/auth";
import { apiFetch } from "@/lib/api";
import {
  canManageBilling,
  canManageCustomization,
  canManageWorkflows,
  canViewAnalytics,
  canViewAuditLogs,
  canViewOperations,
  canViewReports,
  canViewSecurity,
} from "@/lib/permissions";
import type { OnboardingProgress, OrganizationRole } from "@/lib/types";

type NavSection = "Workspace" | "Insights" | "Settings";

interface NavItem {
  label: string;
  href: string;
  section: NavSection;
  /** When set, the item only shows for roles the check passes for. */
  visibleFor?: (role: OrganizationRole | null | undefined) => boolean;
}

const NAV_ITEMS: NavItem[] = [
  { label: "Dashboard", href: "/dashboard", section: "Workspace" },
  { label: "Shipments", href: "/shipments", section: "Workspace" },
  { label: "Claims", href: "/claims", section: "Workspace" },
  { label: "Deadlines", href: "/deadlines", section: "Workspace" },
  { label: "Evidence", href: "/evidence", section: "Workspace" },
  { label: "Notifications", href: "/notifications", section: "Workspace" },
  { label: "Email Intake", href: "/email-intake", section: "Workspace" },
  { label: "Imports", href: "/imports", section: "Workspace" },
  { label: "TMS Integrations", href: "/tms", section: "Workspace" },
  { label: "Communications", href: "/communications", section: "Workspace" },
  { label: "Reconciliation", href: "/reconciliation", section: "Workspace" },
  { label: "Workflows", href: "/workflows", section: "Workspace", visibleFor: canManageWorkflows },
  { label: "Support", href: "/support", section: "Workspace" },
  { label: "Reports", href: "/reports", section: "Insights", visibleFor: canViewReports },
  { label: "Analytics", href: "/analytics", section: "Insights", visibleFor: canViewAnalytics },
  { label: "Operational Intelligence", href: "/operations", section: "Insights", visibleFor: canViewAnalytics },
  { label: "Audit Logs", href: "/audit-logs", section: "Insights", visibleFor: canViewAuditLogs },
  { label: "Members", href: "/settings/members", section: "Settings" },
  { label: "Organization", href: "/settings/organization", section: "Settings" },
  { label: "Evidence Requirements", href: "/settings/evidence-requirements", section: "Settings" },
  { label: "Customization", href: "/settings/customization", section: "Settings", visibleFor: canManageCustomization },
  { label: "Operations", href: "/settings/operations", section: "Settings", visibleFor: canViewOperations },
  { label: "Security", href: "/settings/security", section: "Settings", visibleFor: canViewSecurity },
  { label: "Billing", href: "/settings/billing", section: "Settings", visibleFor: canManageBilling },
  { label: "Profile", href: "/settings/profile", section: "Settings" },
];

const NAV_SECTIONS: NavSection[] = ["Workspace", "Insights", "Settings"];

function SidebarContent({
  onNavigate,
  unreadCount,
  showOnboarding,
}: {
  onNavigate?: () => void;
  unreadCount: number;
  showOnboarding: boolean;
}) {
  const pathname = usePathname();
  const { organizations, currentOrg, selectOrganization } = useAuth();
  const router = useRouter();
  const myRole = currentOrg?.current_user_role;
  const visibleItems = NAV_ITEMS.filter(
    (item) => !item.visibleFor || item.visibleFor(myRole),
  );

  return (
    <div className="flex h-full flex-col">
      <Link
        href="/dashboard"
        onClick={onNavigate}
        className="flex min-h-11 items-center gap-2 px-5 py-5"
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
            className="min-h-11 w-full rounded-md border border-brand-700 bg-brand-900 px-2.5 py-2 text-sm text-white focus:border-brand-400 focus:outline-none"
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
          className="mt-2 inline-block min-h-11 text-sm text-brand-200 underline hover:text-white"
        >
          Create an organization
        </Link>
      </div>

      <nav aria-label="Main" className="flex-1 overflow-y-auto px-3 pb-4">
        {NAV_SECTIONS.map((section) => {
          const items = visibleItems.filter(
            (item) => item.section === section,
          );
          // The onboarding link lives at the top of the Workspace
          // section and only shows while onboarding is incomplete.
          const sectionItems =
            section === "Workspace" && showOnboarding
              ? [
                  {
                    label: "Onboarding",
                    href: "/onboarding",
                    section: "Workspace" as NavSection,
                  },
                  ...items,
                ]
              : items;
          if (sectionItems.length === 0) return null;
          return (
            <div key={section} className="mt-2 first:mt-0">
              <p className="px-3 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-brand-400 first:pt-0">
                {section}
              </p>
              <ul className="space-y-1">
                {sectionItems.map((item) => {
                  const active =
                    pathname === item.href ||
                    pathname.startsWith(`${item.href}/`);
                  const showBadge =
                    item.href === "/notifications" && unreadCount > 0;
                  return (
                    <li key={item.href}>
                      <Link
                        href={item.href}
                        onClick={onNavigate}
                        aria-current={active ? "page" : undefined}
                        className={`flex min-h-11 items-center justify-between gap-2 rounded-md px-3 py-2 text-sm font-medium ${
                          active
                            ? "bg-brand-800 text-white"
                            : "text-brand-100 hover:bg-brand-900 hover:text-white"
                        }`}
                      >
                        {item.label}
                        {showBadge && (
                          <span
                            aria-label={`${unreadCount} unread notifications`}
                            className="inline-flex min-w-5 items-center justify-center rounded-full bg-amber-500 px-1.5 py-0.5 text-xs font-semibold text-brand-950"
                          >
                            {unreadCount > 99 ? "99+" : unreadCount}
                          </span>
                        )}
                      </Link>
                    </li>
                  );
                })}
              </ul>
            </div>
          );
        })}
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
  const { user, currentOrg, logout } = useAuth();
  const pathname = usePathname();
  const [menuOpen, setMenuOpen] = useState(false);
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [unreadCount, setUnreadCount] = useState(0);
  const [onboarding, setOnboarding] = useState<{
    orgId: string;
    progress: OnboardingProgress;
  } | null>(null);
  const menuRef = useRef<HTMLDivElement>(null);

  // Onboarding link: only while onboarding is incomplete for the
  // current organization. A failed fetch hides the link rather than
  // breaking the shell. The stored org id keeps a stale response from
  // showing the link after switching organizations.
  useEffect(() => {
    if (!currentOrg) {
      return;
    }
    let cancelled = false;
    apiFetch<{ data: OnboardingProgress }>("/onboarding", {
      orgId: currentOrg.id,
    })
      .then((response) => {
        if (!cancelled) {
          setOnboarding({ orgId: currentOrg.id, progress: response.data });
        }
      })
      .catch(() => {
        if (!cancelled) setOnboarding(null);
      });
    return () => {
      cancelled = true;
    };
  }, [pathname, currentOrg]);

  const showOnboarding =
    currentOrg !== null &&
    onboarding?.orgId === currentOrg.id &&
    !onboarding.progress.all_completed &&
    !onboarding.progress.dismissed;

  // Unread badge: refetched on every navigation and on organization
  // switch. No websockets in Phase 1, so the count can lag until the
  // next navigation. A failed fetch hides the badge rather than
  // breaking the shell.
  useEffect(() => {
    if (!currentOrg) {
      // No organization: nothing to count. The badge starts at zero
      // and stays there until an organization is selected.
      return;
    }
    let cancelled = false;
    apiFetch<{ data: { unread_count: number } }>("/notifications/unread-count", {
      orgId: currentOrg.id,
    })
      .then((response) => {
        if (!cancelled) setUnreadCount(response.data.unread_count);
      })
      .catch(() => {
        if (!cancelled) setUnreadCount(0);
      });
    return () => {
      cancelled = true;
    };
  }, [pathname, currentOrg]);

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

  // The mobile drawer closes on Escape as well as on navigation and
  // on backdrop click.
  useEffect(() => {
    if (!sidebarOpen) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") setSidebarOpen(false);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [sidebarOpen]);

  return (
    <div className="flex min-h-screen bg-slate-50">
      {/* Desktop sidebar */}
      <aside className="fixed inset-y-0 left-0 hidden w-64 bg-brand-950 md:block">
        <SidebarContent unreadCount={unreadCount} showOnboarding={showOnboarding} />
      </aside>

      {/* Mobile sidebar drawer */}
      {sidebarOpen && (
        <div className="fixed inset-0 z-40 md:hidden">
          <div
            className="absolute inset-0 bg-slate-950/50"
            onClick={() => setSidebarOpen(false)}
            role="presentation"
          />
          <aside className="absolute inset-y-0 left-0 w-64 max-w-[85vw] bg-brand-950">
            <button
              type="button"
              onClick={() => setSidebarOpen(false)}
              aria-label="Close navigation"
              className="absolute right-2 top-4 z-10 flex min-h-11 min-w-11 items-center justify-center rounded-md text-sm font-medium text-brand-100 hover:bg-brand-900 hover:text-white"
            >
              Close
            </button>
            <SidebarContent
              onNavigate={() => setSidebarOpen(false)}
              unreadCount={unreadCount}
              showOnboarding={showOnboarding}
            />
          </aside>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col md:pl-64">
        <header className="sticky top-0 z-30 flex items-center justify-between gap-4 border-b border-slate-200 bg-white px-4 py-3 sm:px-6">
          <button
            type="button"
            className="flex min-h-11 min-w-11 items-center justify-center rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 md:hidden"
            onClick={() => setSidebarOpen(true)}
            aria-label="Open navigation"
            aria-expanded={sidebarOpen}
          >
            Menu
          </button>
          <p className="hidden text-sm text-slate-500 md:block">
            ClaimTrail v1.5.1
          </p>

          <div className="relative" ref={menuRef}>
            <button
              type="button"
              onClick={() => setMenuOpen((open) => !open)}
              aria-expanded={menuOpen}
              aria-haspopup="menu"
              className="flex min-h-11 items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
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

        <main className="mx-auto w-full min-w-0 max-w-6xl flex-1 px-4 py-6 sm:px-6">
          {children}
        </main>

        <footer className="border-t border-slate-200 px-4 py-4 text-center text-xs text-slate-500 sm:px-6">
          ClaimTrail v1.5.1. Claim amounts shown anywhere in the product are
          potential amounts, never a guarantee of recovery.
        </footer>
      </div>
    </div>
  );
}
