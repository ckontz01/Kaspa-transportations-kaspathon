"use client";

import Link from "next/link";
import {
  type FormEvent,
  useCallback,
  useEffect,
  useMemo,
  useState,
} from "react";
import {
  ArrowRight,
  ChartNoAxesCombined,
  ClipboardCheck,
  Database,
  ListChecks,
  Map,
  MessageCircle,
} from "lucide-react";
import { useOsrh } from "@/components/osrh-provider";
import { ProtectedPage } from "@/components/protected-page";
import { RideMap, type RideMapProps } from "@/components/ride-map";
import { apiRequest, errorMessage } from "@/lib/api";

type OperationsSummary = {
  accounts: number;
  passengers: number;
  drivers: number;
  pendingDrivers: number;
  onlineDrivers: number;
  normalRides: number;
  activeNormalRides: number;
  pendingInspections: number;
  openPrivacyRequests: number;
  autonomousVehicles: number;
  carshareVehicles: number;
};

type SafetyVehicle = {
  id: string;
  plateNumber: string;
  vehicleType: string;
  make: string;
  model: string;
  status: string;
  isActive: boolean;
  driverName?: string | null;
};

type SafetyInspection = {
  id: string;
  status: "pending" | "passed" | "failed" | "needs_followup";
  notes?: string | null;
  inspectorName: string;
  inspectionDate: string;
  vehicle: SafetyVehicle;
};

type SystemLog = {
  id: string;
  createdAt: string;
  actorName: string;
  actorRole: string;
  actionType: string;
  actionDescription: string;
  status: string;
  severity: string;
  referenceType?: string | null;
  referenceId?: string | null;
};

type DataSnapshot = {
  collectionCounts: Record<string, number>;
  accounts: Array<{
    id: string;
    fullName: string;
    email: string;
    role: string;
    status: string;
    verificationStatus?: string | null;
    createdAt: string;
  }>;
  vehicles: SafetyVehicle[];
  rides: Array<{
    id: string;
    status: string;
    serviceType?: string;
    pickup?: { label: string };
    dropoff?: { label: string };
    quotedFareSompi?: number;
    network?: string;
    createdAt: string;
  }>;
};

type FleetSnapshot = {
  drivers: Array<{
    id: string;
    label: string;
    latitude: number;
    longitude: number;
    vehicle?: { plateNumber?: string; make?: string; model?: string };
  }>;
  autonomousVehicles: Array<{
    id: string;
    plateNumber: string;
    make: string;
    model: string;
    status: string;
    latitude: number;
    longitude: number;
  }>;
  carshareVehicles: Array<{
    id: string;
    plateNumber: string;
    make: string;
    model: string;
    status: string;
    latitude: number;
    longitude: number;
    zoneId: string;
  }>;
  carshareZones: Array<{
    id: string;
    name: string;
    city: string;
    latitude: number;
    longitude: number;
    radiusMeters: number;
  }>;
};

