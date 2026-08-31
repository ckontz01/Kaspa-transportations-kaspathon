"use client";

import { ArrowUpRight, Code2, ShieldCheck } from "lucide-react";
import { useEffect, useMemo } from "react";
import { CommandPalette, type PaletteCommand } from "@/components/command-palette";
import { ProtocolBand } from "@/components/protocol-band";
import { RideWorkbench } from "@/components/ride-workbench";
import { WalletControl } from "@/components/wallet-control";
import { useKaspaWallet } from "@/lib/use-kaspa-wallet";

function scrollTo(id: string) {
  document.getElementById(id)?.scrollIntoView({ behavior: "smooth", block: "start" });
}

export function RideExperience() {
  const wallet = useKaspaWallet();
  useEffect(() => {
    const root = document.documentElement;
    root.classList.add("motion-ready");
    const items = Array.from(document.querySelectorAll<HTMLElement>("[data-reveal]"));
    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            entry.target.classList.add("is-in");
            observer.unobserve(entry.target);
          }
        }
      },
      { rootMargin: "0px 0px -8%", threshold: 0.08 },
    );
    items.forEach((item) => observer.observe(item));
    return () => {
      observer.disconnect();
      root.classList.remove("motion-ready");
    };
  }, []);
  const commands = useMemo<PaletteCommand[]>(
    () => [
      {
        id: "ride",
        label: "Request a ride",
        detail: "Open the passenger and driver console",
        keywords: "pickup quote dispatch",
        action: () => scrollTo("ride"),
      },
      {
        id: "activity",
        label: "Open ride activity",
        detail: "Inspect the escrow lifecycle",
        keywords: "status signing settlement",
        action: () => scrollTo("activity"),
      },
      {
        id: "ledger",
        label: "Inspect covenant ledger",
        detail: "Read committed state and hashes",
        keywords: "contract template commitment",
        action: () => scrollTo("ledger"),
      },
      {
        id: "protocol",
        label: "Read protocol rules",
        detail: "See exact-fare enforcement",
        keywords: "silverscript covenant",
        action: () => scrollTo("protocol"),
      },
    ],
    [],
  );

  return (
    <div className="site-shell">
      <header className="top-nav">
        <div className="nav-inner">
          <a className="wordmark" href="#ride" aria-label="Kaspa Transit home">
            <span className="wordmark-mark" aria-hidden="true">
              KT
            </span>
            <span>Kaspa Transit</span>
          </a>
          <nav className="nav-links" aria-label="Primary navigation">
            <a href="#ride">Ride</a>
            <a href="#activity">Activity</a>
            <a href="#protocol">Protocol</a>
          </nav>
          <div className="nav-actions">
            <CommandPalette commands={commands} />
            <WalletControl
              state={wallet.state}
              connect={wallet.connect}
              disconnect={wallet.disconnect}
            />
          </div>
        </div>
      </header>

      {wallet.state.error ? (
        <div className="global-message" role="status">
          {wallet.state.error}
        </div>
      ) : null}

      <main>
        <RideWorkbench wallet={wallet.state} signDraft={wallet.signDraft} />

        <section className="guarantee-ledger" aria-labelledby="guarantee-heading" data-reveal>
          <div className="guarantee-lead">
            <ShieldCheck aria-hidden="true" size={27} strokeWidth={1.5} />
            <h2 id="guarantee-heading">Server coordination without server custody.</h2>
          </div>
          <div className="guarantee-rows">
            <article>
              <span>Dispatch</span>
              <div>
                <h3>Dispatch cannot front-run.</h3>
                <p>Acceptance requires ordinary P2PK inputs from the passenger and selected driver.</p>
              </div>
            </article>
            <article>
              <span>Escrow</span>
              <div>
                <h3>Escrow cannot pay network fees.</h3>
                <p>The complete quoted fare remains pinned; separate wallet inputs fund fees and tips.</p>
              </div>
            </article>
            <article>
              <span>Database</span>
              <div>
                <h3>Every mutation has one winner.</h3>
                <p>Ride versions, unique active indexes, and one-time drafts reject duplicate requests.</p>
              </div>
            </article>
          </div>
        </section>

        <div data-reveal>
          <ProtocolBand />
        </div>
      </main>

      <footer className="site-footer">
        <div className="footer-rule" />
        <div className="footer-line">
          <span>Kaspa Transit / normal rides</span>
          <span>SilverScript escrow · MongoDB Atlas · Vercel</span>
          <a
            href="https://github.com/ckontz01/Kaspa-transportations-kaspathon"
            target="_blank"
            rel="noreferrer"
          >
            <Code2 aria-hidden="true" size={15} strokeWidth={1.8} />
            Source
            <ArrowUpRight aria-hidden="true" size={14} strokeWidth={1.8} />
          </a>
        </div>
      </footer>

      <div className="sticky-status" aria-label="Deployment and wallet status">
        <div>
          <span className="status-signal" aria-hidden="true" />
          <strong>testnet-10</strong>
          <span>SilverScript 0.1.0</span>
          <span className="sticky-hash">8aa2a011…f3fe</span>
        </div>
        <button type="button" onClick={() => scrollTo(wallet.state.user ? "activity" : "ride")}>
          {wallet.state.user ? "Open active ride" : "Connect and quote"}
          <ArrowUpRight aria-hidden="true" size={15} strokeWidth={1.8} />
        </button>
      </div>
    </div>
  );
}
