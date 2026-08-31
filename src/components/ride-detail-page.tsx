"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { useOsrh } from "@/components/osrh-provider";
import { ProtectedPage } from "@/components/protected-page";
import { RideMap } from "@/components/ride-map";
import { WalletPanel } from "@/components/wallet-panel";
import { apiRequest, errorMessage } from "@/lib/api";
import {
  formatDistance,
  formatDuration,
  formatKas,
  rideStatusLabel,
  shortHash,
  terminalStatuses,
} from "@/lib/ride";
import type { ApiUser, Ride, SigningDraft } from "@/lib/types";

type RideDetailRole = "passenger" | "driver";

export function RideDetailPage({ role }: { role: RideDetailRole }) {
  const params = useParams<{ rideId: string }>();
  const rideId = params.rideId;
  const { state, signDraft } = useOsrh();
  const [ride, setRide] = useState<Ride | null>(null);
  const [draft, setDraft] = useState<SigningDraft | null>(null);
  const [loading, setLoading] = useState<string | null>("ride");
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const refresh = useCallback(
    async (quiet = false) => {
      if (!rideId) return;
      if (!quiet) setLoading("ride");
      try {
        const current = await apiRequest<Ride>(
          `/api/v1/rides/${encodeURIComponent(rideId)}`,
        );
        setRide(current);
        if (state.user?.address) {
          const pending = await apiRequest<{ drafts: SigningDraft[] }>(
            "/api/v1/signing-drafts/pending",
          ).catch(() => ({ drafts: [] }));
          setDraft(
            pending.drafts.find((item) => item.rideId === rideId) ?? null,
          );
        }
        setError(null);
      } catch (caught) {
        if (!quiet) setError(errorMessage(caught));
      } finally {
        if (!quiet) setLoading(null);
      }
    },
    [rideId, state.user?.address],
  );

  useEffect(() => {
    void refresh();
    const timer = window.setInterval(() => void refresh(true), 6000);
    return () => window.clearInterval(timer);
  }, [refresh]);

  const requestDraft = async (endpoint: string) => {
    if (!ride) return;
    setLoading(endpoint);
    setError(null);
    setNotice(null);
    try {
      const next = await apiRequest<SigningDraft>(
        `/api/v1/rides/${encodeURIComponent(ride.id)}/${endpoint}`,
        { method: "POST", body: JSON.stringify({ version: ride.version }) },
      );
      setDraft(next);
      setNotice(
        "The transaction is prepared. Review it in your wallet and sign when ready.",
      );
      await refresh(true);
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(null);
    }
  };

  const runSimpleAction = async (endpoint: string) => {
    if (!ride) return;
    setLoading(endpoint);
    setError(null);
    setNotice(null);
    try {
      const result = await apiRequest<Ride | SigningDraft>(
        `/api/v1/rides/${encodeURIComponent(ride.id)}/${endpoint}`,
        { method: "POST", body: JSON.stringify({ version: ride.version }) },
      );
      if ("transactionJson" in result) {
        setDraft(result);
        setNotice("The cancellation transaction is ready for wallet approval.");
      } else {
        setRide(result);
        setNotice(
          endpoint === "start"
            ? "The ride is now in progress."
            : "The ride was cancelled.",
        );
      }
      await refresh(true);
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(null);
    }
  };

  const approveDraft = async () => {
    if (!draft) return;
    setLoading("sign");
    setError(null);
    try {
      const result = await signDraft(draft);
      setDraft(null);
      setNotice(
        result.status === "awaiting_next_signer"
          ? "Your signature is recorded. Waiting for the other ride participant."
          : "Signed transaction submitted to Kaspa.",
      );
      await refresh(true);
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(null);
    }
  };

  const rateRide = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!ride) return;
    const form = new FormData(event.currentTarget);
    setLoading("rating");
    setError(null);
    try {
      await apiRequest(`/api/v1/rides/${encodeURIComponent(ride.id)}/rating`, {
        method: "POST",
        body: JSON.stringify({
          score: Number(form.get("score")),
          comment: form.get("comment") || null,
        }),
      });
      setNotice("Thank you. Your rating has been saved.");
      event.currentTarget.reset();
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(null);
    }
  };

  return (
    <ProtectedPage role={role}>
      {loading === "ride" && !ride ? (
        <div className="card page-loading">Loading ride…</div>
      ) : null}
      {!ride && error ? <div className="flash flash-error">{error}</div> : null}
      {ride ? (
        <>
          <div className="page-header">
            <div>
              <Link
                className="back-link"
                href={
                  role === "passenger" ? "/passenger/rides" : "/driver/trips"
                }
              >
                ← Back to rides
              </Link>
              <h1>Ride Details</h1>
              <p className="mono">Ride {ride.id}</p>
            </div>
            <span className={`status-badge ${ride.status}`}>
              {rideStatusLabel[ride.status]}
            </span>
          </div>

          <div className="content-grid">
            <div className="stack">
              <section className="card ride-detail-map">
                <div className="card-header">
                  <h2 className="card-title">Live route</h2>
                  <span className="badge badge-info">OSM / OSRM</span>
                </div>
                <RideMap
                  pickup={ride.pickup}
                  dropoff={ride.dropoff}
                  showGeofences
                />
                <div className="route-summary">
                  <div>
                    <small>Distance</small>
                    <strong>{formatDistance(ride.routeDistanceMeters)}</strong>
                  </div>
                  <div>
                    <small>Estimate</small>
                    <strong>
                      {formatDuration(ride.estimatedDurationSeconds)}
                    </strong>
                  </div>
                  <div>
                    <small>Fare</small>
                    <strong>{formatKas(ride.quotedFareSompi)}</strong>
                  </div>
                </div>
              </section>

              <section className="card">
                <div className="card-header">
                  <h2 className="card-title">Trip information</h2>
                  <span className="badge badge-info">
                    {ride.serviceType || "standard"}
                  </span>
                </div>
                <dl className="detail-list">
                  <div>
                    <dt>Pickup</dt>
                    <dd>{ride.pickup.label}</dd>
                  </div>
                  <div>
                    <dt>Destination</dt>
                    <dd>{ride.dropoff.label}</dd>
                  </div>
                  <div>
                    <dt>Wheelchair</dt>
                    <dd>
                      {ride.wheelchairNeeded ? "Required" : "Not requested"}
                    </dd>
                  </div>
                  <div>
                    <dt>Luggage</dt>
                    <dd>
                      {ride.luggageVolume == null
                        ? "Not specified"
                        : `${ride.luggageVolume} m³`}
                    </dd>
                  </div>
                  <div>
                    <dt>Notes</dt>
                    <dd>{ride.passengerNotes || "—"}</dd>
                  </div>
                  <div>
                    <dt>Requested</dt>
                    <dd>{new Date(ride.createdAt).toLocaleString()}</dd>
                  </div>
                </dl>
              </section>

              {role === "passenger" && ride.status === "settled" ? (
                <form className="card" onSubmit={rateRide}>
                  <h2 className="card-title">Rate this ride</h2>
                  <div className="form-grid">
                    <div className="form-group">
                      <label className="form-label" htmlFor="score">
                        Rating
                      </label>
                      <select
                        id="score"
                        name="score"
                        className="form-control"
                        defaultValue="5"
                      >
                        <option value="5">5 — Excellent</option>
                        <option value="4">4 — Good</option>
                        <option value="3">3 — Fair</option>
                        <option value="2">2 — Poor</option>
                        <option value="1">1 — Very poor</option>
                      </select>
                    </div>
                    <div className="form-group">
                      <label className="form-label" htmlFor="comment">
                        Comment
                      </label>
                      <input
                        id="comment"
                        name="comment"
                        className="form-control"
                        maxLength={500}
                      />
                    </div>
                  </div>
                  <button
                    className="btn btn-primary"
                    type="submit"
                    disabled={loading === "rating"}
                  >
                    {loading === "rating" ? "Saving…" : "Save rating"}
                  </button>
                </form>
              ) : null}
            </div>

            <aside className="stack">
              <WalletPanel compact />
              <section className="card">
                <div className="card-header">
                  <h2 className="card-title">Ride actions</h2>
                </div>
                <RideActions
                  role={role}
                  ride={ride}
                  user={state.user}
                  loading={loading}
                  onDraft={requestDraft}
                  onSimple={runSimpleAction}
                />
                {draft ? (
                  <div className="draft-card">
                    <p>
                      <strong>Wallet signature required</strong>
                    </p>
                    <p className="form-help">
                      Action: {draft.action} · signer{" "}
                      {draft.signingPosition + 1} of {draft.signerCount}
                    </p>
                    <button
                      type="button"
                      className="btn btn-primary btn-block"
                      disabled={!state.canSignCovenants || loading === "sign"}
                      onClick={() => void approveDraft()}
                    >
                      {loading === "sign"
                        ? "Waiting for wallet…"
                        : "Review & sign transaction"}
                    </button>
                    {!state.canSignCovenants ? (
                      <p className="inline-warning">
                        Reconnect the linked Kaspa wallet to sign.
                      </p>
                    ) : null}
                  </div>
                ) : null}
                {notice ? <div className="inline-notice">{notice}</div> : null}
                {error ? <div className="inline-error">{error}</div> : null}
              </section>

              <section className="card">
                <div className="card-header">
                  <h2 className="card-title">SilverScript escrow</h2>
                  <span className="badge badge-success">Covenant</span>
                </div>
                <dl className="detail-list">
                  <div>
                    <dt>Template</dt>
                    <dd className="hash">
                      {shortHash(ride.escrow.templateHash)}
                    </dd>
                  </div>
                  <div>
                    <dt>Commitment</dt>
                    <dd className="hash">{shortHash(ride.rideCommitment)}</dd>
                  </div>
                  <div>
                    <dt>Address</dt>
                    <dd className="hash">{shortHash(ride.escrow.address)}</dd>
                  </div>
                  <div>
                    <dt>Funding tx</dt>
                    <dd className="hash">{shortHash(ride.escrow.txId)}</dd>
                  </div>
                  <div>
                    <dt>Chain state</dt>
                    <dd>{ride.escrow.confirmationStatus}</dd>
                  </div>
                  <div>
                    <dt>Version</dt>
                    <dd>{ride.version}</dd>
                  </div>
                </dl>
                <p className="covenant-note">
                  MongoDB coordinates the concurrent workflow. Kaspa consensus
                  and the SilverScript covenant authorize every movement of the
                  fare.
                </p>
              </section>
            </aside>
          </div>
        </>
      ) : null}
    </ProtectedPage>
  );
}

