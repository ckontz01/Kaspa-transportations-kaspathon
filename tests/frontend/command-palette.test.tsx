import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { CommandPalette } from "@/components/command-palette";

describe("CommandPalette", () => {
  it("opens from the keyboard, filters commands, and runs the selected action", () => {
    const openRide = vi.fn();
    const openProtocol = vi.fn();
    render(
      <CommandPalette
        commands={[
          {
            id: "ride",
            label: "Request a ride",
            detail: "Open the workbench",
            action: openRide,
          },
          {
            id: "protocol",
            label: "Read protocol rules",
            detail: "Inspect the covenant",
            action: openProtocol,
          },
        ]}
      />,
    );

    fireEvent.keyDown(window, { key: "k", ctrlKey: true });
    const input = screen.getByRole("textbox", { name: "Filter commands" });
    fireEvent.change(input, { target: { value: "protocol" } });
    fireEvent.keyDown(input, { key: "Enter" });

    expect(openProtocol).toHaveBeenCalledOnce();
    expect(openRide).not.toHaveBeenCalled();
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
  });

  it("closes with Escape and restores the trigger", () => {
    render(<CommandPalette commands={[]} />);
    const trigger = screen.getByRole("button", { name: /commands/i });
    fireEvent.click(trigger);
    fireEvent.keyDown(window, { key: "Escape" });

    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(trigger).toHaveFocus();
  });
});
