"use client";

import Link from "next/link";
import { FormEvent, useEffect, useState } from "react";
import { CarFront } from "lucide-react";
import { useOsrh } from "@/components/osrh-provider";
import { ProtectedPage } from "@/components/protected-page";
import { WalletPanel } from "@/components/wallet-panel";
import { apiRequest, errorMessage } from "@/lib/api";
import { formatKas, rideStatusLabel, shortHash } from "@/lib/ride";
import type { ApiUser, Ride, Vehicle } from "@/lib/types";

type Role = "passenger" | "driver";

export function AccountSettingsPage({ role }: { role: Role }) {
  const { state, refreshSession } = useOsrh();
  const [loading, setLoading] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const user = state.user;

  const saveProfile = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setLoading("profile");
    setError(null);
    setNotice(null);
    try {
      await apiRequest<{ user: ApiUser }>("/api/v1/account/profile", {
        method: "PATCH",
        body: JSON.stringify({
          fullName: form.get("fullName"),
          phone: form.get("phone") || null,
          streetAddress: form.get("streetAddress") || null,
          city: form.get("city") || null,
          postalCode: form.get("postalCode") || null,
          country: form.get("country") || null,
        }),
      });
      await refreshSession();
      setNotice("Profile details updated.");
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(null);
    }
  };

  const savePreferences = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setLoading("preferences");
    setError(null);
    setNotice(null);
    try {
      await apiRequest("/api/v1/account/preferences", {
        method: "PATCH",
        body: JSON.stringify({
          locationTracking: form.get("locationTracking") === "on",
          notifications: form.get("notifications") === "on",
          emailUpdates: form.get("emailUpdates") === "on",
          dataSharing: form.get("dataSharing") === "on",
        }),
      });
      await refreshSession();
      setNotice("Privacy preferences updated.");
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(null);
    }
  };

  const changePassword = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setLoading("password");
    setError(null);
    setNotice(null);
    try {
      await apiRequest("/api/v1/account/password", {
        method: "POST",
        body: JSON.stringify({
          currentPassword: form.get("currentPassword"),
          newPassword: form.get("newPassword"),
          newPasswordConfirm: form.get("newPasswordConfirm"),
        }),
      });
      setNotice("Password changed. Sign in again with the new password.");
      window.setTimeout(() => window.location.assign("/login"), 1200);
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(null);
    }
  };

  return (
    <ProtectedPage role={role}>
      <div className="page-header">
        <div>
          <h1>{role === "driver" ? "Driver Settings" : "Account Settings"}</h1>
          <p>Manage your profile, privacy, wallet, and security.</p>
        </div>
        <span className={`status-badge ${user?.status}`}>{user?.status}</span>
      </div>
      {notice ? <div className="flash flash-success">{notice}</div> : null}
      {error ? <div className="flash flash-error">{error}</div> : null}
      <div className="content-grid">
        <div className="stack">
          <form className="card" onSubmit={saveProfile}>
            <div className="card-header">
              <h2 className="card-title">Profile Information</h2>
            </div>
            <div className="form-grid">
              <Field
                label="Full Name"
                name="fullName"
                defaultValue={user?.fullName || ""}
                required
              />
              <Field
                label="Email"
                name="email"
                type="email"
                value={user?.email || ""}
                readOnly
              />
            </div>
            <div className="form-grid">
              <Field
                label="Phone"
                name="phone"
                defaultValue={user?.phone || ""}
              />
              <Field
                label="Country"
                name="country"
                defaultValue={user?.addressProfile?.country || "Cyprus"}
              />
            </div>
            <Field
              label="Street Address"
              name="streetAddress"
              defaultValue={user?.addressProfile?.streetAddress || ""}
            />
            <div className="form-grid">
              <Field
                label="City"
                name="city"
                defaultValue={user?.addressProfile?.city || ""}
              />
              <Field
                label="Postal Code"
                name="postalCode"
                defaultValue={user?.addressProfile?.postalCode || ""}
              />
            </div>
            <button
              className="btn btn-primary"
              type="submit"
              disabled={loading !== null}
            >
              {loading === "profile" ? "Saving…" : "Save profile"}
            </button>
          </form>

          <form className="card" onSubmit={savePreferences}>
            <div className="card-header">
              <div>
                <h2 className="card-title">Privacy Preferences (GDPR)</h2>
                <p className="form-help">
                  These are the same controls chosen during registration.
                </p>
              </div>
            </div>
            <Preference
              name="locationTracking"
              label="Allow location tracking for pickup and live ride updates"
              defaultChecked={user?.preferences?.locationTracking ?? true}
            />
            <Preference
              name="notifications"
              label="Allow ride and account notifications"
              defaultChecked={user?.preferences?.notifications ?? true}
            />
            <Preference
              name="emailUpdates"
              label="Allow receipts and service updates by email"
              defaultChecked={user?.preferences?.emailUpdates ?? true}
            />
            <Preference
              name="dataSharing"
              label="Allow anonymized analytics data sharing"
              defaultChecked={user?.preferences?.dataSharing ?? false}
            />
            <button
              className="btn btn-primary"
              type="submit"
              disabled={loading !== null}
            >
              {loading === "preferences" ? "Saving…" : "Save preferences"}
            </button>
          </form>

          <form className="card" onSubmit={changePassword}>
            <div className="card-header">
              <h2 className="card-title">Change Password</h2>
            </div>
            <Field
              label="Current Password"
              name="currentPassword"
              type="password"
              autoComplete="current-password"
              required
            />
            <div className="form-grid">
              <Field
                label="New Password"
                name="newPassword"
                type="password"
                autoComplete="new-password"
                minLength={8}
                required
              />
              <Field
                label="Confirm New Password"
                name="newPasswordConfirm"
                type="password"
                autoComplete="new-password"
                minLength={8}
                required
              />
            </div>
            <button
              className="btn btn-outline"
              type="submit"
              disabled={loading !== null}
            >
              {loading === "password" ? "Changing…" : "Change password"}
            </button>
          </form>
        </div>
        <aside className="stack">
          <WalletPanel />
          <section className="card">
            <h2 className="card-title">Account Details</h2>
            <dl className="detail-list">
              <div>
                <dt>Role</dt>
                <dd>{user?.role}</dd>
              </div>
              <div>
                <dt>Status</dt>
                <dd>{user?.status}</dd>
              </div>
              {role === "driver" ? (
                <div>
                  <dt>Verification</dt>
                  <dd>{user?.verificationStatus}</dd>
                </div>
              ) : null}
              <div>
                <dt>Created</dt>
                <dd>
                  {user?.createdAt
                    ? new Date(user.createdAt).toLocaleDateString()
                    : "—"}
                </dd>
              </div>
              <div>
                <dt>Wallet</dt>
                <dd className="hash">{shortHash(user?.address)}</dd>
              </div>
            </dl>
          </section>
          <Link className="btn btn-outline" href="/privacy">
            Privacy requests
          </Link>
        </aside>
      </div>
    </ProtectedPage>
  );
}

