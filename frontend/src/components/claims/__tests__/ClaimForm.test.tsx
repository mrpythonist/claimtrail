import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ClaimForm } from "../ClaimForm";
import type { Claim, Shipment, ShipmentEvent } from "@/lib/types";

vi.mock("@/lib/api", () => {
  class ApiRequestError extends Error {
    status: number;
    fields: Record<string, string[]>;
    constructor(status: number, body: { message: string }) {
      super(body.message);
      this.status = status;
      this.fields = {};
    }
  }
  return { apiFetch: vi.fn(), ApiRequestError };
});

import { apiFetch } from "@/lib/api";

const mockedApiFetch = vi.mocked(apiFetch);

const SHIPMENTS: Shipment[] = [
  {
    id: "11111111-1111-1111-1111-111111111111",
    organization_id: "org-1",
    internal_reference: "LOAD-001",
    external_load_reference: null,
    broker_or_customer_name: "Acme Brokerage",
    carrier_id: null,
    driver_name: null,
    driver_contact: null,
    pickup_location: null,
    delivery_location: null,
    pickup_appointment: null,
    delivery_appointment: null,
    actual_arrival_at: null,
    actual_departure_at: null,
    base_rate: null,
    currency: "USD",
    status: "delivered",
    status_label: "Delivered",
    notes: null,
    carrier: null,
    created_by: null,
    events_count: 1,
    evidence_count: 0,
    created_at: "2026-11-10T10:00:00Z",
    updated_at: "2026-11-10T10:00:00Z",
  },
  {
    id: "22222222-2222-2222-2222-222222222222",
    organization_id: "org-1",
    internal_reference: "LOAD-002",
    external_load_reference: null,
    broker_or_customer_name: "Beta Logistics",
    carrier_id: null,
    driver_name: null,
    driver_contact: null,
    pickup_location: null,
    delivery_location: null,
    pickup_appointment: null,
    delivery_appointment: null,
    actual_arrival_at: null,
    actual_departure_at: null,
    base_rate: null,
    currency: "USD",
    status: "completed",
    status_label: "Completed",
    notes: null,
    carrier: null,
    created_by: null,
    events_count: 0,
    evidence_count: 0,
    created_at: "2026-11-11T10:00:00Z",
    updated_at: "2026-11-11T10:00:00Z",
  },
];

const EVENTS: ShipmentEvent[] = [
  {
    id: "55555555-5555-5555-5555-555555555555",
    organization_id: "org-1",
    shipment_id: "11111111-1111-1111-1111-111111111111",
    event_category_id: "22222222-2222-2222-2222-222222222222",
    category: { id: "22222222-2222-2222-2222-222222222222", slug: "layover", label: "Layover" },
    event_date: "2026-11-12",
    start_time: null,
    end_time: null,
    duration_minutes: null,
    location: null,
    description: "Overnight wait at the receiver.",
    estimated_amount: "300.00",
    currency: "USD",
    notes: null,
    status: "potential",
    status_label: "Potential",
    reported_by: null,
    created_at: "2026-11-12T10:00:00Z",
    updated_at: "2026-11-12T10:00:00Z",
  },
];

function baseClaim(overrides: Partial<Claim> = {}): Claim {
  return {
    id: "claim-1",
    organization_id: "org-1",
    claim_reference: "CLM-2026-0001",
    shipment_id: "11111111-1111-1111-1111-111111111111",
    shipment: {
      id: "11111111-1111-1111-1111-111111111111",
      internal_reference: "LOAD-001",
      broker_or_customer_name: "Acme Brokerage",
      status: "delivered",
    },
    shipment_event_id: null,
    event: null,
    claim_category: "detention",
    claim_category_label: "Detention",
    description: "Waited six hours at the dock.",
    requested_amount: "250.00",
    accepted_amount: null,
    recovered_amount: null,
    currency: "USD",
    internal_notes: null,
    external_broker_reference: null,
    submission_deadline_at: null,
    submitted_at: null,
    approved_at: null,
    rejected_at: null,
    paid_at: null,
    rejection_reason: null,
    payment_reference: null,
    status: "potential",
    status_label: "Potential",
    allowed_transitions: [],
    created_by: null,
    updated_by: null,
    evidence_count: 0,
    active_deadline: null,
    latest_document: null,
    created_at: "2026-11-13T10:00:00Z",
    updated_at: "2026-11-13T10:00:00Z",
    ...overrides,
  };
}

