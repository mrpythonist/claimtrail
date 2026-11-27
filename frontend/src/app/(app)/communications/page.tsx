"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Input, Select } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import {
  COMMUNICATION_CHANNEL_EVENTS,
  createCommunicationChannel,
  createDriverEvidenceLink,
  listCommunicationChannels,
  listCommunicationDeliveries,
  listCommunicationProviders,
  listDriverEvidenceLinks,
} from "@/lib/comms";
import { canManageCommunications } from "@/lib/permissions";
import type {
  CommunicationChannel,
  CommunicationChannelEvent,
  CommunicationDelivery,
  CommunicationProvider,
  DriverEvidenceLink,
  DriverEvidenceLinkCreated,
  Organization,
} from "@/lib/types";

/**
 * Communication channels and driver evidence links for one
 * organization. Channels connect Slack or Microsoft Teams
 * through an incoming webhook; no provider is live in
 * v1.3.3, so deliveries are recorded locally and nothing
 * is sent to a real workspace. Driver links let a driver
 * submit evidence for one shipment or claim without an
 * account: each link expires, is single-use by default,
 * and can be revoked. The raw token is shown once, at
 * creation, and never again.
 */
function CommunicationsContent({ org }: { org: Organization }) {
  const [providers, setProviders] = useState<CommunicationProvider[]>([]);
  const [providersLoading, setProvidersLoading] = useState(true);
  const [providersError, setProvidersError] = useState<string | null>(null);
  const [channels, setChannels] = useState<CommunicationChannel[]>([]);
  const [channelsLoading, setChannelsLoading] = useState(true);
  const [channelsError, setChannelsError] = useState<string | null>(null);
  const [links, setLinks] = useState<DriverEvidenceLink[]>([]);
  const [linksLoading, setLinksLoading] = useState(true);
  const [linksError, setLinksError] = useState<string | null>(null);
  const [deliveries, setDeliveries] = useState<CommunicationDelivery[]>([]);
  const [deliveriesLoading, setDeliveriesLoading] = useState(true);
  const [deliveriesError, setDeliveriesError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [creatingChannel, setCreatingChannel] = useState(false);
  const [creatingLink, setCreatingLink] = useState(false);
  const [createdLink, setCreatedLink] = useState<DriverEvidenceLinkCreated | null>(null);

  const [provider, setProvider] = useState("mock");
  const [name, setName] = useState("");
  const [webhookUrl, setWebhookUrl] = useState("");
  // The API stores event subscriptions as a settings
  // object (event -> enabled); every event defaults to
  // on, so the form starts with all five checked.
  const [settings, setSettings] = useState<Record<CommunicationChannelEvent, boolean>>({
    claim_created: true,
    evidence_received: true,
    driver_link_used: true,
    sync_completed: true,
    delivery_failed: true,
  });

  const [shipmentId, setShipmentId] = useState("");
  const [claimId, setClaimId] = useState("");
  const [driverName, setDriverName] = useState("");
  const [driverEmail, setDriverEmail] = useState("");
  const [ttlHours, setTtlHours] = useState("72");
  const [maxUses, setMaxUses] = useState("1");

  const isManager = canManageCommunications(org.current_user_role);
  const selectedProvider = providers.find((entry) => entry.name === provider);
  const needsWebhookUrl = selectedProvider?.webhook_url_required ?? false;

  useEffect(() => {
    let cancelled = false;
    listCommunicationProviders(org.id)
      .then((response) => {
        if (!cancelled) setProviders(response);
      })
      .catch((err) => {
        if (!cancelled) {
          setProvidersError(err instanceof ApiRequestError ? err.message : "Could not load providers.");
        }
      })
      .finally(() => {
        if (!cancelled) setProvidersLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id]);

  useEffect(() => {
    let cancelled = false;
    listCommunicationChannels(org.id)
      .then((response) => {
        if (!cancelled) setChannels(response);
      })
      .catch((err) => {
        if (!cancelled) {
          setChannelsError(err instanceof ApiRequestError ? err.message : "Could not load channels.");
        }
      })
      .finally(() => {
        if (!cancelled) setChannelsLoading(false);
      });
    listDriverEvidenceLinks(org.id)
      .then((response) => {
        if (!cancelled) setLinks(response);
      })
      .catch((err) => {
        if (!cancelled) {
          setLinksError(err instanceof ApiRequestError ? err.message : "Could not load driver links.");
        }
      })
      .finally(() => {
        if (!cancelled) setLinksLoading(false);
      });
    listCommunicationDeliveries(org.id)
      .then((response) => {
        if (!cancelled) setDeliveries(response);
      })
      .catch((err) => {
        if (!cancelled) {
          setDeliveriesError(err instanceof ApiRequestError ? err.message : "Could not load deliveries.");
        }
      })
      .finally(() => {
        if (!cancelled) setDeliveriesLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id]);

  function toggleEvent(event: CommunicationChannelEvent) {
    setSettings((current) => ({ ...current, [event]: !current[event] }));
  }

  async function createChannel() {
    if (!name.trim()) {
      setActionError("Give the channel a name.");
      return;
    }
    if (needsWebhookUrl && !webhookUrl.trim()) {
      setActionError("This provider needs a webhook URL.");
      return;
    }
    setActionError(null);
    setCreatingChannel(true);
    try {
      const created = await createCommunicationChannel(org.id, {
        provider,
        name: name.trim(),
        webhook_url: webhookUrl.trim() ? webhookUrl.trim() : null,
        settings,
      });
      setChannels((current) => [created, ...current]);
      setName("");
      setWebhookUrl("");
    } catch (err) {
      setActionError(err instanceof ApiRequestError ? err.message : "Could not create the channel.");
    } finally {
      setCreatingChannel(false);
    }
  }

  async function createLink() {
    if (!shipmentId.trim() && !claimId.trim()) {
      setActionError("A driver link needs a shipment or a claim.");
      return;
    }
    setActionError(null);
    setCreatedLink(null);
    setCreatingLink(true);
    try {
      const created = await createDriverEvidenceLink(org.id, {
        shipment_id: shipmentId.trim() ? shipmentId.trim() : null,
        claim_id: claimId.trim() ? claimId.trim() : null,
        driver_name: driverName.trim() ? driverName.trim() : null,
        driver_email: driverEmail.trim() ? driverEmail.trim() : null,
        ttl_hours: Number(ttlHours) || 72,
        max_uses: Number(maxUses) || 1,
      });
      setLinks((current) => [created, ...current]);
      setCreatedLink(created);
      setShipmentId("");
      setClaimId("");
      setDriverName("");
      setDriverEmail("");
    } catch (err) {
      setActionError(err instanceof ApiRequestError ? err.message : "Could not create the driver link.");
    } finally {
      setCreatingLink(false);
    }
  }

  return (
    <div className="space-y-6">
      {actionError ? <Alert tone="error">{actionError}</Alert> : null}

      <Card>
        <CardHeader title="Providers" />
        <CardBody>
          {providersLoading ? (
            <Spinner />
          ) : providersError ? (
            <Alert tone="error">{providersError}</Alert>
          ) : providers.length === 0 ? (
            <EmptyState title="No providers available" />
          ) : (
            <ul className="space-y-3">
              {providers.map((entry) => (
                <li key={entry.name} className="border-b border-slate-200 pb-3 last:border-0 last:pb-0">
                  <div className="flex items-center gap-2">
                    <span className="font-medium">{entry.label}</span>
                    <Badge tone={entry.live_available ? "success" : "neutral"}>
                      {entry.live_available ? "Live" : "Not live"}
                    </Badge>
                  </div>
                  <p className="mt-1 text-sm text-slate-500">{entry.webhook_url_hint}</p>
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>

      {isManager ? (
        <Card>
          <CardHeader title="Connect a channel" />
          <CardBody>
            <div className="space-y-4">
              <Select
                label="Provider"
                id="comms-provider"
                value={provider}
                onChange={(event) => setProvider(event.target.value)}
              >
                {providers.map((entry) => (
                  <option key={entry.name} value={entry.name}>
                    {entry.label}
                  </option>
                ))}
              </Select>
              <Input
                label="Name"
                id="comms-name"
                value={name}
                onChange={(event) => setName(event.target.value)}
                placeholder="e.g. Ops Slack"
              />
              {needsWebhookUrl ? (
                <>
                  <Input
                    label="Webhook URL"
                    id="comms-webhook-url"
                    type="password"
                    value={webhookUrl}
                    onChange={(event) => setWebhookUrl(event.target.value)}
                    help={selectedProvider?.webhook_url_hint}
                  />
                  <Alert tone="info">
                    The webhook URL is stored encrypted and never shown again. Testing the channel only
                    checks its shape: no live Slack workspace or Teams tenant is contacted from here yet.
                  </Alert>
                </>
              ) : (
                <Alert tone="info">
                  The mock provider needs no webhook URL. Its deliveries are recorded locally so the
                  flow can be verified without a live workspace.
                </Alert>
              )}
              <fieldset>
                <legend className="text-sm font-medium text-slate-700">Events</legend>
                <div className="mt-2 space-y-2">
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
              </fieldset>
              <Button onClick={createChannel} disabled={creatingChannel}>
                {creatingChannel ? "Connecting…" : "Connect"}
              </Button>
            </div>
          </CardBody>
        </Card>
      ) : null}

      <Card>
        <CardHeader title="Channels" />
        <CardBody>
          {channelsLoading ? (
            <Spinner />
          ) : channelsError ? (
            <Alert tone="error">{channelsError}</Alert>
          ) : channels.length === 0 ? (
            <EmptyState title="No channels yet" description="Connect the mock provider to try a delivery, or Slack or Teams to model one." />
          ) : (
            <ul className="space-y-3">
              {channels.map((channel) => (
                <li key={channel.id} className="border-b border-slate-200 pb-3 last:border-0 last:pb-0">
                  <div className="flex items-center gap-2">
                    <Link href={`/communications/${channel.id}`} className="font-medium text-sky-700 hover:underline">
                      {channel.name}
                    </Link>
                    <Badge tone={channel.status === "active" ? "success" : "neutral"}>{channel.status}</Badge>
                  </div>
                  <p className="mt-1 text-sm text-slate-500">
                    {channel.provider_label}
                    {channel.last_tested_at ? ` · Last tested ${channel.last_tested_at}` : " · Never tested"}
                  </p>
                  {channel.last_error ? (
                    <p className="mt-1 text-sm text-amber-700">{channel.last_error}</p>
                  ) : null}
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>

      {isManager ? (
        <Card>
          <CardHeader title="Create a driver evidence link" />
          <CardBody>
            <div className="space-y-4">
              <Input
                label="Shipment ID"
                id="comms-shipment-id"
                value={shipmentId}
                onChange={(event) => setShipmentId(event.target.value)}
                placeholder="Shipment id, from the shipment page"
                help="A link needs a shipment, a claim, or both. The link opens only that shipment or claim."
              />
              <Input
                label="Claim ID"
                id="comms-claim-id"
                value={claimId}
                onChange={(event) => setClaimId(event.target.value)}
                placeholder="Claim id, from the claim page"
              />
              <Input
                label="Driver name"
                id="comms-driver-name"
                value={driverName}
                onChange={(event) => setDriverName(event.target.value)}
              />
              <Input
                label="Driver email"
                id="comms-driver-email"
                type="email"
                value={driverEmail}
                onChange={(event) => setDriverEmail(event.target.value)}
                help="The link is emailed to this address when one is given."
              />
              <Input
                label="Expires after (hours)"
                id="comms-ttl-hours"
                type="number"
                value={ttlHours}
                onChange={(event) => setTtlHours(event.target.value)}
              />
              <Input
                label="Maximum uses"
                id="comms-max-uses"
                type="number"
                value={maxUses}
                onChange={(event) => setMaxUses(event.target.value)}
                help="1 to 10. A link stops working once it is used this many times, or when it expires, or when it is revoked."
              />
              <Button onClick={createLink} disabled={creatingLink}>
                {creatingLink ? "Creating…" : "Create link"}
              </Button>
              {createdLink ? (
                <Alert tone="info">
                  Link created. Share this URL now: it is shown once and cannot be recovered later.
                  <span className="mt-2 block break-all font-mono text-xs">{createdLink.submit_url}</span>
                </Alert>
              ) : null}
            </div>
          </CardBody>
        </Card>
      ) : null}

      <Card>
        <CardHeader title="Driver evidence links" />
        <CardBody>
          {linksLoading ? (
            <Spinner />
          ) : linksError ? (
            <Alert tone="error">{linksError}</Alert>
          ) : links.length === 0 ? (
            <EmptyState title="No driver links yet" description="Create one above to let a driver submit evidence without an account." />
          ) : (
            <ul className="space-y-3">
              {links.map((link) => (
                <li key={link.id} className="border-b border-slate-200 pb-3 last:border-0 last:pb-0">
                  <div className="flex items-center gap-2">
                    <span className="font-medium">{link.driver_name ?? "Unnamed driver"}</span>
                    <Badge tone={link.state === "usable" ? "success" : "neutral"}>{link.state}</Badge>
                  </div>
                  <p className="mt-1 text-sm text-slate-500">
                    Uses {link.used_count} of {link.max_uses} · Expires {link.expires_at}
                    {link.driver_email ? ` · ${link.driver_email}` : ""}
                    {link.shipment_reference ? ` · ${link.shipment_reference}` : ""}
                    {link.claim_reference ? ` · ${link.claim_reference}` : ""}
                  </p>
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Recent deliveries" />
        <CardBody>
          {deliveriesLoading ? (
            <Spinner />
          ) : deliveriesError ? (
            <Alert tone="error">{deliveriesError}</Alert>
          ) : deliveries.length === 0 ? (
            <EmptyState title="No deliveries yet" description="Test a channel or let an event fire to record a delivery." />
          ) : (
            <ul className="space-y-3">
              {deliveries.slice(0, 10).map((delivery) => (
                <li key={delivery.id} className="border-b border-slate-200 pb-3 last:border-0 last:pb-0">
                  <div className="flex items-center gap-2">
                    <span className="font-medium">{delivery.title}</span>
                    <Badge tone={delivery.status === "failed" ? "danger" : delivery.status === "sent" ? "success" : "neutral"}>
                      {delivery.status}
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
    </div>
  );
}

export default function CommunicationsPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Communications"
        description={
          currentOrg
            ? `Communication channels and driver evidence links for ${currentOrg.name}. Slack and Teams channels send event notifications; driver links let a driver submit evidence without an account.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Communications" },
        ]}
      />
      {currentOrg ? (
        <CommunicationsContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to use communications.</Alert>
      )}
    </div>
  );
}
