"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Input, Textarea } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import {
  createImportMapping,
  deleteImportMapping,
  listImportMappings,
  updateImportMapping,
} from "@/lib/imports";
import { canManageImports } from "@/lib/permissions";
import type { ImportMapping, Organization } from "@/lib/types";

/**
 * Saved CSV mappings for one organization. A mapping is exact
 * header text to shipment field name, for headers the built-in
 * aliases do not recognize. Any member may list them; creating,
 * updating, and deleting need manage-imports. One mapping per
 * target may be the default, used when an import names none.
 */
function MappingsContent({ org }: { org: Organization }) {
  const [mappings, setMappings] = useState<ImportMapping[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [name, setName] = useState("");
  const [mappingJson, setMappingJson] = useState(
    JSON.stringify({ "Load ID": "internal_reference", Customer: "broker_or_customer_name" }, null, 2),
  );
  const [isDefault, setIsDefault] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  const [busyId, setBusyId] = useState<string | null>(null);

  const myRole = org.current_user_role;
  const isManager = canManageImports(myRole);

  useEffect(() => {
    let cancelled = false;
    listImportMappings(org.id)
      .then((response) => {
        if (!cancelled) setMappings(response);
      })
      .catch((err) => {
        if (!cancelled) {
          setError(err instanceof ApiRequestError ? err.message : "Could not load mappings.");
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id]);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setFormError(null);
    setFieldErrors({});

    let parsed: Record<string, string>;
    try {
      parsed = JSON.parse(mappingJson) as Record<string, string>;
    } catch {
      setFormError("The mapping is not valid JSON.");
      return;
    }

    setBusy(true);
    try {
      const created = await createImportMapping(org.id, {
        name: name.trim(),
        mapping: parsed,
        is_default: isDefault,
      });
      setMappings((current) =>
        (isDefault
          ? current.map((mapping) => ({ ...mapping, is_default: false }))
          : current
        ).concat(created),
      );
      setName("");
      setIsDefault(false);
    } catch (err) {
      if (err instanceof ApiRequestError) {
        setFormError(err.message);
        setFieldErrors(err.fields);
      } else {
        setFormError("Could not save the mapping.");
      }
    } finally {
      setBusy(false);
    }
  }

  async function makeDefault(mappingId: string) {
    setBusyId(mappingId);
    try {
      const updated = await updateImportMapping(org.id, mappingId, { is_default: true });
      setMappings((current) =>
        current.map((mapping) =>
          mapping.id === mappingId ? updated : { ...mapping, is_default: false },
        ),
      );
    } catch (err) {
      setFormError(err instanceof ApiRequestError ? err.message : "Could not set the default.");
    } finally {
      setBusyId(null);
    }
  }

  async function remove(mappingId: string) {
    setBusyId(mappingId);
    try {
      await deleteImportMapping(org.id, mappingId);
      setMappings((current) => current.filter((mapping) => mapping.id !== mappingId));
    } catch (err) {
      setFormError(err instanceof ApiRequestError ? err.message : "Could not delete the mapping.");
    } finally {
      setBusyId(null);
    }
  }

  return (
    <div className="space-y-6">
      {isManager ? (
        <Card>
          <CardHeader
            title="Save a mapping"
            description="Name the mapping and paste the header-to-field map as JSON. Headers the built-in aliases already know do not need to be listed."
          />
          <CardBody>
            <form onSubmit={submit} className="space-y-4">
              {formError ? <Alert tone="error">{formError}</Alert> : null}
              <Input
                label="Name"
                id="mapping-name"
                value={name}
                onChange={(event) => setName(event.target.value)}
                error={fieldErrors.name?.[0]}
                placeholder="Carrier export"
                required
              />
              <Textarea
                label="Mapping (JSON)"
                id="mapping-json"
                value={mappingJson}
                onChange={(event) => setMappingJson(event.target.value)}
                error={fieldErrors.mapping?.[0]}
                rows={6}
              />
              <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  checked={isDefault}
                  onChange={(event) => setIsDefault(event.target.checked)}
                />
                Default mapping for shipments
              </label>
              <Button type="submit" disabled={busy}>
                Save mapping
              </Button>
            </form>
          </CardBody>
        </Card>
      ) : null}

      <Card>
        <CardHeader title="Saved mappings" description="Reusable across uploads, schedules, and sync runs." />
        <CardBody>
          {loading ? (
            <Spinner label="Loading mappings" />
          ) : error ? (
            <Alert tone="error">{error}</Alert>
          ) : mappings.length === 0 ? (
            <EmptyState
              title="No saved mappings"
              description="Save one above to reuse a column layout instead of retyping it on every import."
            />
          ) : (
            <ul className="divide-y divide-slate-200">
              {mappings.map((mapping) => (
                <li key={mapping.id} className="flex flex-wrap items-center gap-3 py-3">
                  <div className="min-w-0 flex-1">
                    <p className="font-medium text-slate-900">{mapping.name}</p>
                    <p className="text-sm text-slate-500">
                      {Object.keys(mapping.mapping).length} headers · {mapping.target}
                    </p>
                    <pre className="mt-1 overflow-x-auto rounded bg-slate-50 p-2 text-xs text-slate-600">
                      {JSON.stringify(mapping.mapping)}
                    </pre>
                  </div>
                  {mapping.is_default ? <Badge tone="success">Default</Badge> : null}
                  {isManager && !mapping.is_default ? (
                    <Button
                      size="sm"
                      variant="secondary"
                      disabled={busyId === mapping.id}
                      onClick={() => makeDefault(mapping.id)}
                    >
                      Make default
                    </Button>
                  ) : null}
                  {isManager ? (
                    <Button
                      size="sm"
                      variant="secondary"
                      disabled={busyId === mapping.id}
                      onClick={() => remove(mapping.id)}
                    >
                      Delete
                    </Button>
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

export default function ImportMappingsPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Saved CSV mappings"
        description={
          currentOrg
            ? `Column mappings for ${currentOrg.name}. A saved mapping renames headers the built-in aliases do not recognize.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Imports", href: "/imports" },
          { label: "Mappings" },
        ]}
      />
      {currentOrg ? (
        <MappingsContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Create an organization first to use mappings.</Alert>
      )}
    </div>
  );
}