function renderForm(overrides: Partial<Parameters<typeof ClaimForm>[0]> = {}) {
  const onSubmit = vi.fn(async () => undefined);
  const onCancel = vi.fn();
  render(
    <ClaimForm
      orgId="org-1"
      shipments={SHIPMENTS}
      submitting={false}
      serverErrors={{}}
      submitLabel="Create claim"
      onCancel={onCancel}
      onSubmit={onSubmit}
      {...overrides}
    />,
  );
  return { onSubmit, onCancel };
}

describe("ClaimForm", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockedApiFetch.mockResolvedValue({ data: [] });
  });

  it("shows validation errors instead of submitting an empty form", async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm();
    await user.click(screen.getByRole("button", { name: "Create claim" }));
    expect(
      screen.getByText("Choose a shipment for this claim."),
    ).toBeInTheDocument();
    expect(screen.getByText("Requested amount is required.")).toBeInTheDocument();
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it("rejects a requested amount of zero", async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm();
    await user.selectOptions(screen.getByLabelText("Shipment"), "11111111-1111-1111-1111-111111111111");
    await user.type(screen.getByLabelText("Requested amount"), "0");
    await user.click(screen.getByRole("button", { name: "Create claim" }));
    expect(
      screen.getByText("Requested amount must be greater than 0."),
    ).toBeInTheDocument();
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it("submits the filled form values", async () => {
    const user = userEvent.setup();
    const { onSubmit } = renderForm();
    await user.selectOptions(screen.getByLabelText("Shipment"), "11111111-1111-1111-1111-111111111111");
    await user.type(screen.getByLabelText("Requested amount"), "250.00");
    await user.type(
      screen.getByLabelText("Description"),
      "Waited six hours at the dock.",
    );
    await user.click(screen.getByRole("button", { name: "Create claim" }));

    expect(onSubmit).toHaveBeenCalledTimes(1);
    expect(onSubmit).toHaveBeenCalledWith({
      shipment_id: "11111111-1111-1111-1111-111111111111",
      shipment_event_id: null,
      claim_category: "other",
      description: "Waited six hours at the dock.",
      requested_amount: "250.00",
      currency: "USD",
      internal_notes: null,
      external_broker_reference: null,
      submission_deadline_at: null,
    });
  });

  it("defaults the category from the chosen event", async () => {
    const user = userEvent.setup();
    mockedApiFetch.mockResolvedValue({ data: EVENTS });
    renderForm();
    await user.selectOptions(screen.getByLabelText("Shipment"), "11111111-1111-1111-1111-111111111111");
    await waitFor(() => {
      expect(
        screen.getByRole("option", { name: /Layover on/ }),
      ).toBeInTheDocument();
    });
    await user.selectOptions(screen.getByLabelText("Linked event (optional)"), "55555555-5555-5555-5555-555555555555");
    expect(screen.getByLabelText("Category")).toHaveValue("layover");
  });

  it("narrows the shipment list with the search box", async () => {
    const user = userEvent.setup();
    renderForm();
    await user.type(screen.getByLabelText("Search shipments"), "Beta");
    expect(
      screen.queryByRole("option", { name: /LOAD-001/ }),
    ).not.toBeInTheDocument();
    expect(
      screen.getByRole("option", { name: /LOAD-002/ }),
    ).toBeInTheDocument();
  });

  it("calls onCancel when Cancel is clicked", async () => {
    const user = userEvent.setup();
    const { onCancel } = renderForm();
    await user.click(screen.getByRole("button", { name: "Cancel" }));
    expect(onCancel).toHaveBeenCalledTimes(1);
  });

  it("prefills the form when editing and freezes fields past submission", async () => {
    renderForm({
      initial: baseClaim({ status: "submitted", status_label: "Submitted" }),
      submitLabel: "Save claim",
    });
    expect(screen.getByLabelText("Requested amount")).toHaveValue("250.00");
    expect(screen.getByLabelText("Requested amount")).toBeDisabled();
    expect(screen.getByLabelText("Currency")).toBeDisabled();
    expect(screen.getByLabelText("Category")).toBeDisabled();
    expect(screen.getByLabelText("Description")).not.toBeDisabled();
    expect(
      screen.getByText(/are frozen and can no longer be changed/),
    ).toBeInTheDocument();
  });

  it("keeps fields editable before submission", async () => {
    renderForm({
      initial: baseClaim({ status: "under_review", status_label: "Under Review" }),
      submitLabel: "Save claim",
    });
    expect(screen.getByLabelText("Requested amount")).not.toBeDisabled();
    expect(screen.getByLabelText("Category")).not.toBeDisabled();
  });
});
