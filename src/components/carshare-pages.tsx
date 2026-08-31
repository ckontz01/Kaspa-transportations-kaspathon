"use client";

import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import {
  BatteryCharging,
  CalendarDays,
  CarFront,
  Clock3,
  ClipboardList,
  MapPinned,
  Search,
  UserRound,
  XCircle,
} from "lucide-react";
import { useOsrh } from "@/components/osrh-provider";
import { ProtectedPage } from "@/components/protected-page";
import { RideMap } from "@/components/ride-map";
import { apiRequest, errorMessage } from "@/lib/api";

type CarshareProfile = {
  id: string;
  verificationStatus: string;
  licenseLast4: string;
  licenseCountry: string;
  preferredLanguage: string;
  createdAt: string;
};

type Zone = {
  id: string;
  name: string;
  city: string;
  latitude: number;
  longitude: number;
  radiusMeters: number;
};

type CarshareVehicle = {
  id: string;
  plateNumber: string;
  typeCode: string;
  typeName: string;
  make: string;
  model: string;
  year: number;
  color: string;
  seatingCapacity: number;
  electric: boolean;
  zoneId: string;
  zone: Zone;
  latitude: number;
  longitude: number;
  energyLevel: number;
  pricePerMinute: number;
  pricePerHour: number;
  pricePerDay: number;
  status: string;
};

type TeleDrive = {
  id: string;
  status: string;
  progressPercent: number;
  remainingSeconds: number;
  currentLatitude: number;
  currentLongitude: number;
  targetLatitude: number;
  targetLongitude: number;
};

type Booking = {
  id: string;
  vehicleId: string;
  pricingMode: "minute" | "hour" | "day";
  status: string;
  expiresAt: string;
  startedAt?: string;
  endedAt?: string;
  durationMinutes?: number;
  amount?: number;
  paymentStatus?: string;
  vehicle?: CarshareVehicle;
  teleDrive?: TeleDrive | null;
};

type CarshareState = {
  profile: CarshareProfile | null;
  activeBooking: Booking | null;
  history: Booking[];
};