export function OperatorOperationsPage() {
  const { state } = useOsrh();
  const [summary, setSummary] = useState<OperationsSummary | null>(null);
  const [error, setError] = useState<string | null>(null);
  const load = useCallback(async () => {
    try {
      setSummary(
        await apiRequest<OperationsSummary>("/api/v1/operator/operations"),
      );
      setError(null);
    } catch (caught) {
      setError(errorMessage(caught));
    }
  }, []);
  useEffect(() => {
    if (state.user) void load();
  }, [load, state.user]);

  return (
    <ProtectedPage role={["operator", "admin"]}>
      <div className="page-header">
        <div>
          <h1>Operations</h1>
          <p>
            The original OSRH operations hub, backed by Atlas audit records.
          </p>
        </div>
        <button
          className="btn btn-outline"
          type="button"
          onClick={() => void load()}
        >
          Refresh
        </button>
      </div>
      {error ? <div className="flash flash-error">{error}</div> : null}
      <div className="stats-overview">
        <Stat
          label="Active normal rides"
          value={summary?.activeNormalRides ?? 0}
        />
        <Stat label="Online drivers" value={summary?.onlineDrivers ?? 0} />
        <Stat
          label="Pending inspections"
          value={summary?.pendingInspections ?? 0}
        />
        <Stat
          label="Open GDPR requests"
          value={summary?.openPrivacyRequests ?? 0}
        />
      </div>
      <div className="quick-actions">
        <Action
          href="/operator/safety"
          icon={<ClipboardCheck />}
          title="Safety Inspections"
          text="Approve or reject driver vehicles before they go online."
        />
        <Action
          href="/operator/fleet-map"
          icon={<Map />}
          title="Fleet Map"
          text="See online drivers, autonomous vehicles, and carshare vehicles."
        />
        <Action
          href="/operator/logs"
          icon={<ListChecks />}
          title="System Logs"
          text="Review the immutable operational audit trail."
        />
        <Action
          href="/operator/data"
          icon={<Database />}
          title="Database Viewer"
          text="Inspect sanitized Atlas collection summaries and recent records."
        />
        <Action
          href="/operator/reports"
          icon={<ChartNoAxesCombined />}
          title="Reports & Analytics"
          text="Review normal ride and Kaspa settlement reporting."
        />
        <Action
          href="/messages"
          icon={<MessageCircle />}
          title="Messages"
          text="Support passengers and drivers."
        />
      </div>
      {summary ? (
        <section className="card">
          <h2 className="card-title">Platform totals</h2>
          <div className="stats-overview">
            <Stat label="Accounts" value={summary.accounts} />
            <Stat label="Drivers" value={summary.drivers} />
            <Stat label="Normal rides" value={summary.normalRides} />
            <Stat label="Autonomous fleet" value={summary.autonomousVehicles} />
            <Stat label="Carshare fleet" value={summary.carshareVehicles} />
          </div>
        </section>
      ) : null}
    </ProtectedPage>
  );
}

