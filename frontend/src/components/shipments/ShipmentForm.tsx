"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Alert } from "@/components/ui/Alert";
import { Button } from "@/components/ui/Button";
import { Input, Select, Textarea } from "@/components/ui/Input";
import { CustomFieldInputs } from "@/components/customization/CustomFieldInputs";
import { apiFetch, ApiRequestError } from "@/lib/api";
import {
  cleanCustomFieldValues,
  getCustomFields,
  validateCustomFieldValues,
} from "@/lib/customization";
import { blankToNull, inputValueToIso, isoToInputValue } from "@/lib/shipments";
import type { FieldErrors } from "@/lib/validation";
import { validateShipmentForm } from "@/lib/validation";
import type {
  Carrier,
  CustomField,
  CustomFieldValues,
  Shipment,
  ShipmentStatus,
} from "@/lib/types";

export interface ShipmentPayload {
  internal_reference: string;
  external_load_reference: string | null;
  broker_or_customer_name: string;
  carrier_id: string | null;
  driver_name: string | null;
  driver_contact: string | null;
  pickup_location: string | null;
  delivery_location: string | null;
  pickup_appointment: string | null;
  delivery_appointment: string | null;
  actual_arrival_at: string | null;
  actual_departure_at: string | null;
  base_rate: string | null;
  currency: string;
  status?: ShipmentStatus;
  notes: string | null;
  custom_field_values?: CustomFieldValues;
}

interface ShipmentFormProps {
  orgId: string;
  carriers: Carrier[];
  /** Prefilled shipment when editing; omit when creating. */
  initial?: Shipment | null;
  submitting: boolean;
  /** Field errors returned by the server (422), keyed by field name. */
  serverErrors: FieldErrors;
  submitLabel: string;
  onCarrierAdded: (carrier: Carrier) => void;
  onSubmit: (payload: ShipmentPayload) => Promise<void>;
}

const CREATE_STATUSES: ShipmentStatus[] = ["draft", "planned"];

/**
 * Shared create/edit form for shipments. The parent owns the carriers
 * list and the submit call; this form owns field state, client-side
 * validation, and the inline add-carrier form.
 */
