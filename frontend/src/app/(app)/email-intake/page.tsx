"use client";

import Link from "next/link";
import { useEffect, useState, type FormEvent } from "react";
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
  INBOUND_EMAIL_STATUSES,
  createInboundAddress,
  deactivateInboundAddress,
  inboundEmailStatusLabel,
  listInboundAddresses,
  listInboundEmails,
  regenerateInboundAddress,
} from "@/lib/emailIntake";
import { canManageEmailIntake } from "@/lib/permissions";
import type {
  InboundEmail,
  InboundEmailAddress,
  InboundEmailStatus,
  Organization,
  PaginatedMeta,
} from "@/lib/types";

function statusTone(status: InboundEmailStatus): "neutral" | "info" | "warning" | "success" {
  switch (status) {
    case "received":
    case "processed":
      return "info";
    case "matched":
      return "success";
    case "unmatched":
    case "failed":
      return "warning";
    case "dismissed":
      return "neutral";
  }
}

/**
 * Email intake for one organization. The parent keys it by
 * organization id, so switching organizations remounts it with
 * fresh state. The addresses card shows where to forward mail;
 * the list below is the processing history, newest first. Any
 * member may read both; managing addresses and matching emails
 * needs manage-email-intake.
 */
function EmailIntakeContent({ org }: { org: Organization }) {
  const [addresses, setAddresses] = useState<InboundEmailAddress[]>([]);
  const [addressesLoading, setAddressesLoading] = useState(true);
  const [addressesError, setAddressesError] = useState<string | null>(null);
  const [label, setLabel] = useState("");
  const [addressActionError, setAddressActionError] = useState<string | null>(null);
  const [addressBusy, setAddressBusy] = useState(false);

  const [emails, setEmails] = useState<InboundEmail[]>([]);
  const [meta, setMeta] = useState<PaginatedMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState<InboundEmailStatus | "all">("all");
  const [page, setPage] = useState(1);

  const myRole = org.current_user_role;
  const isManager = canManageEmailIntake(myRole);

  useEffect(() => {
    let cancelled = false;
    listInboundAddresses(org.id)
      .then((response) => {
        if (!cancelled) setAddresses(response);
      })
      .catch((err) => {
        if (!cancelled) {
          setAddressesError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load inbound addresses.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setAddressesLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id]);

  useEffect(() => {
    let cancelled = false;
    listInboundEmails(org.id, statusFilter, undefined, page)
      .then((response) => {
        if (!cancelled) {
          setEmails(response.data);
          setMeta(response.meta);
        }
      })
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load inbound emails.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, statusFilter, page]);

  async function handleCreateAddress(event: FormEvent) {
    event.preventDefault();
    setAddressActionError(null);
    setAddressBusy(true);
    try {
      const address = await createInboundAddress(org.id, label.trim() || undefined);
      setAddresses((current) => [...current, address]);
      setLabel("");
    } catch (err) {
      setAddressActionError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not create the address.",
      );
    } finally {
      setAddressBusy(false);
    }
  }

  async function handleRegenerate(addressId: string) {
    setAddressActionError(null);
    setAddressBusy(true);
    try {
      const updated = await regenerateInboundAddress(org.id, addressId);
      setAddresses((current) =>
        current.map((item) => (item.id === addressId ? updated : item)),
      );
    } catch (err) {
      setAddressActionError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not regenerate the address.",
      );
    } finally {
      setAddressBusy(false);
    }
  }

  async function handleDeactivate(addressId: string) {
    setAddressActionError(null);
    setAddressBusy(true);
    try {
      const updated = await deactivateInboundAddress(org.id, addressId);
      setAddresses((current) =>
        current.map((item) => (item.id === addressId ? updated : item)),
      );
    } catch (err) {
      setAddressActionError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not deactivate the address.",
      );
    } finally {
      setAddressBusy(false);
    }
  }

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader title="Inbound addresses" />
        <CardBody>
          {addressesError ? <Alert tone="error">{addressesError}</Alert> : null}
          {addressActionError ? <Alert tone="error">{addressActionError}</Alert> : null}
          {addressesLoading ? (
            <div className="flex justify-center py-6">
              <Spinner />
            </div>
          ) : addresses.length === 0 ? (
            <EmptyState
              title="No inbound address yet"
              description="Your organization has no inbound address. One is created automatically; reload the page if it does not appear."
            />
          ) : (
            <ul className="divide-y divide-slate-200">
              {addresses.map((address) => (
                <li key={address.id} className="py-3 first:pt-0 last:pb-0">
                  <span className="flex flex-wrap items-center gap-2">
                    <span className="font-medium text-slate-900">{address.address}</span>
                    <Badge tone={address.active ? "success" : "neutral"}>
                      {address.active ? "Active" : "Inactive"}
                    </Badge>
                    {address.label ? (
                      <span className="text-sm text-slate-500">{address.label}</span>
                    ) : null}
                  </span>
                  {isManager && address.active ? (
                    <span className="mt-2 flex flex-wrap gap-2">
                      <Button
                        disabled={addressBusy}
                        onClick={() => handleRegenerate(address.id)}
                      >
                        Regenerate
                      </Button>
                      <Button
                        disabled={addressBusy}
                        onClick={() => handleDeactivate(address.id)}
                      >
                        Deactivate
                      </Button>
                    </span>
                  ) : null}
                  {isManager && address.active ? (
                    <p className="mt-1 text-sm text-slate-500">
                      Regenerating replaces this address; mail to the old
                      one stops working.
                    </p>
                  ) : null}
                </li>
              ))}
            </ul>
          )}
          {isManager ? (
            <form onSubmit={handleCreateAddress} className="mt-4 flex flex-wrap items-end gap-3">
              <Input
                id="email-intake-label"
                label="Label for a new address"
                value={label}
                onChange={(event) => setLabel(event.target.value)}
                maxLength={100}
                placeholder="Dispatch inbox"
              />
              <Button type="submit" disabled={addressBusy}>
                {addressBusy ? "Creating..." : "New address"}
              </Button>
            </form>
          ) : (
            <p className="mt-4 text-sm text-slate-500">
              Only an owner, admin, operations manager, or dispatcher can
              manage addresses.
            </p>
          )}
        </CardBody>
      </Card>

      {error ? <Alert tone="error">{error}</Alert> : null}

      <div className="flex flex-wrap items-center gap-3">
        <Select
          id="email-intake-status-filter"
          label="Filter by status"
          value={statusFilter}
          onChange={(event) => {
            setStatusFilter(event.target.value as InboundEmailStatus | "all");
            setPage(1);
          }}
          aria-label="Filter by status"
        >
          <option value="all">All statuses</option>
          {INBOUND_EMAIL_STATUSES.map((status) => (
            <option key={status.value} value={status.value}>
              {status.label}
            </option>
          ))}
        </Select>
      </div>

      {loading ? (
        <div className="flex justify-center py-12">
          <Spinner />
        </div>
      ) : emails.length === 0 ? (
        <EmptyState
          title="No inbound emails"
          description={
            statusFilter === "all"
              ? "No email has arrived yet. Forward shipment paperwork to one of the addresses above."
              : `No ${inboundEmailStatusLabel(statusFilter as InboundEmailStatus).toLowerCase()} emails.`
          }
        />
      ) : (
        <Card>
          <CardBody>
            <ul className="divide-y divide-slate-200">
              {emails.map((email) => (
                <li key={email.id} className="py-3 first:pt-0 last:pb-0">
                  <Link
                    href={`/email-intake/${email.id}`}
                    className="block rounded-md px-2 py-1 hover:bg-slate-50"
                  >
                    <span className="flex flex-wrap items-center gap-2">
                      <span className="font-medium text-slate-900">
                        {email.subject ?? "(no subject)"}
                      </span>
                      <Badge tone={statusTone(email.status)}>
                        {inboundEmailStatusLabel(email.status)}
                      </Badge>
                    </span>
                    <span className="mt-1 block text-sm text-slate-500">
                      From {email.from} ·{" "}
                      {new Date(email.created_at).toLocaleString()}
                      {email.matched_shipment
                        ? ` · Matched to ${email.matched_shipment.internal_reference}`
                        : ""}
                      {email.attachments.length > 0
                        ? ` · ${email.attachments.length} ${email.attachments.length === 1 ? "attachment" : "attachments"}`
                        : ""}
                    </span>
                  </Link>
                </li>
              ))}
            </ul>
            {meta && meta.last_page > 1 ? (
              <div className="mt-4 flex items-center gap-3">
                <Button
                  disabled={page <= 1}
                  onClick={() => setPage((current) => current - 1)}
                >
                  Previous
                </Button>
                <span className="text-sm text-slate-500">
                  Page {meta.current_page} of {meta.last_page}
                </span>
                <Button
                  disabled={page >= meta.last_page}
                  onClick={() => setPage((current) => current + 1)}
                >
                  Next
                </Button>
              </div>
            ) : null}
          </CardBody>
        </Card>
      )}
    </div>
  );
}

export default function EmailIntakePage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Email Intake"
        description={
          currentOrg
            ? `Inbound email for ${currentOrg.name}. Forward shipment paperwork to an address above; ClaimTrail files it against the shipment it names.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Email Intake" },
        ]}
      />
      {currentOrg ? (
        <EmailIntakeContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">
          Create an organization first to receive inbound email.
        </Alert>
      )}
    </div>
  );
}