export function OperatorSafetyPage() {
  const { state } = useOsrh();
  const [vehicles, setVehicles] = useState<SafetyVehicle[]>([]);
  const [inspections, setInspections] = useState<SafetyInspection[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const load = useCallback(async () => {
    try {
      const result = await apiRequest<{
        vehicles: SafetyVehicle[];
        inspections: SafetyInspection[];
      }>("/api/v1/operator/safety-inspections");
      setVehicles(result.vehicles);
      setInspections(result.inspections);
      setError(null);
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => {
    if (state.user) void load();
  }, [load, state.user]);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    setLoading(true);
    setNotice(null);
    setError(null);
    try {
      await apiRequest("/api/v1/operator/safety-inspections", {
        method: "POST",
        body: JSON.stringify({
          vehicleId: form.get("vehicleId"),
          status: form.get("status"),
          notes: form.get("notes") || null,
        }),
      });
      event.currentTarget.reset();
      setNotice("Safety inspection recorded and vehicle availability updated.");
      await load();
    } catch (caught) {
      setError(errorMessage(caught));
      setLoading(false);
    }
  };

  return (
    <ProtectedPage role={["operator", "admin"]}>
      <div className="page-header">
        <div>
          <Link className="back-link" href="/operator/operations">
            ← Operations
          </Link>
          <h1>Safety Inspections</h1>
          <p>
            Driver vehicles stay offline until an operator records a passing
            inspection.
          </p>
        </div>
      </div>
      {notice ? <div className="flash flash-success">{notice}</div> : null}
      {error ? <div className="flash flash-error">{error}</div> : null}
      <div className="content-grid">
        <form className="card" onSubmit={submit}>
          <h2 className="card-title">Record New Inspection</h2>
          <div className="form-group">
            <label className="form-label" htmlFor="vehicleId">
              Vehicle
            </label>
            <select
              className="form-control"
              id="vehicleId"
              name="vehicleId"
              required
            >
              <option value="">Select vehicle…</option>
              {vehicles.map((vehicle) => (
                <option key={vehicle.id} value={vehicle.id}>
                  {vehicle.plateNumber} · {vehicle.make} {vehicle.model} ·{" "}
                  {vehicle.driverName || "Driver"}
                </option>
              ))}
            </select>
          </div>
          <div className="form-group">
            <label className="form-label" htmlFor="status">
              Status
            </label>
            <select className="form-control" id="status" name="status" required>
              <option value="passed">✓ Passed</option>
              <option value="failed">✗ Failed</option>
              <option value="needs_followup">⟳ Needs follow-up</option>
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
              maxLength={1000}
              rows={4}
            />
          </div>
          <button
            className="btn btn-primary"
            type="submit"
            disabled={loading || vehicles.length === 0}
          >
            {loading ? "Saving…" : "Save Inspection"}
          </button>
        </form>
        <section className="card">
          <h2 className="card-title">Inspection Summary</h2>
          <div className="stats-overview">
            <Stat
              label="Pending"
              value={
                inspections.filter((item) => item.status === "pending").length
              }
            />
            <Stat
              label="Follow-up"
              value={
                inspections.filter((item) => item.status === "needs_followup")
                  .length
              }
            />
            <Stat
              label="Failed"
              value={
                inspections.filter((item) => item.status === "failed").length
              }
            />
            <Stat
              label="Passed"
              value={
                inspections.filter((item) => item.status === "passed").length
              }
            />
          </div>
        </section>
      </div>
      <section className="card">
        <h2 className="card-title">Inspection History</h2>
        <div className="data-table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Vehicle</th>
                <th>Driver</th>
                <th>Inspector</th>
                <th>Status</th>
                <th>Notes</th>
              </tr>
            </thead>
            <tbody>
              {inspections.map((item) => (
                <tr key={item.id}>
                  <td>{new Date(item.inspectionDate).toLocaleString()}</td>
                  <td>
                    {item.vehicle.plateNumber}
                    <br />
                    <span className="form-help">
                      {item.vehicle.make} {item.vehicle.model}
                    </span>
                  </td>
                  <td>{item.vehicle.driverName || "—"}</td>
                  <td>{item.inspectorName}</td>
                  <td>
                    <span className={`status-badge ${item.status}`}>
                      {item.status.replaceAll("_", " ")}
                    </span>
                  </td>
                  <td>{item.notes || "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {!loading && inspections.length === 0 ? (
            <div className="empty-state">No inspections recorded.</div>
          ) : null}
        </div>
      </section>
    </ProtectedPage>
  );
}

export function OperatorLogsPage() {
  const { state } = useOsrh();
  const [logs, setLogs] = useState<SystemLog[]>([]);
  const [filter, setFilter] = useState("");
  const [error, setError] = useState<string | null>(null);
  const load = useCallback(async () => {
    try {
      const result = await apiRequest<{ logs: SystemLog[] }>(
        "/api/v1/operator/system-logs?limit=500",
      );
      setLogs(result.logs);
      setError(null);
    } catch (caught) {
      setError(errorMessage(caught));
    }
  }, []);
  useEffect(() => {
    if (state.user) void load();
  }, [load, state.user]);
  const visible = useMemo(() => {
    const query = filter.trim().toLocaleLowerCase();
    return query
      ? logs.filter((item) =>
          `${item.actionType} ${item.actionDescription} ${item.actorName} ${item.status}`
            .toLocaleLowerCase()
            .includes(query),
        )
      : logs;
  }, [filter, logs]);

  return (
    <ProtectedPage role={["operator", "admin"]}>
      <div className="page-header">
        <div>
          <Link className="back-link" href="/operator/operations">
            ← Operations
          </Link>
          <h1>System Logs</h1>
          <p>Operational actions recorded in MongoDB Atlas.</p>
        </div>
        <button
          className="btn btn-outline"
          type="button"
          onClick={() => void load()}
        >
          Refresh
        </button>
      </div>
      {error ? <div className="flash flash-error">{error}</div> : null}
      <section className="card">
        <div className="form-group">
          <label className="form-label" htmlFor="logFilter">
            Filter logs
          </label>
          <input
            className="form-control"
            id="logFilter"
            value={filter}
            onChange={(event) => setFilter(event.target.value)}
            placeholder="Action, operator, status…"
          />
        </div>
        <div className="data-table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Action</th>
                <th>Operator</th>
                <th>Description</th>
                <th>Status</th>
                <th>Reference</th>
              </tr>
            </thead>
            <tbody>
              {visible.map((item) => (
                <tr key={item.id}>
                  <td>{new Date(item.createdAt).toLocaleString()}</td>
                  <td>{item.actionType}</td>
                  <td>
                    {item.actorName}
                    <br />
                    <span className="form-help">{item.actorRole}</span>
                  </td>
                  <td>{item.actionDescription}</td>
                  <td>
                    <span className={`status-badge ${item.status}`}>
                      {item.status}
                    </span>
                  </td>
                  <td className="hash">
                    {item.referenceType || "—"}
                    <br />
                    {item.referenceId || ""}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {visible.length === 0 ? (
            <div className="empty-state">No matching system logs.</div>
          ) : null}
        </div>
      </section>
    </ProtectedPage>
  );
}

export function OperatorDataPage() {
  const { state } = useOsrh();
  const [data, setData] = useState<DataSnapshot | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (state.user)
      void apiRequest<DataSnapshot>("/api/v1/operator/data")
        .then(setData)
        .catch((caught) => setError(errorMessage(caught)));
  }, [state.user]);
  return (
    <ProtectedPage role={["operator", "admin"]}>
      <div className="page-header">
        <div>
          <Link className="back-link" href="/operator/operations">
            ← Operations
          </Link>
          <h1>Database Contents Viewer</h1>
          <p>
            Sanitized operational records from Atlas; password hashes and
            identity documents are never exposed.
          </p>
        </div>
      </div>
      {error ? <div className="flash flash-error">{error}</div> : null}
      {data ? (
        <>
          <section className="card">
            <h2 className="card-title">Collection Counts</h2>
            <div className="stats-overview">
              {Object.entries(data.collectionCounts).map(([name, count]) => (
                <Stat
                  key={name}
                  label={name.replaceAll("_", " ")}
                  value={count}
                />
              ))}
            </div>
          </section>
          <DataTable
            title="Accounts"
            headers={["Name", "Email", "Role", "Status", "Created"]}
            rows={data.accounts.map((item) => [
              item.fullName,
              item.email,
              item.role,
              item.verificationStatus || item.status,
              new Date(item.createdAt).toLocaleString(),
            ])}
          />
          <DataTable
            title="Driver Vehicles"
            headers={["Plate", "Vehicle", "Status", "Active"]}
            rows={data.vehicles.map((item) => [
              item.plateNumber,
              `${item.make} ${item.model}`,
              item.status,
              item.isActive ? "Yes" : "No",
            ])}
          />
          <DataTable
            title="Normal Rides"
            headers={["Date", "Route", "Service", "Network", "Status"]}
            rows={data.rides.map((item) => [
              new Date(item.createdAt).toLocaleString(),
              `${item.pickup?.label || "—"} → ${item.dropoff?.label || "—"}`,
              item.serviceType || "standard",
              item.network || "—",
              item.status,
            ])}
          />
        </>
      ) : (
        <div className="card page-loading">Loading Atlas records…</div>
      )}
    </ProtectedPage>
  );
}

export function OperatorFleetMapPage() {
  const { state } = useOsrh();
  const [fleet, setFleet] = useState<FleetSnapshot | null>(null);
  const [error, setError] = useState<string | null>(null);
  const load = useCallback(async () => {
    try {
      setFleet(await apiRequest<FleetSnapshot>("/api/v1/operator/fleet"));
      setError(null);
    } catch (caught) {
      setError(errorMessage(caught));
    }
  }, []);
  useEffect(() => {
    if (!state.user) return;
    void load();
    const timer = window.setInterval(() => void load(), 30_000);
    return () => window.clearInterval(timer);
  }, [load, state.user]);
  const markers = useMemo<NonNullable<RideMapProps["markers"]>>(
    () => [
      ...(fleet?.drivers.map((item) => ({
        id: `driver-${item.id}`,
        label: `${item.label}${item.vehicle?.plateNumber ? ` · ${item.vehicle.plateNumber}` : ""}`,
        latitude: item.latitude,
        longitude: item.longitude,
        color: "#3b82f6",
        category: "Online driver",
      })) || []),
      ...(fleet?.autonomousVehicles.map((item) => ({
        id: `auto-${item.id}`,
        label: `${item.id} · ${item.make} ${item.model} · ${item.status}`,
        latitude: item.latitude,
        longitude: item.longitude,
        color: item.status === "available" ? "#06b6d4" : "#f59e0b",
        category: "Autonomous vehicle",
      })) || []),
      ...(fleet?.carshareVehicles.map((item) => ({
        id: `carshare-${item.id}`,
        label: `${item.plateNumber} · ${item.make} ${item.model} · ${item.status}`,
        latitude: item.latitude,
        longitude: item.longitude,
        color: item.status === "available" ? "#22c55e" : "#f97316",
        category: "Carshare vehicle",
      })) || []),
    ],
    [fleet],
  );
  return (
    <ProtectedPage role={["operator", "admin"]}>
      <div className="page-header">
        <div>
          <Link className="back-link" href="/operator/operations">
            ← Operations
          </Link>
          <h1>Fleet Map</h1>
          <p>
            Live driver, autonomous, and carshare positions. Refreshes every 30
            seconds.
          </p>
        </div>
        <button
          className="btn btn-outline"
          type="button"
          onClick={() => void load()}
        >
          Refresh
        </button>
      </div>
      {error ? <div className="flash flash-error">{error}</div> : null}
      <div className="stats-overview">
        <Stat label="Online drivers" value={fleet?.drivers.length ?? 0} />
        <Stat
          label="Autonomous vehicles"
          value={fleet?.autonomousVehicles.length ?? 0}
        />
        <Stat
          label="Carshare vehicles"
          value={fleet?.carshareVehicles.length ?? 0}
        />
        <Stat label="Carshare zones" value={fleet?.carshareZones.length ?? 0} />
      </div>
      <section className="card ride-detail-map">
        <RideMap markers={markers} showGeofences />
      </section>
      <section className="card">
        <h2 className="card-title">Map Legend</h2>
        <div className="action-row">
          <span className="badge badge-info">● Online drivers</span>
          <span className="badge badge-info">● Autonomous fleet</span>
          <span className="badge badge-success">● Carshare fleet</span>
        </div>
      </section>
    </ProtectedPage>
  );
}

function DataTable({
  title,
  headers,
  rows,
}: {
  title: string;
  headers: string[];
  rows: Array<Array<string | number>>;
}) {
  return (
    <section className="card">
      <h2 className="card-title">{title}</h2>
      <div className="data-table-wrap">
        <table className="data-table">
          <thead>
            <tr>
              {headers.map((header) => (
                <th key={header}>{header}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((row, index) => (
              <tr key={`${title}-${index}`}>
                {row.map((value, cell) => (
                  <td key={`${title}-${index}-${cell}`}>{value}</td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
        {rows.length === 0 ? (
          <div className="empty-state">No records.</div>
        ) : null}
      </div>
    </section>
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

function Action({
  href,
  icon,
  title,
  text,
}: {
  href: string;
  icon: React.ReactNode;
  title: string;
  text: string;
}) {
  return (
    <Link className="action-card" href={href}>
      <div>
        <span className="action-icon" aria-hidden="true">
          {icon}
        </span>
        <h3>{title}</h3>
        <p>{text}</p>
      </div>
      <span className="action-open">
        Open <ArrowRight aria-hidden="true" size={16} />
      </span>
    </Link>
  );
}
