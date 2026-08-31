"use client";

import Link from "next/link";
import { FormEvent, useCallback, useEffect, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { Bot } from "lucide-react";
import { ProtectedPage } from "@/components/protected-page";
import { RideMap } from "@/components/ride-map";
import { apiRequest, errorMessage } from "@/lib/api";
import { formatDistance, formatDuration } from "@/lib/ride";
import type { LocationInput } from "@/lib/types";

type AutonomousVehicle = {
  id: string;
  plateNumber: string;
  make: string;
  model: string;
  color: string;
  seatingCapacity: number;
  wheelchairReady: boolean;
  status: string;
  latitude: number;
  longitude: number;
  batteryLevel: number;
};
type AutonomousRide = {
  id: string;
  vehicleId: string;
  vehicle: {
    plateNumber: string;
    make: string;
    model: string;
    color: string;
    batteryLevel: number;
  };
  vehiclePosition: LocationInput;
  pickup: LocationInput;
  dropoff: LocationInput;
  pickupDescription?: string | null;
  dropoffDescription?: string | null;
  paymentMethod: string;
  paymentStatus: string;
  notes?: string | null;
  distanceMeters: number;
  estimatedDurationSeconds: number;
  estimatedFare: number;
  status: string;
  createdAt: string;
  updatedAt: string;
};

const defaultPickup: LocationInput = {
  label: "Eleftheria Square, Nicosia",
  latitude: 35.17084,
  longitude: 33.36183,
};
const defaultDropoff: LocationInput = {
  label: "University of Cyprus, Aglantzia",
  latitude: 35.14462,
  longitude: 33.41145,
};

export function AutonomousPage() {
  const router = useRouter();
  const [pickup, setPickup] = useState(defaultPickup);
  const [dropoff, setDropoff] = useState(defaultDropoff);
  const [mode, setMode] = useState<"pickup" | "dropoff">("pickup");
  const [vehicles, setVehicles] = useState<AutonomousVehicle[]>([]);
  const [rides, setRides] = useState<AutonomousRide[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const load = useCallback(async () => {
    try {
      const [vehicleResult, rideResult] = await Promise.all([
        apiRequest<{ vehicles: AutonomousVehicle[] }>(
          "/api/v1/autonomous/vehicles",
        ),
        apiRequest<{ rides: AutonomousRide[] }>("/api/v1/autonomous/rides"),
      ]);
      setVehicles(vehicleResult.vehicles);
      setRides(rideResult.rides);
      setError(null);
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => {
    void load();
  }, [load]);
  const select = useCallback(
    (selectionMode: "pickup" | "dropoff", location: LocationInput) => {
      if (selectionMode === "pickup") setPickup(location);
      else setDropoff(location);
    },
    [],
  );
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setLoading(true);
    setError(null);
    try {
      const ride = await apiRequest<AutonomousRide>(
        "/api/v1/autonomous/rides",
        {
          method: "POST",
          body: JSON.stringify({
            pickup,
            dropoff,
            pickupDescription: form.get("pickupDescription") || null,
            dropoffDescription: form.get("dropoffDescription") || null,
            paymentMethod: form.get("paymentMethod"),
            notes: form.get("notes") || null,
          }),
        },
      );
      router.push(`/autonomous/${ride.id}`);
    } catch (caught) {
      setError(errorMessage(caught));
      setLoading(false);
    }
  };
  const available = vehicles.filter(
    (vehicle) => vehicle.status === "available",
  );
  const active = rides.find(
    (ride) => !["completed", "cancelled"].includes(ride.status),
  );
  return (
    <ProtectedPage role="passenger">
      <div className="page-header">
        <div>
          <h1>Request Autonomous Ride</h1>
          <p>Choose a route within the original Cyprus operating geofences.</p>
        </div>
        <span className="badge badge-info">{available.length} available</span>
      </div>
      {active ? (
        <div className="flash flash-info">
          You have an active autonomous ride.{" "}
          <Link href={`/autonomous/${active.id}`}>Open live tracking →</Link>
        </div>
      ) : null}
      {error ? <div className="flash flash-error">{error}</div> : null}
      <div className="ride-request-layout">
        <div className="stack">
          <section className="card">
            <div className="card-header">
              <h2 className="card-title">Route Map</h2>
              <div className="point-selector">
                <button
                  type="button"
                  className={`btn btn-outline btn-small${mode === "pickup" ? " active" : ""}`}
                  onClick={() => setMode("pickup")}
                >
                  Pickup
                </button>
                <button
                  type="button"
                  className={`btn btn-outline btn-small${mode === "dropoff" ? " active" : ""}`}
                  onClick={() => setMode("dropoff")}
                >
                  Dropoff
                </button>
              </div>
            </div>
            <RideMap
              pickup={pickup}
              dropoff={dropoff}
              driver={
                available[0]
                  ? {
                      label: `${available[0].make} ${available[0].model}`,
                      latitude: available[0].latitude,
                      longitude: available[0].longitude,
                    }
                  : null
              }
              selectionMode={mode}
              onSelect={select}
            />
          </section>
          <form className="card" onSubmit={submit}>
            <h2 className="card-title">Ride Details</h2>
            <LocationEditor
              title="Pickup"
              value={pickup}
              setValue={setPickup}
            />
            <LocationEditor
              title="Dropoff"
              value={dropoff}
              setValue={setDropoff}
            />
            <div className="form-grid">
              <Field
                label="Pickup description (optional)"
                name="pickupDescription"
              />
              <Field
                label="Dropoff description (optional)"
                name="dropoffDescription"
              />
            </div>
            <div className="form-group">
              <label className="form-label" htmlFor="paymentMethod">
                Payment method
              </label>
              <select
                className="form-control"
                id="paymentMethod"
                name="paymentMethod"
                defaultValue="kaspa"
              >
                <option value="kaspa">
                  Kaspa (existing autonomous payment flow)
                </option>
                <option value="card">Card</option>
              </select>
            </div>
            <div className="form-group">
              <label className="form-label" htmlFor="notes">
                Notes
              </label>
              <textarea
                className="form-control"
                id="notes"
                name="notes"
                maxLength={500}
              />
            </div>
            <button
              className="btn btn-primary btn-block"
              type="submit"
              disabled={loading || Boolean(active)}
            >
              {loading
                ? "Finding vehicle…"
                : active
                  ? "Active ride already exists"
                  : "Request autonomous vehicle"}
            </button>
          </form>
        </div>
        <aside className="stack">
          <section className="card">
            <h2 className="card-title">Nearby Vehicles</h2>
            <div className="vehicle-grid compact">
              {vehicles.slice(0, 8).map((vehicle) => (
                <article className="vehicle-card" key={vehicle.id}>
                  <span className="vehicle-icon">
                    <Bot aria-hidden="true" size={21} />
                  </span>
                  <div>
                    <strong>
                      {vehicle.make} {vehicle.model}
                    </strong>
                    <p>
                      {vehicle.plateNumber} · {vehicle.batteryLevel}% battery
                    </p>
                    <span className={`status-badge ${vehicle.status}`}>
                      {vehicle.status}
                    </span>
                  </div>
                </article>
              ))}
            </div>
          </section>
          <section className="card">
            <h2 className="card-title">Autonomous payment</h2>
            <p>
              This mode keeps its existing non-covenant payment selection. The
              new SilverScript escrow applies only to normal driver rides, as
              requested.
            </p>
          </section>
        </aside>
      </div>
      <section className="card">
        <div className="card-header">
          <h2 className="card-title">Autonomous Ride History</h2>
        </div>
        <div className="data-table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Route</th>
                <th>Vehicle</th>
                <th>Fare</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {rides.map((ride) => (
                <tr key={ride.id}>
                  <td>{new Date(ride.createdAt).toLocaleString()}</td>
                  <td>
                    {ride.pickup.label} → {ride.dropoff.label}
                  </td>
                  <td>
                    {ride.vehicle.make} {ride.vehicle.model}
                  </td>
                  <td>€{ride.estimatedFare.toFixed(2)}</td>
                  <td>
                    <span className={`status-badge ${ride.status}`}>
                      {ride.status.replaceAll("_", " ")}
                    </span>
                  </td>
                  <td>
                    <Link
                      className="btn btn-outline btn-small"
                      href={`/autonomous/${ride.id}`}
                    >
                      Details
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {rides.length === 0 ? (
            <div className="empty-state">No autonomous rides yet.</div>
          ) : null}
        </div>
      </section>
    </ProtectedPage>
  );
}

export function AutonomousRideDetailPage() {
  const params = useParams<{ rideId: string }>();
  const [ride, setRide] = useState<AutonomousRide | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const load = useCallback(
    async (quiet = false) => {
      if (!quiet) setLoading(true);
      try {
        const result = await apiRequest<AutonomousRide>(
          `/api/v1/autonomous/rides/${params.rideId}`,
        );
        setRide(result);
        setError(null);
      } catch (caught) {
        if (!quiet) setError(errorMessage(caught));
      } finally {
        if (!quiet) setLoading(false);
      }
    },
    [params.rideId],
  );
  useEffect(() => {
    void load();
    const timer = window.setInterval(() => void load(true), 3000);
    return () => window.clearInterval(timer);
  }, [load]);
  const cancel = async () => {
    setLoading(true);
    try {
      const result = await apiRequest<AutonomousRide>(
        `/api/v1/autonomous/rides/${params.rideId}/cancel`,
        { method: "POST" },
      );
      setRide(result);
      setNotice("Autonomous ride cancelled.");
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(false);
    }
  };
  const rate = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setLoading(true);
    try {
      await apiRequest(`/api/v1/autonomous/rides/${params.rideId}/rating`, {
        method: "POST",
        body: JSON.stringify({
          score: Number(form.get("score")),
          comment: form.get("comment") || null,
        }),
      });
      setNotice("Rating saved.");
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(false);
    }
  };
  return (
    <ProtectedPage role="passenger">
      {loading && !ride ? (
        <div className="card page-loading">Loading autonomous ride…</div>
      ) : null}
      {error ? <div className="flash flash-error">{error}</div> : null}
      {ride ? (
        <>
          <div className="page-header">
            <div>
              <Link className="back-link" href="/autonomous">
                ← Autonomous rides
              </Link>
              <h1>Autonomous Ride Details</h1>
              <p>
                {ride.vehicle.make} {ride.vehicle.model} ·{" "}
                {ride.vehicle.plateNumber}
              </p>
            </div>
            <span className={`status-badge ${ride.status}`}>
              {ride.status.replaceAll("_", " ")}
            </span>
          </div>
          {notice ? <div className="flash flash-success">{notice}</div> : null}
          <div className="content-grid">
            <div className="stack">
              <section className="card ride-detail-map">
                <h2 className="card-title">Live Vehicle Position</h2>
                <RideMap
                  pickup={ride.pickup}
                  dropoff={ride.dropoff}
                  driver={ride.vehiclePosition}
                />
                <div className="route-summary">
                  <div>
                    <small>Distance</small>
                    <strong>{formatDistance(ride.distanceMeters)}</strong>
                  </div>
                  <div>
                    <small>Estimated time</small>
                    <strong>
                      {formatDuration(ride.estimatedDurationSeconds)}
                    </strong>
                  </div>
                  <div>
                    <small>Battery</small>
                    <strong>{ride.vehicle.batteryLevel}%</strong>
                  </div>
                </div>
              </section>
              {ride.status === "completed" ? (
                <form className="card" onSubmit={rate}>
                  <h2 className="card-title">Rate your ride</h2>
                  <div className="form-grid">
                    <div className="form-group">
                      <label className="form-label" htmlFor="score">
                        Stars
                      </label>
                      <select
                        className="form-control"
                        name="score"
                        id="score"
                        defaultValue="5"
                      >
                        <option>5</option>
                        <option>4</option>
                        <option>3</option>
                        <option>2</option>
                        <option>1</option>
                      </select>
                    </div>
                    <Field label="Comment" name="comment" />
                  </div>
                  <button className="btn btn-primary" type="submit">
                    Save rating
                  </button>
                </form>
              ) : null}
            </div>
            <aside className="stack">
              <section className="card">
                <h2 className="card-title">Ride Summary</h2>
                <dl className="detail-list">
                  <div>
                    <dt>Pickup</dt>
                    <dd>{ride.pickup.label}</dd>
                  </div>
                  <div>
                    <dt>Dropoff</dt>
                    <dd>{ride.dropoff.label}</dd>
                  </div>
                  <div>
                    <dt>Fare</dt>
                    <dd>€{ride.estimatedFare.toFixed(2)}</dd>
                  </div>
                  <div>
                    <dt>Payment</dt>
                    <dd>
                      {ride.paymentMethod} · {ride.paymentStatus}
                    </dd>
                  </div>
                  <div>
                    <dt>Notes</dt>
                    <dd>{ride.notes || "—"}</dd>
                  </div>
                </dl>
                {[
                  "vehicle_dispatched",
                  "vehicle_arriving",
                  "vehicle_arrived",
                ].includes(ride.status) ? (
                  <button
                    className="btn btn-danger btn-block"
                    type="button"
                    disabled={loading}
                    onClick={() => void cancel()}
                  >
                    Cancel ride
                  </button>
                ) : null}
              </section>
            </aside>
          </div>
        </>
      ) : null}
    </ProtectedPage>
  );
}

function LocationEditor({
  title,
  value,
  setValue,
}: {
  title: string;
  value: LocationInput;
  setValue: React.Dispatch<React.SetStateAction<LocationInput>>;
}) {
  return (
    <div className="form-group">
      <label className="form-label">{title}</label>
      <div className="location-fields">
        <input
          className="form-control"
          value={value.label}
          onChange={(event) =>
            setValue((current) => ({ ...current, label: event.target.value }))
          }
        />
        <input
          className="form-control"
          type="number"
          step="any"
          value={value.latitude}
          onChange={(event) =>
            setValue((current) => ({
              ...current,
              latitude: Number(event.target.value),
            }))
          }
        />
        <input
          className="form-control"
          type="number"
          step="any"
          value={value.longitude}
          onChange={(event) =>
            setValue((current) => ({
              ...current,
              longitude: Number(event.target.value),
            }))
          }
        />
      </div>
    </div>
  );
}
function Field({ label, name }: { label: string; name: string }) {
  return (
    <div className="form-group">
      <label className="form-label" htmlFor={name}>
        {label}
      </label>
      <input className="form-control" id={name} name={name} />
    </div>
  );
}
