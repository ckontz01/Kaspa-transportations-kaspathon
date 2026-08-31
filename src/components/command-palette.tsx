"use client";

import { Command, Search } from "lucide-react";
import { useEffect, useMemo, useRef, useState, type KeyboardEvent as ReactKeyboardEvent } from "react";

export type PaletteCommand = {
  id: string;
  label: string;
  detail: string;
  keywords?: string;
  action: () => void;
};

export function CommandPalette({ commands }: { commands: PaletteCommand[] }) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [selected, setSelected] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const dialogRef = useRef<HTMLElement>(null);
  const wasOpen = useRef(false);

  const filtered = useMemo(() => {
    const needle = query.trim().toLowerCase();
    if (!needle) return commands;
    return commands.filter((item) =>
      `${item.label} ${item.detail} ${item.keywords ?? ""}`.toLowerCase().includes(needle),
    );
  }, [commands, query]);

  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "k") {
        event.preventDefault();
        setOpen((current) => !current);
      } else if (event.key === "Escape") {
        setOpen(false);
      }
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, []);

  useEffect(() => {
    if (open) {
      wasOpen.current = true;
      setQuery("");
      setSelected(0);
      requestAnimationFrame(() => inputRef.current?.focus());
    } else if (wasOpen.current) {
      wasOpen.current = false;
      triggerRef.current?.focus({ preventScroll: true });
    }
  }, [open]);

  const trapFocus = (event: ReactKeyboardEvent<HTMLElement>) => {
    if (event.key !== "Tab") return;
    const focusable = Array.from(
      dialogRef.current?.querySelectorAll<HTMLElement>(
        'button:not([disabled]), input:not([disabled]), [href], [tabindex]:not([tabindex="-1"])',
      ) ?? [],
    );
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  };

  const run = (command: PaletteCommand) => {
    setOpen(false);
    command.action();
  };

  return (
    <>
      <button
        ref={triggerRef}
        className="command-trigger"
        type="button"
        onClick={() => setOpen(true)}
        aria-haspopup="dialog"
        aria-expanded={open}
      >
        <Command aria-hidden="true" size={15} strokeWidth={1.8} />
        <span>Commands</span>
        <kbd>⌘K</kbd>
      </button>
      {open ? (
        <div
          className="dialog-backdrop"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) setOpen(false);
          }}
        >
          <section
            ref={dialogRef}
            className="command-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="command-title"
            onKeyDown={trapFocus}
          >
            <h2 className="sr-only" id="command-title">
              Command palette
            </h2>
            <label className="command-search">
              <Search aria-hidden="true" size={18} strokeWidth={1.8} />
              <span className="sr-only">Filter commands</span>
              <input
                ref={inputRef}
                aria-label="Filter commands"
                value={query}
                placeholder="Go to a section or run an action"
                onChange={(event) => {
                  setQuery(event.target.value);
                  setSelected(0);
                }}
                onKeyDown={(event) => {
                  if (event.key === "ArrowDown") {
                    event.preventDefault();
                    if (filtered.length) {
                      setSelected((value) => Math.min(value + 1, filtered.length - 1));
                    }
                  }
                  if (event.key === "ArrowUp") {
                    event.preventDefault();
                    setSelected((value) => Math.max(value - 1, 0));
                  }
                  if (event.key === "Enter" && filtered[selected]) {
                    event.preventDefault();
                    run(filtered[selected]);
                  }
                }}
              />
              <kbd>ESC</kbd>
            </label>
            <div className="command-results" role="listbox" aria-label="Commands">
              {filtered.length ? (
                filtered.map((item, index) => (
                  <button
                    key={item.id}
                    type="button"
                    role="option"
                    aria-selected={selected === index}
                    className="command-row"
                    data-selected={selected === index}
                    onMouseEnter={() => setSelected(index)}
                    onClick={() => run(item)}
                  >
                    <span>{item.label}</span>
                    <small>{item.detail}</small>
                  </button>
                ))
              ) : (
                <p className="command-empty">No matching command.</p>
              )}
            </div>
          </section>
        </div>
      ) : null}
    </>
  );
}
