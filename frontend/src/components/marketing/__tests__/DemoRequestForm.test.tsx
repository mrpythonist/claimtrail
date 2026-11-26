import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { DemoRequestForm } from "../DemoRequestForm";
import { apiFetch } from "@/lib/api";

vi.mock("@/lib/api", () => {
  class ApiRequestError extends Error {
    status: number;
    fields: Record<string, string[]>;
    body: { message: string };
    constructor(status: number, body: { message: string }) {
      super(body.message);
      this.status = status;
      this.fields = {};
      this.body = body;
    }
  }
  return { apiFetch: vi.fn(), ApiRequestError };
});

vi.mock("@/lib/analytics", () => ({ trackEvent: vi.fn() }));

const mockedApiFetch = vi.mocked(apiFetch);

beforeEach(() => {
  mockedApiFetch.mockReset();
  mockedApiFetch.mockResolvedValue({ data: { id: "lead-1" } });
});

describe("DemoRequestForm", () => {
  it("submits a demo request and shows the success message", async () => {
    const user = userEvent.setup();
    render(<DemoRequestForm />);

    await user.type(screen.getByLabelText("Your name"), "Dana Whitfield");
    await user.type(screen.getByLabelText("Work email"), "dana@example.com");
    await user.type(screen.getByLabelText("Company"), "Prairie Line Transport");
    await user.selectOptions(screen.getByLabelText("Fleet size"), "3-10 trucks");
    await user.click(screen.getByRole("button", { name: "Request a demo" }));

    await waitFor(() => {
      expect(mockedApiFetch).toHaveBeenCalledWith(
        "/leads",
        expect.objectContaining({
          method: "POST",
          withOrg: false,
          body: expect.objectContaining({
            name: "Dana Whitfield",
            email: "dana@example.com",
            company: "Prairie Line Transport",
            fleet_size: "3-10 trucks",
            source: "demo_request",
          }),
        }),
      );
    });
    expect(
      await screen.findByText(/Your demo request is in/),
    ).toBeInTheDocument();
  });

  it("shows client-side errors for a missing name and a bad email", async () => {
    const user = userEvent.setup();
    render(<DemoRequestForm />);

    await user.type(screen.getByLabelText("Work email"), "not-an-email");
    await user.click(screen.getByRole("button", { name: "Request a demo" }));

    expect(await screen.findByText("Your name is required.")).toBeInTheDocument();
    expect(
      screen.getByText("Enter a valid email address."),
    ).toBeInTheDocument();
    expect(mockedApiFetch).not.toHaveBeenCalled();
  });

  it("shows a server-side error without losing the form", async () => {
    const user = userEvent.setup();
    mockedApiFetch.mockRejectedValue(
      new Error("Could not reach the server."),
    );
    render(<DemoRequestForm />);

    await user.type(screen.getByLabelText("Your name"), "Dana Whitfield");
    await user.type(screen.getByLabelText("Work email"), "dana@example.com");
    await user.click(screen.getByRole("button", { name: "Request a demo" }));

    expect(
      await screen.findByText("Something went wrong. Please try again."),
    ).toBeInTheDocument();
  });
});
