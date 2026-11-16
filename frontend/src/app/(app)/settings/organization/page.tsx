"use client";

import { useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Input, Select } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { canManageOrganization } from "@/lib/permissions";
import { TIMEZONES } from "@/lib/timezones";
import type { Organization } from "@/lib/types";

/**
 * The form initializes from the organization it is given. The parent keys
 * it by organization id, so switching organizations remounts it with
 * fresh state instead of syncing props in an effect.
 */
function OrganizationForm({ org }: { org: Organization }) {
  const { refreshUser } = useAuth();
  const [name, setName] = useState(org.name);
  const [timezone, setTimezone] = useState(org.timezone);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const canEdit = canManageOrganization(org.current_user_role);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (!name.trim()) {
      setError("Organization name is required.");
      return;
    }
    setError(null);
    setMessage(null);
    setLoading(true);
    try {
      await apiFetch<{ data: Organization }>(`/organizations/${org.id}`, {
        method: "PUT",
        body: { name: name.trim(), timezone },
        withOrg: false,
      });
      setMessage("Organization settings saved.");
      await refreshUser();
    } catch (err) {
      setError(
        err instanceof ApiRequestError
          ? err.message
          : "Something went wrong. Please try again.",
      );
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader
          title="Profile"
          description="Basic information about this organization."
        />
        <CardBody>
          <form onSubmit={handleSubmit} className="max-w-md space-y-4" noValidate>
            {message && <Alert tone="success">{message}</Alert>}
            {error && <Alert tone="error">{error}</Alert>}
            {!canEdit && (
              <Alert tone="info">
                Only owners and admins can change organization settings. Your
                role is {org.current_user_role?.replace("_", " ") ?? "unknown"}.
              </Alert>
            )}
            <Input
              id="org-name"
              label="Organization name"
              type="text"
              value={name}
              onChange={(event) => setName(event.target.value)}
              disabled={!canEdit}
            />
            <Select
              id="org-timezone"
              label="Timezone"
              value={timezone}
              onChange={(event) => setTimezone(event.target.value)}
              disabled={!canEdit}
            >
              {TIMEZONES.map((tz) => (
                <option key={tz.value} value={tz.value}>
                  {tz.label}
                </option>
              ))}
            </Select>
            {canEdit && (
              <Button type="submit" loading={loading}>
                Save settings
              </Button>
            )}
          </form>
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Details" description="Read-only identifiers." />
        <CardBody>
          <dl className="grid gap-4 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-slate-500">Organization ID</dt>
              <dd className="mt-1 font-mono text-xs break-all text-slate-900">
                {org.id}
              </dd>
            </div>
            <div>
              <dt className="text-slate-500">Slug</dt>
              <dd className="mt-1 font-mono text-xs text-slate-900">{org.slug}</dd>
            </div>
            <div>
              <dt className="text-slate-500">Created</dt>
              <dd className="mt-1 text-slate-900">
                {new Date(org.created_at).toLocaleString()}
              </dd>
            </div>
            <div>
              <dt className="text-slate-500">Your role</dt>
              <dd className="mt-1 capitalize text-slate-900">
                {org.current_user_role?.replace("_", " ") ?? "Unknown"}
              </dd>
            </div>
          </dl>
        </CardBody>
      </Card>
    </div>
  );
}

export default function OrganizationSettingsPage() {
  const { currentOrg } = useAuth();

  if (!currentOrg) {
    return (
      <div>
        <PageHeader
          title="Organization settings"
          description="No organization selected."
        />
        <Alert tone="info">
          Create an organization first to manage its settings.
        </Alert>
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title="Organization settings"
        description={`Settings for ${currentOrg.name}.`}
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Organization settings" },
        ]}
      />
      <OrganizationForm key={currentOrg.id} org={currentOrg} />
    </div>
  );
}
