import "@testing-library/jest-dom/vitest";
import { cleanup } from "@testing-library/react";
import { afterEach } from "vitest";

// Testing Library's auto-cleanup needs a global afterEach, which vitest
// does not provide without globals enabled, so register it explicitly.
afterEach(() => {
  cleanup();
});