export function CarsharePage() {
  const { state: accountState } = useOsrh();
  const [carshare, setCarshare] = useState<CarshareState | null>(null);
  const [vehicles, setVehicles] = useState<CarshareVehicle[]>([]);
  const [zones, setZones] = useState<Zone[]>([]);
  const [selected, setSelected] = useState<CarshareVehicle | null>(null);
  const [pricingMode, setPricingMode] =
    useState<Booking["pricingMode"]>("minute");
  const [zoneFilter, setZoneFilter] = useState("");
  const [typeFilter, setTypeFilter] = useState("");
  const [electricOnly, setElectricOnly] = useState(false);
  const [minimumSeats, setMinimumSeats] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async (quiet = false) => {
    if (!quiet) setLoading(true);
    try {
      const [stateResult, vehicleResult] = await Promise.all([
        apiRequest<CarshareState>("/api/v1/carshare/state"),
        apiRequest<{ vehicles: CarshareVehicle[]; zones: Zone[] }>(
          "/api/v1/carshare/vehicles",
        ),
      ]);
      setCarshare(stateResult);
      setVehicles(vehicleResult.vehicles);
      setZones(vehicleResult.zones);
      setSelected(
        (current) =>
          current ||
          vehicleResult.vehicles.find((item) => item.status === "available") ||
          null,
      );
      setError(null);
    } catch (caught) {
      if (!quiet) setError(errorMessage(caught));
    } finally {
      if (!quiet) setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (accountState.user) void load();
  }, [accountState.user, load]);

  useEffect(() => {
    const teleDrive = carshare?.activeBooking?.teleDrive;
    if (!teleDrive || teleDrive.status === "arrived") return;
    const timer = window.setInterval(() => void load(true), 2500);
    return () => window.clearInterval(timer);
  }, [carshare?.activeBooking?.teleDrive, load]);

  const register = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setLoading(true);
    setError(null);
    try {
      await apiRequest("/api/v1/carshare/register", {
        method: "POST",
        body: JSON.stringify({
          licenseNumber: form.get("licenseNumber"),
          licenseCountry: form.get("licenseCountry"),
          licenseIssueDate: form.get("licenseIssueDate"),
          licenseExpiryDate: form.get("licenseExpiryDate"),
          dateOfBirth: form.get("dateOfBirth"),
          nationalId: form.get("nationalId") || null,
          preferredLanguage: form.get("preferredLanguage"),
          termsAccepted: form.get("termsAccepted") === "on",
        }),
      });
      setNotice("Carshare registration submitted for operator verification.");
      await load(true);
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(false);
    }
  };

  const book = async (vehicle: CarshareVehicle) => {
    setLoading(true);
    setError(null);
    try {
      await apiRequest("/api/v1/carshare/bookings", {
        method: "POST",
        body: JSON.stringify({ vehicleId: vehicle.id, pricingMode }),
      });
      setNotice(`${vehicle.make} ${vehicle.model} reserved for 15 minutes.`);
      await load(true);
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(false);
    }
  };

  const start = async () => {
    if (!carshare?.activeBooking) return;
    setLoading(true);
    try {
      await apiRequest(
        `/api/v1/carshare/bookings/${carshare.activeBooking.id}/start`,
        {
          method: "POST",
        },
      );
      setNotice("Vehicle unlocked. Your rental has started.");
      await load(true);
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(false);
    }
  };

  const end = async () => {
    if (!carshare?.activeBooking) return;
    setLoading(true);
    try {
      const position = await currentPosition();
      await apiRequest(
        `/api/v1/carshare/bookings/${carshare.activeBooking.id}/end`,
        {
          method: "POST",
          body: JSON.stringify(position),
        },
      );
      setNotice("Rental ended and drop-off location recorded.");
      await load(true);
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(false);
    }
  };

  const requestTeleDrive = async () => {
    if (!carshare?.activeBooking) return;
    setLoading(true);
    try {
      const position = await currentPosition();
      await apiRequest("/api/v1/carshare/teledrive", {
        method: "POST",
        body: JSON.stringify({
          bookingId: carshare.activeBooking.id,
          targetLatitude: position.latitude,
          targetLongitude: position.longitude,
          speedMultiplier: 10,
        }),
      });
      setNotice("Remote driver is bringing the reserved vehicle to you.");
      await load(true);
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(false);
    }
  };

  const filtered = useMemo(
    () =>
      vehicles.filter(
        (vehicle) =>
          vehicle.status === "available" &&
          (!zoneFilter || vehicle.zoneId === zoneFilter) &&
          (!typeFilter || vehicle.typeCode === typeFilter) &&
          (!electricOnly || vehicle.electric) &&
          vehicle.seatingCapacity >= minimumSeats,
      ),
    [electricOnly, minimumSeats, typeFilter, vehicles, zoneFilter],
  );

  const profile = carshare?.profile;
  const active = carshare?.activeBooking;

  return (
    <ProtectedPage role="passenger">
      <div className="page-header">
        <div>
          <h1>Car Share</h1>
          <p>
            Find, reserve, unlock, and return shared vehicles across Cyprus.
          </p>
        </div>
        {profile ? (
          <span className={`status-badge ${profile.verificationStatus}`}>
            {profile.verificationStatus}
          </span>
        ) : null}
      </div>

      {notice ? <div className="flash flash-success">{notice}</div> : null}
      {error ? <div className="flash flash-error">{error}</div> : null}
      {loading && !carshare ? (
        <div className="card page-loading">Loading carshare…</div>
      ) : null}
      {carshare && !profile ? (
        <CarshareRegistrationForm onSubmit={register} loading={loading} />
      ) : null}
      {profile?.verificationStatus === "pending" ? (
        <section className="card pending-card">
          <h2 className="icon-heading">
            <Clock3 aria-hidden="true" size={21} />
            Verification Pending
          </h2>
          <p>
            An OSRH operator is reviewing your licence details. Fleet browsing
            remains available, but booking unlocks after approval.
          </p>
        </section>
      ) : null}
      {profile?.verificationStatus === "rejected" ? (
        <section className="card">
          <h2 className="icon-heading">
            <XCircle aria-hidden="true" size={21} />
            Verification Failed
          </h2>
          <p>
            Contact OSRH support through Messages to resolve your application.
          </p>
        </section>
      ) : null}
      {active ? (
        <ActiveBooking
          booking={active}
          loading={loading}
          onStart={start}
          onEnd={end}
          onTeleDrive={requestTeleDrive}
        />
      ) : null}

      {profile ? (
        <div className="carshare-layout">
          <CarshareFilters
            zones={zones}
            vehicles={vehicles}
            zoneFilter={zoneFilter}
            typeFilter={typeFilter}
            electricOnly={electricOnly}
            minimumSeats={minimumSeats}
            pricingMode={pricingMode}
            setZoneFilter={setZoneFilter}
            setTypeFilter={setTypeFilter}
            setElectricOnly={setElectricOnly}
            setMinimumSeats={setMinimumSeats}
            setPricingMode={setPricingMode}
          />
          <div className="stack">
            <section className="card">
              <div className="card-header">
                <h2 className="card-title">Available Vehicles</h2>
                <span className="badge badge-info">{filtered.length}</span>
              </div>
              {selected ? (
                <RideMap
                  driver={{
                    label: `${selected.make} ${selected.model} · ${selected.plateNumber}`,
                    latitude: selected.latitude,
                    longitude: selected.longitude,
                  }}
                  showGeofences
                />
              ) : null}
            </section>
            <section className="carshare-vehicle-grid">
              {filtered.map((vehicle) => (
                <article
                  className={`card carshare-vehicle-card${selected?.id === vehicle.id ? " selected" : ""}`}
                  key={vehicle.id}
                >
                  <div className="vehicle-photo-placeholder">
                    {vehicle.electric ? (
                      <BatteryCharging aria-hidden="true" size={35} />
                    ) : (
                      <CarFront aria-hidden="true" size={35} />
                    )}
                  </div>
                  <h3>
                    {vehicle.make} {vehicle.model}
                  </h3>
                  <p>
                    {vehicle.plateNumber} · {vehicle.color} ·{" "}
                    {vehicle.seatingCapacity} seats
                  </p>
                  <p>{vehicle.zone.name}</p>
                  <div className="pricing-row">
                    <span>€{vehicle.pricePerMinute.toFixed(2)}/min</span>
                    <span>€{vehicle.pricePerHour.toFixed(2)}/hr</span>
                    <span>€{vehicle.pricePerDay.toFixed(2)}/day</span>
                  </div>
                  <div className="action-row">
                    <span className="badge badge-success">
                      {vehicle.energyLevel}%
                    </span>
                    <button
                      className="btn btn-outline btn-small"
                      type="button"
                      aria-pressed={selected?.id === vehicle.id}
                      onClick={() => setSelected(vehicle)}
                    >
                      {selected?.id === vehicle.id
                        ? "Shown on map"
                        : "Preview on map"}
                    </button>
                    <button
                      className="btn btn-primary btn-small"
                      type="button"
                      disabled={
                        loading ||
                        profile.verificationStatus !== "approved" ||
                        Boolean(active)
                      }
                      onClick={() => void book(vehicle)}
                    >
                      Reserve
                    </button>
                  </div>
                </article>
              ))}
            </section>
          </div>
        </div>
      ) : null}

      {carshare ? <CarshareHistory bookings={carshare.history} /> : null}
    </ProtectedPage>
  );
}

