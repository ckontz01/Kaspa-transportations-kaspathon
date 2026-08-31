"use client";

import { useRouter } from "next/navigation";
import { FormEvent, useCallback, useState } from "react";
import {
  Check,
  Clock3,
  Crosshair,
  MapPin,
  Navigation,
  ShieldCheck,
  SlidersHorizontal,
  WalletCards,
} from "lucide-react";
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
      <div className="booking-page">
        <div className="booking-heading">
          <div>
            <p className="eyebrow">Normal driver ride</p>
            <h1>Where are you going?</h1>
            <p>Set the route, review your options, then authorize the fare.</p>
          </div>
          <span className="badge badge-info">
            <ShieldCheck aria-hidden="true" size={15} />
            Covenant protected
          </span>
        </div>

        <div className="booking-canvas">
          <section className="booking-map-card">
            <div className="booking-map-toolbar">
              <div className="point-selector" aria-label="Choose map point">
                <button
                  type="button"
                  className={`btn btn-outline btn-small${selectionMode === "pickup" ? " active" : ""}`}
                  aria-pressed={selectionMode === "pickup"}
                  aria-label="Choose pickup point on the map"
                  onClick={() => setSelectionMode("pickup")}
                >
                  <span className="stop-dot pickup" aria-hidden="true" />
                  Pickup
                </button>
                <button
                  type="button"
                  className={`btn btn-outline btn-small${selectionMode === "dropoff" ? " active" : ""}`}
                  aria-pressed={selectionMode === "dropoff"}
                  aria-label="Choose destination point on the map"
                  onClick={() => setSelectionMode("dropoff")}
                >
                  <MapPin aria-hidden="true" size={15} />
                  Drop-off
                </button>
              </div>
              <span className="map-mode">
                <Navigation aria-hidden="true" size={15} />
                Selecting {selectionMode}
              </span>
            </div>
            <RideMap
              pickup={pickup}
              dropoff={dropoff}
              selectionMode={selectionMode}
              onSelect={updatePoint}
              onRoute={updateRoute}
            />
            {route ? (
              <div className="booking-route-summary" aria-live="polite">
                <div>
                  <Navigation aria-hidden="true" size={17} />
                  <span>
                    <small>Road distance</small>
                    <strong>{formatDistance(route.distanceMeters)}</strong>
                  </span>
                </div>
                <div>
                  <Clock3 aria-hidden="true" size={17} />
                  <span>
                    <small>Estimated drive</small>
                    <strong>{formatDuration(route.durationSeconds)}</strong>
                  </span>
                </div>
              </div>
            ) : null}
          </section>

          <form className="card booking-sheet" onSubmit={requestQuote}>
            <div className="booking-sheet-handle" />
            <div className="booking-sheet-heading">
              <div>
                <span className="booking-step">1</span>
                <div>
                  <h2>Your route</h2>
                  <p>Tap the map or edit the locations.</p>
                </div>
              </div>
              <span className="network-chip">testnet-10</span>
            </div>
            <div className="booking-locations">
              <LocationFields
                mode="pickup"
                title="Pickup"
                location={pickup}
                onChange={updateField}
                onFocus={() => setSelectionMode("pickup")}
              />
              <LocationFields
                mode="dropoff"
                title="Destination"
                location={dropoff}
                onChange={updateField}
                onFocus={() => setSelectionMode("dropoff")}
              />
            </div>

            <details className="booking-coordinates">
              <summary>
                <Crosshair aria-hidden="true" size={18} />
                <span>
                  <strong>Exact coordinates</strong>
                  <small>Advanced route positioning</small>
                </span>
              </summary>
              <div className="booking-coordinate-grid">
                <CoordinateFields
                  mode="pickup"
                  title="Pickup"
                  location={pickup}
                  onChange={updateField}
                />
                <CoordinateFields
                  mode="dropoff"
                  title="Destination"
                  location={dropoff}
                  onChange={updateField}
                />
              </div>
            </details>

            <div className="form-group booking-service-field">
              <label className="form-label" htmlFor="serviceType">
                Ride type
              </label>
              <select
                className="form-control"
                id="serviceType"
                name="serviceType"
                defaultValue="standard"
                onChange={() => setQuote(null)}
              >
                <option value="standard">Standard · everyday ride</option>
                <option value="comfort">Comfort · extra room</option>
                <option value="accessible">Accessible vehicle</option>
                <option value="cargo">Cargo · large luggage</option>
              </select>
            </div>

            <details className="booking-options">
              <summary>
                <span>
                  <SlidersHorizontal aria-hidden="true" size={18} />
                  Ride preferences
                </span>
                <small>Accessibility, luggage, notes</small>
              </summary>
              <div className="booking-options-body">
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
                <label className="checkbox-label">
                  <input
                    type="checkbox"
                    name="wheelchairNeeded"
                    onChange={() => setQuote(null)}
                  />
                  <span>Wheelchair-accessible vehicle required</span>
                </label>
                <label className="checkbox-label">
                  <input
                    type="checkbox"
                    name="useSimulation"
                    onChange={() => setQuote(null)}
                  />
                  <span>Use simulated driver movement for this test ride</span>
                </label>
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
              </div>
            </details>

            {error ? <div className="inline-error">{error}</div> : null}
            <button
              type="submit"
              className="btn btn-primary btn-block booking-primary-action"
              disabled={loading !== null}
            >
              {loading === "quote" ? "Calculating fare…" : "See fare"}
            </button>

            {quote ? (
              <section className="booking-quote" aria-live="polite">
                <div className="booking-quote-heading">
                  <div>
                    <span className="booking-step">2</span>
                    <span>
                      <small>Your fare</small>
                      <strong>{quote.quotedFareKas} KAS</strong>
                    </span>
                  </div>
                  <small>
                    Valid until {new Date(quote.expiresAt).toLocaleTimeString()}
                  </small>
                </div>
                <dl className="booking-quote-details">
                  <div>
                    <dt>Distance</dt>
                    <dd>{formatDistance(quote.routeDistanceMeters)}</dd>
                  </div>
                  <div>
                    <dt>Drive time</dt>
                    <dd>{formatDuration(quote.estimatedDurationSeconds)}</dd>
                  </div>
                </dl>
                <div className="quote-protection">
                  <WalletCards aria-hidden="true" size={18} />
                  <span>
                    <strong>SilverScript ride escrow</strong>
                    Driver payout needs settlement authorization; cancellation
                    and timeout refunds follow covenant rules.
                  </span>
                </div>
                {!state.canSignCovenants ? (
                  <div className="quote-wallet-warning" role="status">
                    <WalletCards aria-hidden="true" size={18} />
                    <span>
                      <strong>Escrow signature needed later</strong>
                      {state.active
                        ? `${state.active.info.name} can verify this account but does not expose KIP-12 signPskt.`
                        : "Reconnect a covenant-capable wallet on the ride page before funding escrow."}
                    </span>
                  </div>
                ) : null}
                <button
                  type="button"
                  className="btn btn-primary btn-block booking-primary-action booking-confirm-action"
                  onClick={() => void confirmRide()}
                  disabled={loading !== null}
                >
                  {loading === "book" ? (
                    "Preparing covenant…"
                  ) : (
                    <>
                      <Check aria-hidden="true" size={18} />
                      {state.canSignCovenants
                        ? "Confirm ride & sign escrow"
                        : "Create ride · sign later"}
                    </>
                  )}
                </button>
              </section>
            ) : null}
          </form>
        </div>

        <div className="booking-support-grid">
          <WalletPanel />
          <section className="card payment-explainer">
            <span className="payment-explainer-icon">
              <ShieldCheck aria-hidden="true" size={22} />
            </span>
            <div>
              <p className="eyebrow">Payment protection</p>
              <h2>How your normal-ride fare moves</h2>
              <p>
                The fare is locked in a ride-specific SilverScript covenant, not
                paid to OSRH or the driver up front.
              </p>
            </div>
            <ol className="payment-steps">
              <li>You sign the escrow funding transaction.</li>
              <li>You and the selected driver sign the assignment.</li>
              <li>Both parties authorize settlement after the trip.</li>
              <li>Cancellation and timeout refunds follow covenant rules.</li>
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
  onFocus,
}: {
  mode: SelectionMode;
  title: string;
  location: LocationInput;
  onChange: (
    mode: SelectionMode,
    field: keyof LocationInput,
    value: string,
  ) => void;
  onFocus: () => void;
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
          onFocus={onFocus}
          required
        />
      </div>
    </div>
  );
}

function CoordinateFields({
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
    <fieldset>
      <legend>{title}</legend>
      <label>
        <span>Latitude</span>
        <input
          className="form-control"
          aria-label={`${title} latitude`}
          type="number"
          inputMode="decimal"
          step="any"
          value={location.latitude}
          onChange={(event) => onChange(mode, "latitude", event.target.value)}
          required
        />
      </label>
      <label>
        <span>Longitude</span>
        <input
          className="form-control"
          aria-label={`${title} longitude`}
          type="number"
          inputMode="decimal"
          step="any"
          value={location.longitude}
          onChange={(event) => onChange(mode, "longitude", event.target.value)}
          required
        />
      </label>
    </fieldset>
  );
}
