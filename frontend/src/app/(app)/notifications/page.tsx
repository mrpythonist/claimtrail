"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { notificationLinks } from "@/lib/reporting";
import type {
  AppNotification,
  NotificationsMeta,
  Organization,
} from "@/lib/types";

function formatDate(value: string): string {
  return new Date(value).toLocaleString();
}

/**
 * Notification list for one organization. The parent keys it by
 * organization id, so switching organizations remounts it with
 * fresh state.
 */
function NotificationsContent({ org }: { org: Organization }) {
  const [notifications, setNotifications] = useState<AppNotification[]>([]);
  const [meta, setMeta] = useState<NotificationsMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [unreadOnly, setUnreadOnly] = useState(false);
  const [page, setPage] = useState(1);
  const [markingAll, setMarkingAll] = useState(false);
  const [markingId, setMarkingId] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams({ page: String(page) });
    if (unreadOnly) params.set("unread", "1");

    apiFetch<{ data: AppNotification[]; meta: NotificationsMeta }>(
      `/notifications?${params.toString()}`,
      { orgId: org.id },
    )
      .then((response) => {
        if (!cancelled) {
          setNotifications(response.data);
          setMeta(response.meta);
        }
      })
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load notifications.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, page, unreadOnly]);

  async function markOneRead(notification: AppNotification) {
    setError(null);
    setMarkingId(notification.id);
    try {
      const response = await apiFetch<{ data: AppNotification }>(
        `/notifications/${notification.id}/read`,
        { method: "POST", orgId: org.id },
      );
      const updated = response.data;
      setNotifications((current) =>
        unreadOnly
          ? current.filter((item) => item.id !== updated.id)
          : current.map((item) => (item.id === updated.id ? updated : item)),
      );
      setMeta((current) =>
        current
          ? { ...current, unread_count: Math.max(0, current.unread_count - 1) }
          : current,
      );
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not mark the notification as read.",
      );
    } finally {
      setMarkingId(null);
    }
  }

  async function markAllRead() {
    setError(null);
    setMarkingAll(true);
    try {
      await apiFetch<{ data: { marked_read: number } }>("/notifications/read-all", {
        method: "POST",
        orgId: org.id,
      });
      setNotifications((current) =>
        unreadOnly
          ? []
          : current.map((item) => ({ ...item, is_unread: false })),
      );
      setMeta((current) =>
        current ? { ...current, unread_count: 0 } : current,
      );
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not mark notifications as read.",
      );
    } finally {
      setMarkingAll(false);
    }
  }

  const unreadCount = meta?.unread_count ?? 0;

  return (
    <div>
      {error && (
        <div className="mb-4">
          <Alert tone="error">{error}</Alert>
        </div>
      )}

      {loading ? (
        <Spinner label="Loading notifications" />
      ) : (
        <Card>
          <CardHeader
            title="Notifications"
            description={
              unreadCount === 0
                ? "You are all caught up."
                : `${unreadCount} unread notification${unreadCount === 1 ? "" : "s"}.`
            }
            action={
              <Button
                variant="secondary"
                size="sm"
                loading={markingAll}
                disabled={unreadCount === 0}
                onClick={markAllRead}
              >
                Mark all as read
              </Button>
            }
          />

          <div className="flex gap-2 border-b border-slate-200 px-5 py-3">
            <Button
              variant={unreadOnly ? "secondary" : "primary"}
              size="sm"
              onClick={() => {
                setUnreadOnly(false);
                setPage(1);
              }}
            >
              All
            </Button>
            <Button
              variant={unreadOnly ? "primary" : "secondary"}
              size="sm"
              onClick={() => {
                setUnreadOnly(true);
                setPage(1);
              }}
            >
              Unread only
              {unreadCount > 0 && ` (${unreadCount})`}
            </Button>
          </div>

          {notifications.length === 0 ? (
            <EmptyState
              title={unreadOnly ? "No unread notifications" : "No notifications yet"}
              description={
                unreadOnly
                  ? "Everything has been read. Switch to All to see past notifications."
                  : "When claims move, deadlines approach, or payments land, you will see it here."
              }
            />
          ) : (
            <>
              <ul className="divide-y divide-slate-100">
                {notifications.map((notification) => {
                  const links = notificationLinks(notification.data);
                  return (
                    <li
                      key={notification.id}
                      className={`px-5 py-4 ${notification.is_unread ? "bg-brand-50/50" : ""}`}
                    >
                      <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="min-w-0 flex-1">
                          <div className="flex flex-wrap items-center gap-2">
                            <p className="font-medium text-slate-900">
                              {notification.title}
                            </p>
                            <Badge tone={notification.is_unread ? "info" : "neutral"}>
                              {notification.is_unread ? "Unread" : "Read"}
                            </Badge>
                            <Badge tone="neutral">{notification.type_label}</Badge>
                          </div>
                          <p className="mt-1 text-sm leading-relaxed text-slate-600">
                            {notification.body}
                          </p>
                          <p className="mt-1 text-xs text-slate-500">
                            {formatDate(notification.created_at)}
                            {notification.email_sent_at &&
                              ` · Email sent ${formatDate(notification.email_sent_at)}`}
                          </p>
                          {(links.claimId || links.shipmentId || links.deadlineId) && (
                            <div className="mt-2 flex flex-wrap gap-3 text-sm">
                              {links.claimId && (
                                <Link
                                  href={`/claims/${links.claimId}`}
                                  className="font-medium text-brand-700 hover:underline"
                                >
                                  View claim
                                </Link>
                              )}
                              {links.shipmentId && (
                                <Link
                                  href={`/shipments/${links.shipmentId}`}
                                  className="font-medium text-brand-700 hover:underline"
                                >
                                  View shipment
                                </Link>
                              )}
                              {links.deadlineId && (
                                <Link
                                  href="/deadlines"
                                  className="font-medium text-brand-700 hover:underline"
                                >
                                  View deadlines
                                </Link>
                              )}
                            </div>
                          )}
                        </div>
                        {notification.is_unread && (
                          <Button
                            variant="secondary"
                            size="sm"
                            loading={markingId === notification.id}
                            onClick={() => markOneRead(notification)}
                          >
                            Mark as read
                          </Button>
                        )}
                      </div>
                    </li>
                  );
                })}
              </ul>
              {meta && meta.last_page > 1 && (
                <div className="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-sm">
                  <p className="text-slate-500">
                    Page {meta.current_page} of {meta.last_page} ({meta.total}{" "}
                    notifications)
                  </p>
                  <div className="flex gap-2">
                    <Button
                      variant="secondary"
                      size="sm"
                      disabled={page <= 1}
                      onClick={() => setPage((current) => current - 1)}
                    >
                      Previous
                    </Button>
                    <Button
                      variant="secondary"
                      size="sm"
                      disabled={page >= meta.last_page}
                      onClick={() => setPage((current) => current + 1)}
                    >
                      Next
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </Card>
      )}
      <Card className="mt-6">
        <CardBody>
          <p className="text-sm text-slate-600">
            Choose which events notify you, in the app or by email, under{" "}
            <Link
              href="/settings/profile"
              className="font-medium text-brand-700 hover:underline"
            >
              Profile settings
            </Link>
            .
          </p>
        </CardBody>
      </Card>
    </div>
  );
}

export default function NotificationsPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Notifications"
        description={
          currentOrg
            ? `Notifications for ${currentOrg.name}.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Notifications" },
        ]}
      />
      {currentOrg ? (
        <NotificationsContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">
          Create an organization first to receive notifications.
        </Alert>
      )}
    </div>
  );
}