function CarshareFilters({
  zones,
  vehicles,
  zoneFilter,
  typeFilter,
  electricOnly,
  minimumSeats,
  pricingMode,
  setZoneFilter,
  setTypeFilter,
  setElectricOnly,
  setMinimumSeats,
  setPricingMode,
}: {
  zones: Zone[];
  vehicles: CarshareVehicle[];
  zoneFilter: string;
  typeFilter: string;
  electricOnly: boolean;
  minimumSeats: number;
  pricingMode: Booking["pricingMode"];
  setZoneFilter: (value: string) => void;
  setTypeFilter: (value: string) => void;
  setElectricOnly: (value: boolean) => void;
  setMinimumSeats: (value: number) => void;
  setPricingMode: (value: Booking["pricingMode"]) => void;
}) {
  const types = [
    ...new Map(vehicles.map((vehicle) => [vehicle.typeCode, vehicle.typeName])),
  ];
  return (
    <aside className="card carshare-filters">
      <h2 className="card-title icon-heading">
        <Search aria-hidden="true" size={21} />
        Find a Vehicle
      </h2>
      <Select label="Zone" value={zoneFilter} onChange={setZoneFilter}>
        <option value="">All zones</option>
        {zones.map((zone) => (
          <option value={zone.id} key={zone.id}>
            {zone.name}
          </option>
        ))}
      </Select>
      <Select label="Vehicle Type" value={typeFilter} onChange={setTypeFilter}>
        <option value="">All types</option>
        {types.map(([code, name]) => (
          <option value={code} key={code}>
            {name}
          </option>
        ))}
      </Select>
      <Select
        label="Minimum Seats"
        value={String(minimumSeats)}
        onChange={(value) => setMinimumSeats(Number(value))}
      >
        {[1, 2, 4, 5, 7].map((value) => (
          <option value={value} key={value}>
            {value}+
          </option>
        ))}
      </Select>
      <label className="checkbox-label">
        <input
          type="checkbox"
          checked={electricOnly}
          onChange={(event) => setElectricOnly(event.target.checked)}
        />
        <span>Electric only</span>
      </label>
      <Select
        label="Pricing mode"
        value={pricingMode}
        onChange={(value) => setPricingMode(value as Booking["pricingMode"])}
      >
        <option value="minute">Per minute</option>
        <option value="hour">Per hour</option>
        <option value="day">Per day</option>
      </Select>
    </aside>
  );
}

