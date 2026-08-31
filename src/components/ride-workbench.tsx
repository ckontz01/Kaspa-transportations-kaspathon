"use client";

import {
  ArrowRight,
  Check,
  CircleDollarSign,
  Clock3,
  MapPin,
  RefreshCw,
  Route,
  ShieldCheck,
} from "lucide-react";
import { useCallback, useEffect, useMemo, useState } from "react";
import { apiRequest, errorMessage } from "@/lib/api";
import type {
  ApiUser,
  LocationInput,
  Ride,
  RideQuote,
  SigningDraft,
  SubmitDraftResult,
  WalletState,
} from "@/lib/types";
import { StatusRail } from "@/components/status-rail";

type Props = {
  wallet: WalletState;
  signDraft: (draft: SigningDraft) => Promise<SubmitDraftResult>;
};

const terminalStatuses = new Set(["settled", "refunded", "cancelled"]);

const initialPickup: LocationInput = {
  label: "University of Cyprus, Aglantzia",
  latitude: 35.1447,
  longitude: 33.4114,
};

const initialDropoff: LocationInput = {
  label: "Eleftheria Square, Nicosia",
  latitude: 35.1726,
  longitude: 33.3619,
};

const statusLabel: Record<string, string> = {
  awaiting_funding: "Waiting for escrow funding",
  funding_signature_pending: "Funding signature reserved",
  funding_submitted: "Funding submitted to Kaspa",
  funded: "Escrow funded; open for a driver",
  acceptance_signatures_pending: "Driver and passenger signatures pending",
  acceptance_submitted: "Assignment submitted to Kaspa",
  accepted: "Driver fixed in covenant state",
  in_progress: "Ride in progress",
  settlement_signatures_pending: "Settlement signatures pending",
  settled: "Fare settled to driver",
  cancellation_signature_pending: "Cancellation signature pending",
  cancellation_signatures_pending: "Cooperative cancellation pending",
  timeout_refund_signature_pending: "Timeout refund signature pending",
  refunded: "Fare returned to passenger",
  cancelled: "Ride cancelled before funding",
};

function short(value?: string, start = 10, end = 7) {
  if (!value) return "Not assigned";
  return value.length > start + end ? `${value.slice(0, start)}…${value.slice(-end)}` : value;
}

function formatDistance(meters: number) {
  return `${(meters / 1_000).toFixed(1)} km`;
}

function formatDuration(seconds: number) {
  return `${Math.ceil(seconds / 60)} min`;
}

function LocationEditor({
  title,
  value,
  onChange,
}: {
  title: string;
  value: LocationInput;
  onChange: (next: LocationInput) => void;
}) {
  return (
    <fieldset className="location-editor">
      <legend>{title}</legend>
      <label className="field field-wide">
        <span>Place</span>
        <input
          value={value.label}
          onChange={(event) => onChange({ ...value, label: event.target.value })}
          autoComplete="street-address"
        />
      </label>
      <div className="coordinate-row">
        <label className="field">
          <span>Latitude</span>
          <input
            type="number"
            step="0.000001"
            value={value.latitude}
            onChange={(event) => onChange({ ...value, latitude: Number(event.target.value) })}
          />
        </label>
        <label className="field">
          <span>Longitude</span>
          <input
            type="number"
            step="0.000001"
            value={value.longitude}
            onChange={(event) => onChange({ ...value, longitude: Number(event.target.value) })}
          />
        </label>
      </div>
    </fieldset>
  );
}

function DraftPanel({
  draft,
  loading,
  enabled,
  onSign,
}: {
  draft: SigningDraft;
  loading: boolean;
  enabled: boolean;
  onSign: () => void;
}) {
  return (
    <section className="draft-panel" aria-labelledby="draft-title">
      <div>
        <p className="mono-label">KIP-12 partial signing</p>
        <h3 id="draft-title">Approve {draft.action.replaceAll("_", " ")}</h3>
        <p>
          This wallet signs input{draft.signInputs.length === 1 ? "" : "s"}{" "}
          {draft.signInputs.map((item) => item.index).join(", ")}. The covenant input remains
          untouched.
        </p>
      </div>
      <div className="draft-meta">
        <span>
          Signer {draft.signingPosition + 1} / {draft.signerCount}
        </span>
        <span>Expires {new Date(draft.expiresAt).toLocaleTimeString()}</span>
      </div>
      <button
        type="button"
        className="button button-primary"
        disabled={!enabled || loading}
        data-state={loading ? "loading" : "default"}
        onClick={onSign}
      >
        <ShieldCheck aria-hidden="true" size={17} strokeWidth={1.8} />
        {loading ? "Waiting for wallet" : "Review in wallet"}
      </button>
      {!enabled ? (
        <small className="field-help">Select a KIP-12 wallet in this session before signing.</small>
      ) : null}
    </section>
  );
}

