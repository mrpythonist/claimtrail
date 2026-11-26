"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Input, Textarea } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { ApiRequestError, apiFetch } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import {
  dismissInboundEmail,
  getInboundEmail,
  inboundEmailStatusLabel,
  matchInboundEmail,
  unmatchInboundEmail,
} from "@/lib/emailIntake";
import { canManageEmailIntake } from "@/lib/permissions";
import { formatFileSize } from "@/lib/shipments";
import type {
  InboundEmail,
  InboundEmailStatus,
  Organization,
  PaginatedMeta,
  Shipment,
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
 * One inbound email: the message, the references extracted from
 * it, its attachments, and the manual matching controls. A
 * manager can search shipments by reference and match the email,
 * unmatch it, or dismiss it with a reason. Matching turns the
 * attachments into evidence on the shipment.
 */
function EmailIntakeDetailContent({ org, emailId }: { org: Organization; emailId: string }) {
  const [email, setEmail] = useState<InboundEmail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [actionBusy, setActionBusy] = useState(false);

  const [search, setSearch] = useState("");
  const [searchResults, setSearchResults] = useState<Shipment[]>([]);
  const [searching, setSearching] = useState(false);
  const [searched, setSearched] = useState(false);

  const [dismissReason, setDismissReason] = useState("");
  const [showDismiss, setShowDismiss] = useState(false);

  const myRole = org.current_user_role;
  const isManager = canManageEmailIntake(myRole);

  useEffect(() => {
    let cancelled = false;
    getInboundEmail(org.id, emailId)
      .then((response) => {
        if (!cancelled) setEmail(response);
      })
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load the email.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, emailId]);

  async function handleSearch(event: FormEvent) {
    event.preventDefault();
    setSearching(true);
    setSearched(true);
    try {
      const params = new URLSearchParams({
        search: search.trim(),
        per_page: "10",
      });
      const response = await apiFetch<{ data: Shipment[]; meta: PaginatedMeta }>(
        `/shipments?${params.toString()}`,
        { orgId: org.id },
      );
      setSearchResults(response.data);
    } catch (err) {
      setActionError(
        err instanceof ApiRequestError ? err.message : "Could not search shipments.",
      );
      setSearchResults([]);
    } finally {
      setSearching(false);
    }
  }

  async function handleMatch(shipmentId: string) {
    setActionError(null);
    setActionBusy(true);
    try {
      const updated = await matchInboundEmail(org.id, emailId, shipmentId);
      setEmail(updated);
      setSearchResults([]);
      setSearch("");
      setSearched(false);
    } catch (err) {
      setActionError(
        err instanceof ApiRequestError ? err.message : "Could not match the email.",
      );
    } finally {
      setActionBusy(false);
    }
  }

  async function handleUnmatch() {
    setActionError(null);
    setActionBusy(true);
    try {
      const updated = await unmatchInboundEmail(org.id, emailId);
      setEmail(updated);
    } catch (err) {
      setActionError(
        err instanceof ApiRequestError ? err.message : "Could not unmatch the email.",
      );
    } finally {
      setActionBusy(false);
    }
  }

  async function handleDismiss(event: FormEvent) {
    event.preventDefault();
    setActionError(null);
    setActionBusy(true);
    try {
      const updated = await dismissInboundEmail(org.id, emailId, dismissReason.trim());
      setEmail(updated);
      setShowDismiss(false);
      setDismissReason("");
    } catch (err) {
      setActionError(
        err instanceof ApiRequestError ? err.message : "Could not dismiss the email.",
      );
    } finally {
      setActionBusy(false);
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center py-12">
        <Spinner />
      </div>
    );
  }

  if (error || !email) {
    return <Alert tone="error">{error ?? "Email not found."}</Alert>;
  }

  const canMatch = isManager && email.status !== "matched" && email.status !== "dismissed";
  const canDismiss = isManager && email.status !== "matched" && email.status !== "dismissed";

  return (
    <div className="space-y-4">
      {actionError ? <Alert tone="error">{actionError}</Alert> : null}

      <Card>
        <CardHeader title={email.subject ?? "(no subject)"} />
        <CardBody>
          <dl className="space-y-2 text-sm">
            <div className="flex flex-wrap gap-2">
              <dt className="font-medium text-slate-700">Status:</dt>
              <dd>
                <Badge tone={statusTone(email.status)}>
                  {inboundEmailStatusLabel(email.status)}
                </Badge>
              </dd>
            </div>
            <div>
              <dt className="font-medium text-slate-700">From</dt>
              <dd className="text-slate-900">{email.from}</dd>
            </div>
            <div>
              <dt className="font-medium text-slate-700">To</dt>
              <dd className="text-slate-900">{email.to}</dd>
            </div>
            <div>
              <dt className="font-medium text-slate-700">Received</dt>
              <dd className="text-slate-900">
                {new Date(email.created_at).toLocaleString()}
              </dd>
            </div>
            {email.match_reason ? (
              <div>
                <dt className="font-medium text-slate-700">Match</dt>
                <dd className="text-slate-900">
                  {email.match_reason}
                  {email.match_confidence ? ` (${email.match_confidence} confidence)` : ""}
                </dd>
              </div>
            ) : null}
            {email.dismissed_reason ? (
              <div>
                <dt className="font-medium text-slate-700">Dismissed because</dt>
                <dd className="text-slate-900">{email.dismissed_reason}</dd>
              </div>
            ) : null}
            {email.processing_notes ? (
              <div>
                <dt className="font-medium text-slate-700">Processing notes</dt>
                <dd className="whitespace-pre-wrap text-slate-900">{email.processing_notes}</dd>
              </div>
            ) : null}
          </dl>
          {email.text_body ? (
            <div className="mt-4 border-t border-slate-200 pt-4">
              <p className="mb-2 text-sm font-medium text-slate-700">Message</p>
              <p className="whitespace-pre-wrap text-sm text-slate-900">{email.text_body}</p>
            </div>
          ) : null}
        </CardBody>
      </Card>

      {email.matched_shipment ? (
        <Card>
          <CardHeader title="Matched shipment" />
          <CardBody>
            <p className="text-sm">
              <Link
                href={`/shipments/${email.matched_shipment.id}`}
                className="font-medium text-brand-700 hover:underline"
              >
                {email.matched_shipment.internal_reference}
              </Link>
              {email.matched_shipment.external_load_reference
                ? ` (${email.matched_shipment.external_load_reference})`
                : ""}
              {" · "}
              {email.matched_shipment.broker_or_customer_name}
            </p>
            {isManager ? (
              <div className="mt-3">
                <Button disabled={actionBusy} onClick={handleUnmatch}>
                  {actionBusy ? "Working..." : "Unmatch"}
                </Button>
              </div>
            ) : null}
          </CardBody>
        </Card>
      ) : null}

      <Card>
        <CardHeader title="Extracted references" />
        <CardBody>
          {email.extracted_references.length === 0 ? (
            <EmptyState
              title="No references found"
              description="No shipment reference was found in the subject, body, or attachment filenames."
            />
          ) : (
            <ul className="divide-y divide-slate-200">
              {email.extracted_references.map((reference) => (
                <li key={reference.value} className="py-2 first:pt-0 last:pb-0 text-sm">
                  <span className="font-medium text-slate-900">{reference.value}</span>
                  <span className="text-slate-500">
                    {" "}
                    · found in {reference.source}
                    {reference.matched_shipment_id
                      ? ` · matches a shipment (${reference.matched_field})`
                      : " · no matching shipment"}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader title={`Attachments (${email.attachments.length})`} />
        <CardBody>
          {email.attachments.length === 0 ? (
            <EmptyState
              title="No attachments"
              description="This email arrived with no attachments."
            />
          ) : (
            <ul className="divide-y divide-slate-200">
              {email.attachments.map((attachment) => (
                <li key={attachment.id} className="py-2 first:pt-0 last:pb-0 text-sm">
                  <span className="font-medium text-slate-900">{attachment.filename}</span>
                  <span className="text-slate-500">
                    {" "}
                    · {attachment.mime_type} · {formatFileSize(attachment.size_bytes)}
                    {attachment.evidence_file_id
                      ? " · filed as evidence"
                      : " · not filed as evidence yet"}
                  </span>
                </li>
              ))}
            </ul>
          )}
          <p className="mt-3 text-sm text-slate-500">
            Attachments become evidence on the matched shipment, filed as
            Other with the source email named in the description.
          </p>
        </CardBody>
      </Card>

      {canMatch ? (
        <Card>
          <CardHeader title="Match to a shipment" />
          <CardBody>
            <form onSubmit={handleSearch} className="flex flex-wrap items-end gap-3">
              <Input
                id="email-intake-shipment-search"
                label="Search shipments by reference"
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="PLT-1001"
              />
              <Button type="submit" disabled={searching || search.trim() === ""}>
                {searching ? "Searching..." : "Search"}
              </Button>
            </form>
            {searched ? (
              searchResults.length === 0 ? (
                <p className="mt-3 text-sm text-slate-500">
                  No shipments match that search.
                </p>
              ) : (
                <ul className="mt-3 divide-y divide-slate-200">
                  {searchResults.map((shipment) => (
                    <li key={shipment.id} className="flex flex-wrap items-center gap-3 py-2">
                      <span className="text-sm">
                        <span className="font-medium text-slate-900">
                          {shipment.internal_reference}
                        </span>
                        {shipment.external_load_reference
                          ? ` (${shipment.external_load_reference})`
                          : ""}
                        {" · "}
                        {shipment.broker_or_customer_name}
                      </span>
                      <Button
                        disabled={actionBusy}
                        onClick={() => handleMatch(shipment.id)}
                      >
                        Match to this shipment
                      </Button>
                    </li>
                  ))}
                </ul>
              )
            ) : null}
          </CardBody>
        </Card>
      ) : null}

      {canDismiss ? (
        <Card>
          <CardHeader title="Dismiss" />
          <CardBody>
            {showDismiss ? (
              <form onSubmit={handleDismiss} className="space-y-4">
                <Textarea
                  id="email-intake-dismiss-reason"
                  label="Reason"
                  value={dismissReason}
                  onChange={(event) => setDismissReason(event.target.value)}
                  required
                  maxLength={500}
                  rows={3}
                  placeholder="Why is this email not shipment paperwork?"
                />
                <span className="flex gap-2">
                  <Button type="submit" disabled={actionBusy || dismissReason.trim() === ""}>
                    {actionBusy ? "Dismissing..." : "Dismiss email"}
                  </Button>
                  <Button type="button" onClick={() => setShowDismiss(false)}>
                    Cancel
                  </Button>
                </span>
              </form>
            ) : (
              <Button onClick={() => setShowDismiss(true)}>Dismiss this email</Button>
            )}
          </CardBody>
        </Card>
      ) : null}

      {!isManager && email.status !== "matched" ? (
        <p className="text-sm text-slate-500">
          Only an owner, admin, operations manager, or dispatcher can match
          or dismiss inbound emails.
        </p>
      ) : null}
    </div>
  );
}

export default function EmailIntakeDetailPage() {
  const { currentOrg } = useAuth();
  const params = useParams();
  const emailId = typeof params.id === "string" ? params.id : "";

  return (
    <div>
      <PageHeader
        title="Inbound email"
        description="The message, its extracted references, and its attachments."
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Email Intake", href: "/email-intake" },
          { label: "Email" },
        ]}
      />
      {currentOrg ? (
        <EmailIntakeDetailContent key={`${currentOrg.id}-${emailId}`} org={currentOrg} emailId={emailId} />
      ) : (
        <Alert tone="info">Create an organization first to receive inbound email.</Alert>
      )}
    </div>
  );
}