function Select({
  label,
  value,
  onChange,
  children,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  children: React.ReactNode;
}) {
  const id = label.toLowerCase().replaceAll(" ", "-");
  return (
    <div className="form-group">
      <label className="form-label" htmlFor={id}>
        {label}
      </label>
      <select
        className="form-control"
        id={id}
        value={value}
        onChange={(event) => onChange(event.target.value)}
      >
        {children}
      </select>
    </div>
  );
}

function CarshareRegistrationForm({
  onSubmit,
  loading,
}: {
  onSubmit: (event: FormEvent<HTMLFormElement>) => Promise<void>;
  loading: boolean;
}) {
  const today = new Date().toISOString().slice(0, 10);
  return (
    <form className="card registration-card" onSubmit={onSubmit}>
      <div className="card-header">
        <div>
          <h1 className="card-title icon-heading">
            <CarFront aria-hidden="true" size={22} />
            Register for Car Share
          </h1>
          <p>
            Driver licence verification is required before reserving vehicles.
          </p>
        </div>
      </div>
      <div className="form-section">
        <h3 className="icon-heading">
          <ClipboardList aria-hidden="true" size={18} />
          Driver&apos;s Licence Information
        </h3>
        <Field label="Licence Number" name="licenseNumber" required />
        <div className="form-grid">
          <div className="form-group">
            <label className="form-label" htmlFor="licenseCountry">
              Country of Issue
            </label>
            <select
              className="form-control"
              id="licenseCountry"
              name="licenseCountry"
              defaultValue="Cyprus"
            >
              <option>Cyprus</option>
              <option>Greece</option>
              <option>United Kingdom</option>
              <option>Other EU</option>
            </select>
          </div>
          <Field
            label="Date of Birth"
            name="dateOfBirth"
            type="date"
            max={today}
            required
          />
        </div>
        <div className="form-grid">
          <Field
            label="Issue Date"
            name="licenseIssueDate"
            type="date"
            max={today}
            required
          />
          <Field
            label="Expiry Date"
            name="licenseExpiryDate"
            type="date"
            min={today}
            required
          />
        </div>
      </div>
      <div className="form-section">
        <h3 className="icon-heading">
          <UserRound aria-hidden="true" size={18} />
          Personal Information
        </h3>
        <Field
          label="National ID / Passport Number (optional)"
          name="nationalId"
        />
        <div className="form-group">
          <label className="form-label" htmlFor="preferredLanguage">
            Preferred Language
          </label>
          <select
            className="form-control"
            name="preferredLanguage"
            id="preferredLanguage"
          >
            <option value="en">English</option>
            <option value="el">Greek</option>
            <option value="tr">Turkish</option>
          </select>
        </div>
        <label className="checkbox-label">
          <input type="checkbox" name="termsAccepted" required />
          <span>
            I accept the carshare terms, vehicle rules, and geofence policy.
          </span>
        </label>
      </div>
      <button
        className="btn btn-primary btn-block"
        type="submit"
        disabled={loading}
      >
        {loading ? "Submitting…" : "Submit Registration"}
      </button>
    </form>
  );
}

