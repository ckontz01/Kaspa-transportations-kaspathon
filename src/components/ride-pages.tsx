"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { useOsrh } from "@/components/osrh-provider";
import { ProtectedPage } from "@/components/protected-page";
import { WalletPanel } from "@/components/wallet-panel";
import { apiRequest, errorMessage } from "@/lib/api";
import {
  formatDistance,
  formatKas,
  rideStatusLabel,
  shortHash,
} from "@/lib/ride";
import type { PaymentRecord, Ride, SigningDraft } from "@/lib/types";

type DashboardResponse = {
  activeRide: Ride | null;
  recentRides: Ride[];
  stats: {
    totalRides: number;
    completedRides: number;
    cancelledRides: number;
    totalKas: string;
  };
  unreadMessages: number;
};

export function PassengerDashboardPage() {
  const { state } = useOsrh();
  const [data, setData] = useState<DashboardResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!state.user) return;
    void apiRequest<DashboardResponse>("/api/v1/dashboard")
      .then(setData)
      .catch((caught) => setError(errorMessage(caught)));
  }, [state.user]);

  return (
    <ProtectedPage role="passenger">
      <div className="dashboard-container">
        <div className="dashboard-header">
          <h1>Passenger Dashboard</h1>
          <p>
            Welcome back, {state.user?.fullName || "passenger"}. Where would you
            like to go?
          </p>
        </div>
        {error ? <div className="flash flash-error">{error}</div> : null}
        {!data && !error ? (
          <div className="card page-loading">Loading dashboard…</div>
        ) : null}
        {data ? (
          <>
            <div className="stats-overview">
              <Stat label="Total rides" value={data.stats.totalRides} />
              <Stat label="Completed" value={data.stats.completedRides} />
              <Stat label="KAS settled" value={data.stats.totalKas} />
              <Stat label="Unread messages" value={data.unreadMessages} />
            </div>
            {data.activeRide ? (
              <section className="card active-ride-card">
                <div className="card-header">
                  <div>
                    <h2 className="card-title">Active ride</h2>
                    <p>
                      {data.activeRide.pickup.label} →{" "}
                      {data.activeRide.dropoff.label}
                    </p>
                  </div>
                  <span className={`status-badge ${data.activeRide.status}`}>
                    {rideStatusLabel[data.activeRide.status]}
                  </span>
                </div>
                <div className="action-row">
                  <Link
                    className="btn btn-primary"
                    href={`/passenger/rides/${data.activeRide.id}`}
                  >
                    Open ride
                  </Link>
                  <span className="form-help">
                    Fare {formatKas(data.activeRide.quotedFareSompi)}
                  </span>
                </div>
              </section>
            ) : null}
            <h2 className="section-title">Quick Actions</h2>
            <div className="quick-actions">
              <Action
                href="/passenger/request-ride"
                icon="🚕"
                title="Request Driver Ride"
                text="Book a nearby driver with Kaspa covenant escrow."
              />
              <Action
                href="/autonomous"
                icon="🤖"
                title="Autonomous Ride"
                text="Request an available autonomous vehicle."
              />
              <Action
                href="/carshare"
                icon="🔑"
                title="Car Share"
                text="Find and reserve a shared vehicle."
              />
              <Action
                href="/passenger/rides"
                icon="🧾"
                title="Ride History"
                text="Review active and completed trips."
              />
              <Action
                href="/passenger/payments"
                icon="💎"
                title="Payments"
                text="Inspect covenant settlements and refunds."
              />
              <Action
                href="/messages"
                icon="💬"
                title="Messages"
                text="Talk to drivers and OSRH support."
              />
            </div>
            <section className="card table-card">
              <div className="card-header">
                <h2 className="card-title">Recent rides</h2>
                <Link href="/passenger/rides">View all</Link>
              </div>
              <RideTable rides={data.recentRides} role="passenger" />
            </section>
          </>
        ) : null}
      </div>
    </ProtectedPage>
  );
}