export function ShipmentForm({
  orgId,
  carriers,
  initial = null,
  submitting,
  serverErrors,
  submitLabel,
  onCarrierAdded,
  onSubmit,
}: ShipmentFormProps) {
  const isEdit = initial !== null;

  const [internalReference, setInternalReference] = useState(
    initial?.internal_reference ?? "",
  );
  const [externalLoadReference, setExternalLoadReference] = useState(
    initial?.external_load_reference ?? "",
  );
  const [brokerOrCustomerName, setBrokerOrCustomerName] = useState(
    initial?.broker_or_customer_name ?? "",
  );
  const [carrierId, setCarrierId] = useState(initial?.carrier_id ?? "");
  const [driverName, setDriverName] = useState(initial?.driver_name ?? "");
  const [driverContact, setDriverContact] = useState(initial?.driver_contact ?? "");
  const [pickupLocation, setPickupLocation] = useState(initial?.pickup_location ?? "");
  const [deliveryLocation, setDeliveryLocation] = useState(
    initial?.delivery_location ?? "",
  );
  const [pickupAppointment, setPickupAppointment] = useState(
    isoToInputValue(initial?.pickup_appointment ?? null),
  );
  const [deliveryAppointment, setDeliveryAppointment] = useState(
    isoToInputValue(initial?.delivery_appointment ?? null),
  );
  const [actualArrivalAt, setActualArrivalAt] = useState(
    isoToInputValue(initial?.actual_arrival_at ?? null),
  );
  const [actualDepartureAt, setActualDepartureAt] = useState(
    isoToInputValue(initial?.actual_departure_at ?? null),
  );
  const [baseRate, setBaseRate] = useState(initial?.base_rate ?? "");
  const [currency, setCurrency] = useState(initial?.currency ?? "USD");
  const [status, setStatus] = useState<ShipmentStatus>(initial?.status ?? "draft");
  const [notes, setNotes] = useState(initial?.notes ?? "");
  const [errors, setErrors] = useState<FieldErrors>({});
  const [customFields, setCustomFields] = useState<CustomField[]>([]);
  const [customValues, setCustomValues] = useState<CustomFieldValues>(
    initial?.custom_field_values ?? {},
  );

  // Custom field definitions are optional: a failed fetch leaves
  // the form working exactly as before.
  useEffect(() => {
    let cancelled = false;
    getCustomFields(orgId, "shipment")
      .then((fields) => {
        if (!cancelled) setCustomFields(fields);
      })
      .catch(() => {
        if (!cancelled) setCustomFields([]);
      });
    return () => {
      cancelled = true;
    };
  }, [orgId]);

  // Inline add-carrier form state.
  const [showCarrierForm, setShowCarrierForm] = useState(false);
  const [newCarrierName, setNewCarrierName] = useState("");
  const [newCarrierContact, setNewCarrierContact] = useState("");
  const [newCarrierDot, setNewCarrierDot] = useState("");
  const [carrierError, setCarrierError] = useState<string | null>(null);
  const [savingCarrier, setSavingCarrier] = useState(false);

  function fieldError(field: string): string | undefined {
    return errors[field] ?? serverErrors[field];
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    const formErrors = validateShipmentForm({
      internal_reference: internalReference,
      broker_or_customer_name: brokerOrCustomerName,
      base_rate: baseRate,
      currency,
    });
    Object.assign(
      formErrors,
      validateCustomFieldValues(customFields, customValues),
    );
    setErrors(formErrors);
    if (Object.keys(formErrors).length > 0) return;

    const payload: ShipmentPayload = {
      internal_reference: internalReference.trim(),
      external_load_reference: blankToNull(externalLoadReference),
      broker_or_customer_name: brokerOrCustomerName.trim(),
      carrier_id: carrierId ? carrierId : null,
      driver_name: blankToNull(driverName),
      driver_contact: blankToNull(driverContact),
      pickup_location: blankToNull(pickupLocation),
      delivery_location: blankToNull(deliveryLocation),
      pickup_appointment: inputValueToIso(pickupAppointment),
      delivery_appointment: inputValueToIso(deliveryAppointment),
      actual_arrival_at: inputValueToIso(actualArrivalAt),
      actual_departure_at: inputValueToIso(actualDepartureAt),
      base_rate: baseRate.trim() ? baseRate.trim() : null,
      currency: currency.trim().toUpperCase() || "USD",
      notes: blankToNull(notes),
    };
    if (customFields.length > 0) {
      payload.custom_field_values = cleanCustomFieldValues(customValues);
    }
    // Status is only set at creation; after that it moves forward from
    // the shipment page.
    if (!isEdit) {
      payload.status = status;
    }
    await onSubmit(payload);
  }

  async function handleAddCarrier(event: FormEvent) {
    event.preventDefault();
    if (!newCarrierName.trim()) {
      setCarrierError("Carrier name is required.");
      return;
    }
    setCarrierError(null);
    setSavingCarrier(true);
    try {
      const response = await apiFetch<{ data: Carrier }>("/carriers", {
        method: "POST",
        body: {
          name: newCarrierName.trim(),
          contact_name: blankToNull(newCarrierContact),
          dot_number: blankToNull(newCarrierDot),
        },
        orgId,
      });
      onCarrierAdded(response.data);
      setCarrierId(String(response.data.id));
      setNewCarrierName("");
      setNewCarrierContact("");
      setNewCarrierDot("");
      setShowCarrierForm(false);
    } catch (err) {
      setCarrierError(
        err instanceof ApiRequestError
          ? err.fieldError("name") ?? err.message
          : "Could not add the carrier.",
      );
    } finally {
      setSavingCarrier(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-6" noValidate>
      <div className="grid gap-4 sm:grid-cols-2">
        <Input
          id="internal_reference"
          label="Internal reference"
          type="text"
          value={internalReference}
          onChange={(event) => setInternalReference(event.target.value)}
          error={fieldError("internal_reference")}
          placeholder="LOAD-2026-001"
          help="Your own reference for this load."
        />
        <Input
          id="external_load_reference"
          label="External load reference"
          type="text"
          value={externalLoadReference}
          onChange={(event) => setExternalLoadReference(event.target.value)}
          error={fieldError("external_load_reference")}
          placeholder="Broker load number, if any"
        />
        <Input
          id="broker_or_customer_name"
          label="Broker or customer name"
          type="text"
          value={brokerOrCustomerName}
          onChange={(event) => setBrokerOrCustomerName(event.target.value)}
          error={fieldError("broker_or_customer_name")}
        />
        <div>
          <Select
            id="carrier_id"
            label="Carrier"
            value={carrierId}
            onChange={(event) => setCarrierId(event.target.value)}
            error={fieldError("carrier_id")}
          >
            <option value="">No carrier assigned</option>
            {carriers.map((carrier) => (
              <option key={carrier.id} value={carrier.id}>
                {carrier.name}
              </option>
            ))}
          </Select>
          <button
            type="button"
            onClick={() => setShowCarrierForm((show) => !show)}
            className="mt-1 text-sm text-brand-700 hover:underline"
          >
            {showCarrierForm ? "Hide the new carrier form" : "Add a new carrier"}
          </button>
        </div>
      </div>

      {showCarrierForm && (
        <div className="space-y-4 rounded-md border border-slate-200 bg-slate-50 p-4">
          <p className="text-sm font-medium text-slate-900">New carrier</p>
          {carrierError && <Alert tone="error">{carrierError}</Alert>}
          <div className="grid gap-4 sm:grid-cols-3">
            <Input
              id="new-carrier-name"
              label="Name"
              type="text"
              value={newCarrierName}
              onChange={(event) => setNewCarrierName(event.target.value)}
            />
            <Input
              id="new-carrier-contact"
              label="Contact name"
              type="text"
              value={newCarrierContact}
              onChange={(event) => setNewCarrierContact(event.target.value)}
            />
            <Input
              id="new-carrier-dot"
              label="DOT number"
              type="text"
              value={newCarrierDot}
              onChange={(event) => setNewCarrierDot(event.target.value)}
            />
          </div>
          <Button type="button" loading={savingCarrier} onClick={handleAddCarrier}>
            Save carrier
          </Button>
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        <Input
          id="driver_name"
          label="Driver name"
          type="text"
          value={driverName}
          onChange={(event) => setDriverName(event.target.value)}
          error={fieldError("driver_name")}
        />
        <Input
          id="driver_contact"
          label="Driver contact"
          type="text"
          value={driverContact}
          onChange={(event) => setDriverContact(event.target.value)}
          error={fieldError("driver_contact")}
          placeholder="Phone or email"
        />
        <Input
          id="pickup_location"
          label="Pickup location"
          type="text"
          value={pickupLocation}
          onChange={(event) => setPickupLocation(event.target.value)}
          error={fieldError("pickup_location")}
        />
        <Input
          id="delivery_location"
          label="Delivery location"
          type="text"
          value={deliveryLocation}
          onChange={(event) => setDeliveryLocation(event.target.value)}
          error={fieldError("delivery_location")}
        />
        <Input
          id="pickup_appointment"
          label="Pickup appointment"
          type="datetime-local"
          value={pickupAppointment}
          onChange={(event) => setPickupAppointment(event.target.value)}
          error={fieldError("pickup_appointment")}
        />
        <Input
          id="delivery_appointment"
          label="Delivery appointment"
          type="datetime-local"
          value={deliveryAppointment}
          onChange={(event) => setDeliveryAppointment(event.target.value)}
          error={fieldError("delivery_appointment")}
        />
        <Input
          id="actual_arrival_at"
          label="Actual arrival"
          type="datetime-local"
          value={actualArrivalAt}
          onChange={(event) => setActualArrivalAt(event.target.value)}
          error={fieldError("actual_arrival_at")}
        />
        <Input
          id="actual_departure_at"
          label="Actual departure"
          type="datetime-local"
          value={actualDepartureAt}
          onChange={(event) => setActualDepartureAt(event.target.value)}
          error={fieldError("actual_departure_at")}
        />
        <Input
          id="base_rate"
          label="Base rate"
          type="text"
          inputMode="decimal"
          value={baseRate}
          onChange={(event) => setBaseRate(event.target.value)}
          error={fieldError("base_rate")}
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
        {isEdit ? (
          <div>
            <p className="mb-1 block text-sm font-medium text-slate-700">Status</p>
            <p className="rounded-md border border-slate-300 bg-slate-50 px-3 py-2 text-sm text-slate-700">
              {initial?.status_label}
            </p>
            <p className="mt-1 text-xs text-slate-500">
              Change the status from the shipment page.
            </p>
          </div>
        ) : (
          <Select
            id="status"
            label="Status"
            value={status}
            onChange={(event) => setStatus(event.target.value as ShipmentStatus)}
            error={fieldError("status")}
          >
            {CREATE_STATUSES.map((value) => (
              <option key={value} value={value}>
                {value === "draft" ? "Draft" : "Planned"}
              </option>
            ))}
          </Select>
        )}
      </div>

      <Textarea
        id="notes"
        label="Notes"
        rows={4}
        value={notes}
        onChange={(event) => setNotes(event.target.value)}
        error={fieldError("notes")}
      />

      <CustomFieldInputs
        fields={customFields}
        values={customValues}
        onChange={setCustomValues}
        errors={{ ...serverErrors, ...errors }}
      />

      <Button type="submit" loading={submitting}>
        {submitLabel}
      </Button>
    </form>
  );
}
