"use client";

import Link from "next/link";
import { useEffect, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState } from "@/components/ui/EmptyState";
import { Input, Select, Textarea } from "@/components/ui/Input";
import { PageHeader } from "@/components/ui/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { ApiRequestError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { canManageSupport } from "@/lib/permissions";
import {
  SUPPORT_CATEGORIES,
  SUPPORT_PRIORITIES,
  SUPPORT_STATUSES,
  createTicket,
  listTickets,
  supportCategoryLabel,
  supportStatusLabel,
} from "@/lib/support";
import type {
  Organization,
  PaginatedMeta,
  SupportTicket,
  SupportTicketCategory,
  SupportTicketPriority,
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
 * Support tickets for one organization. The parent keys it by
 * organization id, so switching organizations remounts it with
 * fresh state. Any member may open a ticket; owner and admin see
 * the internal notes and triage controls on the ticket page.
 */
function SupportContent({ org }: { org: Organization }) {
  const [tickets, setTickets] = useState<SupportTicket[]>([]);
  const [meta, setMeta] = useState<PaginatedMeta | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState<SupportTicketStatus | "all">("open");
  const [page, setPage] = useState(1);
  const [showForm, setShowForm] = useState(false);
  const [subject, setSubject] = useState("");
  const [category, setCategory] = useState<SupportTicketCategory>("technical");
  const [priority, setPriority] = useState<SupportTicketPriority>("normal");
  const [message, setMessage] = useState("");
  const [creating, setCreating] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  const myRole = org.current_user_role;
  const isManager = canManageSupport(myRole);

  // Loading starts true and clears in the fetch's finally below, so
  // the effect only sets state from async callbacks. Changing the
  // filter or page keeps the previous list visible while the new
  // one loads instead of flashing the spinner.
  useEffect(() => {
    let cancelled = false;
    listTickets(org.id, statusFilter, page)
      .then((response) => {
        if (!cancelled) {
          setTickets(response.data);
          setMeta(response.meta);
        }
      })
      .catch((err) => {
        if (!cancelled) {
          setError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load support tickets.",
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

  async function handleCreate(event: FormEvent) {
    event.preventDefault();
    setFormError(null);
    setCreating(true);
    try {
      const ticket = await createTicket(org.id, {
        subject: subject.trim(),
        category,
        priority,
        message: message.trim(),
      });
      setTickets((current) => [ticket, ...current]);
      setSubject("");
      setMessage("");
      setShowForm(false);
      setStatusFilter("open");
      setPage(1);
    } catch (err) {
      setFormError(
        err instanceof ApiRequestError
          ? err.message
          : "Could not create the ticket.",
      );
    } finally {
      setCreating(false);
    }
  }

  return (
    <div className="space-y-4">
      {error ? <Alert tone="error">{error}</Alert> : null}

      <div className="flex flex-wrap items-center gap-3">
        <Select
          id="support-status-filter"
          label="Filter by status"
          value={statusFilter}
          onChange={(event) => {
            setStatusFilter(event.target.value as SupportTicketStatus | "all");
            setPage(1);
          }}
          aria-label="Filter by status"
        >
          <option value="all">All statuses</option>
          {SUPPORT_STATUSES.map((status) => (
            <option key={status.value} value={status.value}>
              {status.label}
            </option>
          ))}
        </Select>
        <Button onClick={() => setShowForm((current) => !current)}>
          {showForm ? "Close form" : "New ticket"}
        </Button>
      </div>

      {showForm ? (
        <Card>
          <CardHeader title="Open a support ticket" />
          <CardBody>
            <form onSubmit={handleCreate} className="space-y-4">
              {formError ? <Alert tone="error">{formError}</Alert> : null}
              <Input
                id="support-subject"
                label="Subject"
                value={subject}
                onChange={(event) => setSubject(event.target.value)}
                required
                maxLength={255}
                placeholder="What do you need help with?"
              />
              <div className="grid gap-4 sm:grid-cols-2">
                <Select
                  id="support-category"
                  label="Category"
                  value={category}
                  onChange={(event) =>
                    setCategory(event.target.value as SupportTicketCategory)
                  }
                >
                  {SUPPORT_CATEGORIES.map((item) => (
                    <option key={item.value} value={item.value}>
                      {item.label}
                    </option>
                  ))}
                </Select>
                <Select
                  id="support-priority"
                  label="Priority"
                  value={priority}
                  onChange={(event) =>
                    setPriority(event.target.value as SupportTicketPriority)
                  }
                >
                  {SUPPORT_PRIORITIES.map((item) => (
                    <option key={item.value} value={item.value}>
                      {item.label}
                    </option>
                  ))}
                </Select>
              </div>
              <Textarea
                id="support-message"
                label="Message"
                value={message}
                onChange={(event) => setMessage(event.target.value)}
                required
                maxLength={5000}
                rows={5}
                placeholder="Describe the problem, what you expected, and what happened instead. Include the steps to reproduce it when you can."
              />
              <p className="text-sm text-slate-500">
                {isManager
                  ? "As an owner or admin you can triage tickets, change their status, and leave internal notes from the ticket page."
                  : "An owner or admin of your organization answers tickets. A reply on a resolved ticket reopens it."}
              </p>
              <Button type="submit" disabled={creating}>
                {creating ? "Creating..." : "Create ticket"}
              </Button>
            </form>
          </CardBody>
        </Card>
      ) : null}

      {loading ? (
        <div className="flex justify-center py-12">
          <Spinner />
        </div>
      ) : tickets.length === 0 ? (
        <EmptyState
          title="No support tickets"
          description={
            statusFilter === "all"
              ? "No tickets yet. Open one when you need help."
              : `No ${supportStatusLabel(statusFilter as SupportTicketStatus).toLowerCase()} tickets.`
          }
        />
      ) : (
        <Card>
          <CardBody>
            <ul className="divide-y divide-slate-200">
              {tickets.map((ticket) => (
                <li key={ticket.id} className="py-3 first:pt-0 last:pb-0">
                  <Link
                    href={`/support/${ticket.id}`}
                    className="block rounded-md px-2 py-1 hover:bg-slate-50"
                  >
                    <span className="flex flex-wrap items-center gap-2">
                      <span className="font-medium text-slate-900">
                        {ticket.subject}
                      </span>
                      <Badge tone={statusTone(ticket.status)}>
                        {supportStatusLabel(ticket.status)}
                      </Badge>
                      <Badge tone="neutral">
                        {supportCategoryLabel(ticket.category)}
                      </Badge>
                      <Badge tone="neutral">{ticket.priority}</Badge>
                    </span>
                    <span className="mt-1 block text-sm text-slate-500">
                      {ticket.requester?.name ?? "Unknown"} ·{" "}
                      {new Date(ticket.created_at).toLocaleString()} ·{" "}
                      {ticket.messages.length}{" "}
                      {ticket.messages.length === 1 ? "message" : "messages"}
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

export default function SupportPage() {
  const { currentOrg } = useAuth();

  return (
    <div>
      <PageHeader
        title="Support"
        description={
          currentOrg
            ? `Support tickets for ${currentOrg.name}.`
            : "No organization selected."
        }
        crumbs={[
          { label: "Dashboard", href: "/dashboard" },
          { label: "Support" },
        ]}
      />
      {currentOrg ? (
        <SupportContent key={currentOrg.id} org={currentOrg} />
      ) : (
        <Alert tone="info">
          Create an organization first to open a support ticket.
        </Alert>
      )}
    </div>
  );
}