function ActiveBooking({
  booking,
  loading,
  onStart,
  onEnd,
  onTeleDrive,
}: {
  booking: Booking;
  loading: boolean;
  onStart: () => Promise<void>;
  onEnd: () => Promise<void>;
  onTeleDrive: () => Promise<void>;
}) {
  const vehicle = booking.vehicle;
  const position = booking.teleDrive
    ? {
        label: vehicle ? `${vehicle.make} ${vehicle.model}` : booking.vehicleId,
        latitude: booking.teleDrive.currentLatitude,
        longitude: booking.teleDrive.currentLongitude,
      }
    : vehicle
      ? {
          label: `${vehicle.make} ${vehicle.model}`,
          latitude: vehicle.latitude,
          longitude: vehicle.longitude,
        }
      : null;
  return (
    <section className="card active-carshare">
      <div className="card-header">
        <div>
          <h2 className="card-title icon-heading">
            {booking.status === "in_progress" ? (
              <CarFront aria-hidden="true" size={21} />
            ) : (
              <CalendarDays aria-hidden="true" size={21} />
            )}
            {booking.status === "in_progress"
              ? "Active Rental"
              : "Reserved Vehicle"}
          </h2>
          <p>
            {vehicle?.make} {vehicle?.model} · {booking.vehicleId}
          </p>
        </div>
        <span className={`status-badge ${booking.status}`}>
          {booking.status.replaceAll("_", " ")}
        </span>
      </div>
      {position ? (
        <div className="ride-detail-map">
          <RideMap driver={position} showGeofences />
        </div>
      ) : null}
      {booking.teleDrive ? (
        <div className="tele-drive-status">
          <strong>
            Remote delivery: {booking.teleDrive.status.replaceAll("_", " ")}
          </strong>
          <div className="progress-track">
            <span style={{ width: `${booking.teleDrive.progressPercent}%` }} />
          </div>
          <p>
            {booking.teleDrive.progressPercent}% · ETA{" "}
            {booking.teleDrive.remainingSeconds}s
          </p>
        </div>
      ) : null}
      <div className="action-row">
        {booking.status === "reserved" ? (
          <>
            <button
              className="btn btn-primary"
              type="button"
              disabled={loading}
              onClick={() => void onStart()}
            >
              Unlock & start rental
            </button>
            <button
              className="btn btn-outline"
              type="button"
              disabled={loading || Boolean(booking.teleDrive)}
              onClick={() => void onTeleDrive()}
            >
              <MapPinned aria-hidden="true" size={18} />
              Drive this car to me
            </button>
          </>
        ) : (
          <button
            className="btn btn-danger"
            type="button"
            disabled={loading}
            onClick={() => void onEnd()}
          >
            End rental at my location
          </button>
        )}
      </div>
    </section>
  );
}

function CarshareHistory({ bookings }: { bookings: Booking[] }) {
  return (
    <section className="card">
      <div className="card-header">
        <h2 className="card-title">History & Payments</h2>
      </div>
      <div className="data-table-wrap">
        <table className="data-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Vehicle</th>
              <th>Pricing</th>
              <th>Duration</th>
              <th>Amount</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {bookings.map((booking) => (
              <tr key={booking.id}>
                <td>
                  {new Date(
                    booking.endedAt || booking.expiresAt,
                  ).toLocaleString()}
                </td>
                <td>{booking.vehicleId}</td>
                <td>{booking.pricingMode}</td>
                <td>
                  {booking.durationMinutes
                    ? `${booking.durationMinutes} min`
                    : "—"}
                </td>
                <td>
                  {booking.amount != null
                    ? `€${booking.amount.toFixed(2)}`
                    : "—"}
                </td>
                <td>
                  <span className={`status-badge ${booking.status}`}>
                    {booking.status}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {bookings.length === 0 ? (
          <div className="empty-state">No carshare history yet.</div>
        ) : null}
      </div>
    </section>
  );
}

function Field({
  label,
  name,
  ...props
}: {
  label: string;
  name: string;
} & React.InputHTMLAttributes<HTMLInputElement>) {
  return (
    <div className="form-group">
      <label className="form-label" htmlFor={name}>
        {label}
      </label>
      <input className="form-control" id={name} name={name} {...props} />
    </div>
  );
}

function currentPosition() {
  return new Promise<{ latitude: number; longitude: number }>(
    (resolve, reject) =>
      navigator.geolocation.getCurrentPosition(
        (position) =>
          resolve({
            latitude: position.coords.latitude,
            longitude: position.coords.longitude,
          }),
        () =>
          reject(
            new Error("Location access is required for this carshare action."),
          ),
        { enableHighAccuracy: true, timeout: 12_000 },
      ),
  );
}
