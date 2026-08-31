"use client";

import { Gem, ShieldCheck } from "lucide-react";
import { KasKeeperInstallCard } from "@/components/kaskeeper-install-card";
import { useOsrh } from "@/components/osrh-provider";

function shortAddress(value: string) {
  return value.length > 28 ? `${value.slice(0, 16)}…${value.slice(-8)}` : value;
}

export function WalletPanel({ compact = false }: { compact?: boolean }) {
  const { state, connect, disconnect, rediscover } = useOsrh();
  const connected = Boolean(state.active && state.user?.address);
  const hasKasKeeper = state.providers.some(
    (provider) =>
      provider.info.rdns === "com.kaskeeper" ||
      provider.info.name.toLowerCase() === "kaskeeper",
  );
  const status = state.canSignCovenants
    ? { label: "Escrow ready", className: "badge-success" }
    : connected
      ? { label: "Account linked", className: "badge-info" }
      : state.user?.address
        ? { label: "Reconnect to sign", className: "badge-warning" }
        : { label: "Not linked", className: "" };

  return (
    <section
      className={`wallet-panel${compact ? " wallet-panel-compact" : ""}`}
    >
      <div className="wallet-panel-heading">
        <div>
          <h2>
            <Gem aria-hidden="true" size={20} />
            Kaspa Wallet
          </h2>
          <p>KIP-5 proves ownership; KIP-12 signs covenant transactions.</p>
        </div>
        <span
          className={`badge ${status.className}`.trim()}
          aria-live="polite"
        >
          {status.label}
        </span>
      </div>

      {state.user?.address ? (
        <div className="wallet-address-row">
          <code>{shortAddress(state.user.address)}</code>
          <span>{state.network || "testnet-10"}</span>
        </div>
      ) : (
        <p className="wallet-explainer">
          Link a testnet-10 wallet before requesting or accepting a driver ride.
          Your password account remains separate from transaction approval.
        </p>
      )}

      <div className="wallet-provider-list">
        {state.providers.length ? (
          state.providers.map((provider) => {
            const id = provider.info.rdns ?? provider.info.uuid;
            const selected = state.active === provider;
            const canSign = Boolean(provider.provider.signPskt);
            return (
              <button
                type="button"
                className={`btn ${selected ? "btn-outline" : "btn-primary"} wallet-provider-button`}
                key={id}
                disabled={state.phase === "connecting"}
                onClick={() =>
                  void (selected ? disconnect() : connect(provider))
                }
              >
                <span>
                  <strong>
                    {selected ? "Disconnect" : "Connect"} {provider.info.name}
                  </strong>
                  <small>
                    {canSign
                      ? "Account verification + covenant signing"
                      : "Account verification · sign escrow later"}
                  </small>
                </span>
                {canSign ? <ShieldCheck aria-hidden="true" size={18} /> : null}
              </button>
            );
          })
        ) : (
          <div className="wallet-compatibility">
            <p className="form-help">
              <strong>KasWare:</strong> open this site in Chrome, Edge, or Brave
              where the extension is installed and unlocked. Browser extensions
              are not injected into most embedded or in-app browsers.
            </p>
            <p className="form-help">
              <strong>Kaspium:</strong> the current mobile wallet does not
              expose KIP-12 <code>signPskt</code> to websites, so it cannot
              authorize a covenant ride yet.
            </p>
            <div className="wallet-provider-actions">
              <button
                type="button"
                className="btn btn-outline btn-small"
                disabled={state.phase === "discovering"}
                onClick={rediscover}
              >
                {state.phase === "discovering" ? "Checking…" : "Detect again"}
              </button>
              <a
                className="btn btn-secondary btn-small"
                href="https://www.kasware.xyz/"
                target="_blank"
                rel="noreferrer"
              >
                KasWare website
              </a>
            </div>
          </div>
        )}
        {!hasKasKeeper ? <KasKeeperInstallCard compact={compact} /> : null}
      </div>
    </section>
  );
}
