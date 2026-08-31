import Link from "next/link";

export default function Home() {
  return (
    <>
      <section className="hero">
        <div className="hero-content">
          <div className="hero-badge">
            <span className="pulse" />
            One-Stop Ride-Hail
          </div>
          <h1>
            Your Ride,
            <br />
            <span>Your Way</span>
          </h1>
          <p className="hero-subtitle">
            Experience seamless transportation with Kaspa Transportations!
            Connecting passengers with drivers, autonomous vehicles, and
            flexible car-sharing for safe, reliable, on-demand mobility.
          </p>
          <div className="hero-buttons">
            <Link href="#register" className="btn-hero primary">
              🚀 Get Started Free
            </Link>
            <Link href="/login" className="btn-hero secondary">
              Sign In →
            </Link>
          </div>
          <div className="stats-bar">
            <div className="stat-item kaspa-fee-stat">
              <div className="stat-value">0%</div>
              <div className="stat-label">No Middleman Fee!</div>
            </div>
            <div className="stat-item">
              <div className="stat-value">24/7</div>
              <div className="stat-label">Availability</div>
            </div>
            <div className="stat-item">
              <div className="stat-value">🔒</div>
              <div className="stat-label">Secure Platform</div>
            </div>
            <div className="stat-item">
              <div className="stat-value">🚗</div>
              <div className="stat-label">Verified Drivers</div>
            </div>
            <div className="stat-item">
              <div className="stat-value">🤖</div>
              <div className="stat-label">Autonomous Ready</div>
            </div>
          </div>
        </div>
      </section>

      <section className="features-section">
        <div className="section-header">
          <h2>Why Choose Kaspa?</h2>
          <p>
            A complete ride-hailing ecosystem designed for the University of
            Cyprus community
          </p>
        </div>
        <div className="features-grid">
          <Feature kind="passenger" icon="🎯" title="Easy Booking">
            Request rides in seconds with our intuitive interface. Set your
            pickup and dropoff, choose your ride type, and you&apos;re on your
            way.
          </Feature>
          <Feature kind="driver" icon="💰" title="Earn as a Driver">
            Join our verified driver network. Set your own schedule, accept
            rides in your area, and earn money on your own terms.
          </Feature>
          <Feature kind="autonomous" icon="🤖" title="Autonomous Vehicles">
            Experience the future of transportation with our autonomous vehicle
            fleet. Safe, efficient, and available within designated zones.
          </Feature>
          <Feature kind="carshare" icon="🔑" title="Car Sharing">
            Rent vehicles by the minute, hour, or day. Pick up and drop off at
            convenient zones across Cyprus. Freedom to drive yourself.
          </Feature>
          <Feature kind="safe" icon="🛡️" title="Safe & Verified">
            All drivers undergo verification. Real-time tracking, covenant
            payments, and support ensure your peace of mind.
          </Feature>
        </div>
      </section>

      <section className="how-it-works">
        <div className="section-header">
          <h2>How It Works</h2>
          <p>Get moving in just a few simple steps</p>
        </div>
        <div className="steps-container">
          <Step number="1" title="Create Account">
            Sign up as a passenger or driver in under a minute
          </Step>
          <Step number="2" title="Request or Accept">
            Passengers request rides, drivers accept and earn
          </Step>
          <Step number="3" title="Track & Ride">
            Real-time tracking from pickup to destination
          </Step>
          <Step number="4" title="Rate & Pay">
            Secure Kaspa covenant payment and feedback
          </Step>
        </div>
      </section>

      <section className="cta-section" id="register">
        <div className="section-header">
          <h2>Ready to Get Started?</h2>
          <p>Join the smart transportation network</p>
        </div>
        <div className="cta-cards">
          <Cta
            kind="passenger-cta"
            icon="🚕"
            title="Ride with Us"
            href="/register/passenger"
            link="Register as Passenger →"
          >
            Create your passenger account and start requesting rides today.
            Fast, reliable, and always available.
          </Cta>
          <Cta
            kind="driver-cta"
            icon="🚗"
            title="Drive with Us"
            href="/register/driver"
            link="Register as Driver →"
          >
            Become a verified driver and start earning. Flexible hours,
            transparent earnings, and full support.
          </Cta>
          <Cta
            kind="carshare-cta"
            icon="🔑"
            title="Rent a Car"
            href="/register/passenger"
            link="Get Started →"
          >
            Drive yourself with our car-sharing fleet. Register as a passenger
            to access mobility services.
          </Cta>
        </div>
      </section>
      <div className="login-banner">
        <p>
          Already have an account? <Link href="/login">Sign in here</Link>
        </p>
      </div>
    </>
  );
}

function Feature({
  kind,
  icon,
  title,
  children,
}: {
  kind: string;
  icon: string;
  title: string;
  children: React.ReactNode;
}) {
  return (
    <div className={`feature-card ${kind}`}>
      <div className="feature-icon">{icon}</div>
      <h3>{title}</h3>
      <p>{children}</p>
    </div>
  );
}

function Step({
  number,
  title,
  children,
}: {
  number: string;
  title: string;
  children: React.ReactNode;
}) {
  return (
    <div className="step">
      <div className="step-number">{number}</div>
      <h4>{title}</h4>
      <p>{children}</p>
    </div>
  );
}

function Cta({
  kind,
  icon,
  title,
  href,
  link,
  children,
}: {
  kind: string;
  icon: string;
  title: string;
  href: string;
  link: string;
  children: React.ReactNode;
}) {
  return (
    <div className={`cta-card ${kind}`}>
      <div className="cta-icon">{icon}</div>
      <h3>{title}</h3>
      <p>{children}</p>
      <Link href={href} className="btn-cta">
        {link}
      </Link>
    </div>
  );
}
