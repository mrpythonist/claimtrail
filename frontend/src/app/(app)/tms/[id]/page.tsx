"use client";

import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Select } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { canManageTmsIntegrations } from "@/lib/permissions";
import {
  TMS_SYNC_KINDS,
  deleteTmsIntegration,
  getTmsIntegration,
  listTmsSyncRuns,
  runTmsSync,
  testTmsConnection,
  tmsSyncKindLabel,
  tmsSyncRunStatusLabel,
  updateTmsIntegration,
} from "@/lib/tms";
import type {
  Organization,
  TmsConnectionTest,
  TmsIntegration,
  TmsSyncKind,
  TmsSyncRun,
} from "@/lib/types";

function runStatusTone(status: TmsSyncRun["status"]): "neutral" | "info" | "warning" | "success" {
  switch (status) {
    case "completed":
      return "success";
    case "failed":
      return "warning";
    case "pending":
    case "running":
      return "info";
  }
}

/**
 * One TMS integration: its settings, the connection test,
 * the sync controls, and the sync run history. Settings
 * choose which kinds a full sync runs; a single kind can
 * still be run on demand. A failed run records the reason
 * on the integration and in the run history: for Trimble
 * that is the live-unavailable message, because no live
 * TMW system is reachable from here.
 */
function TmsDetailContent({ org, integrationId }: { org: Organization; integrationId: string }) {
  const router = useRouter();
  const [integration, setIntegration] = useState<TmsIntegration | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [runs, setRuns] = useState<TmsSyncRun[]>([]);
  const [runsLoading, setRunsLoading] = useState(true);
  const [actionError, setActionError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [testResult, setTestResult] = useState<TmsConnectionTest | null>(null);
  const [syncKind, setSyncKind] = useState<TmsSyncKind>("full");
  const [lastRun, setLastRun] = useState<TmsSyncRun | null>(null);

  const [autoSync, setAutoSync] = useState(false);
  const [syncShipments, setSyncShipments] = useState(true);
  const [syncStatus, setSyncStatus] = useState(true);
  const [syncAppointments, setSyncAppointments] = useState(true);
  const [syncDocuments, setSyncDocuments] = useState(true);

  const isManager = canManageTmsIntegrations(org.current_user_role);

  useEffect(() => {
    let cancelled = false;
    getTmsIntegration(org.id, integrationId)
      .then((response) => {
        if (cancelled) return;
        setIntegration(response);
        setAutoSync(response.settings.auto_sync);
        setSyncShipments(response.settings.sync_shipments);
        setSyncStatus(response.settings.sync_status);
        setSyncAppointments(response.settings.sync_appointments);
        setSyncDocuments(response.settings.sync_documents);
      })
      .catch((err) => {
        if (!cancelled) {
          setError(err instanceof ApiRequestError ? err.message : "Could not load the integration.");
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, integrationId]);

  useEffect(() => {
    let cancelled = false;
    listTmsSyncRuns(org.id, { tms_integration_id: integrationId })
      .then((response) => {
        if (!cancelled) setRuns(response);
      })
      .catch(() => {
        // The history is secondary; the integration above is the page.
      })
      .finally(() => {
        if (!cancelled) setRunsLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, integrationId]);

  async function test() {
    setActionError(null);
    setTestResult(null);
    setBusy(true);
    try {
      setTestResult(await testTmsConnection(org.id, integrationId));
    } catch (err) {
      setActionError(err instanceof ApiRequestError ? err.message : "Could not test the connection.");
    } finally {
      setBusy(false);
    }
  }

  async function sync() {
    setActionError(null);
    setLastRun(null);
    setBusy(true);
    try {
      const run = await runTmsSync(org.id, integrationId, syncKind);
      setLastRun(run);
      setRuns((current) => [run, ...current]);
      const refreshed = await getTmsIntegration(org.id, integrationId);
      setIntegration(refreshed);
    } catch (err) {
      setActionError(err instanceof ApiRequestError ? err.message : "Could not run the sync.");
    } finally {
      setBusy(false);
    }
  }

  async function saveSettings() {
    if (!integration) return;
    setActionError(null);
    setBusy(true);
    try {
      const updated = await updateTmsIntegration(org.id, integration.id, {
        settings: {
          auto_sync: autoSync,
          sync_shipments: syncShipments,
          sync_status: syncStatus,
          sync_appointments: syncAppointments,
          sync_documents: syncDocuments,
        },
      });
      setIntegration(updated);
    } catch (err) {
      setActionError(err instanceof ApiRequestError ? err.message : "Could not save the settings.");
    } finally {
      setBusy(false);
    }
  }

  async function toggleStatus() {
    if (!integration) return;
    setActionError(null);
    setBusy(true);
    try {
      const updated = await updateTmsIntegration(org.id, integration.id, {
        status: integration.status === "active" ? "disabled" : "active",
      });
      setIntegration(updated);
    } catch (err) {
      setActionError(err instanceof ApiRequestError ? err.message : "Could not change the status.");
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    if (!integration) return;
    setActionError(null);
    setBusy(true);
    try {
      await deleteTmsIntegration(org.id, integration.id);
      router.push("/tms");
    } catch (err) {
      setActionError(err instanceof ApiRequestError ? err.message : "Could not delete the integration.");
      setBusy(false);
    }
  }

  if (loading) return <Spinner />;
  if (error) return <Alert tone="error">{error}</Alert>;
  if (!integration) return <EmptyState title="Integration not found" />;

  return (
    <div className="space-y-6">
      {actionError ? <Alert tone="error">{actionError}</Alert> : null}

      <Card>
        <CardHeader title={integration.name} />
        <CardBody>
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={integration.status === "active" ? "success" : "neutral"}>{integration.status}</Badge>
            <Badge tone={integration.live_available ? "success" : "neutral"}>
              {integration.live_available ? "Live" : "Not live"}
            </Badge>
            <span className="text-sm text-slate-500">{integration.provider_label}</span>
          </div>
          <p className="mt-2 text-sm text-slate-500">
            {integration.last_sync_at ? `Last sync ${integration.last_sync_at}.` : "Never synced."}
            {integration.has_credentials ? " Credentials stored." : " No credentials stored."}
          </p>
          {integration.last_error ? (
            <Alert tone="error">{integration.last_error}</Alert>
          ) : null}
          {isManager ? (
            <div className="mt-4 flex flex-wrap gap-2">
              <Button size="sm" variant="secondary" onClick={test} disabled={busy}>
                Test connection
              </Button>
              <Button size="sm" variant="secondary" onClick={toggleStatus} disabled={busy}>
                {integration.status === "active" ? "Disable" : "Enable"}
              </Button>
              <Button size="sm" variant="secondary" onClick={remove} disabled={busy}>
                Delete
              </Button>
            </div>
          ) : null}
          {testResult ? (
            <Alert tone={testResult.ok ? "info" : "error"}>{testResult.message}</Alert>
          ) : null}
        </CardBody>
      </Card>

      {isManager ? (
        <Card>
          <CardHeader title="Sync settings" />
          <CardBody>
            <div className="space-y-3">
              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={syncShipments} onChange={(event) => setSyncShipments(event.target.checked)} />
                Sync shipments
              </label>
              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={syncStatus} onChange={(event) => setSyncStatus(event.target.checked)} />
                Sync statuses
              </label>
              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={syncAppointments} onChange={(event) => setSyncAppointments(event.target.checked)} />
                Sync appointments
              </label>
              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={syncDocuments} onChange={(event) => setSyncDocuments(event.target.checked)} />
                Sync documents as evidence
              </label>
              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={autoSync} onChange={(event) => setAutoSync(event.target.checked)} />
                Auto-sync when due (daily)
              </label>
              <Button size="sm" onClick={saveSettings} disabled={busy}>
                Save settings
              </Button>
            </div>
          </CardBody>
        </Card>
      ) : null}

      {isManager ? (
        <Card>
          <CardHeader title="Run a sync" />
          <CardBody>
            <div className="flex flex-wrap items-end gap-3">
              <Select
                label="Kind"
                id="tms-sync-kind"
                value={syncKind}
                onChange={(event) => setSyncKind(event.target.value as TmsSyncKind)}
              >
                {TMS_SYNC_KINDS.map((kind) => (
                  <option key={kind.value} value={kind.value}>
                    {kind.label}
                  </option>
                ))}
              </Select>
              <Button onClick={sync} disabled={busy}>
                {busy ? "Syncing…" : "Sync now"}
              </Button>
            </div>
            {lastRun ? (
              <div className="mt-4">
                <Badge tone={runStatusTone(lastRun.status)}>{tmsSyncRunStatusLabel(lastRun.status)}</Badge>
                <p className="mt-2 text-sm text-slate-500">
                  {lastRun.summary
                    ? `${lastRun.summary.fetched} loads fetched, ${lastRun.summary.created} created, ${lastRun.summary.updated} updated, ${lastRun.summary.skipped} skipped, ${lastRun.summary.documents_created} documents stored.`
                    : lastRun.error_message ?? ""}
                </p>
              </div>
            ) : null}
          </CardBody>
        </Card>
      ) : null}

      <Card>
        <CardHeader title="Sync runs" />
        <CardBody>
          {runsLoading ? (
            <Spinner />
          ) : runs.length === 0 ? (
            <EmptyState title="No sync runs yet" description="Run a sync to see its history here." />
          ) : (
            <ul className="space-y-3">
              {runs.map((run) => (
                <li key={run.id} className="border-b border-slate-200 pb-3 last:border-0 last:pb-0">
                  <div className="flex items-center gap-2">
                    <span className="font-medium">{tmsSyncKindLabel(run.kind)}</span>
                    <Badge tone={runStatusTone(run.status)}>{tmsSyncRunStatusLabel(run.status)}</Badge>
                  </div>
                  <p className="mt-1 text-sm text-slate-500">
                    {run.finished_at ?? run.created_at}
                    {run.summary
                      ? ` · ${run.summary.fetched} fetched, ${run.summary.created} created, ${run.summary.updated} updated, ${run.summary.skipped} skipped`
                      : ""}
                  </p>
                  {run.error_message ? (
                    <p className="mt-1 text-sm text-amber-700">{run.error_message}</p>
                  ) : null}
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>

      <p className="text-sm text-slate-500">
        <Link href="/tms" className="text-sky-700 hover:underline">
          Back to TMS integrations
        </Link>
      </p>
    </div>
  );
}

export default function TmsDetailPage() {
  const { currentOrg } = useAuth();
  const params = useParams();
  const integrationId = typeof params.id === "string" ? params.id : "";

  return (
    <div>
      <PageHeader
        title="TMS Integration"
        description="One TMS connection: settings, connection test, sync controls, and run history."
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "TMS Integrations", href: "/tms" },
          { label: "Integration" },
        ]}
      />
      {currentOrg && integrationId ? (
        <TmsDetailContent key={`${currentOrg.id}-${integrationId}`} org={currentOrg} integrationId={integrationId} />
      ) : (
        <Alert tone="info">Create an organization first to use TMS integrations.</Alert>
      )}
    </div>
  );
}
