"use client";

import Image from "next/image";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useState } from "react";
import kaspaLogo from "../../OSRH_KASPA_PHP/assets/img/Kaspa-logo.svg.png";
import { useOsrh } from "@/components/osrh-provider";

const publicLinks = [
  ["Home", "/"],
  ["Login", "/login"],
  ["Register Passenger", "/register/passenger"],
  ["Register Driver", "/register/driver"],
] as const;

const passengerLinks = [
  ["Dashboard", "/passenger/dashboard"],
  ["Driver Ride", "/passenger/request-ride"],
  ["Autonomous Ride", "/autonomous"],
  ["Carshare Ride", "/carshare"],
  ["History", "/passenger/rides"],
  ["Payments", "/passenger/payments"],
  ["Messages", "/messages"],
  ["Settings", "/passenger/settings"],
  ["Privacy", "/privacy"],
] as const;

const driverLinks = [
  ["Dashboard", "/driver/dashboard"],
  ["Trips", "/driver/trips"],
  ["Vehicles", "/driver/vehicles"],
  ["Documents", "/driver/documents"],
  ["Earnings", "/driver/earnings"],
  ["Messages", "/messages"],
  ["Settings", "/driver/settings"],
] as const;

const operatorLinks = [
  ["Dashboard", "/operator/dashboard"],
  ["Operations", "/operator/operations"],
  ["Drivers Hub", "/operator/drivers"],
  ["Autonomous", "/operator/autonomous"],
  ["Carshare", "/operator/carshare"],
  ["Reports", "/operator/reports"],
  ["GDPR", "/operator/privacy"],
  ["Messages", "/messages"],
] as const;

export function AppShell({ children }: { children: React.ReactNode }) {
  const { state, logout } = useOsrh();
  const pathname = usePathname();
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const user = state.user;
  const links = !user
    ? publicLinks
    : user.role === "driver"
      ? driverLinks
      : user.role === "operator" || user.role === "admin"
        ? operatorLinks
        : passengerLinks;

  const handleLogout = async () => {
    await logout();
    router.push("/");
    router.refresh();
  };

  return (
    <div className="app">
      <nav className="navbar">
        <div className="navbar-inner">
          <Link href="/" className="navbar-brand" aria-label="Kaspa home">
            <Image
              src={kaspaLogo}
              alt="Kaspa Transportations logo"
              className="navbar-logo"
              priority
            />
            <span className="navbar-title">Kaspa</span>
          </Link>

          <button
            className="navbar-toggle"
            type="button"
            aria-label="Toggle navigation"
            aria-expanded={open}
            onClick={() => setOpen((value) => !value)}
          >
            <span />
            <span />
            <span />
          </button>

          <div className={`navbar-links${open ? " is-open" : ""}`}>
            <ul className="navbar-menu">
              {links.map(([label, href]) => (
                <li key={href}>
                  <Link
                    href={href}
                    className={pathname === href ? "is-active" : undefined}
                    onClick={() => setOpen(false)}
                  >
                    {label}
                  </Link>
                </li>
              ))}
            </ul>
            <div className="navbar-right">
              {user ? (
                <>
                  <button
                    type="button"
                    className="btn btn-outline btn-small"
                    onClick={handleLogout}
                  >
                    Logout
                  </button>
                  <Link href="/profile" className="navbar-username">
                    <span className="navbar-username-icon" aria-hidden="true">
                      👤
                    </span>
                    {user.fullName || user.displayName || "Account"}
                  </Link>
                </>
              ) : (
                <Link href="/login" className="btn btn-primary btn-small">
                  Login
                </Link>
              )}
            </div>
          </div>
        </div>
      </nav>

      {state.error ? (
        <div className="flash flash-error global-flash">{state.error}</div>
      ) : null}
      <main className="app-main">
        <div className="container">{children}</div>
      </main>

      <footer className="app-footer">
        <div className="container footer-inner">
          <span>© {new Date().getFullYear()} OSRH. All rights reserved.</span>
          <span className="footer-meta">
            University of Cyprus mobility project.
          </span>
        </div>
      </footer>
    </div>
  );
}
