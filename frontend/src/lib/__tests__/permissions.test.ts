import { describe, expect, it } from "vitest";
import {
  canExportClaims,
  canExportReports,
  canManageCustomization,
  canViewAuditLogs,
  canViewOperations,
  canViewReports,
} from "../permissions";
import type { OrganizationRole } from "../types";

const ROLES: OrganizationRole[] = [
  "owner",
  "admin",
  "operations_manager",
  "dispatcher",
  "billing_staff",
  "viewer",
];

describe("canViewReports", () => {
  it("allows owner, admin, operations manager, billing staff, and viewer", () => {
    expect(
      ROLES.filter((role) => canViewReports(role)),
    ).toEqual([
      "owner",
      "admin",
      "operations_manager",
      "billing_staff",
      "viewer",
    ]);
  });

  it("denies dispatchers and missing roles", () => {
    expect(canViewReports("dispatcher")).toBe(false);
    expect(canViewReports(null)).toBe(false);
    expect(canViewReports(undefined)).toBe(false);
  });
});

describe("canExportReports", () => {
  it("allows owner, admin, operations manager, and billing staff", () => {
    expect(
      ROLES.filter((role) => canExportReports(role)),
    ).toEqual([
      "owner",
      "admin",
      "operations_manager",
      "billing_staff",
    ]);
  });

  it("denies viewers and dispatchers: viewers can read reports but not export", () => {
    expect(canExportReports("viewer")).toBe(false);
    expect(canExportReports("dispatcher")).toBe(false);
    expect(canExportReports(null)).toBe(false);
  });
});

describe("canViewAuditLogs", () => {
  it("allows owner, admin, and operations manager only", () => {
    expect(
      ROLES.filter((role) => canViewAuditLogs(role)),
    ).toEqual(["owner", "admin", "operations_manager"]);
  });

  it("denies billing staff, dispatchers, viewers, and missing roles", () => {
    expect(canViewAuditLogs("billing_staff")).toBe(false);
    expect(canViewAuditLogs("dispatcher")).toBe(false);
    expect(canViewAuditLogs("viewer")).toBe(false);
    expect(canViewAuditLogs(null)).toBe(false);
    expect(canViewAuditLogs(undefined)).toBe(false);
  });
});

describe("canManageCustomization", () => {
  it("allows owner, admin, and operations manager only", () => {
    expect(ROLES.filter((role) => canManageCustomization(role))).toEqual([
      "owner",
      "admin",
      "operations_manager",
    ]);
  });

  it("denies billing staff, dispatchers, viewers, and missing roles", () => {
    expect(canManageCustomization("billing_staff")).toBe(false);
    expect(canManageCustomization("dispatcher")).toBe(false);
    expect(canManageCustomization("viewer")).toBe(false);
    expect(canManageCustomization(null)).toBe(false);
    expect(canManageCustomization(undefined)).toBe(false);
  });
});

describe("canExportClaims", () => {
  it("allows owner, admin, operations manager, and billing staff", () => {
    expect(ROLES.filter((role) => canExportClaims(role))).toEqual([
      "owner",
      "admin",
      "operations_manager",
      "billing_staff",
    ]);
  });

  it("denies viewers and dispatchers", () => {
    expect(canExportClaims("viewer")).toBe(false);
    expect(canExportClaims("dispatcher")).toBe(false);
    expect(canExportClaims(null)).toBe(false);
  });
});

describe("canViewOperations", () => {
  it("allows owner and admin only", () => {
    expect(
      ROLES.filter((role) => canViewOperations(role)),
    ).toEqual(["owner", "admin"]);
  });

  it("denies operations managers, billing staff, dispatchers, viewers, and missing roles", () => {
    expect(canViewOperations("operations_manager")).toBe(false);
    expect(canViewOperations("billing_staff")).toBe(false);
    expect(canViewOperations("dispatcher")).toBe(false);
    expect(canViewOperations("viewer")).toBe(false);
    expect(canViewOperations(null)).toBe(false);
    expect(canViewOperations(undefined)).toBe(false);
  });
});
