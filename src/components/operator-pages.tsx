"use client";

import Link from "next/link";
import { useCallback, useEffect, useMemo, useState } from "react";
import {
  Activity,
  ArrowRight,
  Bot,
  ChartNoAxesCombined,
  KeyRound,
  MessageCircle,
  ShieldCheck,
  UsersRound,
} from "lucide-react";
import { useOsrh } from "@/components/osrh-provider";
import { ProtectedPage } from "@/components/protected-page";
import { apiRequest, errorMessage } from "@/lib/api";

type DriverApplication = {
  id: string;
  fullName: string;
  email: string;
  phone: string;
  dateOfBirth: string;
  status: string;
  verificationStatus: string;
  createdAt: string;
};

export function OperatorDashboardPage() {
  const { state } = useOsrh();
  const [drivers, setDrivers] = useState<DriverApplication[]>([]);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (state.user)
      void apiRequest<{ drivers: DriverApplication[] }>(
        "/api/v1/operator/drivers",
      )
        .then((result) => setDrivers(result.drivers))
        .catch((caught) => setError(errorMessage(caught)));
  }, [state.user]);
  const pending = drivers.filter(
    (driver) => driver.verificationStatus === "pending",
  ).length;
  const approved = drivers.filter(
    (driver) => driver.verificationStatus === "approved",
  ).length;
  return (
    <ProtectedPage role={["operator", "admin"]}>
      <div className="dashboard-container">
        <div className="dashboard-header">
          <h1>Operator Dashboard</h1>
          <p>OSRH operations, approvals, privacy, and normal-ride oversight.</p>
        </div>
        {error ? <div className="flash flash-error">{error}</div> : null}
        <div className="stats-overview">
          <Stat label="Driver applications" value={drivers.length} />
          <Stat label="Pending approval" value={pending} />
          <Stat label="Approved drivers" value={approved} />
          <Stat label="Operator" value={state.user?.fullName || "OSRH"} />
        </div>
        <div className="quick-actions">
          <Action
            href="/operator/operations"
            icon={<Activity />}
            title="Operations Hub"
            text="Open safety, fleet maps, system logs, and the Atlas data viewer."
          />
          <Action
            href="/operator/drivers"
            icon={<UsersRound />}
            title="Drivers Hub"
            text="Review and approve driver applications."
          />
          <Action
            href="/operator/reports"
            icon={<ChartNoAxesCombined />}
            title="Reports"
            text="View normal-ride and settlement reports."
          />
          <Action
            href="/messages"
            icon={<MessageCircle />}
            title="Messages"
            text="Provide support to passengers and drivers."
          />
          <Action
            href="/operator/privacy"
            icon={<ShieldCheck />}
            title="GDPR Requests"
            text="Review account privacy requests."
          />
          <Action
            href="/operator/autonomous"
            icon={<Bot />}
            title="Autonomous Hub"
            text="Open autonomous mobility operations."
          />
          <Action
            href="/operator/carshare"
            icon={<KeyRound />}
            title="Carshare Hub"
            text="Open shared-vehicle operations."
          />
        </div>
      </div>
    </ProtectedPage>
  );
}