export function DriverDashboardPage() {
  const { state } = useOsrh();
  const [data, setData] = useState<DashboardResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!state.user) return;
    void apiRequest<DashboardResponse>("/api/v1/dashboard")
      .then(setData)
      .catch((caught) => setError(errorMessage(caught)));
  }, [state.user]);

  const pending =
    state.user?.verificationStatus !== "approved" ||
    state.user?.status !== "active";
  return (
    <ProtectedPage role="driver">
      <div className="dashboard-container">
        <div className="dashboard-header">
          <h1>Driver Dashboard</h1>
          <p>Welcome, {state.user?.fullName || "driver"}.</p>
        </div>
        {pending ? (
          <div className="flash flash-warning">
            <strong>Application pending.</strong> An OSRH operator must approve
            your driver documents before you can go online or accept rides.
          </div>
        ) : null}
        {error ? <div className="flash flash-error">{error}</div> : null}
        {data ? (
          <>
            <div className="stats-overview">
              <Stat label="Assigned trips" value={data.stats.totalRides} />
              <Stat label="Completed" value={data.stats.completedRides} />
              <Stat label="Earnings (KAS)" value={data.stats.totalKas} />
              <Stat label="Unread messages" value={data.unreadMessages} />
            </div>
            {data.activeRide ? (
              <section className="card">
                <div className="card-header">
                  <div>
                    <h2 className="card-title">Current trip</h2>
                    <p>
                      {data.activeRide.pickup.label} →{" "}
                      {data.activeRide.dropoff.label}
                    </p>
                  </div>
                  <span className={`status-badge ${data.activeRide.status}`}>
                    {rideStatusLabel[data.activeRide.status]}
                  </span>
                </div>
                <Link
                  className="btn btn-primary"
                  href={`/driver/rides/${data.activeRide.id}`}
                >
                  Open trip
                </Link>
              </section>
            ) : null}
            <div className="quick-actions">
              <Action
                href="/driver/trips"
                icon="🗺️"
                title="Available Trips"
                text="View requests and assigned rides."
              />
              <Action
                href="/driver/vehicles"
                icon="🚗"
                title="My Vehicles"
                text="Register vehicles and manage availability."
              />
              <Action
                href="/driver/earnings"
                icon="💎"
                title="Earnings"
                text="Review on-chain driver payouts."
              />
              <Action
                href="/messages"
                icon="💬"
                title="Messages"
                text="Contact passengers and support."
              />
            </div>
            <section className="card">
              <div className="card-header">
                <h2 className="card-title">Recent trips</h2>
                <Link href="/driver/trips">View all</Link>
              </div>
              <RideTable rides={data.recentRides} role="driver" />
            </section>
          </>
        ) : !error ? (
          <div className="card page-loading">Loading dashboard…</div>
        ) : null}
      </div>
    </ProtectedPage>
  );
}

export function RideHistoryPage({ role }: { role: "passenger" | "driver" }) {
  const { state } = useOsrh();
  const [rides, setRides] = useState<Ride[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!state.user) return;
    if (!state.user.address) {
      setLoading(false);
      return;
    }
    void apiRequest<{ rides: Ride[] }>("/api/v1/rides?limit=50")
      .then((result) => setRides(result.rides))
      .catch((caught) => setError(errorMessage(caught)))
      .finally(() => setLoading(false));
  }, [state.user]);

  return (
    <ProtectedPage role={role}>
      <div className="page-header">
        <div>
          <h1>{role === "passenger" ? "Ride History" : "Assigned Trips"}</h1>
          <p>Every normal ride and its Kaspa settlement state.</p>
        </div>
        {role === "passenger" ? (
          <Link className="btn btn-primary" href="/passenger/request-ride">
            Request ride
          </Link>
        ) : null}
      </div>
      {!state.user?.address ? <WalletPanel /> : null}
      {error ? <div className="flash flash-error">{error}</div> : null}
      {loading ? (
        <div className="card page-loading">Loading rides…</div>
      ) : (
        <section className="card">
          <RideTable rides={rides} role={role} />
        </section>
      )}
    </ProtectedPage>
  );
}

