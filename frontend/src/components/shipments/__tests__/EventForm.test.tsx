import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { EventForm } from "../EventForm";
import type { EventCategory } from "@/lib/types";

const CATEGORIES: EventCategory[] = [
  { id: "11111111-1111-1111-1111-111111111111", slug: "detention", label: "Detention", description: null, is_system: true },
  { id: "22222222-2222-2222-2222-222222222222", slug: "layover", label: "Layover", description: null, is_system: true },
  { id: "33333333-3333-3333-3333-333333333333", slug: "tonu", label: "TONU", description: null, is_system: true },
];

function renderForm(overrides: Partial<Parameters<typeof EventForm>[0]> = {}) {
  const onSubmit = vi.fn(async () => undefined);
  const onCancel = vi.fn();
  render(
    <EventForm
      categories={CATEGORIES}
      submitting={false}
      serverErrors={{}}
      submitLabel="Add event"
      onCancel={onCancel}
      onSubmit={onSubmit}
      {...overrides}
    />,
  );
  return { onSubmit, onCancel };
}

describe("EventForm", () => {
  it("renders the category options", () => {
    renderForm();
    const select = screen.getByLabelText("Event category");
    expect(select).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "Detention" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "Layover" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "TONU" })).toBeInTheDocument();
  });

  it("shows validation errors instead of submitting an empty form", async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm();
    await user.click(screen.getByRole("button", { name: "Add event" }));
    expect(screen.getByText("Event category is required.")).toBeInTheDocument();
    expect(screen.getByText("Event date is required.")).toBeInTheDocument();
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it("submits the filled form values", async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm();
    await user.selectOptions(
      screen.getByLabelText("Event category"),
      "22222222-2222-2222-2222-222222222222",
    );
    await user.type(screen.getByLabelText("Event date"), "2026-11-16");
    await user.type(screen.getByLabelText("Start time"), "08:00");
    await user.type(screen.getByLabelText("End time"), "10:30");
    await user.type(screen.getByLabelText("Estimated amount"), "250.00");
    await user.click(screen.getByRole("button", { name: "Add event" }));

    expect(onSubmit).toHaveBeenCalledTimes(1);
    expect(onSubmit).toHaveBeenCalledWith({
      event_category_id: "22222222-2222-2222-2222-222222222222",
      event_date: "2026-11-16",
      start_time: "08:00",
      end_time: "10:30",
      duration_minutes: null,
      location: null,
      description: null,
      estimated_amount: "250.00",
      currency: "USD",
      notes: null,
    });
  });

  it("calls onCancel when Cancel is clicked", async () => {
    const user = userEvent.setup();
    const { onCancel } = renderForm();
    await user.click(screen.getByRole("button", { name: "Cancel" }));
    expect(onCancel).toHaveBeenCalledTimes(1);
  });

  it("shows a status select when editing an existing event", () => {
    renderForm({
      initial: {
        id: "77777777-7777-7777-7777-777777777777",
        organization_id: "org-1",
        shipment_id: "33333333-3333-3333-3333-333333333333",
        event_category_id: "11111111-1111-1111-1111-111111111111",
        category: { id: "11111111-1111-1111-1111-111111111111", slug: "detention", label: "Detention" },
        event_date: "2026-11-16",
        start_time: "08:00",
        end_time: "10:30",
        duration_minutes: 150,
        location: "Dallas, TX",
        description: "Waited at the dock.",
        estimated_amount: "250.00",
        currency: "USD",
        notes: null,
        status: "under_review",
        status_label: "Under Review",
        reported_by: { id: 1, name: "Test User" },
        created_at: "2026-11-16T10:00:00Z",
        updated_at: "2026-11-16T10:00:00Z",
      },
      submitLabel: "Save event",
    });
    const statusSelect = screen.getByLabelText("Status");
    expect(statusSelect).toBeInTheDocument();
    expect(statusSelect).toHaveValue("under_review");
    expect(screen.getByLabelText("Event date")).toHaveValue("2026-11-16");
  });
});
