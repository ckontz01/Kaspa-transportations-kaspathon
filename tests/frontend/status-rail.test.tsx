import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { StatusRail } from "@/components/status-rail";

describe("StatusRail", () => {
  it("marks the settled lifecycle as complete", () => {
    render(<StatusRail status="settled" />);
    const steps = screen.getAllByRole("listitem");

    expect(steps).toHaveLength(5);
    expect(steps.every((step) => step.dataset.state === "complete")).toBe(true);
  });

  it("keeps the acceptance phase active while signatures are pending", () => {
    render(<StatusRail status="acceptance_signatures_pending" />);
    const steps = screen.getAllByRole("listitem");

    expect(steps[0]).toHaveAttribute("data-state", "complete");
    expect(steps[1]).toHaveAttribute("data-state", "complete");
    expect(steps[2]).toHaveAttribute("data-state", "active");
  });
});