export function ProfilePage() {
  const { state } = useOsrh();
  const role: Role = state.user?.role === "driver" ? "driver" : "passenger";
  return (
    <ProtectedPage>
      <div className="page-header">
        <div>
          <h1>My Profile</h1>
          <p>Your OSRH account and linked Kaspa payment identity.</p>
        </div>
        <Link className="btn btn-primary" href={`/${role}/settings`}>
          Edit settings
        </Link>
      </div>
      <div className="content-grid">
        <section className="card">
          <div className="profile-summary">
            <div className="profile-avatar" aria-hidden="true">
              {(state.user?.fullName || "O").charAt(0).toUpperCase()}
            </div>
            <div>
              <h2>{state.user?.fullName}</h2>
              <p>{state.user?.email}</p>
              <span className={`status-badge ${state.user?.status}`}>
                {state.user?.role} · {state.user?.status}
              </span>
            </div>
          </div>
          <dl className="detail-list">
            <div>
              <dt>Phone</dt>
              <dd>{state.user?.phone || "—"}</dd>
            </div>
            <div>
              <dt>Address</dt>
              <dd>
                {[
                  state.user?.addressProfile?.streetAddress,
                  state.user?.addressProfile?.city,
                  state.user?.addressProfile?.postalCode,
                  state.user?.addressProfile?.country,
                ]
                  .filter(Boolean)
                  .join(", ") || "—"}
              </dd>
            </div>
            <div>
              <dt>Kaspa wallet</dt>
              <dd className="hash">{state.user?.address || "Not linked"}</dd>
            </div>
          </dl>
        </section>
        <WalletPanel />
      </div>
    </ProtectedPage>
  );
}

