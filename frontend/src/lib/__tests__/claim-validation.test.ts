import { describe, expect, it } from "vitest";
import {
  validateClaimForm,
  validateDeadlineForm,
  validateEvidenceRequirementForm,
  validateRecordPaymentForm,
  validateTransitionForm,
} from "../validation";

describe("validateClaimForm", () => {
  const valid = {
    shipment_id: "shipment-1",
    requested_amount: "250.00",
    currency: "USD",
  };

  it("accepts a valid claim form", () => {
    expect(validateClaimForm(valid)).toEqual({});
  });

  it("requires a shipment", () => {
    const errors = validateClaimForm({ ...valid, shipment_id: "" });
    expect(errors.shipment_id).toBe("Choose a shipment for this claim.");
  });

  it("requires a requested amount", () => {
    const errors = validateClaimForm({ ...valid, requested_amount: "  " });
    expect(errors.requested_amount).toBe("Requested amount is required.");
  });

  it("rejects a requested amount of zero", () => {
    const errors = validateClaimForm({ ...valid, requested_amount: "0" });
    expect(errors.requested_amount).toBe(
      "Requested amount must be greater than 0.",
    );
  });

  it("rejects a negative requested amount", () => {
    const errors = validateClaimForm({ ...valid, requested_amount: "-50" });
    expect(errors.requested_amount).toBe(
      "Requested amount must be greater than 0.",
    );
  });

  it("rejects a non-numeric requested amount", () => {
    const errors = validateClaimForm({ ...valid, requested_amount: "abc" });
    expect(errors.requested_amount).toBe(
      "Requested amount must be greater than 0.",
    );
  });

  it("rejects a currency that is not a 3-letter code", () => {
    const errors = validateClaimForm({ ...valid, currency: "US" });
    expect(errors.currency).toBe(
      "Currency must be a 3-letter code, for example USD.",
    );
  });
});

describe("validateRecordPaymentForm", () => {
  it("accepts a valid payment form", () => {
    expect(
      validateRecordPaymentForm({
        recovered_amount: "200.00",
        payment_date: "2026-11-16",
      }),
    ).toEqual({});
  });

  it("requires a recovered amount greater than zero", () => {
    const errors = validateRecordPaymentForm({
      recovered_amount: "0",
      payment_date: "2026-11-16",
    });
    expect(errors.recovered_amount).toBe(
      "Recovered amount must be greater than 0.",
    );
  });

  it("requires a payment date", () => {
    const errors = validateRecordPaymentForm({
      recovered_amount: "200.00",
      payment_date: "",
    });
    expect(errors.payment_date).toBe("Payment date is required.");
  });
});

describe("validateDeadlineForm", () => {
  it("accepts a valid deadline form", () => {
    expect(
      validateDeadlineForm({
        deadline_at: "2026-11-30T17:00",
        timezone: "America/Chicago",
      }),
    ).toEqual({});
  });

  it("requires a deadline date and time", () => {
    const errors = validateDeadlineForm({
      deadline_at: "",
      timezone: "UTC",
    });
    expect(errors.deadline_at).toBe("Deadline date and time are required.");
  });

  it("requires a timezone", () => {
    const errors = validateDeadlineForm({
      deadline_at: "2026-11-30T17:00",
      timezone: "",
    });
    expect(errors.timezone).toBe("Timezone is required.");
  });
});

describe("validateEvidenceRequirementForm", () => {
  it("accepts a valid requirement form", () => {
    expect(
      validateEvidenceRequirementForm({ document_category: "bill_of_lading" }),
    ).toEqual({});
  });

  it("requires a document category", () => {
    const errors = validateEvidenceRequirementForm({ document_category: "" });
    expect(errors.document_category).toBe("Document category is required.");
  });
});

describe("validateTransitionForm", () => {
  it("accepts a plain transition with no extra data", () => {
    expect(
      validateTransitionForm({
        to_status: "under_review",
        accepted_amount: "",
        requested_amount: "250.00",
        rejection_reason: "",
        reason: "",
      }),
    ).toEqual({});
  });

  it("requires an accepted amount when accepting", () => {
    const errors = validateTransitionForm({
      to_status: "accepted",
      accepted_amount: "",
      requested_amount: "250.00",
      rejection_reason: "",
      reason: "",
    });
    expect(errors.accepted_amount).toBe("Accepted amount is required.");
  });

  it("rejects a partially accepted amount at or above the requested amount", () => {
    const errors = validateTransitionForm({
      to_status: "partially_accepted",
      accepted_amount: "250.00",
      requested_amount: "250.00",
      rejection_reason: "",
      reason: "",
    });
    expect(errors.accepted_amount).toBe(
      "A partially accepted amount must be less than the requested amount.",
    );
  });

  it("accepts a partially accepted amount below the requested amount", () => {
    expect(
      validateTransitionForm({
        to_status: "partially_accepted",
        accepted_amount: "150.00",
        requested_amount: "250.00",
        rejection_reason: "",
        reason: "",
      }),
    ).toEqual({});
  });

  it("requires a rejection reason when rejecting", () => {
    const errors = validateTransitionForm({
      to_status: "rejected",
      accepted_amount: "",
      requested_amount: "250.00",
      rejection_reason: "  ",
      reason: "",
    });
    expect(errors.rejection_reason).toBe("A rejection reason is required.");
  });

  it("requires a reason when closing", () => {
    const errors = validateTransitionForm({
      to_status: "closed",
      accepted_amount: "",
      requested_amount: "250.00",
      rejection_reason: "",
      reason: "",
    });
    expect(errors.reason).toBe("A reason is required to close a claim.");
  });
});