function RideActions({
  role,
  ride,
  user,
  loading,
  onDraft,
  onSimple,
}: {
  role: RideDetailRole;
  ride: Ride;
  user: ApiUser | null;
  loading: string | null;
  onDraft: (endpoint: string) => Promise<void>;
  onSimple: (endpoint: string) => Promise<void>;
}) {
  const busy = loading !== null;
  if (terminalStatuses.has(ride.status)) {
    return (
      <p>
        This ride is closed. Its final status and transaction facts remain in
        your history.
      </p>
    );
  }
  return (
    <div className="stack">
      {role === "passenger" && ride.status === "awaiting_funding" ? (
        <button
          className="btn btn-primary"
          type="button"
          disabled={busy}
          onClick={() => void onDraft("funding-plan")}
        >
          Fund ride escrow
        </button>
      ) : null}
      {role === "driver" && ride.status === "accepted" ? (
        <button
          className="btn btn-primary"
          type="button"
          disabled={busy}
          onClick={() => void onSimple("start")}
        >
          Start ride
        </button>
      ) : null}
      {ride.status === "in_progress" ? (
        <button
          className="btn btn-primary"
          type="button"
          disabled={busy}
          onClick={() => void onDraft("settlement-plan")}
        >
          Complete & settle ride
        </button>
      ) : null}
      {role === "passenger" && ride.status === "funded" ? (
        <button
          className="btn btn-outline"
          type="button"
          disabled={busy}
          onClick={() => void onDraft("timeout-refund-plan")}
        >
          Request timeout refund
        </button>
      ) : null}
      {["awaiting_funding", "funded", "accepted", "in_progress"].includes(
        ride.status,
      ) ? (
        <button
          className="btn btn-danger"
          type="button"
          disabled={busy}
          onClick={() => void onSimple("cancel")}
        >
          Cancel ride
        </button>
      ) : null}
      {[
        "funding_signature_pending",
        "acceptance_signatures_pending",
        "settlement_signatures_pending",
        "cancellation_signatures_pending",
        "timeout_refund_signature_pending",
      ].includes(ride.status) ? (
        <p className="inline-warning">
          A transaction is awaiting{" "}
          {user?.address ? "the next wallet signature" : "wallet reconnection"}.
        </p>
      ) : null}
      {["funding_submitted", "acceptance_submitted"].includes(ride.status) ? (
        <p className="inline-warning">
          The transaction was submitted and is waiting for Kaspa confirmation.
        </p>
      ) : null}
    </div>
  );
}