export function DriverVehiclesPage() {
  const { state, refreshSession } = useOsrh();
  const [vehicles, setVehicles] = useState<Vehicle[]>([]);
  const [loading, setLoading] = useState<string | null>("list");
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = async () => {
    try {
      const result = await apiRequest<{ vehicles: Vehicle[] }>(
        "/api/v1/driver/vehicles",
      );
      setVehicles(result.vehicles);
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(null);
    }
  };
  useEffect(() => {
    if (state.user) void load();
  }, [state.user]);

  const addVehicle = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setLoading("vehicle");
    setError(null);
    setNotice(null);
    try {
      await apiRequest<Vehicle>("/api/v1/driver/vehicles", {
        method: "POST",
        body: JSON.stringify({
          vehicleType: form.get("vehicleType"),
          plateNumber: form.get("plateNumber"),
          make: form.get("make"),
          model: form.get("model"),
          year: Number(form.get("year")),
          color: form.get("color") || null,
          seatingCapacity: Number(form.get("seatingCapacity")),
          wheelchairReady: form.get("wheelchairReady") === "on",
        }),
      });
      event.currentTarget.reset();
      setNotice("Vehicle submitted for inspection.");
      await load();
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(null);
    }
  };

  const setAvailability = async (available: boolean, vehicleId?: string) => {
    setLoading("availability");
    setError(null);
    setNotice(null);
    try {
      let latitude: number | null = null;
      let longitude: number | null = null;
      if (available) {
        const position = await new Promise<GeolocationPosition>(
          (resolve, reject) =>
            navigator.geolocation.getCurrentPosition(resolve, reject, {
              enableHighAccuracy: true,
              timeout: 12_000,
            }),
        );
        latitude = position.coords.latitude;
        longitude = position.coords.longitude;
      }
      await apiRequest("/api/v1/driver/availability", {
        method: "PATCH",
        body: JSON.stringify({
          available,
          vehicleId: available ? vehicleId : null,
          latitude,
          longitude,
          useGps: available,
        }),
      });
      await refreshSession();
      setNotice(
        available
          ? "You are online and available for normal rides."
          : "You are now offline.",
      );
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(null);
    }
  };

  const activeVehicle =
    vehicles.find(
      (vehicle) => vehicle.id === state.user?.driverProfile?.activeVehicleId,
    ) ?? vehicles.find((vehicle) => vehicle.isActive);
  const approved =
    state.user?.status === "active" &&
    state.user?.verificationStatus === "approved";
  return (
    <ProtectedPage role="driver">
      <div className="page-header">
        <div>
          <h1>My Vehicles</h1>
          <p>Register vehicles, inspection details, and driver availability.</p>
        </div>
        {state.user?.driverProfile?.isAvailable ? (
          <button
            className="btn btn-danger"
            type="button"
            disabled={loading !== null}
            onClick={() => void setAvailability(false)}
          >
            Go offline
          </button>
        ) : (
          <button
            className="btn btn-primary"
            type="button"
            disabled={!approved || !activeVehicle || loading !== null}
            onClick={() => void setAvailability(true, activeVehicle?.id)}
          >
            Go online
          </button>
        )}
      </div>
      {!approved ? (
        <div className="flash flash-warning">
          Driver approval is required before you can go online.
        </div>
      ) : null}
      {notice ? <div className="flash flash-success">{notice}</div> : null}
      {error ? <div className="flash flash-error">{error}</div> : null}
      <div className="content-grid">
        <section className="card">
          <div className="card-header">
            <h2 className="card-title">Registered vehicles</h2>
            <span
              className={`badge ${state.user?.driverProfile?.isAvailable ? "badge-success" : "badge-warning"}`}
            >
              {state.user?.driverProfile?.isAvailable ? "Online" : "Offline"}
            </span>
          </div>
          {loading === "list" ? (
            <div className="page-loading">Loading vehicles…</div>
          ) : (
            <div className="vehicle-grid">
              {vehicles.map((vehicle) => (
                <article className="vehicle-card" key={vehicle.id}>
                  <div className="vehicle-icon">
                    <CarFront aria-hidden="true" size={22} />
                  </div>
                  <div>
                    <h3>
                      {vehicle.make} {vehicle.model}
                    </h3>
                    <p>
                      {vehicle.plateNumber} · {vehicle.year} ·{" "}
                      {vehicle.color || "Colour not set"}
                    </p>
                    <div className="action-row">
                      <span className={`status-badge ${vehicle.status}`}>
                        {vehicle.status.replaceAll("_", " ")}
                      </span>
                      {vehicle.wheelchairReady ? (
                        <span className="badge badge-info">Accessible</span>
                      ) : null}
                    </div>
                  </div>
                </article>
              ))}
              {vehicles.length === 0 ? (
                <div className="empty-state">No vehicles registered yet.</div>
              ) : null}
            </div>
          )}
        </section>
        <form className="card" onSubmit={addVehicle}>
          <h2 className="card-title">Add Vehicle</h2>
          <div className="form-grid">
            <Field
              label="Vehicle Type"
              name="vehicleType"
              placeholder="Sedan"
              required
            />
            <Field label="Plate Number" name="plateNumber" required />
          </div>
          <div className="form-grid">
            <Field label="Make" name="make" required />
            <Field label="Model" name="model" required />
          </div>
          <div className="form-grid">
            <Field
              label="Year"
              name="year"
              type="number"
              min={1980}
              max={2100}
              defaultValue={new Date().getFullYear()}
              required
            />
            <Field label="Colour" name="color" />
          </div>
          <Field
            label="Seating Capacity"
            name="seatingCapacity"
            type="number"
            min={1}
            max={20}
            defaultValue={4}
            required
          />
          <Preference name="wheelchairReady" label="Wheelchair-ready vehicle" />
          <button
            className="btn btn-primary btn-block"
            type="submit"
            disabled={loading !== null}
          >
            {loading === "vehicle" ? "Submitting…" : "Add vehicle"}
          </button>
        </form>
      </div>
    </ProtectedPage>
  );
}

