"use client";

import Image from "next/image";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import {
  Activity,
  Bot,
  Car,
  CarFront,
  ChartNoAxesCombined,
  ChevronRight,
  CircleDollarSign,
  FileCheck2,
  Gauge,
  History,
  Home,
  KeyRound,
  LogOut,
  MapPinned,
  Menu,
  MessageCircle,
  MoreHorizontal,
  Navigation,
  Settings2,
  ShieldCheck,
  UserRound,
  UsersRound,
  WalletCards,
  X,
  type LucideIcon,
} from "lucide-react";
import kaspaLogo from "../../OSRH_KASPA_PHP/assets/img/Kaspa-logo.svg.png";
import { useOsrh } from "@/components/osrh-provider";

type NavItem = { label: string; href: string; icon: LucideIcon };

const publicLinks = [
  { label: "Home", href: "/" },
  { label: "Ride", href: "/register/passenger" },
  { label: "Drive", href: "/register/driver" },
] as const;

const passengerLinks: NavItem[] = [
  { label: "Home", href: "/passenger/dashboard", icon: Home },
  { label: "Book a ride", href: "/passenger/request-ride", icon: MapPinned },
  { label: "Autonomous", href: "/autonomous", icon: Bot },
  { label: "Car sharing", href: "/carshare", icon: KeyRound },
  { label: "My rides", href: "/passenger/rides", icon: History },
  { label: "Payments", href: "/passenger/payments", icon: WalletCards },
  { label: "Messages", href: "/messages", icon: MessageCircle },
  { label: "Settings", href: "/passenger/settings", icon: Settings2 },
  { label: "Privacy", href: "/privacy", icon: ShieldCheck },
];

const driverLinks: NavItem[] = [
  { label: "Home", href: "/driver/dashboard", icon: Home },
  { label: "Trips", href: "/driver/trips", icon: Navigation },
  { label: "Vehicles", href: "/driver/vehicles", icon: Car },
  { label: "Documents", href: "/driver/documents", icon: FileCheck2 },
  { label: "Earnings", href: "/driver/earnings", icon: CircleDollarSign },
  { label: "Messages", href: "/messages", icon: MessageCircle },
  { label: "Settings", href: "/driver/settings", icon: Settings2 },
];

const operatorLinks: NavItem[] = [
  { label: "Overview", href: "/operator/dashboard", icon: Gauge },
  { label: "Operations", href: "/operator/operations", icon: Activity },
  { label: "Drivers", href: "/operator/drivers", icon: UsersRound },
  { label: "Autonomous", href: "/operator/autonomous", icon: Bot },
  { label: "Car sharing", href: "/operator/carshare", icon: CarFront },
  { label: "Reports", href: "/operator/reports", icon: ChartNoAxesCombined },
  { label: "Privacy", href: "/operator/privacy", icon: ShieldCheck },
  { label: "Messages", href: "/messages", icon: MessageCircle },
];

