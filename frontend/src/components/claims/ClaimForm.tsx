"use client";

import { useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Input, Select, Textarea } from "@/components/ui/Input";
import { apiFetch, ApiRequestError } from "@/lib/api";
import { CLAIM_CATEGORIES, isClaimPastSubmission } from "@/lib/claims";
import {
  blankToNull,
  inputValueToIso,
  isoToInputValue,
} from "@/lib/shipments";
import type { FieldErrors } from "@/lib/validation";
import { validateClaimForm } from "@/lib/validation";
import type {
  Claim,
  ClaimCategory,
  Shipment,
  ShipmentEvent,
} from "@/lib/types";

export interface ClaimPayload {
  shipment_id?: string;
  shipment_event_id?: string | null;
  claim_category?: ClaimCategory;
  description: string | null;
  requested_amount?: string;
  currency?: string;
  internal_notes: string | null;
  external_broker_reference: string | null;
  submission_deadline_at: string | null;
}

interface ClaimFormProps {
  orgId: string;
  /** Shipments to choose from when creating; unused when editing. */
  shipments: Shipment[];
  /** Prefilled claim when editing; omit when creating. */
  initial?: Claim | null;
  submitting: boolean;
  /** Field errors returned by the server (422), keyed by field name. */
  serverErrors: FieldErrors;
  submitLabel: string;
  onCancel?: () => void;
  onSubmit: (payload: ClaimPayload) => Promise<void>;
}

function formatEventDate(value: string): string {
  return new Date(value).toLocaleDateString();
}

/**
 * Shared create/edit form for claims. The parent owns the shipments
 * list and the submit call; this form owns field state, client-side
 * validation, and the event picker for the chosen shipment.
 *
 * When an event is picked, the category defaults to the event's
 * category (the API inherits it when none is given); the user can
 * still pick another category afterwards.
 */
