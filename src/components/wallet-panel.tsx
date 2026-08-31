"use client";

import { useOsrh } from "@/components/osrh-provider";

function shortAddress(value: string) {
  return value.length > 28 ? `${value.slice(0, 16)}…${value.slice(-8)}` : value;
}

export function WalletPanel({ compact = false }: { compact?: boolean }) {
  const { state, connect, disconnect } = useOsrh();
  const connected = Boolean(state.active && state.user?.address);

  return (
    <section
      className={`wallet-panel${compact ? " wallet-panel-compact" : ""}`}
    >
      <div className="wallet-panel-heading">
        <div>
          <h2>💎 Kaspa Wallet</h2>
          <p>KIP-5 proves ownership; KIP-12 signs covenant transactions.</p>
        </div>
        <span
          className={`badge ${connected ? "badge-success" : "badge-warning"}`}
        >
          {connected
            ? "Ready to sign"
            : state.user?.address
              ? "Reconnect to sign"
              : "Not linked"}
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
            return (
              <button
                type="button"
                className={`btn ${selected ? "btn-outline" : "btn-primary"}`}
                key={id}
                disabled={state.phase === "connecting"}
                onClick={() =>
                  void (selected ? disconnect() : connect(provider))
                }
              >
                {selected
                  ? `Disconnect ${provider.info.name}`
                  : `Connect ${provider.info.name}`}
              </button>
            );
          })
        ) : (
          <p className="form-help">
            No KIP-12 wallet was detected. Install or open a compatible Kaspa
            wallet, then refresh.
          </p>
        )}
      </div>
    </section>
  );
}