export function OperatorDriversPage() {
  const { state } = useOsrh();
  const [drivers, setDrivers] = useState<DriverApplication[]>([]);
  const [loading, setLoading] = useState<string | null>("list");
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const load = useCallback(async () => {
    try {
      const result = await apiRequest<{ drivers: DriverApplication[] }>(
        "/api/v1/operator/drivers",
      );
      setDrivers(result.drivers);
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
  const update = async (
    id: string,
    status: "approved" | "rejected" | "pending",
  ) => {
    setLoading(id);
    setError(null);
    setNotice(null);
    try {
      await apiRequest(`/api/v1/operator/drivers/${id}`, {
        method: "PATCH",
        body: JSON.stringify({ status }),
      });
      setNotice(`Driver application marked ${status}.`);
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
          <h1>Drivers Hub</h1>
          <p>Review registration details and control driver verification.</p>
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
      <section className="card">
        {loading === "list" ? (
          <div className="page-loading">Loading applications…</div>
        ) : (
          <div className="data-table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Registered</th>
                  <th>Driver</th>
                  <th>Contact</th>
                  <th>Birth date</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {drivers.map((driver) => (
                  <tr key={driver.id}>
                    <td>{new Date(driver.createdAt).toLocaleDateString()}</td>
                    <td>
                      <strong>{driver.fullName}</strong>
                    </td>
                    <td>
                      {driver.email}
                      <br />
                      <span className="form-help">{driver.phone}</span>
                    </td>
                    <td>{new Date(driver.dateOfBirth).toLocaleDateString()}</td>
                    <td>
                      <span
                        className={`status-badge ${driver.verificationStatus}`}
                      >
                        {driver.verificationStatus}
                      </span>
                    </td>
                    <td>
                      <div className="action-row">
                        <Link
                          className="btn btn-outline btn-small"
                          href={`/operator/drivers/${driver.id}`}
                        >
                          Documents
                        </Link>
                        <button
                          className="btn btn-primary btn-small"
                          type="button"
                          disabled={
                            loading !== null ||
                            driver.verificationStatus === "approved"
                          }
                          onClick={() => void update(driver.id, "approved")}
                        >
                          Approve
                        </button>
                        <button
                          className="btn btn-danger btn-small"
                          type="button"
                          disabled={
                            loading !== null ||
                            driver.verificationStatus === "rejected"
                          }
                          onClick={() => void update(driver.id, "rejected")}
                        >
                          Reject
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {drivers.length === 0 ? (
              <div className="empty-state">No driver applications.</div>
            ) : null}
          </div>
        )}
      </section>
    </ProtectedPage>
  );
}

export function OperatorReportsPage() {
  const { state } = useOsrh();
  const [drivers, setDrivers] = useState<DriverApplication[]>([]);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (state.user)
      void apiRequest<{ drivers: DriverApplication[] }>(
        "/api/v1/operator/drivers",
      )
        .then((result) => setDrivers(result.drivers))
        .catch((caught) => setError(errorMessage(caught)));
  }, [state.user]);
  const byStatus = useMemo(
    () =>
      drivers.reduce<Record<string, number>>((accumulator, driver) => {
        accumulator[driver.verificationStatus] =
          (accumulator[driver.verificationStatus] || 0) + 1;
        return accumulator;
      }, {}),
    [drivers],
  );
  return (
    <ProtectedPage role={["operator", "admin"]}>
      <div className="page-header">
        <div>
          <h1>Operational Reports</h1>
          <p>Live MongoDB-backed account and verification statistics.</p>
        </div>
        <span className="badge badge-info">Live</span>
      </div>
      {error ? <div className="flash flash-error">{error}</div> : null}
      <div className="stats-overview">
        <Stat label="Drivers" value={drivers.length} />
        <Stat label="Approved" value={byStatus.approved || 0} />
        <Stat label="Pending" value={byStatus.pending || 0} />
        <Stat label="Rejected" value={byStatus.rejected || 0} />
      </div>
      <section className="card">
        <h2 className="card-title">Reporting migration</h2>
        <p>
          The operational surface now reads from MongoDB Atlas. Covenant
          transaction identifiers, ride commitments, and payout states remain
          attached to each normal ride, making the financial report auditable
          against Kaspa.
        </p>
        <p className="inline-warning">
          Historical SQL Server reports will appear here after a legacy export
          is supplied to the migration command.
        </p>
      </section>
    </ProtectedPage>
  );
}

type PrivacyRequest = {
  id: string;
  requestType: string;
  notes?: string | null;
  status: string;
  response?: string | null;
  createdAt: string;
  account: {
    id: string;
    fullName: string;
    email?: string | null;
    role?: string | null;
  };
};

export function OperatorPrivacyPage() {
  const { state } = useOsrh();
  const [requests, setRequests] = useState<PrivacyRequest[]>([]);
  const [loading, setLoading] = useState<string | null>("list");
  const [error, setError] = useState<string | null>(null);
  const load = useCallback(async () => {
    try {
      const result = await apiRequest<{ requests: PrivacyRequest[] }>(
        "/api/v1/operator/privacy/requests",
      );
      setRequests(result.requests);
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
  const review = async (
    id: string,
    status: "in_progress" | "completed" | "rejected",
  ) => {
    setLoading(id);
    try {
      await apiRequest(`/api/v1/operator/privacy/requests/${id}`, {
        method: "PATCH",
        body: JSON.stringify({
          status,
          response:
            status === "completed"
              ? "Request completed by OSRH operator."
              : null,
        }),
      });
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
          <h1>GDPR Requests</h1>
          <p>Review passenger and driver data-rights requests.</p>
        </div>
        <button
          type="button"
          className="btn btn-outline"
          onClick={() => void load()}
        >
          Refresh
        </button>
      </div>
      {error ? <div className="flash flash-error">{error}</div> : null}
      <section className="card">
        {loading === "list" ? (
          <div className="page-loading">Loading requests…</div>
        ) : (
          <div className="data-table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Account</th>
                  <th>Request</th>
                  <th>Notes</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {requests.map((request) => (
                  <tr key={request.id}>
                    <td>{new Date(request.createdAt).toLocaleString()}</td>
                    <td>
                      {request.account.fullName}
                      <br />
                      <span className="form-help">{request.account.email}</span>
                    </td>
                    <td>{request.requestType}</td>
                    <td>{request.notes || "—"}</td>
                    <td>
                      <span className={`status-badge ${request.status}`}>
                        {request.status}
                      </span>
                    </td>
                    <td>
                      <div className="action-row">
                        <button
                          className="btn btn-outline btn-small"
                          type="button"
                          disabled={loading !== null}
                          onClick={() => void review(request.id, "in_progress")}
                        >
                          Start
                        </button>
                        <button
                          className="btn btn-primary btn-small"
                          type="button"
                          disabled={loading !== null}
                          onClick={() => void review(request.id, "completed")}
                        >
                          Complete
                        </button>
                        <button
                          className="btn btn-danger btn-small"
                          type="button"
                          disabled={loading !== null}
                          onClick={() => void review(request.id, "rejected")}
                        >
                          Reject
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {requests.length === 0 ? (
              <div className="empty-state">No GDPR requests.</div>
            ) : null}
          </div>
        )}
      </section>
    </ProtectedPage>
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
        <span className="action-icon">{icon}</span>
        <h3>{title}</h3>
        <p>{text}</p>
      </div>
      <span className="action-open">
        Open <ArrowRight aria-hidden="true" size={16} />
      </span>
    </Link>
  );
}
