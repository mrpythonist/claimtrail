import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { ScreenshotGallery } from "../ScreenshotGallery";

describe("ScreenshotGallery", () => {
  it("renders a labelled placeholder when no capture exists", () => {
    render(<ScreenshotGallery available={{}} />);

    expect(screen.getAllByText("Screenshot placeholder")).toHaveLength(7);
    expect(
      screen.getByText(/A real capture of the dashboard screen/),
    ).toBeInTheDocument();
    expect(screen.queryByRole("img")).not.toBeInTheDocument();
  });

  it("renders the real image when a capture exists", () => {
    render(
      <ScreenshotGallery
        available={{ dashboard: true, billing: true }}
      />,
    );

    const images = screen.getAllByRole("img");
    expect(images).toHaveLength(2);
    expect(images[0]).toHaveAttribute("src", "/screenshots/dashboard.png");
    expect(images[1]).toHaveAttribute("src", "/screenshots/billing.png");
    expect(screen.getAllByText("Screenshot placeholder")).toHaveLength(5);
  });
});
