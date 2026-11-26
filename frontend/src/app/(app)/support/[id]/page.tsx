"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Select, Textarea } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { canManageSupport } from "@/lib/permissions";
import {
  SUPPORT_STATUSES,
  getTicket,
  replyToTicket,
  setTicketStatus,
  supportCategoryLabel,
  supportStatusLabel,
} from "@/lib/support";
import type {
  Organization,
  SupportTicket,
  SupportTicketStatus,
} from "@/lib/types";

function statusTone(status: SupportTicketStatus): "neutral" | "info" | "warning" | "success" {
  switch (status) {
    case "open":
      return "info";
    case "in_progress":
      return "warning";
    case "waiting_on_customer":
      return "warning";
    case "resolved":
      return "success";
    case "closed":
      return "neutral";
  }
}

/**
 * One support ticket with its conversation. Any member may reply;
 * a public reply from the requester on a resolved ticket reopens
 * it. Owner and admin may change the status and write internal
 * notes, which only they see.
 */
function TicketContent({ org, ticketId }: { org: Organization; ticketId: string }) {
  const [ticket, setTicket] = useState<SupportTicket | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [reply, setReply] = useState("");
  const [internal, setInternal] = useState(false);
  const [sending, setSending] = useState(false);
  const [replyError, setReplyError] = useState<string | null>(null);
  const [statusBusy, setStatusBusy] = useState(false);
  const [statusError, setStatusError] = useState<string | null>(null);

  const isManager = canManageSupport(org.current_user_role);
  const isClosed = ticket?.status === "closed";

  useEffect(() => {
    let cancelled = false;
    getTicket(org.id, ticketId)
      .then((loaded) => {
        if (!cancelled) setTicket(loaded);
      })
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load the ticket.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [org.id, ticketId]);

  async function handleReply(event: FormEvent) {
    event.preventDefault();
    if (ticket === null) return;
    setReplyError(null);
    setSending(true);
    try {
      const result = await replyToTicket(org.id, ticket.id, reply.trim(), internal);
      setTicket(result.ticket);
      setReply("");
      setInternal(false);
    } catch (err) {
      setReplyError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not send the reply.",
      );
    } finally {
      setSending(false);
    }
  }

  async function handleStatusChange(next: SupportTicketStatus) {
    if (ticket === null) return;
    setStatusError(null);
    setStatusBusy(true);
    try {
      const updated = await setTicketStatus(org.id, ticket.id, next);
      setTicket(updated);
    } catch (err) {
      setStatusError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not change the status.",
      );
    } finally {
      setStatusBusy(false);
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center py-12">
        <Spinner />
      </div>
    );
  }

  if (error !== null || ticket === null) {
    return <Alert tone="error">{error ?? "Ticket not found."}</Alert>;
  }

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader
          title={ticket.subject}
          description={`Opened by ${ticket.requester?.name ?? "Unknown"} on ${new Date(ticket.created_at).toLocaleString()}`}
        />
        <CardBody>
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={statusTone(ticket.status)}>
              {supportStatusLabel(ticket.status)}
            </Badge>
            <Badge tone="neutral">{supportCategoryLabel(ticket.category)}</Badge>
            <Badge tone="neutral">{ticket.priority}</Badge>
            {ticket.resolved_at ? (
              <span className="text-sm text-slate-500">
                Resolved {new Date(ticket.resolved_at).toLocaleString()}
              </span>
            ) : null}
            {ticket.closed_at ? (
              <span className="text-sm text-slate-500">
                Closed {new Date(ticket.closed_at).toLocaleString()}
              </span>
            ) : null}
          </div>
          {isManager ? (
            <div className="mt-4 flex flex-wrap items-center gap-3">
              <Select
                id="ticket-status"
                label="Status"
                value={ticket.status}
                onChange={(event) =>
                  handleStatusChange(event.target.value as SupportTicketStatus)
                }
                disabled={statusBusy}
              >
                {SUPPORT_STATUSES.map((status) => (
                  <option key={status.value} value={status.value}>
                    {status.label}
                  </option>
                ))}
              </Select>
              {statusError ? <Alert tone="error">{statusError}</Alert> : null}
            </div>
          ) : null}
        </CardBody>
      </Card>

      <Card>
        <CardHeader title="Conversation" />
        <CardBody>
          {ticket.messages.length === 0 ? (
            <EmptyState title="No messages" description="This ticket has no messages yet." />
          ) : (
            <ul className="space-y-4">
              {ticket.messages.map((message) => (
                <li
                  key={message.id}
                  className={
                    message.internal
                      ? "rounded-md border border-amber-200 bg-amber-50 p-3"
                      : "rounded-md border border-slate-200 p-3"
                  }
                >
                  <p className="text-sm text-slate-500">
                    {message.user?.name ?? "Unknown"}
                    {message.internal ? " · internal note (only owner and admin see this)" : ""} ·{" "}
                    {new Date(message.created_at).toLocaleString()}
                  </p>
                  <p className="mt-1 whitespace-pre-wrap text-sm text-slate-800">
                    {message.body}
                  </p>
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>

      {isClosed ? (
        <Alert tone="info">
          This ticket is closed and takes no more messages. Open a new
          ticket if the issue comes back.
        </Alert>
      ) : (
        <Card>
          <CardHeader title="Reply" />
          <CardBody>
            <form onSubmit={handleReply} className="space-y-4">
              {replyError ? <Alert tone="error">{replyError}</Alert> : null}
              <Textarea
                id="ticket-reply"
                label="Message"
                value={reply}
                onChange={(event) => setReply(event.target.value)}
                required
                maxLength={5000}
                rows={4}
                placeholder="Write your reply..."
              />
              {isManager ? (
                <label className="flex items-center gap-2 text-sm text-slate-700">
                  <input
                    type="checkbox"
                    checked={internal}
                    onChange={(event) => setInternal(event.target.checked)}
                  />
                  Internal note (only owner and admin see this; it does not
                  change the ticket status or notify the requester)
                </label>
              ) : null}
              <Button type="submit" disabled={sending}>
                {sending ? "Sending..." : "Send reply"}
              </Button>
            </form>
          </CardBody>
        </Card>
      )}
    </div>
  );
}

export default function SupportTicketPage() {
  const params = useParams();
  const ticketId = typeof params.id === "string" ? params.id : "";
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Support ticket"
        description={
          currentOrg
            ? `Support tickets for ${currentOrg.name}.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Support", href: "/support" },
          { label: "Ticket" },
        ]}
      />
      {currentOrg ? (
        <TicketContent key={currentOrg.id} org={currentOrg} ticketId={ticketId} />
      ) : (
        <Alert tone="info">
          Create an organization first to open a support ticket.
        </Alert>
      )}
      <p className="mt-4">
        <Link href="/support" className="text-sm text-brand-700 hover:underline">
          Back to all tickets
        </Link>
      </p>
    </div>
  );
}
