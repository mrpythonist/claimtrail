import { describe, expect, it } from "vitest";
import {
  validateEmailField,
  validateLoginForm,
  validateRegisterForm,
} from "../validation";

describe("validateLoginForm", () => {
  it("accepts a valid login form", () => {
    expect(
      validateLoginForm({ email: "user@example.com", password: "password123" }),
    ).toEqual({});
  });

  it("requires an email address", () => {
    const errors = validateLoginForm({ email: "", password: "password123" });
    expect(errors.email).toBe("Email is required.");
  });

  it("rejects a malformed email address", () => {
    const errors = validateLoginForm({ email: "not-an-email", password: "password123" });
    expect(errors.email).toBe("Enter a valid email address.");
  });

  it("requires a password", () => {
    const errors = validateLoginForm({ email: "user@example.com", password: "" });
    expect(errors.password).toBe("Password is required.");
  });
});

describe("validateRegisterForm", () => {
  const valid = {
    name: "Test User",
    email: "user@example.com",
    password: "password123",
    password_confirmation: "password123",
  };

  it("accepts a valid registration form", () => {
    expect(validateRegisterForm(valid)).toEqual({});
  });

  it("requires a name", () => {
    const errors = validateRegisterForm({ ...valid, name: "  " });
    expect(errors.name).toBe("Name is required.");
  });

  it("requires a password of at least 8 characters", () => {
    const errors = validateRegisterForm({
      ...valid,
      password: "short",
      password_confirmation: "short",
    });
    expect(errors.password).toBe("Password must be at least 8 characters.");
  });

  it("requires the password confirmation to match", () => {
    const errors = validateRegisterForm({
      ...valid,
      password_confirmation: "different123",
    });
    expect(errors.password_confirmation).toBe("Passwords do not match.");
  });
});

describe("validateEmailField", () => {
  it("accepts a valid email", () => {
    expect(validateEmailField("user@example.com")).toBeUndefined();
  });

  it("rejects an empty value", () => {
    expect(validateEmailField("")).toBe("Email is required.");
  });

  it("rejects a malformed email", () => {
    expect(validateEmailField("user@")).toBe("Enter a valid email address.");
  });
});