type Earnings = {
  totalSompi: string;
  totalKas: string;
  completedTrips: number;
  averageKas: string;
  recentPayments: Ride[];
};
export function DriverEarningsPage() {
  const { state } = useOsrh();
  const [data, setData] = useState<Earnings | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (state.user)
      void apiRequest<Earnings>("/api/v1/driver/earnings")
        .then(setData)
        .catch((caught) => setError(errorMessage(caught)));
  }, [state.user]);
  return (
    <ProtectedPage role="driver">
      <div className="page-header">
        <div>
          <h1>Driver Earnings</h1>
          <p>Completed trips paid through SilverScript covenant settlement.</p>
        </div>
        <span className="badge badge-success">KAS</span>
      </div>
      {error ? <div className="flash flash-error">{error}</div> : null}
      {data ? (
        <>
          <div className="stats-overview">
            <Stat label="Total earnings" value={`${data.totalKas} KAS`} />
            <Stat label="Completed trips" value={data.completedTrips} />
            <Stat label="Average fare" value={`${data.averageKas} KAS`} />
            <Stat label="Wallet" value={shortHash(state.user?.address, 8, 4)} />
          </div>
          <section className="card">
            <div className="card-header">
              <h2 className="card-title">Recent payouts</h2>
            </div>
            <div className="data-table-wrap">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Route</th>
                    <th>Status</th>
                    <th>Amount</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  {data.recentPayments.map((ride) => (
                    <tr key={ride.id}>
                      <td>{new Date(ride.updatedAt).toLocaleString()}</td>
                      <td>
                        {ride.pickup.label} → {ride.dropoff.label}
                      </td>
                      <td>{rideStatusLabel[ride.status]}</td>
                      <td>{formatKas(ride.quotedFareSompi)}</td>
                      <td>
                        <Link
                          className="btn btn-outline btn-small"
                          href={`/driver/rides/${ride.id}`}
                        >
                          Details
                        </Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {data.recentPayments.length === 0 ? (
                <div className="empty-state">
                  No completed driver payouts yet.
                </div>
              ) : null}
            </div>
          </section>
        </>
      ) : !error ? (
        <div className="card page-loading">Loading earnings…</div>
      ) : null}
    </ProtectedPage>
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
      <input id={name} name={name} className="form-control" {...props} />
    </div>
  );
}
function Preference({
  name,
  label,
  defaultChecked,
}: {
  name: string;
  label: string;
  defaultChecked?: boolean;
}) {
  return (
    <div className="form-group">
      <label className="checkbox-label">
        <input type="checkbox" name={name} defaultChecked={defaultChecked} />
        <span>{label}</span>
      </label>
    </div>
  );
}
function Stat({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="stat-card">
      <span className="stat-label">{label}</span>
      <span className="stat-value">{value}</span>
    </div>
  );
}
