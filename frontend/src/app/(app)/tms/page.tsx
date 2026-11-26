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
import { canManageTmsIntegrations } from "@/lib/permissions";
import {
  createTmsIntegration,
  listTmsIntegrations,
  listTmsProviders,
} from "@/lib/tms";
import type {
  Organization,
  TmsIntegration,
  TmsProvider,
} from "@/lib/types";

/**
 * TMS integrations for one organization. The provider
 * catalogue is the adapter's registered drivers: the mock
 * provider and Trimble TMW.Suite modelled schema-only.
 * No live TMS is contacted: the mock syncs dummy data and
 * the Trimble provider fails every fetch with a
 * live-unavailable message until a TMW customer, a
 * Trimble rep, and an on-prem connector exist. Any member
 * may read this page; creating integrations needs
 * manage-tms-integrations.
 */
function TmsContent({ org }: { org: Organization }) {
  const [providers, setProviders] = useState<TmsProvider[]>([]);
  const [providersLoading, setProvidersLoading] = useState(true);
  const [providersError, setProvidersError] = useState<string | null>(null);
  const [integrations, setIntegrations] = useState<TmsIntegration[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);

  const [provider, setProvider] = useState("mock");
  const [name, setName] = useState("");
  const [clientId, setClientId] = useState("");
  const [clientSecret, setClientSecret] = useState("");
  const [accountId, setAccountId] = useState("");

  const isManager = canManageTmsIntegrations(org.current_user_role);
  const needsCredentials = provider === "trimble-tmw";

  useEffect(() => {
    let cancelled = false;
    listTmsProviders(org.id)
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
    listTmsIntegrations(org.id)
      .then((response) => {
        if (!cancelled) setIntegrations(response);
      })
      .catch((err) => {
        if (!cancelled) {
          setError(err instanceof ApiRequestError ? err.message : "Could not load integrations.");
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id]);

  async function create() {
    if (!name.trim()) {
      setActionError("Give the integration a name.");
      return;
    }
    setActionError(null);
    setCreating(true);
    try {
      const created = await createTmsIntegration(org.id, {
        provider,
        name: name.trim(),
        credentials: needsCredentials
          ? { client_id: clientId.trim(), client_secret: clientSecret, account_id: accountId.trim() }
          : undefined,
      });
      setIntegrations((current) => [created, ...current]);
      setName("");
      setClientId("");
      setClientSecret("");
      setAccountId("");
    } catch (err) {
      setActionError(err instanceof ApiRequestError ? err.message : "Could not create the integration.");
    } finally {
      setCreating(false);
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
                  <p className="mt-1 text-sm text-slate-500">{entry.live_note}</p>
                  <p className="mt-1 text-sm text-slate-500">
                    Shipments, status, appointments, and documents:{" "}
                    {[
                      entry.capabilities.shipments,
                      entry.capabilities.status,
                      entry.capabilities.appointments,
                      entry.capabilities.documents,
                    ].every(Boolean)
                      ? "supported"
                      : "partial"}
                    .
                  </p>
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>

      {isManager ? (
        <Card>
          <CardHeader title="Connect a TMS" />
          <CardBody>
            <div className="space-y-4">
              <Select
                label="Provider"
                id="tms-provider"
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
                id="tms-name"
                value={name}
                onChange={(event) => setName(event.target.value)}
                placeholder="e.g. TMW Production"
              />
              {needsCredentials ? (
                <>
                  <Input
                    label="Client ID"
                    id="tms-client-id"
                    value={clientId}
                    onChange={(event) => setClientId(event.target.value)}
                    help="From a TTC API Key application, provisioned through a Trimble representative."
                  />
                  <Input
                    label="Client secret"
                    id="tms-client-secret"
                    type="password"
                    value={clientSecret}
                    onChange={(event) => setClientSecret(event.target.value)}
                  />
                  <Input
                    label="Account ID"
                    id="tms-account-id"
                    value={accountId}
                    onChange={(event) => setAccountId(event.target.value)}
                    help="The TTC account id the CloudHub configuration maps to the customer's connector."
                  />
                  <Alert tone="info">
                    These credentials are stored encrypted and never shown again. Testing the connection
                    only checks their shape: no live TMW system is reachable from here yet.
                  </Alert>
                </>
              ) : (
                <Alert tone="info">
                  The mock provider needs no credentials. Its syncs run against three fixed dummy loads.
                </Alert>
              )}
              <Button onClick={create} disabled={creating}>
                {creating ? "Connecting…" : "Connect"}
              </Button>
            </div>
          </CardBody>
        </Card>
      ) : null}

      <Card>
        <CardHeader title="Integrations" />
        <CardBody>
          {loading ? (
            <Spinner />
          ) : error ? (
            <Alert tone="error">{error}</Alert>
          ) : integrations.length === 0 ? (
            <EmptyState title="No TMS integrations yet" description="Connect the mock provider to try a sync, or Trimble TMW.Suite to model one." />
          ) : (
            <ul className="space-y-3">
              {integrations.map((integration) => (
                <li key={integration.id} className="border-b border-slate-200 pb-3 last:border-0 last:pb-0">
                  <div className="flex items-center gap-2">
                    <Link href={`/tms/${integration.id}`} className="font-medium text-sky-700 hover:underline">
                      {integration.name}
                    </Link>
                    <Badge tone={integration.status === "active" ? "success" : "neutral"}>
                      {integration.status}
                    </Badge>
                    {integration.live_available ? null : (
                      <Badge tone="neutral">Not live</Badge>
                    )}
                  </div>
                  <p className="mt-1 text-sm text-slate-500">
                    {integration.provider_label}
                    {integration.last_sync_at ? ` · Last sync ${integration.last_sync_at}` : " · Never synced"}
                  </p>
                  {integration.last_error ? (
                    <p className="mt-1 text-sm text-amber-700">{integration.last_error}</p>
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

export default function TmsPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="TMS Integrations"
        description={
          currentOrg
            ? `TMS integrations for ${currentOrg.name}. Sync shipments, statuses, appointments, and documents from a transportation management system.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "TMS Integrations" },
        ]}
      />
      {currentOrg ? (
        <TmsContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to use TMS integrations.</Alert>
      )}
    </div>
  );
}
