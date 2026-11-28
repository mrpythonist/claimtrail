"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Input, Select } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { Table, TableCell } from "@/components/ui/Table";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import {
  API_KEY_SCOPES,
  WEBHOOK_EVENTS,
  createAccountingConnection,
  createApiKey,
  createWebhookEndpoint,
  deleteWebhookEndpoint,
  disconnectAccountingConnection,
  listAccountingConnections,
  listAccountingProviders,
  listApiKeys,
  listWebhookDeliveries,
  listWebhookEndpoints,
  revokeApiKey,
  rotateApiKey,
  syncAccountingConnection,
  testAccountingConnection,
  testWebhookEndpoint,
  updateWebhookEndpoint,
} from "@/lib/developers";
import { canManageApiKeys, canManageIntegrations } from "@/lib/permissions";
import type {
  AccountingConnection,
  AccountingProviderOption,
  ApiKey,
  Organization,
  WebhookDelivery,
  WebhookEndpoint,
} from "@/lib/types";

/**
 * Developer tools for one organization: public API keys,
 * webhook endpoints and deliveries, and mock accounting
 * connections.
 *
 * The public API is a separate surface (/api/public/v1)
 * authenticated by Bearer API key, never by user session.
 * The full key is shown exactly once, at creation or
 * rotation, and never again. Rotation keeps the old key
 * working for a 24 hour grace window, then it stops;
 * revocation is immediate and permanent. The public surface
 * is rate limited per key (120 requests per minute) and
 * gated by plan: the Free plan is refused with a 402.
 *
 * Webhook deliveries are real HTTP attempts signed with
 * HMAC-SHA256; failed deliveries retry with backoff, at most
 * 5 attempts, then are recorded failed. Accounting providers
 * are mock only (QuickBooks / Xero modelled schema-only):
 * no live credentials, no real sync, no money moves. Broker
 * integrations are read/status access only: this version does
 * not send claim packages to a broker, and no submission
 * channel exists.
 *
 * Managing keys, endpoints, and connections needs
 * manage-api-keys / manage-integrations and the Starter plan
 * or better. Any member may read this page.
 */

const SCOPE_LABELS: Record<string, string> = Object.fromEntries(
  API_KEY_SCOPES.map((item) => [item.value, item.label]),
);

const EVENT_LABELS: Record<string, string> = Object.fromEntries(
  WEBHOOK_EVENTS.map((item) => [item.value, item.label]),
);

function errorMessage(err: unknown, fallback: string): string {
  return err instanceof ApiRequestError ? err.message : fallback;
}

function isPlanError(err: unknown): boolean {
  return err instanceof ApiRequestError && err.status === 402;
}

