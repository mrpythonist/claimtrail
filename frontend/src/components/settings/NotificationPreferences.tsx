"use client";

import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Spinner } from "@/components/ui/Spinner";
import { apiFetch, ApiRequestError } from "@/lib/api";
import type { NotificationPreference, Organization } from "@/lib/types";

/**
 * Per-event-type notification channel toggles for the current
 * organization. Preferences are per member per organization, and the
 * API returns all thirteen event types with defaults (both channels on)
 * for types the member has never changed. Saving sends the whole
 * list back in one PUT.
 */
export function NotificationPreferences({ org }: { org: Organization }) {
  const [preferences, setPreferences] = useState<NotificationPreference[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<{ data: NotificationPreference[] }>("/notification-preferences", {
      orgId: org.id,
    })
      .then((response) => {
        if (!cancelled) setPreferences(response.data);
      })
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load notification preferences.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id]);

  function toggle(eventType: string, channel: "in_app" | "email") {
    setPreferences((current) =>
      current.map((preference) =>
        preference.event_type === eventType
          ? { ...preference, [channel]: !preference[channel] }
          : preference,
      ),
    );
  }

  async function handleSave() {
    setError(null);
    setMessage(null);
    setSaving(true);
    try {
      const response = await apiFetch<{ data: NotificationPreference[] }>(
        "/notification-preferences",
        {
          method: "PUT",
          orgId: org.id,
          body: {
            preferences: preferences.map((preference) => ({
              event_type: preference.event_type,
              in_app: preference.in_app,
              email: preference.email,
            })),
          },
        },
      );
      setPreferences(response.data);
      setMessage("Notification preferences saved.");
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not save notification preferences.",
      );
    } finally {
      setSaving(false);
    }
  }

  return (
    <Card>
      <CardHeader
        title="Notification preferences"
        description={`Which events notify you in ${org.name}, in the app or by email.`}
      />
      <CardBody>
        {loading ? (
          <Spinner label="Loading notification preferences" />
        ) : (
          <div className="space-y-4">
            {message && <Alert tone="success">{message}</Alert>}
            {error && <Alert tone="error">{error}</Alert>}
            {preferences.length === 0 && !error ? (
              <p className="text-sm text-slate-500">
                No notification types are available.
              </p>
            ) : (
              <>
                <ul className="divide-y divide-slate-100">
                  {preferences.map((preference) => (
                    <li
                      key={preference.event_type}
                      className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0"
                    >
                      <span className="text-sm font-medium text-slate-900">
                        {preference.label}
                      </span>
                      <span className="flex items-center gap-5 text-sm text-slate-700">
                        <label className="flex cursor-pointer items-center gap-2">
                          <input
                            type="checkbox"
                            checked={preference.in_app}
                            onChange={() =>
                              toggle(preference.event_type, "in_app")
                            }
                            className="h-4 w-4 rounded border-slate-300 accent-brand-700"
                          />
                          In app
                        </label>
                        <label className="flex cursor-pointer items-center gap-2">
                          <input
                            type="checkbox"
                            checked={preference.email}
                            onChange={() =>
                              toggle(preference.event_type, "email")
                            }
                            className="h-4 w-4 rounded border-slate-300 accent-brand-700"
                          />
                          Email
                        </label>
                      </span>
                    </li>
                  ))}
                </ul>
                <Button onClick={handleSave} loading={saving}>
                  Save preferences
                </Button>
              </>
            )}
          </div>
        )}
      </CardBody>
    </Card>
  );
}
