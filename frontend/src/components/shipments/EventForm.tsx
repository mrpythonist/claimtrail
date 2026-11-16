"use client";

import { useState, type FormEvent } from "react";
import { Button } from "@/components/ui/Button";
import { Input, Select, Textarea } from "@/components/ui/Input";
import { blankToNull, EVENT_STATUS_LABELS } from "@/lib/shipments";
import type { FieldErrors } from "@/lib/validation";
import { validateEventForm } from "@/lib/validation";
import type {
  EventCategory,
  EventStatus,
  ShipmentEvent,
} from "@/lib/types";

export interface EventPayload {
  event_category_id: number;
  event_date: string;
  start_time: string | null;
  end_time: string | null;
  duration_minutes: number | null;
  location: string | null;
  description: string | null;
  estimated_amount: string | null;
  currency: string | null;
  notes: string | null;
  status?: EventStatus;
}

interface EventFormProps {
  categories: EventCategory[];
  /** Prefilled event when editing; omit when adding. */
  initial?: ShipmentEvent | null;
  submitting: boolean;
  /** Field errors returned by the server (422), keyed by field name. */
  serverErrors: FieldErrors;
  submitLabel: string;
  onCancel: () => void;
  onSubmit: (payload: EventPayload) => Promise<void>;
}

const EVENT_STATUSES: EventStatus[] = ["potential", "under_review", "dismissed"];

/**
 * Add/edit form for a shipment event. The parent owns the API call and
 * the category list; this form owns field state and client-side
 * validation.
 */
export function EventForm({
  categories,
  initial = null,
  submitting,
  serverErrors,
  submitLabel,
  onCancel,
  onSubmit,
}: EventFormProps) {
  const isEdit = initial !== null;

  const [eventCategoryId, setEventCategoryId] = useState(
    initial?.event_category_id ? String(initial.event_category_id) : "",
  );
  const [eventDate, setEventDate] = useState(initial?.event_date ?? "");
  const [startTime, setStartTime] = useState(initial?.start_time ?? "");
  const [endTime, setEndTime] = useState(initial?.end_time ?? "");
  const [durationMinutes, setDurationMinutes] = useState(
    initial?.duration_minutes !== null && initial?.duration_minutes !== undefined
      ? String(initial.duration_minutes)
      : "",
  );
  const [location, setLocation] = useState(initial?.location ?? "");
  const [description, setDescription] = useState(initial?.description ?? "");
  const [estimatedAmount, setEstimatedAmount] = useState(
    initial?.estimated_amount ?? "",
  );
  const [currency, setCurrency] = useState(initial?.currency ?? "USD");
  const [notes, setNotes] = useState(initial?.notes ?? "");
  const [status, setStatus] = useState<EventStatus>(initial?.status ?? "potential");
  const [errors, setErrors] = useState<FieldErrors>({});

  function fieldError(field: string): string | undefined {
    return errors[field] ?? serverErrors[field];
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    const formErrors = validateEventForm({
      event_category_id: eventCategoryId,
      event_date: eventDate,
      start_time: startTime,
      end_time: endTime,
      estimated_amount: estimatedAmount,
    });
    setErrors(formErrors);
    if (Object.keys(formErrors).length > 0) return;

    const payload: EventPayload = {
      event_category_id: Number(eventCategoryId),
      event_date: eventDate,
      start_time: startTime || null,
      end_time: endTime || null,
      duration_minutes: durationMinutes.trim()
        ? Number(durationMinutes.trim())
        : null,
      location: blankToNull(location),
      description: blankToNull(description),
      estimated_amount: estimatedAmount.trim() ? estimatedAmount.trim() : null,
      currency: blankToNull(currency),
      notes: blankToNull(notes),
    };
    if (isEdit) {
      payload.status = status;
    }
    await onSubmit(payload);
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4" noValidate>
      <div className="grid gap-4 sm:grid-cols-2">
        <Select
          id="event_category_id"
          label="Event category"
          value={eventCategoryId}
          onChange={(event) => setEventCategoryId(event.target.value)}
          error={fieldError("event_category_id")}
        >
          <option value="">Select a category</option>
          {categories.map((category) => (
            <option key={category.id} value={category.id}>
              {category.label}
            </option>
          ))}
        </Select>
        <Input
          id="event_date"
          label="Event date"
          type="date"
          value={eventDate}
          onChange={(event) => setEventDate(event.target.value)}
          error={fieldError("event_date")}
        />
        <Input
          id="start_time"
          label="Start time"
          type="time"
          value={startTime}
          onChange={(event) => setStartTime(event.target.value)}
          error={fieldError("start_time")}
        />
        <Input
          id="end_time"
          label="End time"
          type="time"
          value={endTime}
          onChange={(event) => setEndTime(event.target.value)}
          error={fieldError("end_time")}
        />
        <Input
          id="duration_minutes"
          label="Duration (minutes)"
          type="number"
          min="0"
          value={durationMinutes}
          onChange={(event) => setDurationMinutes(event.target.value)}
          error={fieldError("duration_minutes")}
          help="Leave blank when both times are set; the duration is computed from them."
        />
        <Input
          id="location"
          label="Location"
          type="text"
          value={location}
          onChange={(event) => setLocation(event.target.value)}
          error={fieldError("location")}
        />
        <Input
          id="estimated_amount"
          label="Estimated amount"
          type="text"
          inputMode="decimal"
          value={estimatedAmount}
          onChange={(event) => setEstimatedAmount(event.target.value)}
          error={fieldError("estimated_amount")}
          placeholder="0.00"
        />
        <Input
          id="currency"
          label="Currency"
          type="text"
          value={currency}
          onChange={(event) => setCurrency(event.target.value)}
          error={fieldError("currency")}
          help="3-letter code, for example USD."
        />
        {isEdit && (
          <Select
            id="event_status"
            label="Status"
            value={status}
            onChange={(event) => setStatus(event.target.value as EventStatus)}
            error={fieldError("status")}
          >
            {EVENT_STATUSES.map((value) => (
              <option key={value} value={value}>
                {EVENT_STATUS_LABELS[value]}
              </option>
            ))}
          </Select>
        )}
      </div>

      <Textarea
        id="description"
        label="Description"
        rows={3}
        value={description}
        onChange={(event) => setDescription(event.target.value)}
        error={fieldError("description")}
        placeholder="What happened and why it is chargeable."
      />
      <Textarea
        id="notes"
        label="Notes"
        rows={3}
        value={notes}
        onChange={(event) => setNotes(event.target.value)}
        error={fieldError("notes")}
      />

      <div className="flex gap-3">
        <Button type="submit" loading={submitting}>
          {submitLabel}
        </Button>
        <Button type="button" variant="secondary" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </form>
  );
}
