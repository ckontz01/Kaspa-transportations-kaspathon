"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { useOsrh } from "@/components/osrh-provider";
import { ProtectedPage } from "@/components/protected-page";
import { apiRequest, errorMessage } from "@/lib/api";

type AutonomousVehicle = {
  id: string;
  plateNumber: string;
  make: string;
  model: string;
  batteryLevel: number;
  status: string;
  wheelchairReady: boolean;
};
type AutonomousRide = {
  id: string;
  vehicleId: string;
  pickup: { label: string };
  dropoff: { label: string };
  status: string;
  estimatedFare: number;
  createdAt: string;
};
type CarshareCustomer = {
  id: string;
  account: { fullName?: string; email?: string };
  licenseLast4: string;
  licenseCountry: string;
  verificationStatus: string;
  createdAt: string;
};
type CarshareVehicle = {
  id: string;
  plateNumber: string;
  make: string;
  model: string;
  typeName: string;
  zoneId: string;
  status: string;
  energyLevel: number;
};
type CarshareZone = {
  id: string;
  name: string;
  city: string;
  radiusMeters: number;
};

export function OperatorAutonomousPage() {
  const { state } = useOsrh();
  const [vehicles, setVehicles] = useState<AutonomousVehicle[]>([]);
  const [rides, setRides] = useState<AutonomousRide[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [updating, setUpdating] = useState<string | null>(null);
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
    }
  }, []);
  useEffect(() => {
    if (state.user) void load();
  }, [load, state.user]);
  const updateStatus = async (vehicleId: string, status: string) => {
    setUpdating(vehicleId);
    setNotice(null);
    setError(null);
    try {
      await apiRequest(`/api/v1/operator/autonomous/vehicles/${vehicleId}`, {
        method: "PATCH",
        body: JSON.stringify({ status }),
      });
      setNotice(`${vehicleId} marked ${status}.`);
      await load();
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setUpdating(null);
    }
  };
  return (
    <ProtectedPage role={["operator", "admin"]}>
      <div className="page-header">
        <div>
          <h1>Autonomous Hub</h1>
          <p>Fleet availability and autonomous ride operations.</p>
        </div>
        <div className="action-row">
          <Link className="btn btn-outline" href="/operator/fleet-map">
            Fleet map
          </Link>
          <button
            className="btn btn-outline"
            type="button"
            onClick={() => void load()}
          >
            Refresh
          </button>
        </div>
      </div>
      {notice ? <div className="flash flash-success">{notice}</div> : null}
      {error ? <div className="flash flash-error">{error}</div> : null}
      <div className="stats-overview">
        <Stat label="Fleet vehicles" value={vehicles.length} />
        <Stat
          label="Available"
          value={vehicles.filter((item) => item.status === "available").length}
        />
        <Stat
          label="Active rides"
          value={
            rides.filter(
              (item) => !["completed", "cancelled"].includes(item.status),
            ).length
          }
        />
        <Stat
          label="Completed"
          value={rides.filter((item) => item.status === "completed").length}
        />
      </div>
      <section className="card">
        <div className="card-header">
          <h2 className="card-title">Autonomous Fleet</h2>
        </div>
        <div className="data-table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Code</th>
                <th>Vehicle</th>
                <th>Plate</th>
                <th>Battery</th>
                <th>Accessible</th>
                <th>Status</th>
                <th>Management</th>
              </tr>
            </thead>
            <tbody>
              {vehicles.map((vehicle) => (
                <tr key={vehicle.id}>
                  <td>{vehicle.id}</td>
                  <td>
                    {vehicle.make} {vehicle.model}
                  </td>
                  <td>{vehicle.plateNumber}</td>
                  <td>{vehicle.batteryLevel}%</td>
                  <td>{vehicle.wheelchairReady ? "Yes" : "No"}</td>
                  <td>
                    <span className={`status-badge ${vehicle.status}`}>
                      {vehicle.status}
                    </span>
                  </td>
                  <td>
                    <select
                      className="form-control"
                      aria-label={`Status for ${vehicle.id}`}
                      value={vehicle.status}
                      disabled={
                        updating !== null ||
                        ["reserved", "busy"].includes(vehicle.status)
                      }
                      onChange={(event) =>
                        void updateStatus(vehicle.id, event.target.value)
                      }
                    >
                      {![
                        "available",
                        "offline",
                        "maintenance",
                        "charging",
                      ].includes(vehicle.status) ? (
                        <option value={vehicle.status}>{vehicle.status}</option>
                      ) : null}
                      <option value="available">Available</option>
                      <option value="offline">Offline</option>
                      <option value="maintenance">Maintenance</option>
                      <option value="charging">Charging</option>
                    </select>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
      <section className="card">
        <div className="card-header">
          <h2 className="card-title">Recent Autonomous Rides</h2>
        </div>
        <div className="data-table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Vehicle</th>
                <th>Route</th>
                <th>Fare</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {rides.map((ride) => (
                <tr key={ride.id}>
                  <td>{new Date(ride.createdAt).toLocaleString()}</td>
                  <td>{ride.vehicleId}</td>
                  <td>
                    {ride.pickup.label} → {ride.dropoff.label}
                  </td>
                  <td>€{ride.estimatedFare.toFixed(2)}</td>
                  <td>
                    <span className={`status-badge ${ride.status}`}>
                      {ride.status.replaceAll("_", " ")}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {rides.length === 0 ? (
            <div className="empty-state">No autonomous rides.</div>
          ) : null}
        </div>
      </section>
    </ProtectedPage>
  );
}

export function OperatorCarsharePage() {
  const { state } = useOsrh();
  const [customers, setCustomers] = useState<CarshareCustomer[]>([]);
  const [vehicles, setVehicles] = useState<CarshareVehicle[]>([]);
  const [zones, setZones] = useState<CarshareZone[]>([]);
  const [loading, setLoading] = useState<string | null>("list");
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const load = useCallback(async () => {
    try {
      const [customerResult, fleetResult] = await Promise.all([
        apiRequest<{ customers: CarshareCustomer[] }>(
          "/api/v1/operator/carshare/customers",
        ),
        apiRequest<{ vehicles: CarshareVehicle[]; zones: CarshareZone[] }>(
          "/api/v1/carshare/vehicles",
        ),
      ]);
      setCustomers(customerResult.customers);
      setVehicles(fleetResult.vehicles);
      setZones(fleetResult.zones);
      setError(null);
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(null);
    }
  }, []);
  useEffect(() => {
    if (state.user) void load();
  }, [load, state.user]);
  const verify = async (id: string, status: "approved" | "rejected") => {
    setLoading(id);
    setError(null);
    try {
      await apiRequest(`/api/v1/operator/carshare/customers/${id}`, {
        method: "PATCH",
        body: JSON.stringify({ status }),
      });
      setNotice(`Carshare registration ${status}.`);
      await load();
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(null);
    }
  };
  return (
    <ProtectedPage role={["operator", "admin"]}>
      <div className="page-header">
        <div>
          <h1>Carshare Hub</h1>
          <p>Review self-drive customer registrations.</p>
        </div>
        <button
          className="btn btn-outline"
          type="button"
          onClick={() => void load()}
        >
          Refresh
        </button>
      </div>
      {notice ? <div className="flash flash-success">{notice}</div> : null}
      {error ? <div className="flash flash-error">{error}</div> : null}
      <div className="stats-overview">
        <Stat label="Registrations" value={customers.length} />
        <Stat label="Fleet vehicles" value={vehicles.length} />
        <Stat
          label="Available"
          value={vehicles.filter((item) => item.status === "available").length}
        />
        <Stat label="Zones" value={zones.length} />
      </div>
      <section className="card">
        {loading === "list" ? (
          <div className="page-loading">Loading registrations…</div>
        ) : (
          <div className="data-table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Registered</th>
                  <th>Customer</th>
                  <th>Licence</th>
                  <th>Country</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {customers.map((customer) => (
                  <tr key={customer.id}>
                    <td>{new Date(customer.createdAt).toLocaleDateString()}</td>
                    <td>
                      {customer.account.fullName || "Passenger"}
                      <br />
                      <span className="form-help">
                        {customer.account.email}
                      </span>
                    </td>
                    <td>•••• {customer.licenseLast4}</td>
                    <td>{customer.licenseCountry}</td>
                    <td>
                      <span
                        className={`status-badge ${customer.verificationStatus}`}
                      >
                        {customer.verificationStatus}
                      </span>
                    </td>
                    <td>
                      <div className="action-row">
                        <button
                          className="btn btn-primary btn-small"
                          type="button"
                          disabled={loading !== null}
                          onClick={() => void verify(customer.id, "approved")}
                        >
                          Approve
                        </button>
                        <button
                          className="btn btn-danger btn-small"
                          type="button"
                          disabled={loading !== null}
                          onClick={() => void verify(customer.id, "rejected")}
                        >
                          Reject
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {customers.length === 0 ? (
              <div className="empty-state">No carshare registrations.</div>
            ) : null}
          </div>
        )}
      </section>
      <section className="card">
        <div className="card-header">
          <h2 className="card-title">Carshare Vehicles</h2>
          <Link href="/operator/fleet-map">Open fleet map →</Link>
        </div>
        <div className="data-table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Plate</th>
                <th>Vehicle</th>
                <th>Type</th>
                <th>Zone</th>
                <th>Energy</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              {vehicles.map((vehicle) => (
                <tr key={vehicle.id}>
                  <td>{vehicle.plateNumber}</td>
                  <td>
                    {vehicle.make} {vehicle.model}
                  </td>
                  <td>{vehicle.typeName}</td>
                  <td>{vehicle.zoneId}</td>
                  <td>{vehicle.energyLevel}%</td>
                  <td>
                    <span className={`status-badge ${vehicle.status}`}>
                      {vehicle.status}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
      <section className="card">
        <h2 className="card-title">Carshare Zones</h2>
        <div className="data-table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Zone</th>
                <th>City</th>
                <th>Radius</th>
                <th>Current vehicles</th>
              </tr>
            </thead>
            <tbody>
              {zones.map((zone) => (
                <tr key={zone.id}>
                  <td>{zone.name}</td>
                  <td>{zone.city}</td>
                  <td>{zone.radiusMeters} m</td>
                  <td>
                    {
                      vehicles.filter((vehicle) => vehicle.zoneId === zone.id)
                        .length
                    }
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </ProtectedPage>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <div className="stat-card">
      <span className="stat-label">{label}</span>
      <span className="stat-value">{value}</span>
    </div>
  );
}
