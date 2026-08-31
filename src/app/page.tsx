import Link from "next/link";
import {
  ArrowRight,
  Bot,
  CarFront,
  Check,
  Clock3,
  KeyRound,
  MapPin,
  Navigation,
  Route,
  ShieldCheck,
  Sparkles,
  UserRoundCheck,
  WalletCards,
} from "lucide-react";

const services = [
  {
    title: "Ride with a driver",
    description: "Choose a route, see the fare, and request a verified driver.",
    href: "/register/passenger",
    link: "Start riding",
    icon: CarFront,
    tone: "pear",
  },
  {
    title: "Autonomous ride",
    description:
      "Request an available vehicle inside supported mobility zones.",
    href: "/login",
    link: "Explore autonomous",
    icon: Bot,
    tone: "cyan",
  },
  {
    title: "Car sharing",
    description:
      "Find, reserve, and unlock a shared vehicle when you need one.",
    href: "/login",
    link: "Explore car sharing",
    icon: KeyRound,
    tone: "lavender",
  },
] as const;

export default function Home() {
  return (
    <div className="home-page">
      <section className="home-hero">
        <div className="home-hero-copy">
          <p className="eyebrow">
            <Sparkles aria-hidden="true" size={15} />
            One app for moving across Cyprus
          </p>
          <h1>
            Where to<span>?</span>
          </h1>
          <p className="home-lede">
            Book a driver, request an autonomous vehicle, or reserve a shared
            car from one clear mobility app—with normal-ride fares protected by
            Kaspa covenant rules.
          </p>
          <div className="home-actions">
            <Link
              href="/register/passenger"
              className="btn btn-primary btn-large"
            >
              Request a ride
              <ArrowRight aria-hidden="true" size={18} />
            </Link>
            <Link href="/register/driver" className="btn btn-outline btn-large">
              Drive with us
            </Link>
          </div>
          <div className="home-assurance" aria-label="Platform assurances">
            <span>
              <ShieldCheck aria-hidden="true" size={18} />
              Verified drivers
            </span>
            <span>
              <WalletCards aria-hidden="true" size={18} />
              Covenant-protected normal rides
            </span>
          </div>
        </div>

        <div
          className="route-artifact"
          aria-label="Example ride from Eleftheria Square to University of Cyprus"
        >
          <div className="route-artifact-map" aria-hidden="true">
            <span className="map-street street-one" />
            <span className="map-street street-two" />
            <span className="map-street street-three" />
            <span className="map-block block-one" />
            <span className="map-block block-two" />
            <span className="map-block block-three" />
            <svg viewBox="0 0 520 420" role="presentation">
              <path
                className="route-shadow"
                d="M102 302 C142 212 192 282 244 195 S362 168 420 92"
              />
              <path
                className="route-line"
                d="M102 302 C142 212 192 282 244 195 S362 168 420 92"
              />
            </svg>
            <span className="route-pin route-pin-pickup">
              <span />
            </span>
            <span className="route-pin route-pin-dropoff">
              <MapPin aria-hidden="true" size={18} />
            </span>
            <span className="route-car">
              <CarFront aria-hidden="true" size={20} />
            </span>
          </div>
          <div className="route-artifact-sheet">
            <div className="route-sheet-handle" />
            <p className="eyebrow">Example route</p>
            <div className="route-stop-list">
              <div>
                <span className="stop-dot pickup" />
                <p>
                  <small>Pickup</small>
                  <strong>Eleftheria Square</strong>
                </p>
              </div>
              <div>
                <span className="stop-dot destination" />
                <p>
                  <small>Destination</small>
                  <strong>University of Cyprus</strong>
                </p>
              </div>
            </div>
            <div className="route-artifact-meta">
              <span>
                <Clock3 aria-hidden="true" size={16} />
                Fare shown before booking
              </span>
              <span>
                <Route aria-hidden="true" size={16} />
                Live route preview
              </span>
            </div>
          </div>
        </div>
      </section>

      <section
        className="home-service-section"
        aria-labelledby="services-title"
      >
        <div className="section-heading-row">
          <div>
            <p className="eyebrow">Choose how you move</p>
            <h2 id="services-title">One account, three ways to go.</h2>
          </div>
          <p>
            Every existing mobility mode stays available, now with a shorter
            path from intent to action.
          </p>
        </div>
        <div className="service-cards">
          {services.map(
            ({ title, description, href, link, icon: Icon, tone }) => (
              <article className={`service-card tone-${tone}`} key={title}>
                <span className="service-icon">
                  <Icon aria-hidden="true" size={24} />
                </span>
                <div>
                  <h3>{title}</h3>
                  <p>{description}</p>
                </div>
                <Link href={href}>
                  {link}
                  <ArrowRight aria-hidden="true" size={17} />
                </Link>
              </article>
            ),
          )}
        </div>
      </section>

      <section className="home-process" aria-labelledby="process-title">
        <div className="process-intro">
          <p className="eyebrow">Normal driver rides</p>
          <h2 id="process-title">From “where to?” to on your way.</h2>
          <p>
            The protocol details remain visible when you need them. The booking
            path keeps the next decision clear.
          </p>
          <Link href="/register/passenger" className="text-link">
            Create passenger account
            <ArrowRight aria-hidden="true" size={17} />
          </Link>
        </div>
        <ol className="process-steps">
          <ProcessStep icon={MapPin} number="01" title="Set your route">
            Pick up and destination stay visible beside the map.
          </ProcessStep>
          <ProcessStep icon={Navigation} number="02" title="Review the ride">
            Choose service and accessibility options, then see the fare.
          </ProcessStep>
          <ProcessStep icon={WalletCards} number="03" title="Authorize escrow">
            Your wallet signs the ride-specific covenant funding plan.
          </ProcessStep>
          <ProcessStep icon={Check} number="04" title="Ride and settle">
            Settlement follows the normal-ride signatures and covenant rules.
          </ProcessStep>
        </ol>
      </section>

      <section className="home-driver-callout">
        <div>
          <span className="callout-icon">
            <UserRoundCheck aria-hidden="true" size={25} />
          </span>
          <p className="eyebrow">For drivers</p>
          <h2>Your workday, without the clutter.</h2>
          <p>
            Manage verification, vehicles, trips, documents, messages, and
            on-chain earnings from a dedicated driver workspace.
          </p>
        </div>
        <Link href="/register/driver" className="btn btn-primary btn-large">
          Apply to drive
          <ArrowRight aria-hidden="true" size={18} />
        </Link>
      </section>
    </div>
  );
}

function ProcessStep({
  icon: Icon,
  number,
  title,
  children,
}: {
  icon: typeof MapPin;
  number: string;
  title: string;
  children: React.ReactNode;
}) {
  return (
    <li>
      <span className="process-step-icon">
        <Icon aria-hidden="true" size={20} />
      </span>
      <span className="process-step-number">{number}</span>
      <div>
        <h3>{title}</h3>
        <p>{children}</p>
      </div>
    </li>
  );
}
