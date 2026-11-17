import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { SavedViews } from "../SavedViews";
import type { SavedView } from "@/lib/types";

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

const VIEWS: SavedView[] = [
  {
    id: "view-1",
    resource_type: "shipment",
    name: "Detention this month",
    filters: { event_type: "detention" },
    created_at: "2026-11-16T10:00:00Z",
    updated_at: "2026-11-16T10:00:00Z",
  },
];

function renderViews(onApply = vi.fn()) {
  render(
    <SavedViews
      resourceType="shipment"
      orgId="org-1"
      currentFilters={{ status: "delivered", search: "" }}
      onApply={onApply}
    />,
  );
  return { onApply };
}

beforeEach(() => {
  vi.mocked(mockedApiFetch).mockReset();
  mockedApiFetch.mockImplementation(async (path: string) => {
    if (path.startsWith("/saved-views?")) return { data: VIEWS };
    throw new Error(`Unexpected call: ${path}`);
  });
});

describe("SavedViews", () => {
  it("lists saved views fetched on mount", async () => {
    renderViews();
    expect(await screen.findByText("Detention this month")).toBeInTheDocument();
    expect(mockedApiFetch).toHaveBeenCalledWith(
      "/saved-views?resource_type=shipment",
      { orgId: "org-1" },
    );
  });

  it("applies a view's filters when its chip is clicked", async () => {
    const user = userEvent.setup();
    const { onApply } = renderViews();
    await user.click(await screen.findByText("Detention this month"));
    expect(onApply).toHaveBeenCalledWith({ event_type: "detention" });
  });

  it("saves the current non-empty filters with the given name", async () => {
    const user = userEvent.setup();
    mockedApiFetch.mockImplementation(async (path: string, options?: unknown) => {
      if (path.startsWith("/saved-views?")) return { data: [] };
      if (path === "/saved-views") {
        const body = (options as { body: unknown }).body as Record<string, unknown>;
        return {
          data: {
            id: "view-2",
            resource_type: "shipment",
            name: body.name,
            filters: body.filters,
            created_at: "2026-11-16T10:00:00Z",
            updated_at: "2026-11-16T10:00:00Z",
          },
        };
      }
      throw new Error(`Unexpected call: ${path}`);
    });
    renderViews();
    await user.type(screen.getByLabelText("Save current view"), "My view");
    await user.click(screen.getByRole("button", { name: "Save view" }));
    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith("/saved-views", {
        method: "POST",
        body: {
          name: "My view",
          resource_type: "shipment",
          filters: { status: "delivered" },
        },
        orgId: "org-1",
      });
    });
    expect(await screen.findByText("My view")).toBeInTheDocument();
  });

  it("requires a name before saving", async () => {
    const user = userEvent.setup();
    renderViews();
    await user.click(screen.getByRole("button", { name: "Save view" }));
    expect(screen.getByText("Give the view a name first.")).toBeInTheDocument();
    expect(mockedApiFetch).not.toHaveBeenCalledWith(
      "/saved-views",
      expect.anything(),
    );
  });

  it("deletes a view when its delete button is clicked", async () => {
    const user = userEvent.setup();
    mockedApiFetch.mockImplementation(async (path: string) => {
      if (path.startsWith("/saved-views?")) return { data: VIEWS };
      if (path === "/saved-views/view-1") return { message: "Deleted." };
      throw new Error(`Unexpected call: ${path}`);
    });
    renderViews();
    await user.click(
      await screen.findByRole("button", { name: "Delete view Detention this month" }),
    );
    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith("/saved-views/view-1", {
        method: "DELETE",
        orgId: "org-1",
      });
    });
    expect(screen.queryByText("Detention this month")).not.toBeInTheDocument();
  });
});
