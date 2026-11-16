import { describe, expect, it } from "vitest";
import {
  barWidth,
  changedFields,
  fieldLabel,
  formatAuditValue,
  formatCurrencyAmount,
  formatMoneyRows,
  formatMonthLabel,
  notificationLinks,
} from "../reporting";

describe("formatCurrencyAmount", () => {
  it("shows the currency code next to the amount", () => {
    expect(formatCurrencyAmount("1250.00", "USD")).toBe("USD 1,250.00");
  });

  it("keeps currencies separate: same amount, different codes", () => {
    expect(formatCurrencyAmount("100.00", "USD")).not.toBe(
      formatCurrencyAmount("100.00", "CAD"),
    );
    expect(formatCurrencyAmount("100.00", "CAD")).toBe("CAD 100.00");
  });

  it("uppercases a lowercase currency code", () => {
    expect(formatCurrencyAmount("50.5", "usd")).toBe("USD 50.50");
  });

  it("formats large amounts with separators and two decimals", () => {
    expect(formatCurrencyAmount("1234567.89", "EUR")).toBe("EUR 1,234,567.89");
  });

  it("falls back to the raw string for an unparseable amount", () => {
    expect(formatCurrencyAmount("not-a-number", "USD")).toBe(
      "USD not-a-number",
    );
  });

  it("formats zero without dropping the decimals", () => {
    expect(formatCurrencyAmount("0.00", "USD")).toBe("USD 0.00");
  });
});

describe("formatMoneyRows", () => {
  it("formats each currency row on its own, never merged", () => {
    expect(
      formatMoneyRows([
        { currency: "USD", amount: "100.00" },
        { currency: "CAD", amount: "200.00" },
      ]),
    ).toEqual(["USD 100.00", "CAD 200.00"]);
  });

  it("returns an empty list for no rows", () => {
    expect(formatMoneyRows([])).toEqual([]);
  });
});

describe("barWidth", () => {
  it("gives the largest count a full bar", () => {
    expect(barWidth(10, 10)).toBe(100);
  });

  it("scales smaller counts against the largest", () => {
    expect(barWidth(5, 10)).toBe(50);
    expect(barWidth(1, 3)).toBe(33);
  });

  it("returns zero when there is nothing to compare against", () => {
    expect(barWidth(0, 0)).toBe(0);
    expect(barWidth(0, 10)).toBe(0);
    expect(barWidth(5, 0)).toBe(0);
  });
});

describe("formatMonthLabel", () => {
  it("turns a YYYY-MM month into a short label", () => {
    expect(formatMonthLabel("2026-11")).toBe("Nov 2026");
    expect(formatMonthLabel("2026-01")).toBe("Jan 2026");
  });

  it("passes anything else through unchanged", () => {
    expect(formatMonthLabel("not-a-month")).toBe("not-a-month");
  });
});

describe("changedFields", () => {
  it("lists fields present in either payload", () => {
    expect(
      changedFields(
        { status: "potential", requested_amount: "100.00" },
        { status: "submitted", accepted_amount: "80.00" },
      ),
    ).toEqual([
      { field: "status", before: "potential", after: "submitted" },
      { field: "requested_amount", before: "100.00", after: undefined },
      { field: "accepted_amount", before: undefined, after: "80.00" },
    ]);
  });

  it("handles a creation (no before payload)", () => {
    expect(changedFields(null, { status: "potential" })).toEqual([
      { field: "status", before: undefined, after: "potential" },
    ]);
  });

  it("handles a deletion (no after payload)", () => {
    expect(changedFields({ status: "potential" }, null)).toEqual([
      { field: "status", before: "potential", after: undefined },
    ]);
  });

  it("returns an empty list when both payloads are null", () => {
    expect(changedFields(null, null)).toEqual([]);
  });
});

describe("formatAuditValue", () => {
  it("renders scalars as-is", () => {
    expect(formatAuditValue("submitted")).toBe("submitted");
    expect(formatAuditValue(42)).toBe("42");
    expect(formatAuditValue(true)).toBe("true");
  });

  it("renders null and undefined as Not set", () => {
    expect(formatAuditValue(null)).toBe("Not set");
    expect(formatAuditValue(undefined)).toBe("Not set");
  });

  it("renders an empty string as Empty", () => {
    expect(formatAuditValue("")).toBe("Empty");
  });

  it("never renders structured values as raw blobs", () => {
    expect(formatAuditValue({ nested: "value" })).toBe("Changed");
    expect(formatAuditValue(["a", "b"])).toBe("Changed");
    expect(formatAuditValue({ nested: "value" })).not.toContain("nested");
  });
});

describe("fieldLabel", () => {
  it("turns snake_case into words", () => {
    expect(fieldLabel("requested_amount")).toBe("Requested Amount");
    expect(fieldLabel("status")).toBe("Status");
  });
});

describe("notificationLinks", () => {
  it("picks out string entity ids", () => {
    expect(
      notificationLinks({
        claim_id: "claim-1",
        shipment_id: "ship-2",
        deadline_id: "dead-3",
      }),
    ).toEqual({
      claimId: "claim-1",
      shipmentId: "ship-2",
      deadlineId: "dead-3",
    });
  });

  it("returns nulls for a null payload", () => {
    expect(notificationLinks(null)).toEqual({
      claimId: null,
      shipmentId: null,
      deadlineId: null,
    });
  });

  it("ignores non-string or empty ids", () => {
    expect(
      notificationLinks({ claim_id: 42, shipment_id: "", deadline_id: null }),
    ).toEqual({
      claimId: null,
      shipmentId: null,
      deadlineId: null,
    });
  });
});
