import { describe, expect, it } from "vitest";
import {
  bulkClaimTargetNeeds,
  bulkResultSummary,
  nonEmptyFilters,
  toggleAllOnPage,
  toggleId,
} from "@/lib/bulk";

describe("toggleId", () => {
  it("adds an id that is not selected and removes one that is", () => {
    expect(toggleId([], "a")).toEqual(["a"]);
    expect(toggleId(["a", "b"], "a")).toEqual(["b"]);
  });
});

describe("toggleAllOnPage", () => {
  it("selects every page id when not all are selected", () => {
    expect(toggleAllOnPage(["a"], ["a", "b"])).toEqual(["a", "b"]);
  });

  it("clears only the page ids when all are selected", () => {
    expect(toggleAllOnPage(["a", "b", "c"], ["a", "b"])).toEqual(["c"]);
  });

  it("does nothing on an empty page", () => {
    expect(toggleAllOnPage(["a"], [])).toEqual(["a"]);
  });
});

describe("bulkResultSummary", () => {
  it("reports updated count only when nothing failed", () => {
    expect(bulkResultSummary(3, [], "shipments")).toBe("3 shipments updated.");
  });

  it("includes failure reasons", () => {
    const summary = bulkResultSummary(
      1,
      [{ id: "x", reason: "Already archived." }],
      "claims",
    );
    expect(summary).toContain("1 claims updated.");
    expect(summary).toContain("1 failed.");
    expect(summary).toContain("Already archived.");
  });
});

describe("nonEmptyFilters", () => {
  it("drops empty values and keeps the rest", () => {
    expect(
      nonEmptyFilters({ search: "acme", status: "", carrier_id: "c1" }),
    ).toEqual({ search: "acme", carrier_id: "c1" });
  });
});

describe("bulkClaimTargetNeeds", () => {
  it("needs an accepted amount for accepted and partially accepted", () => {
    expect(bulkClaimTargetNeeds("accepted")).toEqual({
      acceptedAmount: true,
      reason: false,
    });
    expect(bulkClaimTargetNeeds("partially_accepted").acceptedAmount).toBe(true);
  });

  it("needs a reason for rejected and closed", () => {
    expect(bulkClaimTargetNeeds("rejected")).toEqual({
      acceptedAmount: false,
      reason: true,
    });
    expect(bulkClaimTargetNeeds("closed").reason).toBe(true);
  });

  it("needs nothing extra for other targets", () => {
    expect(bulkClaimTargetNeeds("submitted")).toEqual({
      acceptedAmount: false,
      reason: false,
    });
  });
});