function DevelopersContent({ org }: { org: Organization }) {
  const [tab, setTab] = useState<"keys" | "webhooks" | "accounting">("keys");

  const [keys, setKeys] = useState<ApiKey[]>([]);
  const [keysLoading, setKeysLoading] = useState(true);
  const [keysError, setKeysError] = useState<string | null>(null);
  const [keysPlanBlocked, setKeysPlanBlocked] = useState(false);
  const [revealedKey, setRevealedKey] = useState<string | null>(null);
  const [revealedKeyName, setRevealedKeyName] = useState<string | null>(null);
  const [keyName, setKeyName] = useState("");
  const [keyKind, setKeyKind] = useState<"standard" | "broker">("standard");
  const [keyScopes, setKeyScopes] = useState<string[]>(["claims:read"]);
  const [keyBrokerName, setKeyBrokerName] = useState("");
  const [keyFormError, setKeyFormError] = useState<string | null>(null);
  const [keyBusy, setKeyBusy] = useState(false);

  const [endpoints, setEndpoints] = useState<WebhookEndpoint[]>([]);
  const [endpointsLoading, setEndpointsLoading] = useState(true);
  const [endpointsError, setEndpointsError] = useState<string | null>(null);
  const [revealedSecret, setRevealedSecret] = useState<string | null>(null);
  const [endpointName, setEndpointName] = useState("");
  const [endpointUrl, setEndpointUrl] = useState("");
  const [endpointEvents, setEndpointEvents] = useState<string[]>(["claim.created"]);
  const [endpointFormError, setEndpointFormError] = useState<string | null>(null);
  const [endpointBusy, setEndpointBusy] = useState(false);
  const [deliveries, setDeliveries] = useState<WebhookDelivery[]>([]);
  const [deliveriesFor, setDeliveriesFor] = useState<string | null>(null);
  const [deliveriesLoading, setDeliveriesLoading] = useState(false);
  const [deliveriesError, setDeliveriesError] = useState<string | null>(null);

  const [providers, setProviders] = useState<AccountingProviderOption[]>([]);
  const [connections, setConnections] = useState<AccountingConnection[]>([]);
  const [accountingLoading, setAccountingLoading] = useState(true);
  const [accountingError, setAccountingError] = useState<string | null>(null);
  const [accountingPlanBlocked, setAccountingPlanBlocked] = useState(false);
  const [accountingProvider, setAccountingProvider] = useState("mock_quickbooks");
  const [accountingName, setAccountingName] = useState("");
  const [accountingCompany, setAccountingCompany] = useState("");
  const [accountingFormError, setAccountingFormError] = useState<string | null>(null);
  const [accountingBusy, setAccountingBusy] = useState(false);
  const [accountingNote, setAccountingNote] = useState<string | null>(null);

  const canKeys = canManageApiKeys(org.current_user_role);
  const canIntegrations = canManageIntegrations(org.current_user_role);

  useEffect(() => {
    let cancelled = false;
    listApiKeys()
      .then((response) => {
        if (!cancelled) setKeys(response);
      })
      .catch((err) => {
        if (!cancelled) {
          setKeysPlanBlocked(isPlanError(err));
          setKeysError(errorMessage(err, "Could not load API keys."));
        }
      })
      .finally(() => {
        if (!cancelled) setKeysLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id]);

  useEffect(() => {
    let cancelled = false;
    listWebhookEndpoints()
      .then((response) => {
        if (!cancelled) setEndpoints(response);
      })
      .catch((err) => {
        if (!cancelled) setEndpointsError(errorMessage(err, "Could not load webhook endpoints."));
      })
      .finally(() => {
        if (!cancelled) setEndpointsLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id]);

  useEffect(() => {
    let cancelled = false;
    Promise.all([listAccountingProviders(), listAccountingConnections()])
      .then(([providerList, connectionList]) => {
        if (!cancelled) {
          setProviders(providerList);
          setConnections(connectionList);
        }
      })
      .catch((err) => {
        if (!cancelled) {
          setAccountingPlanBlocked(isPlanError(err));
          setAccountingError(errorMessage(err, "Could not load accounting connections."));
        }
      })
      .finally(() => {
        if (!cancelled) setAccountingLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id]);

  function toggleScope(scope: string) {
    setKeyScopes((current) =>
      current.includes(scope) ? current.filter((item) => item !== scope) : [...current, scope],
    );
  }

  function toggleEvent(event: string) {
    setEndpointEvents((current) =>
      current.includes(event) ? current.filter((item) => item !== event) : [...current, event],
    );
  }

  async function createKey(event: FormEvent) {
    event.preventDefault();
    if (!keyName.trim()) {
      setKeyFormError("Give the key a name.");
      return;
    }
    if (keyScopes.length === 0) {
      setKeyFormError("Pick at least one scope.");
      return;
    }
    if (keyKind === "broker" && !keyBrokerName.trim()) {
      setKeyFormError("A broker key needs the broker name.");
      return;
    }
    setKeyFormError(null);
    setKeyBusy(true);
    try {
      const created = await createApiKey({
        name: keyName.trim(),
        kind: keyKind,
        scopes: keyScopes,
        broker_name: keyKind === "broker" ? keyBrokerName.trim() : undefined,
      });
      setKeys((current) => [created.key, ...current]);
      setRevealedKey(created.plain_text_key);
      setRevealedKeyName(created.key.name);
      setKeyName("");
      setKeyBrokerName("");
      setKeyScopes(["claims:read"]);
    } catch (err) {
      setKeyFormError(errorMessage(err, "Could not create the API key."));
    } finally {
      setKeyBusy(false);
    }
  }

  async function rotateKey(key: ApiKey) {
    setKeyFormError(null);
    setKeyBusy(true);
    try {
      const rotated = await rotateApiKey(key.id);
      setKeys((current) => current.map((item) => (item.id === key.id ? rotated.key : item)));
      setRevealedKey(rotated.plain_text_key);
      setRevealedKeyName(rotated.key.name);
    } catch (err) {
      setKeyFormError(errorMessage(err, "Could not rotate the API key."));
    } finally {
      setKeyBusy(false);
    }
  }

  async function revokeKey(key: ApiKey) {
    if (!window.confirm(`Revoke the key "${key.name}"? Revocation is immediate and permanent.`)) {
      return;
    }
    setKeyFormError(null);
    setKeyBusy(true);
    try {
      await revokeApiKey(key.id);
      setKeys((current) =>
        current.map((item) => (item.id === key.id ? { ...item, status: "revoked" as const } : item)),
      );
    } catch (err) {
      setKeyFormError(errorMessage(err, "Could not revoke the API key."));
    } finally {
      setKeyBusy(false);
    }
  }

  async function createEndpoint(event: FormEvent) {
    event.preventDefault();
    if (!endpointName.trim()) {
      setEndpointFormError("Give the endpoint a name.");
      return;
    }
    if (!endpointUrl.trim()) {
      setEndpointFormError("Give the endpoint a URL.");
      return;
    }
    if (endpointEvents.length === 0) {
      setEndpointFormError("Pick at least one event, or leave the list empty for every event.");
      return;
    }
    setEndpointFormError(null);
    setEndpointBusy(true);
    try {
      const created = await createWebhookEndpoint({
        name: endpointName.trim(),
        url: endpointUrl.trim(),
        events: endpointEvents,
      });
      setEndpoints((current) => [created.endpoint, ...current]);
      setRevealedSecret(created.plain_text_secret);
      setEndpointName("");
      setEndpointUrl("");
    } catch (err) {
      setEndpointFormError(errorMessage(err, "Could not create the webhook endpoint."));
    } finally {
      setEndpointBusy(false);
    }
  }

  async function toggleEndpoint(endpoint: WebhookEndpoint) {
    setEndpointFormError(null);
    try {
      const updated = await updateWebhookEndpoint(endpoint.id, {
        status: endpoint.status === "active" ? "disabled" : "active",
      });
      setEndpoints((current) => current.map((item) => (item.id === endpoint.id ? updated : item)));
    } catch (err) {
      setEndpointFormError(errorMessage(err, "Could not change the endpoint."));
    }
  }

  async function removeEndpoint(endpoint: WebhookEndpoint) {
    if (!window.confirm(`Delete the endpoint "${endpoint.name}" and its delivery history?`)) {
      return;
    }
    setEndpointFormError(null);
    try {
      await deleteWebhookEndpoint(endpoint.id);
      setEndpoints((current) => current.filter((item) => item.id !== endpoint.id));
      if (deliveriesFor === endpoint.id) {
        setDeliveries([]);
        setDeliveriesFor(null);
      }
    } catch (err) {
      setEndpointFormError(errorMessage(err, "Could not delete the endpoint."));
    }
  }

  async function testEndpoint(endpoint: WebhookEndpoint) {
    setEndpointFormError(null);
    try {
      const delivery = await testWebhookEndpoint(endpoint.id);
      setEndpointFormError(
        delivery.status === "delivered"
          ? `Test delivery ${delivery.status}: the endpoint answered ${delivery.http_status ?? "unknown"}.`
          : `Test delivery ${delivery.status}: check the endpoint and try again.`,
      );
      if (deliveriesFor === endpoint.id) {
        await loadDeliveries(endpoint.id);
      }
    } catch (err) {
      setEndpointFormError(errorMessage(err, "Could not send the test delivery."));
    }
  }

  async function loadDeliveries(endpointId: string) {
    setDeliveriesFor(endpointId);
    setDeliveriesLoading(true);
    setDeliveriesError(null);
    try {
      setDeliveries(await listWebhookDeliveries(endpointId));
    } catch (err) {
      setDeliveriesError(errorMessage(err, "Could not load deliveries."));
    } finally {
      setDeliveriesLoading(false);
    }
  }

  async function createConnection(event: FormEvent) {
    event.preventDefault();
    if (!accountingName.trim()) {
      setAccountingFormError("Give the connection a name.");
      return;
    }
    if (!accountingCompany.trim()) {
      setAccountingFormError("Give the company name the mock provider should model.");
      return;
    }
    setAccountingFormError(null);
    setAccountingNote(null);
    setAccountingBusy(true);
    try {
      const created = await createAccountingConnection({
        provider: accountingProvider,
        name: accountingName.trim(),
        company_name: accountingCompany.trim(),
      });
      setConnections((current) => [created, ...current]);
      setAccountingName("");
      setAccountingCompany("");
    } catch (err) {
      setAccountingFormError(errorMessage(err, "Could not create the accounting connection."));
    } finally {
      setAccountingBusy(false);
    }
  }

  async function testConnection(connection: AccountingConnection) {
    setAccountingNote(null);
    setAccountingFormError(null);
    try {
      const result = await testAccountingConnection(connection.id);
      setAccountingNote(result.message);
    } catch (err) {
      setAccountingFormError(errorMessage(err, "Could not test the accounting connection."));
    }
  }

  async function syncConnection(connection: AccountingConnection) {
    setAccountingNote(null);
    setAccountingFormError(null);
    try {
      const summary = await syncAccountingConnection(connection.id);
      setAccountingNote(
        `Mock sync: ${summary.exported} payment${summary.exported === 1 ? "" : "s"} exported, ${summary.skipped} skipped. No money moved.`,
      );
      setConnections((current) =>
        current.map((item) =>
          item.id === connection.id ? { ...item, last_sync_summary: summary } : item,
        ),
      );
    } catch (err) {
      setAccountingFormError(errorMessage(err, "Could not sync the accounting connection."));
    }
  }

  async function disconnectConnection(connection: AccountingConnection) {
    setAccountingNote(null);
    setAccountingFormError(null);
    try {
      const updated = await disconnectAccountingConnection(connection.id);
      setConnections((current) =>
        current.map((item) => (item.id === connection.id ? updated : item)),
      );
    } catch (err) {
      setAccountingFormError(errorMessage(err, "Could not disconnect the accounting connection."));
    }
  }

  return (
    <div>
      <div style={{ display: "flex", gap: 8, marginBottom: 16 }}>
        <Button variant={tab === "keys" ? "primary" : "secondary"} onClick={() => setTab("keys")}>
          API Keys
        </Button>
        <Button variant={tab === "webhooks" ? "primary" : "secondary"} onClick={() => setTab("webhooks")}>
          Webhooks
        </Button>
        <Button
          variant={tab === "accounting" ? "primary" : "secondary"}
          onClick={() => setTab("accounting")}
        >
          Accounting
        </Button>
      </div>

      {tab === "keys" && (
        <div>
          {keysPlanBlocked && (
            <Alert tone="info">
              The public API needs the Starter plan or better. Upgrade the plan to create API keys.
            </Alert>
          )}
          {revealedKey && (
            <Alert tone="info">
              <strong>Copy this key now: {revealedKeyName}.</strong> The full key is shown exactly
              once and never again. Store it somewhere safe; the app cannot show it to you later.
              <br />
              <code>{revealedKey}</code>
              <br />
              <Button variant="secondary" onClick={() => setRevealedKey(null)}>
                I have stored it
              </Button>
            </Alert>
          )}
          {keysError && !keysPlanBlocked && <Alert tone="error">{keysError}</Alert>}
          {keysLoading ? (
            <Spinner label="Loading API keys" />
          ) : keys.length === 0 ? (
            <EmptyState
              title="No API keys yet"
              description="Create a key to let another system read or create claims through the public API."
            />
          ) : (
            <Card>
              <CardHeader title="API keys" />
              <CardBody>
                <Table headers={["Name", "Kind", "Prefix", "Scopes", "Status", "Last used", "Actions"]}>
                  {keys.map((key) => (
                    <tr key={key.id} className="border-b border-slate-100 last:border-0">
                      <TableCell>{key.name}</TableCell>
                      <TableCell>
                        {key.kind === "broker"
                          ? `Broker${key.broker_name ? ` (${key.broker_name})` : ""}`
                          : "Standard"}
                      </TableCell>
                      <TableCell>
                        <code>{key.key_prefix}</code>
                      </TableCell>
                      <TableCell>{key.scopes.map((scope) => SCOPE_LABELS[scope] ?? scope).join(", ")}</TableCell>
                      <TableCell>
                        <Badge tone={key.status === "active" ? "success" : key.status === "rotating" ? "warning" : "danger"}>
                          {key.status}
                        </Badge>
                      </TableCell>
                      <TableCell>{key.last_used_at ? new Date(key.last_used_at).toLocaleString() : "Never"}</TableCell>
                      <TableCell>
                        <Button variant="secondary" disabled={!canKeys || keyBusy} onClick={() => rotateKey(key)}>
                          Rotate
                        </Button>{" "}
                        <Button
                          variant="danger"
                          disabled={!canKeys || keyBusy || key.status === "revoked"}
                          onClick={() => revokeKey(key)}
                        >
                          Revoke
                        </Button>
                      </TableCell>
                    </tr>
                  ))}
                </Table>
              </CardBody>
            </Card>
          )}
          {canKeys && !keysPlanBlocked && (
            <Card>
              <CardHeader title="Create an API key" />
              <CardBody>
                <form onSubmit={createKey}>
                  {keyFormError && <Alert tone="error">{keyFormError}</Alert>}
                  <Input id="api-key-name" label="Name" value={keyName} onChange={(event) => setKeyName(event.target.value)} placeholder="e.g. TMS sync key" />
                  <Select
                    id="api-key-kind"
                    label="Kind"
                    value={keyKind}
                    onChange={(event) => setKeyKind(event.target.value as "standard" | "broker")}
                  >
                    <option value="standard">Standard</option>
                    <option value="broker">Broker (read and status only)</option>
                  </Select>
                  {keyKind === "broker" && (
                    <Input id="api-key-broker-name" label="Broker name" value={keyBrokerName} onChange={(event) => setKeyBrokerName(event.target.value)} placeholder="e.g. Acme Brokerage LLC" />
                  )}
                  <div>
                    <strong>Scopes</strong>
                    {API_KEY_SCOPES.filter((scope) =>
                      keyKind === "broker" ? ["claims:read", "broker:status"].includes(scope.value) : scope.value !== "broker:status",
                    ).map((scope) => (
                      <label key={scope.value} style={{ display: "block" }}>
                        <input
                          type="checkbox"
                          checked={keyScopes.includes(scope.value)}
                          onChange={() => toggleScope(scope.value)}
                        />{" "}
                        {scope.label} <code>{scope.value}</code>
                      </label>
                    ))}
                  </div>
                  <Button type="submit" disabled={keyBusy}>
                    {keyBusy ? "Creating..." : "Create key"}
                  </Button>
                </form>
              </CardBody>
            </Card>
          )}
          <p>
            The public API lives at <code>/api/public/v1</code> and is documented in{" "}
            <code>docs/DEVELOPER-API.md</code>. Rotating a key keeps the old key working for 24
            hours, then it stops. Revoking a key is immediate and permanent.
          </p>
        </div>
      )}

      {tab === "webhooks" && (
        <div>
          {revealedSecret && (
            <Alert tone="info">
              <strong>Copy this signing secret now.</strong> It is shown exactly once and never
              again. Receivers verify the HMAC-SHA256 signature in the{" "}
              <code>X-ClaimTrail-Signature</code> header with it.
              <br />
              <code>{revealedSecret}</code>
              <br />
              <Button variant="secondary" onClick={() => setRevealedSecret(null)}>
                I have stored it
              </Button>
            </Alert>
          )}
          {endpointsError && <Alert tone="error">{endpointsError}</Alert>}
          {endpointFormError && <Alert tone="error">{endpointFormError}</Alert>}
          {endpointsLoading ? (
            <Spinner label="Loading webhook endpoints" />
          ) : endpoints.length === 0 ? (
            <EmptyState
              title="No webhook endpoints yet"
              description="Add an endpoint to receive claim, payment, document, and workflow events as they happen."
            />
          ) : (
            <Card>
              <CardHeader title="Webhook endpoints" />
              <CardBody>
                <Table headers={["Name", "Host", "Events", "Status", "Last delivered", "Last error", "Actions"]}>
                  {endpoints.map((endpoint) => (
                    <tr key={endpoint.id} className="border-b border-slate-100 last:border-0">
                      <TableCell>{endpoint.name}</TableCell>
                      <TableCell>{endpoint.url_host ?? "—"}</TableCell>
                      <TableCell>
                        {endpoint.events.length === 0
                          ? "All events"
                          : endpoint.events.map((event) => EVENT_LABELS[event] ?? event).join(", ")}
                      </TableCell>
                      <TableCell>
                        <Badge tone={endpoint.status === "active" ? "success" : "danger"}>
                          {endpoint.status}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        {endpoint.last_delivered_at ? new Date(endpoint.last_delivered_at).toLocaleString() : "Never"}
                      </TableCell>
                      <TableCell>{endpoint.last_error ?? "—"}</TableCell>
                      <TableCell>
                        <Button variant="secondary" disabled={!canIntegrations} onClick={() => testEndpoint(endpoint)}>
                          Test
                        </Button>{" "}
                        <Button variant="secondary" disabled={!canIntegrations} onClick={() => loadDeliveries(endpoint.id)}>
                          Deliveries
                        </Button>{" "}
                        <Button variant="secondary" disabled={!canIntegrations} onClick={() => toggleEndpoint(endpoint)}>
                          {endpoint.status === "active" ? "Disable" : "Enable"}
                        </Button>{" "}
                        <Button variant="danger" disabled={!canIntegrations} onClick={() => removeEndpoint(endpoint)}>
                          Delete
                        </Button>
                      </TableCell>
                    </tr>
                  ))}
                </Table>
              </CardBody>
            </Card>
          )}
          {deliveriesFor && (
            <Card>
              <CardHeader title="Deliveries" />
              <CardBody>
                {deliveriesLoading ? (
                  <Spinner label="Loading deliveries" />
                ) : deliveriesError ? (
                  <Alert tone="error">{deliveriesError}</Alert>
                ) : deliveries.length === 0 ? (
                  <EmptyState title="No deliveries yet" description="Deliveries for this endpoint will appear here." />
                ) : (
                  <Table headers={["Event", "Status", "Attempts", "HTTP", "Delivered at"]}>
                    {deliveries.map((delivery) => (
                      <tr key={delivery.id} className="border-b border-slate-100 last:border-0">
                        <TableCell>{EVENT_LABELS[delivery.event_type] ?? delivery.event_type}</TableCell>
                        <TableCell>
                          <Badge
                            tone={delivery.status === "delivered" ? "success" : delivery.status === "failed" ? "danger" : "warning"}
                          >
                            {delivery.status}
                          </Badge>
                        </TableCell>
                        <TableCell>{String(delivery.attempts)}</TableCell>
                        <TableCell>{delivery.http_status === null ? "—" : String(delivery.http_status)}</TableCell>
                        <TableCell>
                          {delivery.delivered_at ? new Date(delivery.delivered_at).toLocaleString() : "—"}
                        </TableCell>
                      </tr>
                    ))}
                  </Table>
                )}
              </CardBody>
            </Card>
          )}
          {canIntegrations && (
            <Card>
              <CardHeader title="Add a webhook endpoint" />
              <CardBody>
                <form onSubmit={createEndpoint}>
                  <Input id="webhook-name" label="Name" value={endpointName} onChange={(event) => setEndpointName(event.target.value)} placeholder="e.g. Ops alert hook" />
                  <Input id="webhook-url" label="URL" value={endpointUrl} onChange={(event) => setEndpointUrl(event.target.value)} placeholder="https://example.com/claimtrail-hooks" />
                  <div>
                    <strong>Events</strong>
                    {WEBHOOK_EVENTS.map((event) => (
                      <label key={event.value} style={{ display: "block" }}>
                        <input
                          type="checkbox"
                          checked={endpointEvents.includes(event.value)}
                          onChange={() => toggleEvent(event.value)}
                        />{" "}
                        {event.label} <code>{event.value}</code>
                      </label>
                    ))}
                  </div>
                  <Button type="submit" disabled={endpointBusy}>
                    {endpointBusy ? "Creating..." : "Create endpoint"}
                  </Button>
                </form>
              </CardBody>
            </Card>
          )}
          <p>
            Failed deliveries retry with backoff, at most 5 attempts, then are recorded failed.
            The signing secret is stored encrypted and never returned after creation.
          </p>
        </div>
      )}

      {tab === "accounting" && (
        <div>
          {accountingPlanBlocked && (
            <Alert tone="info">
              Accounting integrations need the Starter plan or better. Upgrade the plan to connect
              a provider.
            </Alert>
          )}
          {accountingNote && <Alert tone="info">{accountingNote}</Alert>}
          {accountingError && !accountingPlanBlocked && <Alert tone="error">{accountingError}</Alert>}
          {accountingFormError && <Alert tone="error">{accountingFormError}</Alert>}
          {accountingLoading ? (
            <Spinner label="Loading accounting connections" />
          ) : connections.length === 0 ? (
            <EmptyState
              title="No accounting connections yet"
              description="Connect a mock provider to model exporting recorded payments to a ledger."
            />
          ) : (
            <Card>
              <CardHeader title="Accounting connections" />
              <CardBody>
                <Table headers={["Name", "Provider", "Company", "Status", "Last synced", "Actions"]}>
                  {connections.map((connection) => (
                    <tr key={connection.id} className="border-b border-slate-100 last:border-0">
                      <TableCell>{connection.name}</TableCell>
                      <TableCell>{connection.provider_label}</TableCell>
                      <TableCell>{connection.company_name ?? "—"}</TableCell>
                      <TableCell>
                        <Badge tone={connection.status === "connected" ? "success" : "danger"}>
                          {connection.status}
                        </Badge>
                      </TableCell>
                      <TableCell>
                        {connection.last_synced_at ? new Date(connection.last_synced_at).toLocaleString() : "Never"}
                      </TableCell>
                      <TableCell>
                        <Button variant="secondary" disabled={!canIntegrations} onClick={() => testConnection(connection)}>
                          Test
                        </Button>{" "}
                        <Button
                          variant="secondary"
                          disabled={!canIntegrations || connection.status !== "connected"}
                          onClick={() => syncConnection(connection)}
                        >
                          Sync
                        </Button>{" "}
                        <Button
                          variant="danger"
                          disabled={!canIntegrations || connection.status !== "connected"}
                          onClick={() => disconnectConnection(connection)}
                        >
                          Disconnect
                        </Button>
                      </TableCell>
                    </tr>
                  ))}
                </Table>
              </CardBody>
            </Card>
          )}
          {canIntegrations && !accountingPlanBlocked && (
            <Card>
              <CardHeader title="Connect a provider" />
              <CardBody>
                <form onSubmit={createConnection}>
                  <Select
                    id="accounting-provider"
                    label="Provider"
                    value={accountingProvider}
                    onChange={(event) => setAccountingProvider(event.target.value)}
                  >
                    {providers.map((provider) => (
                      <option key={provider.value} value={provider.value}>
                        {provider.label}
                      </option>
                    ))}
                  </Select>
                  <Input id="accounting-name" label="Name" value={accountingName} onChange={(event) => setAccountingName(event.target.value)} placeholder="e.g. QuickBooks mock" />
                  <Input id="accounting-company" label="Company name" value={accountingCompany} onChange={(event) => setAccountingCompany(event.target.value)} placeholder="e.g. Acme Carriers LLC" />
                  <Button type="submit" disabled={accountingBusy}>
                    {accountingBusy ? "Connecting..." : "Connect"}
                  </Button>
                </form>
              </CardBody>
            </Card>
          )}
          <p>
            Only mock providers exist: QuickBooks and Xero modelled schema-only. No live
            accounting system is contacted, no credentials are stored, and a sync only exports
            recorded payments to dummy ids. A real provider is a later swap.
          </p>
        </div>
      )}
    </div>
  );
}

export default function DevelopersPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Developers"
        description={
          currentOrg
            ? `Public API keys, webhooks, and accounting integrations for ${currentOrg.name}. The public API needs the Starter plan or better.`
            : "No organization selected."
        }
        crumbs={[{ label: "Dashboard", href: "/dashboard" }, { label: "Developers" }]}
      />
      {currentOrg ? (
        <DevelopersContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to use the developer tools.</Alert>
      )}
    </div>
  );
}
