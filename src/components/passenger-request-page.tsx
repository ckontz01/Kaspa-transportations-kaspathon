"use client";

import { useRouter } from "next/navigation";
import { FormEvent, useCallback, useState } from "react";
import { useOsrh } from "@/components/osrh-provider";
import { ProtectedPage } from "@/components/protected-page";
import { RideMap } from "@/components/ride-map";
import { WalletPanel } from "@/components/wallet-panel";
import { apiRequest, errorMessage } from "@/lib/api";
import { formatDistance, formatDuration } from "@/lib/ride";
import type { LocationInput, Ride, RideQuote, SigningDraft } from "@/lib/types";

const nicosiaPickup: LocationInput = {
  label: "Eleftheria Square, Nicosia",
  latitude: 35.17084,
  longitude: 33.36183,
};

const nicosiaDropoff: LocationInput = {
  label: "University of Cyprus, Aglantzia",
  latitude: 35.14462,
  longitude: 33.41145,
};

type SelectionMode = "pickup" | "dropoff";
type RouteInfo = { distanceMeters: number; durationSeconds: number };

export function PassengerRequestPage() {
  const { state, signDraft } = useOsrh();
  const router = useRouter();
  const [pickup, setPickup] = useState<LocationInput>(nicosiaPickup);
  const [dropoff, setDropoff] = useState<LocationInput>(nicosiaDropoff);
  const [selectionMode, setSelectionMode] = useState<SelectionMode>("pickup");
  const [route, setRoute] = useState<RouteInfo | null>(null);
  const [quote, setQuote] = useState<RideQuote | null>(null);
  const [loading, setLoading] = useState<"quote" | "book" | null>(null);
  const [error, setError] = useState<string | null>(null);

  const updatePoint = useCallback(
    (mode: SelectionMode, location: LocationInput) => {
      if (mode === "pickup") setPickup(location);
      else setDropoff(location);
      setQuote(null);
    },
    [],
  );

  const updateRoute = useCallback((nextRoute: RouteInfo) => {
    setRoute(nextRoute);
  }, []);

  const updateField = (
    mode: SelectionMode,
    field: keyof LocationInput,
    value: string,
  ) => {
    const setter = mode === "pickup" ? setPickup : setDropoff;
    setter((current) => ({
      ...current,
      [field]: field === "label" ? value : Number(value),
    }));
    setQuote(null);
  };

  const requestQuote = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError(null);
    if (!state.user?.address) {
      setError("Link a Kaspa testnet wallet before requesting a driver ride.");
      return;
    }
    const form = new FormData(event.currentTarget);
    setLoading("quote");
    try {
      const result = await apiRequest<RideQuote>("/api/v1/quotes", {
        method: "POST",
        body: JSON.stringify({
          pickup,
          dropoff,
          serviceType: form.get("serviceType"),
          luggageVolume: form.get("luggageVolume")
            ? Number(form.get("luggageVolume"))
            : null,
          wheelchairNeeded: form.get("wheelchairNeeded") === "on",
          passengerNotes: form.get("passengerNotes") || null,
          useSimulation: form.get("useSimulation") === "on",
        }),
      });
      setQuote(result);
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(null);
    }
  };

  const confirmRide = async () => {
    if (!quote) return;
    setError(null);
    setLoading("book");
    let created: Ride | null = null;
    try {
      created = await apiRequest<Ride>("/api/v1/rides", {
        method: "POST",
        body: JSON.stringify({ quoteId: quote.id }),
      });
      if (state.canSignCovenants) {
        const draft = await apiRequest<SigningDraft>(
          `/api/v1/rides/${encodeURIComponent(created.id)}/funding-plan`,
          {
            method: "POST",
            body: JSON.stringify({ version: created.version }),
          },
        );
        await signDraft(draft);
      }
      router.push(`/passenger/rides/${created.id}`);
    } catch (caught) {
      setError(errorMessage(caught));
      if (created) router.push(`/passenger/rides/${created.id}`);
    } finally {
      setLoading(null);
    }
  };

  return (
    <ProtectedPage role="passenger">
      <div className="page-header">
        <div>
          <h1>Request a Driver Ride</h1>
          <p>
            Select pickup and destination points, review the route, then approve
            the Kaspa covenant.
          </p>
        </div>
        <span className="badge badge-info">Normal ride</span>
      </div>

      <div className="ride-request-layout">
        <div className="stack">
          <section className="card">
            <div className="card-header">
              <div>
                <h2 className="card-title">Choose your route</h2>
                <p className="form-help">
                  Click the map after choosing which point to place.
                </p>
              </div>
              <span className="map-mode">📍 Selecting {selectionMode}</span>
            </div>
            <div className="point-selector">
              <button
                type="button"
                className={`btn btn-outline btn-small${selectionMode === "pickup" ? " active" : ""}`}
                onClick={() => setSelectionMode("pickup")}
              >
                Set pickup
              </button>
              <button
                type="button"
                className={`btn btn-outline btn-small${selectionMode === "dropoff" ? " active" : ""}`}
                onClick={() => setSelectionMode("dropoff")}
              >
                Set destination
              </button>
            </div>
            <RideMap
              pickup={pickup}
              dropoff={dropoff}
              selectionMode={selectionMode}
              onSelect={updatePoint}
              onRoute={updateRoute}
            />
            {route ? (
              <div className="route-summary">
                <div>
                  <small>Road distance</small>
                  <strong>{formatDistance(route.distanceMeters)}</strong>
                </div>
                <div>
                  <small>Estimated drive</small>
                  <strong>{formatDuration(route.durationSeconds)}</strong>
                </div>
                <div>
                  <small>Network</small>
                  <strong>Kaspa testnet-10</strong>
                </div>
              </div>
            ) : null}
          </section>

          <form className="card" onSubmit={requestQuote}>
            <div className="card-header">
              <div>
                <h2 className="card-title">Ride details</h2>
                <p className="form-help">
                  The same normal ride options from OSRH.
                </p>
              </div>
            </div>
            <LocationFields
              mode="pickup"
              title="Pickup"
              location={pickup}
              onChange={updateField}
            />
            <LocationFields
              mode="dropoff"
              title="Destination"
              location={dropoff}
              onChange={updateField}
            />
            <div className="form-grid">
              <div className="form-group">
                <label className="form-label" htmlFor="serviceType">
                  Service type
                </label>
                <select
                  className="form-control"
                  id="serviceType"
                  name="serviceType"
                  defaultValue="standard"
                  onChange={() => setQuote(null)}
                >
                  <option value="standard">Standard</option>
                  <option value="comfort">Comfort</option>
                  <option value="accessible">Accessible</option>
                  <option value="cargo">Cargo / large luggage</option>
                </select>
              </div>
              <div className="form-group">
                <label className="form-label" htmlFor="luggageVolume">
                  Luggage volume (m³)
                </label>
                <input
                  className="form-control"
                  id="luggageVolume"
                  name="luggageVolume"
                  type="number"
                  min="0"
                  max="20"
                  step="0.1"
                  placeholder="0.5"
                  onChange={() => setQuote(null)}
                />
              </div>
            </div>
            <div className="form-group">
              <label className="checkbox-label">
                <input
                  type="checkbox"
                  name="wheelchairNeeded"
                  onChange={() => setQuote(null)}
                />
                <span>Wheelchair-accessible vehicle required</span>
              </label>
            </div>
            <div className="form-group">
              <label className="checkbox-label">
                <input
                  type="checkbox"
                  name="useSimulation"
                  onChange={() => setQuote(null)}
                />
                <span>Use simulated driver movement for this test ride</span>
              </label>
            </div>
            <div className="form-group">
              <label className="form-label" htmlFor="passengerNotes">
                Notes for the driver
              </label>
              <textarea
                className="form-control"
                id="passengerNotes"
                name="passengerNotes"
                maxLength={500}
                placeholder="Pickup landmark, luggage, or accessibility notes"
                onChange={() => setQuote(null)}
              />
            </div>
            {error ? <div className="inline-error">{error}</div> : null}
            <button
              type="submit"
              className="btn btn-primary btn-block"
              disabled={loading !== null}
            >
              {loading === "quote" ? "Calculating fare…" : "Calculate fare"}
            </button>
          </form>

          {quote ? (
            <section className="card" aria-live="polite">
              <div className="card-header">
                <div>
                  <h2 className="card-title">Fare quote</h2>
                  <p className="form-help">
                    Valid until {new Date(quote.expiresAt).toLocaleTimeString()}
                    .
                  </p>
                </div>
                <span className="badge badge-success">
                  {quote.quotedFareKas} KAS
                </span>
              </div>
              <dl className="detail-list">
                <div>
                  <dt>Distance</dt>
                  <dd>{formatDistance(quote.routeDistanceMeters)}</dd>
                </div>
                <div>
                  <dt>Duration</dt>
                  <dd>{formatDuration(quote.estimatedDurationSeconds)}</dd>
                </div>
                <div>
                  <dt>Payment</dt>
                  <dd>SilverScript ride escrow</dd>
                </div>
                <div>
                  <dt>Driver receives</dt>
                  <dd>Only after both parties sign settlement</dd>
                </div>
                <div>
                  <dt>Refund path</dt>
                  <dd>Covenant cancellation / timeout rule</dd>
                </div>
              </dl>
              <button
                type="button"
                className="btn btn-primary btn-block"
                onClick={() => void confirmRide()}
                disabled={loading !== null}
              >
                {loading === "book"
                  ? "Preparing covenant…"
                  : state.canSignCovenants
                    ? "Confirm ride & sign escrow"
                    : "Confirm ride"}
              </button>
            </section>
          ) : null}
        </div>

        <div className="stack">
          <WalletPanel />
          <section className="card">
            <h2 className="card-title">How payment works</h2>
            <p>
              Your fare is locked in a ride-specific SilverScript covenant, not
              paid to OSRH or the driver up front.
            </p>
            <ol className="payment-steps">
              <li>You sign the escrow funding transaction.</li>
              <li>The selected driver and you sign the assignment.</li>
              <li>After the trip, both parties authorize settlement.</li>
              <li>
                Cancellation and timeout refunds follow the covenant rules.
              </li>
            </ol>
          </section>
        </div>
      </div>
    </ProtectedPage>
  );
}

function LocationFields({
  mode,
  title,
  location,
  onChange,
}: {
  mode: SelectionMode;
  title: string;
  location: LocationInput;
  onChange: (
    mode: SelectionMode,
    field: keyof LocationInput,
    value: string,
  ) => void;
}) {
  return (
    <div className="form-group">
      <label className="form-label" htmlFor={`${mode}-label`}>
        {title}
      </label>
      <div className="location-fields">
        <input
          className="form-control"
          id={`${mode}-label`}
          value={location.label}
          onChange={(event) => onChange(mode, "label", event.target.value)}
          required
        />
        <input
          className="form-control"
          aria-label={`${title} latitude`}
          type="number"
          step="any"
          value={location.latitude}
          onChange={(event) => onChange(mode, "latitude", event.target.value)}
          required
        />
        <input
          className="form-control"
          aria-label={`${title} longitude`}
          type="number"
          step="any"
          value={location.longitude}
          onChange={(event) => onChange(mode, "longitude", event.target.value)}
          required
        />
      </div>
    </div>
  );
}
