"use client";

import Link from "next/link";
import { useEffect, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { Input, Select } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { TIMEZONES } from "@/lib/timezones";
import type { DashboardData, Organization } from "@/lib/types";

const UPCOMING_MODULES = [
  {
    title: "Shipments",
    description: "Loads, appointments, rates, and status, with CSV import and export.",
  },
  {
    title: "Events",
    description: "Detention, layover, TONU, lumper, and other charge events per shipment.",
  },
  {
    title: "Evidence",
    description: "Rate confirmations, bills of lading, receipts, and photos, stored privately.",
  },
  {
    title: "Claims",
    description: "Claim workflow from potential opportunity through submission and payment.",
  },
  {
    title: "Deadlines",
    description: "Submission deadlines and reminders, configured per claim.",
  },
  {
    title: "Reports",
    description: "Recovery by status, event type, and period, with CSV export.",
  },
];

function CreateOrganizationCard() {
  const { createOrganization } = useAuth();
  const [name, setName] = useState("");
  const [timezone, setTimezone] = useState("UTC");
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (!name.trim()) {
      setError("Organization name is required.");
      return;
    }
    setError(null);
    setLoading(true);
    try {
      await createOrganization(name.trim(), timezone);
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
    <Card>
      <CardHeader
        title="Create your organization"
        description="An organization is your company's workspace. You will be its owner."
      />
      <CardBody>
        <form onSubmit={handleSubmit} className="max-w-md space-y-4" noValidate>
          {error && <Alert tone="error">{error}</Alert>}
          <Input
            id="org-name"
            label="Organization name"
            type="text"
            value={name}
            onChange={(event) => setName(event.target.value)}
            placeholder="Acme Trucking Co."
          />
          <Select
            id="org-timezone"
            label="Timezone"
            value={timezone}
            onChange={(event) => setTimezone(event.target.value)}
          >
            {TIMEZONES.map((tz) => (
              <option key={tz.value} value={tz.value}>
                {tz.label}
              </option>
            ))}
          </Select>
          <Button type="submit" loading={loading}>
            Create organization
          </Button>
        </form>
      </CardBody>
    </Card>
  );
}

/**
 * Loads and renders the overview for one organization. The parent keys
 * it by organization id, so switching organizations remounts it with
 * fresh state and the effect only fetches on mount.
 */
function DashboardContent({ org }: { org: Organization }) {
  const [data, setData] = useState<DashboardData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    apiFetch<{ data: DashboardData }>("/dashboard", { orgId: org.id })
      .then((response) => {
        if (!cancelled) setData(response.data);
      })
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load the dashboard.",
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

  if (loading) {
    return <Spinner label="Loading dashboard" />;
  }

  if (error) {
    return <Alert tone="error">{error}</Alert>;
  }

  if (!data) {
    return (
      <Alert tone="info">No data to show for this organization yet.</Alert>
    );
  }

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Card>
          <CardBody>
            <p className="text-sm text-slate-500">Members</p>
            <p className="mt-1 text-3xl font-semibold text-slate-900">
              {data.counts.members_total}
            </p>
            <p className="mt-1 text-xs text-slate-500">
              {data.counts.members_active} active,{" "}
              {data.counts.members_inactive} inactive
            </p>
          </CardBody>
        </Card>
        <Card>
          <CardBody>
            <p className="text-sm text-slate-500">Pending invitations</p>
            <p className="mt-1 text-3xl font-semibold text-slate-900">
              {data.counts.invitations_pending}
            </p>
            <p className="mt-1 text-xs text-slate-500">
              <Link
                href="/settings/members"
                className="text-brand-700 hover:underline"
              >
                Manage members and invitations
              </Link>
            </p>
          </CardBody>
        </Card>
        <Card>
          <CardBody>
            <p className="text-sm text-slate-500">Your role</p>
            <p className="mt-1 text-3xl font-semibold capitalize text-slate-900">
              {data.current_user_role?.replace("_", " ") ?? "Unknown"}
            </p>
            <p className="mt-1 text-xs text-slate-500">
              in {data.organization.name}
            </p>
          </CardBody>
        </Card>
        <Card>
          <CardBody>
            <p className="text-sm text-slate-500">Organization timezone</p>
            <p className="mt-1 text-lg font-semibold text-slate-900">
              {data.organization.timezone}
            </p>
            <p className="mt-1 text-xs text-slate-500">
              Member since{" "}
              {data.member_since
                ? new Date(data.member_since).toLocaleDateString()
                : "Unknown"}
            </p>
          </CardBody>
        </Card>
      </div>

      <div>
        <h2 className="text-lg font-semibold text-slate-900">What comes next</h2>
        <p className="mt-1 text-sm text-slate-500">
          Milestone 1 covers accounts, organizations, and members. The modules
          below arrive in later milestones.
        </p>
        <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {UPCOMING_MODULES.map((module) => (
            <Card key={module.title}>
              <CardBody>
                <div className="flex items-center justify-between gap-2">
                  <h3 className="text-base font-semibold text-slate-900">
                    {module.title}
                  </h3>
                  <Badge tone="neutral">Later milestone</Badge>
                </div>
                <p className="mt-2 text-sm leading-relaxed text-slate-600">
                  {module.description}
                </p>
              </CardBody>
            </Card>
          ))}
        </div>
      </div>
    </div>
  );
}

export default function DashboardPage() {
  const { user, currentOrg, organizations } = useAuth();
  const [resendMessage, setResendMessage] = useState<string | null>(null);
  const [resending, setResending] = useState(false);

  async function resendVerification() {
    setResendMessage(null);
    setResending(true);
    try {
      const response = await apiFetch<{ message: string }>(
        "/email/verification-notification",
        { method: "POST", withOrg: false },
      );
      setResendMessage(response.message);
    } catch (err) {
      setResendMessage(
        err instanceof ApiRequestError
          ? err.message
          : "Something went wrong. Please try again.",
      );
    } finally {
      setResending(false);
    }
  }

  return (
    <div>
      <PageHeader
        title="Dashboard"
        description={
          currentOrg
            ? `Overview for ${currentOrg.name}.`
            : "Get started by creating your organization."
        }
      />

      {user && !user.email_verified_at && (
        <div className="mb-6">
          <Alert tone="warning">
            Your email address is not verified yet.{" "}
            <button
              type="button"
              onClick={resendVerification}
              disabled={resending}
              className="font-medium underline hover:text-amber-900 disabled:no-underline disabled:opacity-60"
            >
              {resending ? "Sending..." : "Send a new verification link"}
            </button>
            {resendMessage && <span className="ml-2">{resendMessage}</span>}
          </Alert>
        </div>
      )}

      {organizations.length === 0 ? (
        <CreateOrganizationCard />
      ) : currentOrg ? (
        <DashboardContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">Select an organization to see its overview.</Alert>
      )}
    </div>
  );
}
