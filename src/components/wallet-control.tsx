"use client";

import { Wallet, X } from "lucide-react";
import {
  useEffect,
  useRef,
  useState,
  type KeyboardEvent as ReactKeyboardEvent,
} from "react";
import type { KaspaProviderDetail } from "kaspa-wallet-standard";
import type { WalletState } from "@/lib/types";

type Props = {
  state: WalletState;
  connect: (provider: KaspaProviderDetail) => Promise<unknown>;
  disconnect: () => Promise<void>;
};

export function WalletControl({ state, connect, disconnect }: Props) {
  const [open, setOpen] = useState(false);
  const [selected, setSelected] = useState<string | null>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const dialogRef = useRef<HTMLElement>(null);
  const wasOpen = useRef(false);

  useEffect(() => {
    if (open) {
      wasOpen.current = true;
      requestAnimationFrame(() => {
        dialogRef.current
          ?.querySelector<HTMLElement>("button:not([disabled])")
          ?.focus();
      });
      const closeOnEscape = (event: KeyboardEvent) => {
        if (event.key === "Escape") setOpen(false);
      };
      window.addEventListener("keydown", closeOnEscape);
      return () => window.removeEventListener("keydown", closeOnEscape);
    }
    if (wasOpen.current) {
      wasOpen.current = false;
      triggerRef.current?.focus({ preventScroll: true });
    }
  }, [open]);

  const trapFocus = (event: ReactKeyboardEvent<HTMLElement>) => {
    if (event.key !== "Tab") return;
    const focusable = Array.from(
      dialogRef.current?.querySelectorAll<HTMLElement>(
        'button:not([disabled]), [href], [tabindex]:not([tabindex="-1"])',
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

  if (state.user?.address) {
    const short = `${state.user.address.slice(0, 13)}…${state.user.address.slice(-6)}`;
    return (
      <div className="wallet-session">
        <span className="wallet-indicator" aria-hidden="true" />
        <span className="wallet-address">{short}</span>
        <button
          className="wallet-disconnect"
          type="button"
          onClick={() => void disconnect()}
        >
          Disconnect
        </button>
      </div>
    );
  }

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        className="button button-primary wallet-open"
        onClick={() => setOpen(true)}
        disabled={state.phase === "discovering"}
        data-state={state.phase === "connecting" ? "loading" : "default"}
      >
        <Wallet aria-hidden="true" size={17} strokeWidth={1.8} />
        {state.phase === "discovering" ? "Finding wallets" : "Connect wallet"}
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
            className="wallet-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="wallet-dialog-title"
            onKeyDown={trapFocus}
          >
            <div className="dialog-heading">
              <div>
                <h2 id="wallet-dialog-title">Select a Kaspa wallet</h2>
                <p>
                  Selection is required again each session before any signature.
                </p>
              </div>
              <button
                type="button"
                className="icon-button"
                aria-label="Close wallet selector"
                onClick={() => setOpen(false)}
              >
                <X aria-hidden="true" size={19} strokeWidth={1.8} />
              </button>
            </div>
            <div className="wallet-list">
              {state.providers.length ? (
                state.providers.map((detail) => {
                  const key = detail.info.rdns ?? detail.info.uuid;
                  const loading =
                    selected === key && state.phase === "connecting";
                  return (
                    <button
                      type="button"
                      className="wallet-option"
                      key={key}
                      disabled={state.phase === "connecting"}
                      data-state={loading ? "loading" : "default"}
                      onClick={async () => {
                        setSelected(key);
                        try {
                          await connect(detail);
                          setOpen(false);
                        } catch {
                          // The wallet hook exposes the sanitized error in the dialog.
                        } finally {
                          setSelected(null);
                        }
                      }}
                    >
                      {detail.info.icon ? (
                        // KIP-12 discovery strips every non-data URI before it reaches this component.
                        // eslint-disable-next-line @next/next/no-img-element
                        <img
                          src={detail.info.icon}
                          alt=""
                          width={32}
                          height={32}
                        />
                      ) : (
                        <span className="wallet-fallback" aria-hidden="true">
                          <Wallet size={18} strokeWidth={1.8} />
                        </span>
                      )}
                      <span>
                        <strong>{detail.info.name}</strong>
                        <small>
                          {detail.provider.signPskt
                            ? "Covenant signing available"
                            : "No signPskt support"}
                        </small>
                      </span>
                      <em>{loading ? "Check wallet" : "Select"}</em>
                    </button>
                  );
                })
              ) : (
                <div className="wallet-empty">
                  <Wallet aria-hidden="true" size={24} strokeWidth={1.6} />
                  <p>No KIP-12 provider announced itself on this page.</p>
                  <small>
                    Install or unlock a compatible wallet, then reopen this
                    selector.
                  </small>
                </div>
              )}
            </div>
            {state.error ? <p className="inline-error">{state.error}</p> : null}
            <p className="trust-note">
              Wallet names and icons are display hints. Your wallet
              extension&apos;s own prompt is the signature trust boundary.
            </p>
          </section>
        </div>
      ) : null}
    </>
  );
}