export function RideWorkbench({ wallet, signDraft }: Props) {
  const [mode, setMode] = useState<"passenger" | "driver">("passenger");
  const [pickup, setPickup] = useState(initialPickup);
  const [dropoff, setDropoff] = useState(initialDropoff);
  const [quote, setQuote] = useState<RideQuote | null>(null);
  const [ride, setRide] = useState<Ride | null>(null);
  const [dispatch, setDispatch] = useState<Ride[]>([]);
  const [draft, setDraft] = useState<SigningDraft | null>(null);
  const [loading, setLoading] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const run = useCallback(async <T,>(name: string, operation: () => Promise<T>) => {
    setLoading(name);
    setError(null);
    setNotice(null);
    try {
      return await operation();
    } catch (caught) {
      setError(errorMessage(caught));
      return undefined;
    } finally {
      setLoading(null);
    }
  }, []);

  const loadRides = useCallback(async () => {
    if (!wallet.user) return;
    const response = await apiRequest<{ rides: Ride[] }>("/api/v1/rides?limit=20");
    const active = response.rides.find((item) => !terminalStatuses.has(item.status));
    setRide(active ?? response.rides[0] ?? null);
  }, [wallet.user]);

  const loadDispatch = useCallback(async () => {
    if (!wallet.user) return;
    const response = await apiRequest<{ rides: Ride[] }>("/api/v1/dispatch");
    setDispatch(response.rides);
  }, [wallet.user]);

  const loadPendingDrafts = useCallback(async () => {
    if (!wallet.user) return;
    const response = await apiRequest<{ drafts: SigningDraft[] }>(
      "/api/v1/signing-drafts/pending",
    );
    setDraft(response.drafts[0] ?? null);
  }, [wallet.user]);

  useEffect(() => {
    if (!wallet.user) {
      setRide(null);
      setDraft(null);
      setDispatch([]);
      return;
    }
    void Promise.all([loadRides(), loadPendingDrafts()]).catch((caught) => {
      setError(errorMessage(caught));
    });
  }, [loadPendingDrafts, loadRides, wallet.user]);

  useEffect(() => {
    if (mode === "driver" && wallet.user) {
      void loadDispatch().catch((caught) => setError(errorMessage(caught)));
    }
  }, [loadDispatch, mode, wallet.user]);

  const requestQuote = async () => {
    await run("quote", async () => {
      const next = await apiRequest<RideQuote>("/api/v1/quotes", {
        method: "POST",
        body: JSON.stringify({ pickup, dropoff }),
      });
      setQuote(next);
      return next;
    });
  };

  const lockRide = async () => {
    if (!quote) return;
    await run("ride", async () => {
      const next = await apiRequest<Ride>("/api/v1/rides", {
        method: "POST",
        body: JSON.stringify({ quoteId: quote.id }),
      });
      setRide(next);
      setQuote(null);
      setNotice("Ride created. The quote is immutable; prepare its genesis escrow next.");
      return next;
    });
  };

  const createPlan = async (endpoint: string, name: string, target = ride) => {
    if (!target) return;
    await run(name, async () => {
      const next = await apiRequest<SigningDraft>(
        `/api/v1/rides/${encodeURIComponent(target.id)}/${endpoint}`,
        { method: "POST", body: JSON.stringify({ version: target.version }) },
      );
      setDraft(next);
      setRide((current) =>
        current?.id === target.id
          ? { ...current, version: current.version + 1, status: statusForPlan(endpoint) }
          : current,
      );
      return next;
    });
  };

  const signCurrentDraft = async () => {
    if (!draft) return;
    await run("sign", async () => {
      const result = await signDraft(draft);
      if (result.status === "awaiting_next_signer") {
        setNotice(`Your input is signed. Waiting for ${short(result.nextSigner, 12, 8)}.`);
        setDraft(null);
      } else {
        setNotice(`Transaction ${short(result.transactionId, 12, 8)} was accepted for broadcast.`);
        if (result.ride) setRide(result.ride);
        setDraft(null);
        await loadDispatch();
      }
      return result;
    });
  };

  const refreshRide = async () => {
    if (!ride) return;
    await run("refresh", async () => {
      const next = await apiRequest<Ride>(`/api/v1/rides/${encodeURIComponent(ride.id)}`);
      setRide(next);
      await loadPendingDrafts();
      return next;
    });
  };

  const beginRide = async () => {
    if (!ride) return;
    await run("start", async () => {
      const next = await apiRequest<Ride>(
        `/api/v1/rides/${encodeURIComponent(ride.id)}/start`,
        { method: "POST", body: JSON.stringify({ version: ride.version }) },
      );
      setRide(next);
      return next;
    });
  };

  const cancel = async () => {
    if (!ride) return;
    await run("cancel", async () => {
      const result = await apiRequest<Ride | SigningDraft>(
        `/api/v1/rides/${encodeURIComponent(ride.id)}/cancel`,
        { method: "POST", body: JSON.stringify({ version: ride.version }) },
      );
      if ("transactionJson" in result) setDraft(result);
      else setRide(result);
      return result;
    });
  };

  const passengerRide = ride?.passengerId === wallet.user?.id;
  const driverRide = ride?.driverId === wallet.user?.id;
  const canCancel = ride && ["awaiting_funding", "funded", "accepted", "in_progress"].includes(ride.status);

  const ledger = useMemo(
    () => [
      ["Network", ride?.network ?? wallet.network ?? "testnet-10"],
      ["Fare", ride ? `${ride.quotedFareSompi / 100_000_000} KAS` : quote ? `${quote.quotedFareKas} KAS` : "—"],
      ["Template", short(ride?.escrow.templateHash, 9, 7)],
      ["Commitment", short(ride?.rideCommitment, 9, 7)],
      ["Covenant", short(ride?.escrow.covenantId, 9, 7)],
      ["Phase", ride ? (ride.escrow.state.phase === 1 ? "driver pinned" : "unassigned") : "—"],
    ],
    [quote, ride, wallet.network],
  );

  return (
    <>
      <section className="hero-shell" id="ride" aria-labelledby="hero-heading" data-reveal>
        <div className="hero-copy">
          <p className="mono-label">Normal ride / covenant escrow</p>
          <h1 id="hero-heading">Fare locked before pickup.</h1>
          <p className="hero-lede">
            The passenger funds one Kaspa UTXO. Driver assignment changes covenant state. Completion
            releases the exact quoted fare—never an amount rewritten by the server.
          </p>
          <div className="hero-facts" aria-label="Payment system guarantees">
            <span>Fixed sompi amounts</span>
            <span>KIP-12 partial signing</span>
            <span>Testnet-10 default</span>
          </div>
        </div>

        <div className="ride-console" aria-label="Ride request console">
          <div className="console-tabs" role="tablist" aria-label="Ride role">
            <button
              type="button"
              role="tab"
              aria-selected={mode === "passenger"}
              onClick={() => setMode("passenger")}
            >
              Passenger
            </button>
            <button
              type="button"
              role="tab"
              aria-selected={mode === "driver"}
              onClick={() => setMode("driver")}
            >
              Driver
            </button>
          </div>
          {mode === "passenger" ? (
            <div className="console-body" role="tabpanel">
              {!quote ? (
                <>
                  <LocationEditor title="Pickup" value={pickup} onChange={setPickup} />
                  <LocationEditor title="Drop-off" value={dropoff} onChange={setDropoff} />
                  <button
                    type="button"
                    className="button button-primary button-full"
                    disabled={!wallet.user || loading === "quote" || Boolean(ride && !terminalStatuses.has(ride.status))}
                    data-state={loading === "quote" ? "loading" : "default"}
                    onClick={() => void requestQuote()}
                  >
                    <Route aria-hidden="true" size={17} strokeWidth={1.8} />
                    {loading === "quote" ? "Calculating" : "Calculate fixed quote"}
                  </button>
                  {!wallet.user ? (
                    <p className="field-help">Connect a wallet to request a server-signed quote.</p>
                  ) : null}
                </>
              ) : (
                <div className="quote-result">
                  <div className="quote-amount">
                    <span>Upfront escrow</span>
                    <strong>{quote.quotedFareKas} KAS</strong>
                    <small>{Number(quote.quotedFareSompi).toLocaleString()} sompi</small>
                  </div>
                  <dl>
                    <div>
                      <dt>Route</dt>
                      <dd>{formatDistance(quote.routeDistanceMeters)}</dd>
                    </div>
                    <div>
                      <dt>ETA</dt>
                      <dd>{formatDuration(quote.estimatedDurationSeconds)}</dd>
                    </div>
                    <div>
                      <dt>Valid until</dt>
                      <dd>{new Date(quote.expiresAt).toLocaleTimeString()}</dd>
                    </div>
                  </dl>
                  <button
                    type="button"
                    className="button button-primary button-full"
                    disabled={loading === "ride"}
                    data-state={loading === "ride" ? "loading" : "default"}
                    onClick={() => void lockRide()}
                  >
                    <ShieldCheck aria-hidden="true" size={17} strokeWidth={1.8} />
                    {loading === "ride" ? "Creating ride" : "Lock quote to ride"}
                  </button>
                  <button type="button" className="text-button" onClick={() => setQuote(null)}>
                    Change route
                  </button>
                </div>
              )}
            </div>
          ) : (
            <div className="console-body dispatch-board" role="tabpanel">
              <div className="dispatch-heading">
                <div>
                  <h2>Funded dispatch</h2>
                  <p>Acceptance needs your signature, then the passenger&apos;s.</p>
                </div>
                <button
                  type="button"
                  className="icon-button"
                  aria-label="Refresh dispatch"
                  onClick={() => void run("dispatch", loadDispatch)}
                >
                  <RefreshCw aria-hidden="true" size={17} strokeWidth={1.8} />
                </button>
              </div>
              {dispatch.length ? (
                <div className="dispatch-list">
                  {dispatch.map((item) => (
                    <article key={item.id} className="dispatch-row">
                      <div>
                        <strong>{item.pickup.label}</strong>
                        <span>
                          <ArrowRight aria-hidden="true" size={14} /> {item.dropoff.label}
                        </span>
                      </div>
                      <dl>
                        <div>
                          <dt>Fare</dt>
                          <dd>{item.quotedFareSompi / 100_000_000} KAS</dd>
                        </div>
                        <div>
                          <dt>Distance</dt>
                          <dd>{formatDistance(item.routeDistanceMeters)}</dd>
                        </div>
                      </dl>
                      <button
                        type="button"
                        className="button button-secondary"
                        disabled={!wallet.canSignCovenants || loading === `accept-${item.id}`}
                        onClick={() => void createPlan("acceptance-plan", `accept-${item.id}`, item)}
                      >
                        Accept ride
                      </button>
                    </article>
                  ))}
                </div>
              ) : (
                <p className="empty-state">No confirmed funded rides are waiting for a driver.</p>
              )}
            </div>
          )}
        </div>
      </section>

      <section
        className="workbench-shell"
        id="activity"
        aria-labelledby="workbench-heading"
        data-reveal
      >
        <aside className="lifecycle-pane">
          <p className="mono-label">Transaction path</p>
          <h2 id="workbench-heading">One ride, one UTXO lineage.</h2>
          <StatusRail status={ride?.status} />
        </aside>

        <div className="activity-pane">
          <div className="pane-heading">
            <div>
              <p className="mono-label">Active ride</p>
              <h2>{ride ? statusLabel[ride.status] : "No active ride"}</h2>
            </div>
            {ride ? (
              <button
                type="button"
                className="icon-button"
                aria-label="Refresh ride state"
                disabled={loading === "refresh"}
                onClick={() => void refreshRide()}
              >
                <RefreshCw aria-hidden="true" size={17} strokeWidth={1.8} />
              </button>
            ) : null}
          </div>

          {ride ? (
            <div className="ride-detail">
              <div className="route-line">
                <MapPin aria-hidden="true" size={18} strokeWidth={1.8} />
                <div>
                  <strong>{ride.pickup.label}</strong>
                  <span>{ride.dropoff.label}</span>
                </div>
              </div>
              <dl className="ride-metrics">
                <div>
                  <dt>
                    <CircleDollarSign aria-hidden="true" size={15} /> Fare
                  </dt>
                  <dd>{ride.quotedFareSompi / 100_000_000} KAS</dd>
                </div>
                <div>
                  <dt>
                    <Route aria-hidden="true" size={15} /> Route
                  </dt>
                  <dd>{formatDistance(ride.routeDistanceMeters)}</dd>
                </div>
                <div>
                  <dt>
                    <Clock3 aria-hidden="true" size={15} /> ETA
                  </dt>
                  <dd>{formatDuration(ride.estimatedDurationSeconds)}</dd>
                </div>
              </dl>
              <div className="ride-actions">
                {ride.status === "awaiting_funding" && passengerRide ? (
                  <button
                    type="button"
                    className="button button-primary"
                    disabled={!wallet.canSignCovenants || loading === "fund"}
                    onClick={() => void createPlan("funding-plan", "fund")}
                  >
                    Prepare escrow transaction
                  </button>
                ) : null}
                {["funding_submitted", "acceptance_submitted"].includes(ride.status) ? (
                  <button type="button" className="button button-secondary" onClick={() => void refreshRide()}>
                    Check chain confirmation
                  </button>
                ) : null}
                {ride.status === "accepted" && driverRide ? (
                  <button type="button" className="button button-primary" onClick={() => void beginRide()}>
                    Start ride
                  </button>
                ) : null}
                {ride.status === "in_progress" ? (
                  <button
                    type="button"
                    className="button button-primary"
                    disabled={!wallet.canSignCovenants}
                    onClick={() => void createPlan("settlement-plan", "settle")}
                  >
                    Prepare fare settlement
                  </button>
                ) : null}
                {canCancel ? (
                  <button type="button" className="button button-secondary" onClick={() => void cancel()}>
                    Cancel ride
                  </button>
                ) : null}
                {ride.status.includes("signature") ? (
                  <button
                    type="button"
                    className="button button-secondary"
                    onClick={() => void run("pending", loadPendingDrafts)}
                  >
                    Load my signing request
                  </button>
                ) : null}
              </div>
            </div>
          ) : (
            <div className="activity-empty">
              <Route aria-hidden="true" size={26} strokeWidth={1.5} />
              <p>Request a quote as passenger or open funded dispatch as driver.</p>
            </div>
          )}

          {draft ? (
            <DraftPanel
              draft={draft}
              enabled={wallet.canSignCovenants}
              loading={loading === "sign"}
              onSign={() => void signCurrentDraft()}
            />
          ) : null}
          {notice ? (
            <p className="inline-notice">
              <Check aria-hidden="true" size={16} strokeWidth={2} /> {notice}
            </p>
          ) : null}
          {error ? <p className="inline-error">{error}</p> : null}
        </div>

        <aside className="ledger-pane" id="ledger">
          <p className="mono-label">Covenant ledger</p>
          <h2>Signed facts</h2>
          <dl className="spec-table">
            {ledger.map(([term, value]) => (
              <div key={term}>
                <dt>{term}</dt>
                <dd>{value}</dd>
              </div>
            ))}
          </dl>
          <p className="ledger-note">
            MongoDB stores the workflow version and event log. Kaspa consensus decides whether the
            value may move.
          </p>
        </aside>
      </section>
    </>
  );
}

function statusForPlan(endpoint: string): Ride["status"] {
  if (endpoint === "funding-plan") return "funding_signature_pending";
  if (endpoint === "acceptance-plan") return "acceptance_signatures_pending";
  if (endpoint === "settlement-plan") return "settlement_signatures_pending";
  return "cancellation_signatures_pending";
}