export function ClaimForm({
  orgId,
  shipments,
  initial = null,
  submitting,
  serverErrors,
  submitLabel,
  onCancel,
  onSubmit,
}: ClaimFormProps) {
  const isEdit = initial !== null;
  const frozen = isEdit && isClaimPastSubmission(initial.status);

  const [shipmentSearch, setShipmentSearch] = useState("");
  const [shipmentId, setShipmentId] = useState(
    initial ? String(initial.shipment_id) : "",
  );
  const [events, setEvents] = useState<ShipmentEvent[]>([]);
  const [eventsLoading, setEventsLoading] = useState(
    initial?.shipment_id ? true : false,
  );
  const [eventsError, setEventsError] = useState<string | null>(null);
  const [eventId, setEventId] = useState(
    initial?.shipment_event_id ? String(initial.shipment_event_id) : "",
  );
  const [category, setCategory] = useState<ClaimCategory>(
    initial?.claim_category ?? "other",
  );
  const [requestedAmount, setRequestedAmount] = useState(
    initial?.requested_amount ?? "",
  );
  const [currency, setCurrency] = useState(initial?.currency ?? "USD");
  const [description, setDescription] = useState(initial?.description ?? "");
  const [internalNotes, setInternalNotes] = useState(
    initial?.internal_notes ?? "",
  );
  const [brokerReference, setBrokerReference] = useState(
    initial?.external_broker_reference ?? "",
  );
  const [submissionDeadline, setSubmissionDeadline] = useState(
    isoToInputValue(initial?.submission_deadline_at ?? null),
  );
  const [errors, setErrors] = useState<FieldErrors>({});

  // Load the events of the chosen shipment whenever it changes.
  // The loading flag is set by the change handler (or the initial
  // state on mount), so this effect only fetches and applies results.
  useEffect(() => {
    if (!shipmentId) return;
    let cancelled = false;
    apiFetch<{ data: ShipmentEvent[] }>(`/shipments/${shipmentId}/events`, {
      orgId,
    })
      .then((response) => {
        if (!cancelled) setEvents(response.data);
      })
      .catch((err) => {
        if (!cancelled) {
          setEvents([]);
          setEventsError(
            err instanceof ApiRequestError
              ? err.message
              : "Could not load events for this shipment.",
          );
        }
      })
      .finally(() => {
        if (!cancelled) setEventsLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [orgId, shipmentId]);

  function fieldError(field: string): string | undefined {
    return errors[field] ?? serverErrors[field];
  }

  function handleShipmentChange(value: string) {
    setShipmentId(value);
    setEventId("");
    setEvents([]);
    setEventsError(null);
    setEventsLoading(value !== "");
  }

  function handleEventChange(value: string) {
    setEventId(value);
    const event = events.find((item) => String(item.id) === value);
    const slug = event?.category.slug;
    if (slug && CLAIM_CATEGORIES.some((item) => item.value === slug)) {
      setCategory(slug as ClaimCategory);
    }
  }

  const filteredShipments = shipments.filter((shipment) => {
    const query = shipmentSearch.trim().toLowerCase();
    if (!query) return true;
    return (
      shipment.internal_reference.toLowerCase().includes(query) ||
      shipment.broker_or_customer_name.toLowerCase().includes(query)
    );
  });

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    const formErrors = validateClaimForm({
      shipment_id: shipmentId,
      requested_amount: requestedAmount,
      currency,
    });
    // Frozen fields are not sent and not validated on edit.
    if (frozen) {
      delete formErrors.requested_amount;
      delete formErrors.currency;
    }
    setErrors(formErrors);
    if (Object.keys(formErrors).length > 0) return;

    const payload: ClaimPayload = {
      description: blankToNull(description),
      internal_notes: blankToNull(internalNotes),
      external_broker_reference: blankToNull(brokerReference),
      submission_deadline_at: inputValueToIso(submissionDeadline),
    };
    if (!isEdit) {
      payload.shipment_id = shipmentId;
    }
    if (!frozen) {
      payload.shipment_event_id = eventId ? eventId : null;
      payload.claim_category = category;
      payload.requested_amount = requestedAmount.trim();
      payload.currency = currency.trim().toUpperCase() || "USD";
    }
    await onSubmit(payload);
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-6" noValidate>
      {frozen && (
        <Alert tone="info">
          This claim is {initial?.status_label.toLowerCase()}. Its amount,
          currency, category, and event link are frozen and can no longer be
          changed. Description, notes, broker reference, and the submission
          deadline can still be edited.
        </Alert>
      )}

      {isEdit ? (
        <div>
          <p className="mb-1 block text-sm font-medium text-slate-700">
            Shipment
          </p>
          <p className="rounded-md border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-700">
            {initial?.shipment ? (
              <>
                {initial.shipment.internal_reference} -{" "}
                {initial.shipment.broker_or_customer_name}
              </>
            ) : (
              "Shipment not available"
            )}
          </p>
          <p className="mt-1 text-xs text-slate-500">
            A claim stays with the shipment it was created for.{" "}
            {initial?.shipment && (
              <Link
                href={`/shipments/${initial.shipment.id}`}
                className="text-brand-700 hover:underline"
              >
                View shipment
              </Link>
            )}
          </p>
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2">
          <Input
            id="shipment-search"
            label="Search shipments"
            type="search"
            value={shipmentSearch}
            onChange={(event) => setShipmentSearch(event.target.value)}
            placeholder="Reference or broker name"
            help="Narrows the shipment list below."
          />
          <Select
            id="shipment_id"
            label="Shipment"
            value={shipmentId}
            onChange={(event) => handleShipmentChange(event.target.value)}
            error={fieldError("shipment_id")}
          >
            <option value="">Choose a shipment</option>
            {filteredShipments.map((shipment) => (
              <option key={shipment.id} value={shipment.id}>
                {shipment.internal_reference} - {shipment.broker_or_customer_name}
              </option>
            ))}
          </Select>
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        <Select
          id="shipment_event_id"
          label="Linked event (optional)"
          value={eventId}
          onChange={(event) => handleEventChange(event.target.value)}
          error={fieldError("shipment_event_id")}
          disabled={frozen || !shipmentId || eventsLoading}
          help={
            !shipmentId
              ? "Choose a shipment first."
              : eventsLoading
                ? "Loading events..."
                : "Picking an event sets the category from it."
          }
        >
          <option value="">No event</option>
          {events.map((event) => (
            <option key={event.id} value={event.id}>
              {event.category.label} on {formatEventDate(event.event_date)}
              {event.description ? ` - ${event.description}` : ""}
            </option>
          ))}
        </Select>
        <Select
          id="claim_category"
          label="Category"
          value={category}
          onChange={(event) => setCategory(event.target.value as ClaimCategory)}
          error={fieldError("claim_category")}
          disabled={frozen}
        >
          {CLAIM_CATEGORIES.map((item) => (
            <option key={item.value} value={item.value}>
              {item.label}
            </option>
          ))}
        </Select>
        <Input
          id="requested_amount"
          label="Requested amount"
          type="text"
          inputMode="decimal"
          value={requestedAmount}
          onChange={(event) => setRequestedAmount(event.target.value)}
          error={fieldError("requested_amount")}
          disabled={frozen}
          placeholder="0.00"
          help="The amount claimed from the broker. This is a potential amount, not recovered money."
        />
        <Input
          id="currency"
          label="Currency"
          type="text"
          value={currency}
          onChange={(event) => setCurrency(event.target.value)}
          error={fieldError("currency")}
          disabled={frozen}
          help="3-letter code, for example USD."
        />
        <Input
          id="external_broker_reference"
          label="Broker reference"
          type="text"
          value={brokerReference}
          onChange={(event) => setBrokerReference(event.target.value)}
          error={fieldError("external_broker_reference")}
          placeholder="Broker claim or load number, if any"
        />
        <Input
          id="submission_deadline_at"
          label="Submission deadline"
          type="datetime-local"
          value={submissionDeadline}
          onChange={(event) => setSubmissionDeadline(event.target.value)}
          error={fieldError("submission_deadline_at")}
          help="The date the broker expects this claim. Leave blank when there is none."
        />
      </div>

      {eventsError && <Alert tone="warning">{eventsError}</Alert>}

      <Textarea
        id="description"
        label="Description"
        rows={3}
        value={description}
        onChange={(event) => setDescription(event.target.value)}
        error={fieldError("description")}
        placeholder="What happened and why this amount is claimed."
      />
      <Textarea
        id="internal_notes"
        label="Internal notes"
        rows={3}
        value={internalNotes}
        onChange={(event) => setInternalNotes(event.target.value)}
        error={fieldError("internal_notes")}
        help="Notes for your team only. They are left out of the claim package unless you choose to include them."
      />

      <div className="flex flex-wrap gap-2">
        <Button type="submit" loading={submitting}>
          {submitLabel}
        </Button>
        {onCancel && (
          <Button type="button" variant="secondary" onClick={onCancel}>
            Cancel
          </Button>
        )}
      </div>
    </form>
  );
}
