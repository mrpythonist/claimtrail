import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ReviewNotes } from "../ReviewNotes";
import type { ReviewNote } from "@/lib/types";

vi.mock("@/lib/api", () => {
  class ApiRequestError extends Error {
    status: number;
    fields: Record<string, string[]>;
    constructor(status: number, body: { message: string }) {
      super(body.message);
      this.status = status;
      this.fields = {};
    }
  }
  return { apiFetch: vi.fn(), ApiRequestError };
});

import { apiFetch } from "@/lib/api";

const mockedApiFetch = vi.mocked(apiFetch);

const NOTES: ReviewNote[] = [
  {
    id: "note-1",
    note: "Rate confirmation is missing.",
    author: { id: 1, name: "Amir" },
    created_at: "2026-11-16T10:00:00Z",
  },
];

beforeEach(() => {
  mockedApiFetch.mockReset();
  mockedApiFetch.mockImplementation(async (path: string) => {
    if (path.endsWith("/review-notes")) return { data: NOTES };
    throw new Error(`Unexpected call: ${path}`);
  });
});

describe("ReviewNotes", () => {
  it("lists notes fetched from the API", async () => {
    render(<ReviewNotes claimId="claim-1" orgId="org-1" canAdd />);
    expect(
      await screen.findByText("Rate confirmation is missing."),
    ).toBeInTheDocument();
    expect(screen.getByText(/Amir/)).toBeInTheDocument();
  });

  it("hides the add-note form when the user cannot add notes", async () => {
    render(<ReviewNotes claimId="claim-1" orgId="org-1" canAdd={false} />);
    await screen.findByText("Rate confirmation is missing.");
    expect(
      screen.queryByRole("button", { name: "Add note" }),
    ).not.toBeInTheDocument();
  });

  it("posts a new note and shows it at the top", async () => {
    const user = userEvent.setup();
    mockedApiFetch.mockImplementation(async (path: string, options?: unknown) => {
      const opts = (options ?? {}) as { method?: string; body?: unknown };
      if (path.endsWith("/review-notes") && opts.method !== "POST") {
        return { data: NOTES };
      }
      if (path.endsWith("/review-notes")) {
        const body = opts.body as { note: string };
        return {
          data: {
            id: "note-2",
            note: body.note,
            author: { id: 2, name: "Aqib" },
            created_at: "2026-11-16T11:00:00Z",
          },
        };
      }
      throw new Error(`Unexpected call: ${path}`);
    });
    render(<ReviewNotes claimId="claim-1" orgId="org-1" canAdd />);
    await screen.findByText("Rate confirmation is missing.");
    await user.type(
      screen.getByLabelText("Add a reviewer note"),
      "Checked the BOL, it matches.",
    );
    await user.click(screen.getByRole("button", { name: "Add note" }));
    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith("/claims/claim-1/review-notes", {
        method: "POST",
        body: { note: "Checked the BOL, it matches." },
        orgId: "org-1",
      });
    });
    expect(
      await screen.findByText("Checked the BOL, it matches."),
    ).toBeInTheDocument();
  });

  it("requires a note before posting", async () => {
    const user = userEvent.setup();
    render(<ReviewNotes claimId="claim-1" orgId="org-1" canAdd />);
    await screen.findByText("Rate confirmation is missing.");
    await user.click(screen.getByRole("button", { name: "Add note" }));
    expect(screen.getByText("Write a note first.")).toBeInTheDocument();
  });
});
