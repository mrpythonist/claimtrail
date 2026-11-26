import { afterEach, describe, expect, it } from "vitest";
import {
  clearSignupPlan,
  getSignupPlan,
  setSignupPlan,
} from "../signup-plan";

afterEach(() => {
  window.localStorage.clear();
});

describe("signup plan", () => {
  it("stores and reads back a listed plan", () => {
    setSignupPlan("growth");
    expect(getSignupPlan()).toBe("growth");
  });

  it("ignores an unknown plan key", () => {
    setSignupPlan("platinum");
    expect(getSignupPlan()).toBeNull();
    expect(window.localStorage.getItem("claimtrail_signup_plan")).toBeNull();
  });

  it("ignores a stored value that is not a listed plan", () => {
    window.localStorage.setItem("claimtrail_signup_plan", "bogus");
    expect(getSignupPlan()).toBeNull();
  });

  it("clears the stored plan", () => {
    setSignupPlan("starter");
    clearSignupPlan();
    expect(getSignupPlan()).toBeNull();
  });
});