export function AppShell({ children }: { children: React.ReactNode }) {
  const { state, logout } = useOsrh();
  const pathname = usePathname();
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [moreOpen, setMoreOpen] = useState(false);
  const user = state.user;
  const links = user
    ? user.role === "driver"
      ? driverLinks
      : user.role === "operator" || user.role === "admin"
        ? operatorLinks
        : passengerLinks
    : [];

  const isActive = (href: string) =>
    pathname === href || (href !== "/" && pathname.startsWith(`${href}/`));

  const roleLabel = user
    ? user.role === "operator" || user.role === "admin"
      ? "Mobility operator"
      : user.role === "driver"
        ? "Driver workspace"
        : "Passenger"
    : null;

  const mobilePrimary = user
    ? links.filter((item) => {
        if (user.role === "passenger") {
          return [
            "/passenger/dashboard",
            "/passenger/request-ride",
            "/passenger/rides",
          ].includes(item.href);
        }
        if (user.role === "driver") {
          return [
            "/driver/dashboard",
            "/driver/trips",
            "/driver/earnings",
          ].includes(item.href);
        }
        return [
          "/operator/dashboard",
          "/operator/operations",
          "/operator/drivers",
        ].includes(item.href);
      })
    : [];

  const handleLogout = async () => {
    await logout();
    setMoreOpen(false);
    router.push("/");
    router.refresh();
  };

  useEffect(() => {
    if (!moreOpen) return;
    const previousOverflow = document.body.style.overflow;
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === "Escape") setMoreOpen(false);
    };
    document.body.style.overflow = "hidden";
    window.addEventListener("keydown", closeOnEscape);
    return () => {
      document.body.style.overflow = previousOverflow;
      window.removeEventListener("keydown", closeOnEscape);
    };
  }, [moreOpen]);

  const brand = (
    <Link
      href={user ? links[0]?.href || "/" : "/"}
      className="app-brand"
      aria-label="Kaspa Transportations home"
      onClick={() => {
        setOpen(false);
        setMoreOpen(false);
      }}
    >
      <span className="app-brand-mark">
        <Image src={kaspaLogo} alt="" className="app-brand-logo" priority />
      </span>
      <span className="app-brand-copy">
        <strong>Kaspa</strong>
        <small>Transportations</small>
      </span>
    </Link>
  );

  return (
    <div className={`app${user ? " app-authenticated" : " app-public"}`}>
      {user ? (
        <>
          <aside className="app-rail">
            {brand}
            <div className="rail-context">
              <span>{roleLabel}</span>
              <small>Kaspa testnet</small>
            </div>
            <nav className="rail-navigation" aria-label="Primary navigation">
              {links.map(({ label, href, icon: Icon }) => (
                <Link
                  key={href}
                  href={href}
                  className={`rail-link${isActive(href) ? " is-active" : ""}`}
                  aria-current={isActive(href) ? "page" : undefined}
                >
                  <Icon aria-hidden="true" size={19} strokeWidth={2} />
                  <span>{label}</span>
                  {isActive(href) ? (
                    <ChevronRight
                      className="rail-link-caret"
                      aria-hidden="true"
                      size={15}
                    />
                  ) : null}
                </Link>
              ))}
            </nav>
            <div className="rail-account">
              <Link href="/profile" className="rail-profile">
                <span className="account-avatar" aria-hidden="true">
                  {(user.fullName || user.displayName || "A").charAt(0)}
                </span>
                <span>
                  <strong>
                    {user.fullName || user.displayName || "Account"}
                  </strong>
                  <small>View account</small>
                </span>
              </Link>
              <button
                type="button"
                className="icon-button"
                aria-label="Sign out"
                title="Sign out"
                onClick={() => void handleLogout()}
              >
                <LogOut aria-hidden="true" size={18} />
              </button>
            </div>
          </aside>
          <header className="mobile-app-header">
            {brand}
            <Link
              href="/profile"
              className="account-avatar"
              aria-label="Account"
            >
              {(user.fullName || user.displayName || "A").charAt(0)}
            </Link>
          </header>
        </>
      ) : (
        <header className="public-header">
          <div className="public-header-inner">
            {brand}
            <nav className={`public-navigation${open ? " is-open" : ""}`}>
              {publicLinks.map(({ label, href }) => (
                <Link
                  key={href}
                  href={href}
                  className={isActive(href) ? "is-active" : undefined}
                  aria-current={isActive(href) ? "page" : undefined}
                  onClick={() => setOpen(false)}
                >
                  {label}
                </Link>
              ))}
              <Link className="btn btn-primary public-login" href="/login">
                Sign in
              </Link>
            </nav>
            <Link
              className="btn btn-primary public-login-desktop"
              href="/login"
            >
              Sign in
            </Link>
            <button
              className="public-menu-button"
              type="button"
              aria-label={open ? "Close navigation" : "Open navigation"}
              aria-expanded={open}
              onClick={() => setOpen((value) => !value)}
            >
              {open ? <X aria-hidden="true" /> : <Menu aria-hidden="true" />}
            </button>
          </div>
        </header>
      )}

      {state.error ? (
        <div className="flash flash-error global-flash">{state.error}</div>
      ) : null}
      <main className="app-main">
        <div className="app-content">{children}</div>
      </main>

      {user ? (
        <>
          <nav className="mobile-bottom-nav" aria-label="Mobile navigation">
            {mobilePrimary.map(({ label, href, icon: Icon }) => (
              <Link
                key={href}
                href={href}
                className={isActive(href) ? "is-active" : undefined}
                aria-current={isActive(href) ? "page" : undefined}
              >
                <Icon aria-hidden="true" size={21} strokeWidth={2} />
                <span>{label.replace("Book a ride", "Book")}</span>
              </Link>
            ))}
            <button
              type="button"
              className={moreOpen ? "is-active" : undefined}
              aria-haspopup="dialog"
              aria-expanded={moreOpen}
              onClick={() => setMoreOpen(true)}
            >
              <MoreHorizontal aria-hidden="true" size={21} />
              <span>More</span>
            </button>
          </nav>
          {moreOpen ? (
            <div
              className="mobile-sheet-backdrop"
              onMouseDown={(event) => {
                if (event.currentTarget === event.target) setMoreOpen(false);
              }}
            >
              <section
                className="mobile-more-sheet"
                role="dialog"
                aria-modal="true"
                aria-labelledby="mobile-menu-title"
              >
                <div className="mobile-sheet-handle" />
                <div className="mobile-sheet-heading">
                  <div>
                    <span className="eyebrow">{roleLabel}</span>
                    <h2 id="mobile-menu-title">Everything else</h2>
                  </div>
                  <button
                    type="button"
                    className="icon-button"
                    aria-label="Close menu"
                    autoFocus
                    onClick={() => setMoreOpen(false)}
                  >
                    <X aria-hidden="true" size={20} />
                  </button>
                </div>
                <nav
                  className="mobile-more-links"
                  aria-label="All destinations"
                >
                  {links.map(({ label, href, icon: Icon }) => (
                    <Link
                      key={href}
                      href={href}
                      className={isActive(href) ? "is-active" : undefined}
                      aria-current={isActive(href) ? "page" : undefined}
                      onClick={() => setMoreOpen(false)}
                    >
                      <Icon aria-hidden="true" size={20} />
                      <span>{label}</span>
                      <ChevronRight aria-hidden="true" size={17} />
                    </Link>
                  ))}
                  <Link href="/profile" onClick={() => setMoreOpen(false)}>
                    <UserRound aria-hidden="true" size={20} />
                    <span>Account</span>
                    <ChevronRight aria-hidden="true" size={17} />
                  </Link>
                </nav>
                <button
                  type="button"
                  className="btn btn-outline btn-block mobile-logout"
                  onClick={() => void handleLogout()}
                >
                  <LogOut aria-hidden="true" size={18} />
                  Sign out
                </button>
              </section>
            </div>
          ) : null}
        </>
      ) : (
        <footer className="app-footer">
          <div className="footer-inner">
            <strong>Move simply. Settle transparently.</strong>
            <span>
              © {new Date().getFullYear()} OSRH · University of Cyprus mobility
              project
            </span>
          </div>
        </footer>
      )}
    </div>
  );
}
