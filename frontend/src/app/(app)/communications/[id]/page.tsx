"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import {
  COMMUNICATION_CHANNEL_EVENTS,
  communicationDeliveryStatusLabel,
  deleteCommunicationChannel,
  getCommunicationChannel,
  listCommunicationDeliveries,
  testCommunicationChannel,
  updateCommunicationChannel,
} from "@/lib/comms";
import { canManageCommunications } from "@/lib/permissions";
import type {
  CommunicationChannel,
  CommunicationChannelEvent,
  CommunicationChannelTest,
  CommunicationDelivery,
  Organization,
} from "@/lib/types";

/**
 * One communication channel: its settings, the channel
 * test, and its delivery history. Testing only checks the
 * webhook shape and records a test delivery: no live
 * Slack workspace or Teams tenant is contacted in
 * v1.3.3. A failed delivery records the reason on the
 * channel and in the delivery history.
 */
function ChannelDetailContent({ org, channelId }: { org: Organization; channelId: string }) {
  const router = useRouter();
  const [channel, setChannel] = useState<CommunicationChannel | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [deliveries, setDeliveries] = useState<CommunicationDelivery[]>([]);
  const [deliveriesLoading, setDeliveriesLoading] = useState(true);
  const [actionError, setActionError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [testResult, setTestResult] = useState<CommunicationChannelTest | null>(null);
  const [settings, setSettings] = useState<Record<CommunicationChannelEvent, boolean>>({
    claim_created: true,
    evidence_received: true,
    driver_link_used: true,
    sync_completed: true,
    delivery_failed: true,
  });

  const isManager = canManageCommunications(org.current_user_role);

  useEffect(() => {
    let cancelled = false;
    getCommunicationChannel(org.id, channelId)
      .then((response) => {
        if (cancelled) return;
        setChannel(response);
        setSettings(response.settings);
      })
      .catch((err) => {
        if (!cancelled) {
          setError(err instanceof ApiRequestError ? err.message : "Could not load the channel.");
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, channelId]);

  useEffect(() => {
    let cancelled = false;
    listCommunicationDeliveries(org.id, { communication_channel_id: channelId })
      .then((response) => {
        if (!cancelled) setDeliveries(response);
      })
      .catch(() => {
        // The history is secondary; the channel above is the page.
      })
      .finally(() => {
        if (!cancelled) setDeliveriesLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, channelId]);

  function toggleEvent(event: CommunicationChannelEvent) {
    setSettings((current) => ({ ...current, [event]: !current[event] }));
  }

  async function test() {
    setActionError(null);
    setTestResult(null);
    setBusy(true);
    try {
      const result = await testCommunicationChannel(org.id, channelId);
      setTestResult(result);
      setChannel((current) => (current ? { ...current, last_tested_at: new Date().toISOString() } : current));
      const fresh = await listCommunicationDeliveries(org.id, { communication_channel_id: channelId });
      setDeliveries(fresh);
    } catch (err) {
      setActionError(err instanceof ApiRequestError ? err.message : "Could not test the channel.");
    } finally {
      setBusy(false);
    }
  }

  async function saveEvents() {
    if (!channel) return;
    setActionError(null);
    setBusy(true);
    try {
      const updated = await updateCommunicationChannel(org.id, channelId, { settings });
      setChannel(updated);
    } catch (err) {
      setActionError(err instanceof ApiRequestError ? err.message : "Could not save the events.");
    } finally {
      setBusy(false);
    }
  }

  async function setStatus(status: "active" | "disabled") {
    setActionError(null);
    setBusy(true);
    try {
      const updated = await updateCommunicationChannel(org.id, channelId, { status });
      setChannel(updated);
    } catch (err) {
      setActionError(err instanceof ApiRequestError ? err.message : "Could not change the channel status.");
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    setActionError(null);
    setBusy(true);
    try {
      await deleteCommunicationChannel(org.id, channelId);
      router.push("/communications");
    } catch (err) {
      setActionError(err instanceof ApiRequestError ? err.message : "Could not delete the channel.");
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return <Spinner />;
  }

  if (error || !channel) {
    return <Alert tone="error">{error ?? "Channel not found."}</Alert>;
  }

  return (
    <div className="space-y-6">
      {actionError ? <Alert tone="error">{actionError}</Alert> : null}

      <Card>
        <CardHeader title={channel.name} />
        <CardBody>
          <div className="flex items-center gap-2">
            <Badge tone={channel.status === "active" ? "success" : "neutral"}>{channel.status}</Badge>
            <span className="text-sm text-slate-500">{channel.provider_label}</span>
          </div>
          <p className="mt-2 text-sm text-slate-500">
            {channel.last_tested_at ? `Last tested ${channel.last_tested_at}` : "Never tested"}
          </p>
          {channel.last_error ? (
            <p className="mt-1 text-sm text-amber-700">{channel.last_error}</p>
          ) : null}
          {isManager ? (
            <div className="mt-4 flex flex-wrap gap-2">
              <Button onClick={test} disabled={busy}>
                {busy ? "Testing…" : "Test channel"}
              </Button>
              {channel.status === "active" ? (
                <Button onClick={() => setStatus("disabled")} disabled={busy} variant="secondary">
                  Disable
                </Button>
              ) : (
                <Button onClick={() => setStatus("active")} disabled={busy} variant="secondary">
                  Enable
                </Button>
              )}
              <Button onClick={remove} disabled={busy} variant="danger">
                Delete channel
              </Button>
            </div>
          ) : null}
          {testResult ? (
            <Alert tone={testResult.ok ? "success" : "error"}>{testResult.message}</Alert>
          ) : null}
        </CardBody>
      </Card>

      {isManager ? (
        <Card>
          <CardHeader title="Events" />
          <CardBody>
            <div className="space-y-2">
              {COMMUNICATION_CHANNEL_EVENTS.map((entry) => (
                <label key={entry.value} className="flex items-center gap-2 text-sm text-slate-700">
                  <input
                    type="checkbox"
                    checked={settings[entry.value] ?? true}
                    onChange={() => toggleEvent(entry.value)}
                  />
                  {entry.label}
                </label>
              ))}
            </div>
            <div className="mt-4">
              <Button onClick={saveEvents} disabled={busy}>
                Save events
              </Button>
            </div>
          </CardBody>
        </Card>
      ) : (
        <Card>
          <CardHeader title="Events" />
          <CardBody>
            <p className="text-sm text-slate-500">
              {COMMUNICATION_CHANNEL_EVENTS.filter((entry) => channel.settings[entry.value])
                .map((entry) => entry.label)
                .join(", ") || "No events subscribed."}
            </p>
          </CardBody>
        </Card>
      )}

      <Card>
        <CardHeader title="Deliveries" />
        <CardBody>
          {deliveriesLoading ? (
            <Spinner />
          ) : deliveries.length === 0 ? (
            <EmptyState title="No deliveries yet" description="Test the channel to record a delivery." />
          ) : (
            <ul className="space-y-3">
              {deliveries.map((delivery) => (
                <li key={delivery.id} className="border-b border-slate-200 pb-3 last:border-0 last:pb-0">
                  <div className="flex items-center gap-2">
                    <span className="font-medium">{delivery.title}</span>
                    <Badge tone={delivery.status === "failed" ? "danger" : delivery.status === "sent" ? "success" : "neutral"}>
                      {communicationDeliveryStatusLabel(delivery.status)}
                    </Badge>
                  </div>
                  <p className="mt-1 text-sm text-slate-500">{delivery.event} · {delivery.created_at}</p>
                  {delivery.detail ? (
                    <p className="mt-1 text-sm text-amber-700">{delivery.detail}</p>
                  ) : null}
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>

      <p className="text-sm text-slate-500">
        <Link href="/communications" className="text-sky-700 hover:underline">
          Back to Communications
        </Link>
      </p>
    </div>
  );
}

export default function ChannelDetailPage() {
  const { currentOrg } = useAuth();
  const params = useParams();
  const channelId = typeof params.id === "string" ? params.id : "";

  return (
    <div>
      <PageHeader
        title="Channel"
        description="One communication channel: settings, channel test, and delivery history."
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Communications", href: "/communications" },
          { label: "Channel" },
        ]}
      />
      {currentOrg && channelId ? (
        <ChannelDetailContent key={`${currentOrg.id}-${channelId}`} org={currentOrg} channelId={channelId} />
      ) : (
        <Alert tone="info">Create an organization first to use communications.</Alert>
      )}
    </div>
  );
}
