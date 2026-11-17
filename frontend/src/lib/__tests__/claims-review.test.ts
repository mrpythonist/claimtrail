import { describe, expect, it } from "vitest";
import { reviewNextAction } from "@/lib/claims";

describe("reviewNextAction", () => {
  it("says the claim cannot be decided when it is not under review", () => {
    const text = reviewNextAction("submitted", 100, 0);
    expect(text).toContain("not under review");
  });

  it("names the missing documents when readiness is incomplete", () => {
    const text = reviewNextAction("under_review", 50, 2);
    expect(text).toContain("50%");
    expect(text).toContain("2 required documents are still missing");
  });

  it("says the claim is ready to approve when evidence is complete", () => {
    const text = reviewNextAction("under_review", 100, 0);
    expect(text).toContain("Evidence is complete");
    expect(text).toContain("Approve");
  });
});
