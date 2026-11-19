"use client";

import { useState, type FormEvent } from "react";
import Link from "next/link";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Input, Select } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { canManageOrganization } from "@/lib/permissions";
import { TIMEZONES } from "@/lib/timezones";
import { DATE_FORMATS, type Organization } from "@/lib/types";

function settingsString(
  settings: Record<string, unknown> | null,
  key: string,
): string {
  const value = settings?.[key];
  return typeof value === "string" ? value : "";
}

/**
 * The form initializes from the organization it is given. The parent keys
 * it by organization id, so switching organizations remounts it with
 * fresh state instead of syncing props in an effect.
 */
function OrganizationForm({ org }: { org: Organization }) {
  const { refreshUser } = useAuth();
  const [name, setName] = useState(org.name);
  const [timezone, setTimezone] = useState(org.timezone);
  const [defaultCurrency, setDefaultCurrency] = useState(
    settingsString(org.settings, "default_currency") || "USD",
  );
  const [dateFormat, setDateFormat] = useState(
    settingsString(org.settings, "date_format") || "Y-m-d",
  );
  const [claimReferencePrefix, setClaimReferencePrefix] = useState(
    settingsString(org.settings, "claim_reference_prefix"),
  );
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
    const currency = defaultCurrency.trim().toUpperCase();
    if (!/^[A-Z]{3}$/.test(currency)) {
      setError("Default currency must be a 3-letter code, for example USD.");
      return;
    }
    const prefix = claimReferencePrefix.trim().toUpperCase();
    if (prefix && !/^[A-Z0-9-]{1,10}$/.test(prefix)) {
      setError(
        "Claim reference prefix must be up to 10 letters, numbers, or dashes.",
      );
      return;
    }
    setError(null);
    setMessage(null);
    setLoading(true);
    try {
      await apiFetch<{ data: Organization }>(`/organizations/${org.id}`, {
        method: "PUT",
        body: {
          name: name.trim(),
          timezone,
          settings: {
            ...(org.settings ?? {}),
            default_currency: currency,
            date_format: dateFormat,
            claim_reference_prefix: prefix ? prefix : null,
          },
        },
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
            <Input
              id="org-default-currency"
              label="Default currency"
              type="text"
              value={defaultCurrency}
              onChange={(event) => setDefaultCurrency(event.target.value)}
              disabled={!canEdit}
              help="3-letter code, for example USD."
            />
            <Select
              id="org-date-format"
              label="Date format"
              value={dateFormat}
              onChange={(event) => setDateFormat(event.target.value)}
              disabled={!canEdit}
            >
              {DATE_FORMATS.map((format) => (
                <option key={format.value} value={format.value}>
                  {format.label}
                </option>
              ))}
            </Select>
            <Input
              id="org-claim-reference-prefix"
              label="Claim reference prefix"
              type="text"
              value={claimReferencePrefix}
              onChange={(event) => setClaimReferencePrefix(event.target.value)}
              disabled={!canEdit}
              placeholder="For example: CLM"
              help="Up to 10 letters, numbers, or dashes. Leave blank to use the default prefix."
            />
            {canEdit && (
              <Button type="submit" loading={loading}>
                Save settings
              </Button>
            )}
          </form>
        </CardBody>
      </Card>

      <Card>
        <CardHeader
          title="Evidence requirements"
          description="Which documents a claim needs before it is ready to submit."
        />
        <CardBody>
          <p className="text-sm text-slate-600">
            Requirements are scoped per event type, or apply to every claim.
            Readiness on each claim is counted against them.
          </p>
          <div className="mt-3">
            <Link
              href="/settings/evidence-requirements"
              className="text-sm text-brand-700 hover:underline"
            >
              Manage evidence requirements
            </Link>
          </div>
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