export function PassengerPaymentsPage() {
  const { state } = useOsrh();
  const [payments, setPayments] = useState<PaymentRecord[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!state.user) return;
    void apiRequest<{ payments: PaymentRecord[] }>("/api/v1/payments")
      .then((result) => setPayments(result.payments))
      .catch((caught) => setError(errorMessage(caught)))
      .finally(() => setLoading(false));
  }, [state.user]);

  const total = payments
    .filter((item) => item.status === "settled")
    .reduce((sum, item) => sum + Number(item.amountSompi), 0);
  const refunded = payments
    .filter((item) => item.status === "refunded")
    .reduce((sum, item) => sum + Number(item.amountSompi), 0);
  return (
    <ProtectedPage role="passenger">
      <div className="page-header">
        <div>
          <h1>Payments</h1>
          <p>SilverScript covenant settlements and refunds for normal rides.</p>
        </div>
        <span className="badge badge-success">Kaspa</span>
      </div>
      <div className="stats-overview">
        <Stat label="Settled fares" value={formatKas(total)} />
        <Stat label="Refunded" value={formatKas(refunded)} />
        <Stat label="Transactions" value={payments.length} />
        <Stat
          label="Wallet"
          value={
            state.user?.address
              ? shortHash(state.user.address, 8, 5)
              : "Not linked"
          }
        />
      </div>
      {error ? <div className="flash flash-error">{error}</div> : null}
      {loading ? (
        <div className="card page-loading">Loading payments…</div>
      ) : (
        <section className="card">
          <div className="data-table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Ride</th>
                  <th>Route</th>
                  <th>Kind</th>
                  <th>Amount</th>
                  <th>Status</th>
                  <th>Transaction</th>
                </tr>
              </thead>
              <tbody>
                {payments.map((payment) => (
                  <tr key={payment.id}>
                    <td>{new Date(payment.createdAt).toLocaleString()}</td>
                    <td>
                      <Link href={`/passenger/rides/${payment.rideId}`}>
                        {shortHash(payment.rideId, 7, 4)}
                      </Link>
                    </td>
                    <td>
                      {payment.pickup.label} → {payment.dropoff.label}
                    </td>
                    <td>{payment.kind.replaceAll("_", " ")}</td>
                    <td>{formatKas(payment.amountSompi)}</td>
                    <td>
                      <span className={`status-badge ${payment.status}`}>
                        {payment.status}
                      </span>
                    </td>
                    <td className="hash">{shortHash(payment.transactionId)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {payments.length === 0 ? (
              <div className="empty-state">
                No settled or refunded normal-ride payments yet.
              </div>
            ) : null}
          </div>
        </section>
      )}
    </ProtectedPage>
  );
}

export function DriverTripsPage() {
  const { state, signDraft } = useOsrh();
  const [own, setOwn] = useState<Ride[]>([]);
  const [available, setAvailable] = useState<Ride[]>([]);
  const [loading, setLoading] = useState<string | null>("list");
  const [error, setError] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    if (!state.user) return;
    try {
      const assigned = state.user.address
        ? await apiRequest<{ rides: Ride[] }>("/api/v1/rides?limit=50")
        : { rides: [] };
      setOwn(assigned.rides);
      if (
        state.user.status === "active" &&
        state.user.verificationStatus === "approved" &&
        state.user.address
      ) {
        const dispatch = await apiRequest<{ rides: Ride[] }>(
          "/api/v1/dispatch",
        );
        setAvailable(dispatch.rides);
      }
      setError(null);
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(null);
    }
  }, [state.user]);

  useEffect(() => {
    void refresh();
  }, [refresh]);

  const accept = async (ride: Ride) => {
    setLoading(ride.id);
    setError(null);
    try {
      if (!state.canSignCovenants)
        throw new Error(
          "Connect your linked KIP-12 wallet before accepting a ride.",
        );
      const draft = await apiRequest<SigningDraft>(
        `/api/v1/rides/${ride.id}/acceptance-plan`,
        { method: "POST", body: JSON.stringify({ version: ride.version }) },
      );
      await signDraft(draft);
      window.location.assign(`/driver/rides/${ride.id}`);
    } catch (caught) {
      setError(errorMessage(caught));
      await refresh();
    } finally {
      setLoading(null);
    }
  };

  const pending =
    state.user?.status !== "active" ||
    state.user?.verificationStatus !== "approved";
  return (
    <ProtectedPage role="driver">
      <div className="page-header">
        <div>
          <h1>Driver Trips</h1>
          <p>Available normal-ride requests and your assigned trips.</p>
        </div>
        <button
          className="btn btn-outline"
          type="button"
          onClick={() => void refresh()}
        >
          Refresh
        </button>
      </div>
      {pending ? (
        <div className="flash flash-warning">
          Your driver application must be approved before you can accept
          requests.
        </div>
      ) : null}
      {!state.user?.address ? <WalletPanel /> : null}
      {error ? <div className="flash flash-error">{error}</div> : null}
      {!pending ? (
        <section className="card">
          <div className="card-header">
            <h2 className="card-title">Available ride requests</h2>
            <span className="badge badge-info">{available.length}</span>
          </div>
          <div className="data-table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Pickup</th>
                  <th>Destination</th>
                  <th>Distance</th>
                  <th>Fare</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                {available.map((ride) => (
                  <tr key={ride.id}>
                    <td>{ride.pickup.label}</td>
                    <td>{ride.dropoff.label}</td>
                    <td>{formatDistance(ride.routeDistanceMeters)}</td>
                    <td>{formatKas(ride.quotedFareSompi)}</td>
                    <td>
                      <button
                        className="btn btn-primary btn-small"
                        type="button"
                        disabled={loading !== null}
                        onClick={() => void accept(ride)}
                      >
                        {loading === ride.id ? "Opening wallet…" : "Accept"}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {available.length === 0 ? (
              <div className="empty-state">
                No funded normal-ride requests are waiting right now.
              </div>
            ) : null}
          </div>
        </section>
      ) : null}
      <section className="card">
        <div className="card-header">
          <h2 className="card-title">Your trips</h2>
        </div>
        {loading === "list" ? (
          <div className="page-loading">Loading trips…</div>
        ) : (
          <RideTable rides={own} role="driver" />
        )}
      </section>
    </ProtectedPage>
  );
}

export function RideTable({
  rides,
  role,
}: {
  rides: Ride[];
  role: "passenger" | "driver";
}) {
  if (rides.length === 0)
    return <div className="empty-state">No rides to show.</div>;
  return (
    <div className="data-table-wrap">
      <table className="data-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Pickup</th>
            <th>Destination</th>
            <th>Fare</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {rides.map((ride) => (
            <tr key={ride.id}>
              <td>{new Date(ride.createdAt).toLocaleDateString()}</td>
              <td>{ride.pickup.label}</td>
              <td>{ride.dropoff.label}</td>
              <td>{formatKas(ride.quotedFareSompi)}</td>
              <td>
                <span className={`status-badge ${ride.status}`}>
                  {rideStatusLabel[ride.status]}
                </span>
              </td>
              <td>
                <Link
                  className="btn btn-outline btn-small"
                  href={`/${role}/rides/${ride.id}`}
                >
                  Details
                </Link>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
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

function Action({
  href,
  icon,
  title,
  text,
}: {
  href: string;
  icon: string;
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
      <span>Open →</span>
    </Link>
  );
}
