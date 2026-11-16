import { describe, expect, it } from "vitest";
import { validateEventForm, validateShipmentForm } from "../validation";

describe("validateShipmentForm", () => {
  const valid = {
    internal_reference: "LOAD-2026-001",
    broker_or_customer_name: "Acme Brokerage",
    base_rate: "1500.00",
    currency: "USD",
  };

  it("accepts a valid shipment form", () => {
    expect(validateShipmentForm(valid)).toEqual({});
  });

  it("accepts a form with optional fields blank", () => {
    expect(
      validateShipmentForm({
        ...valid,
        base_rate: "",
        currency: "",
      }),
    ).toEqual({});
  });

  it("requires an internal reference", () => {
    const errors = validateShipmentForm({ ...valid, internal_reference: "  " });
    expect(errors.internal_reference).toBe("Internal reference is required.");
  });

  it("requires a broker or customer name", () => {
    const errors = validateShipmentForm({
      ...valid,
      broker_or_customer_name: "",
    });
    expect(errors.broker_or_customer_name).toBe(
      "Broker or customer name is required.",
    );
  });

  it("rejects a non-numeric base rate", () => {
    const errors = validateShipmentForm({ ...valid, base_rate: "not a number" });
    expect(errors.base_rate).toBe("Base rate must be a number of 0 or more.");
  });

  it("rejects a negative base rate", () => {
    const errors = validateShipmentForm({ ...valid, base_rate: "-50" });
    expect(errors.base_rate).toBe("Base rate must be a number of 0 or more.");
  });

  it("accepts a zero base rate", () => {
    expect(validateShipmentForm({ ...valid, base_rate: "0" })).toEqual({});
  });

  it("rejects a currency that is not a 3-letter code", () => {
    const errors = validateShipmentForm({ ...valid, currency: "USDD" });
    expect(errors.currency).toBe(
      "Currency must be a 3-letter code, for example USD.",
    );
  });
});

describe("validateEventForm", () => {
  const valid = {
    event_category_id: "1",
    event_date: "2026-11-16",
    start_time: "08:00",
    end_time: "10:30",
    estimated_amount: "250.00",
  };

  it("accepts a valid event form", () => {
    expect(validateEventForm(valid)).toEqual({});
  });

  it("accepts a form with optional times and amount blank", () => {
    expect(
      validateEventForm({
        ...valid,
        start_time: "",
        end_time: "",
        estimated_amount: "",
      }),
    ).toEqual({});
  });

  it("requires an event category", () => {
    const errors = validateEventForm({ ...valid, event_category_id: "" });
    expect(errors.event_category_id).toBe("Event category is required.");
  });

  it("requires an event date", () => {
    const errors = validateEventForm({ ...valid, event_date: "" });
    expect(errors.event_date).toBe("Event date is required.");
  });

  it("rejects an end time before the start time", () => {
    const errors = validateEventForm({
      ...valid,
      start_time: "10:30",
      end_time: "08:00",
    });
    expect(errors.end_time).toBe("End time must be after the start time.");
  });

  it("rejects an end time equal to the start time", () => {
    const errors = validateEventForm({
      ...valid,
      start_time: "08:00",
      end_time: "08:00",
    });
    expect(errors.end_time).toBe("End time must be after the start time.");
  });

  it("rejects a non-numeric estimated amount", () => {
    const errors = validateEventForm({
      ...valid,
      estimated_amount: "a lot",
    });
    expect(errors.estimated_amount).toBe(
      "Estimated amount must be a number of 0 or more.",
    );
  });

  it("rejects a negative estimated amount", () => {
    const errors = validateEventForm({ ...valid, estimated_amount: "-10" });
    expect(errors.estimated_amount).toBe(
      "Estimated amount must be a number of 0 or more.",
    );
  });
});
