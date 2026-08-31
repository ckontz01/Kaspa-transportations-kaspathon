"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { FormEvent, useEffect, useState } from "react";
import {
  AlertTriangle,
  ArrowLeft,
  ArrowRight,
  BadgeDollarSign,
  Bot,
  CarFront,
  KeyRound,
  MapPinned,
  ShieldCheck,
} from "lucide-react";
import { useOsrh } from "@/components/osrh-provider";
import { apiRequest, errorMessage } from "@/lib/api";
import type { ApiUser } from "@/lib/types";

type SessionResponse = { user: ApiUser; network: string };

function dashboardFor(user: ApiUser) {
  if (user.role === "driver") return "/driver/dashboard";
  if (user.role === "operator" || user.role === "admin")
    return "/operator/dashboard";
  return "/passenger/dashboard";
}

function useRedirectSignedIn() {
  const { state } = useOsrh();
  const router = useRouter();
  useEffect(() => {
    if (state.user) router.replace(dashboardFor(state.user));
  }, [router, state.user]);
}

export function LoginPage() {
  useRedirectSignedIn();
  const { refreshSession } = useOsrh();
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setLoading(true);
    setError(null);
    const form = new FormData(event.currentTarget);
    try {
      const session = await apiRequest<SessionResponse>(
        "/api/v1/accounts/login",
        {
          method: "POST",
          body: JSON.stringify({
            email: form.get("email"),
            password: form.get("password"),
          }),
        },
      );
      await refreshSession();
      router.push(dashboardFor(session.user));
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="login-container">
      <div className="login-wrapper">
        <div className="login-brand">
          <div className="brand-logo">
            <CarFront aria-hidden="true" size={26} />
          </div>
          <h2 className="brand-title">Welcome to OSRH</h2>
          <p className="brand-subtitle">
            Your smart ride-hailing platform. Connect with drivers and
            autonomous vehicles for seamless transportation.
          </p>
          <ul className="brand-features">
            <li>
              <span className="feature-icon">
                <MapPinned aria-hidden="true" size={16} />
              </span>
              <span>Easy ride booking in seconds</span>
            </li>
            <li>
              <span className="feature-icon">
                <ShieldCheck aria-hidden="true" size={16} />
              </span>
              <span>Secure and verified platform</span>
            </li>
            <li>
              <span className="feature-icon">
                <Bot aria-hidden="true" size={16} />
              </span>
              <span>Autonomous vehicle support</span>
            </li>
            <li>
              <span className="feature-icon">
                <KeyRound aria-hidden="true" size={16} />
              </span>
              <span>Flexible car sharing</span>
            </li>
            <li>
              <span className="feature-icon">
                <BadgeDollarSign aria-hidden="true" size={16} />
              </span>
              <span>Transparent pricing</span>
            </li>
          </ul>
        </div>
        <div className="login-form-section">
          <Link href="/" className="back-link">
            <ArrowLeft aria-hidden="true" size={17} />
            Back to home
          </Link>
          <div className="login-header">
            <h1>Sign In</h1>
            <p>Enter your credentials to access your account</p>
          </div>
          {error ? (
            <div className="flash-error-custom">
              <AlertTriangle aria-hidden="true" size={18} />
              <span>{error}</span>
            </div>
          ) : null}
          <form className="login-form" onSubmit={submit}>
            <div className="form-group">
              <label className="form-label" htmlFor="email">
                Email Address
              </label>
              <input
                className="form-control"
                type="email"
                id="email"
                name="email"
                placeholder="name@example.com"
                autoComplete="email"
                required
              />
            </div>
            <div className="form-group">
              <label className="form-label" htmlFor="password">
                Password
              </label>
              <input
                className="form-control"
                type="password"
                id="password"
                name="password"
                placeholder="Enter your password"
                autoComplete="current-password"
                required
              />
            </div>
            <button type="submit" className="login-btn" disabled={loading}>
              {loading ? (
                "Signing in…"
              ) : (
                <>
                  Sign in <ArrowRight aria-hidden="true" size={17} />
                </>
              )}
            </button>
          </form>
          <div className="login-divider">
            <span>New to OSRH?</span>
          </div>
          <div className="register-links">
            <Link
              href="/register/passenger"
              className="register-link passenger"
            >
              <CarFront aria-hidden="true" size={17} />
              Passenger
            </Link>
            <Link href="/register/driver" className="register-link driver">
              <ShieldCheck aria-hidden="true" size={17} />
              Driver
            </Link>
            <Link href="/register/passenger" className="register-link carshare">
              <KeyRound aria-hidden="true" size={17} />
              Car share
            </Link>
          </div>
        </div>
      </div>
    </div>
  );
}

export function PassengerRegistrationPage() {
  useRedirectSignedIn();
  const { refreshSession } = useOsrh();
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setLoading(true);
    setError(null);
    const form = new FormData(event.currentTarget);
    const checkbox = (name: string) => form.get(name) === "on";
    try {
      await apiRequest<SessionResponse>("/api/v1/accounts/passenger", {
        method: "POST",
        body: JSON.stringify({
          fullName: form.get("fullName"),
          email: form.get("email"),
          phone: form.get("phone") || null,
          streetAddress: form.get("streetAddress") || null,
          city: form.get("city") || null,
          postalCode: form.get("postalCode") || null,
          country: form.get("country") || "Cyprus",
          password: form.get("password"),
          passwordConfirm: form.get("passwordConfirm"),
          preferences: {
            locationTracking: checkbox("locationTracking"),
            notifications: checkbox("notifications"),
            emailUpdates: checkbox("emailUpdates"),
            dataSharing: checkbox("dataSharing"),
          },
        }),
      });
      await refreshSession();
      router.push("/passenger/dashboard");
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="card registration-card">
      <div className="card-header registration-heading">
        <div>
          <h1 className="card-title">Passenger Registration</h1>
          <p>Create your account to book rides and deliveries.</p>
        </div>
      </div>
      {error ? <div className="flash flash-error">{error}</div> : null}
      <form onSubmit={submit}>
        <FormSection title="Personal Information">
          <Field
            label="Full Name"
            name="fullName"
            autoComplete="name"
            required
          />
          <Field
            label="Email"
            name="email"
            type="email"
            autoComplete="email"
            required
          />
          <Field
            label="Phone (optional)"
            name="phone"
            autoComplete="tel"
            placeholder="e.g., 99123456"
          />
        </FormSection>
        <FormSection title="Address (Optional)">
          <Field
            label="Street Address"
            name="streetAddress"
            autoComplete="street-address"
            placeholder="e.g., 123 Main Street"
          />
          <div className="address-grid">
            <Field
              label="City"
              name="city"
              autoComplete="address-level2"
              placeholder="e.g., Nicosia"
            />
            <Field
              label="Postal Code"
              name="postalCode"
              autoComplete="postal-code"
              placeholder="e.g., 1000"
            />
            <Field
              label="Country"
              name="country"
              autoComplete="country-name"
              defaultValue="Cyprus"
            />
          </div>
        </FormSection>
        <FormSection title="Security">
          <Field
            label="Password"
            name="password"
            type="password"
            autoComplete="new-password"
            minLength={8}
            required
            hint="Minimum 8 characters"
          />
          <Field
            label="Confirm Password"
            name="passwordConfirm"
            type="password"
            autoComplete="new-password"
            minLength={8}
            required
          />
        </FormSection>
        <FormSection title="Privacy Preferences (GDPR)">
          <p className="form-help">
            Control how we use your data. You can change these settings anytime
            in your profile.
          </p>
          <Check
            name="locationTracking"
            label="Allow location tracking for ride pickup"
            hint="Required for automatic pickup location detection"
            defaultChecked
          />
          <Check
            name="notifications"
            label="Allow push notifications"
            hint="Receive updates about your rides and driver arrival"
            defaultChecked
          />
          <Check
            name="emailUpdates"
            label="Allow email updates"
            hint="Receive receipts and service updates via email"
            defaultChecked
          />
          <Check
            name="dataSharing"
            label="Allow data sharing with partners"
            hint="Help us improve our services through analytics"
          />
        </FormSection>
        <button
          type="submit"
          className="btn btn-primary btn-block"
          disabled={loading}
        >
          {loading ? "Creating account…" : "Create Account"}
        </button>
        <p className="auth-footnote">
          Already have an account? <Link href="/login">Sign in</Link>
        </p>
        <p className="auth-footnote">
          Want to become a driver?{" "}
          <Link href="/register/driver">Register as driver</Link>
        </p>
      </form>
    </div>
  );
}

export function DriverRegistrationPage() {
  useRedirectSignedIn();
  const { refreshSession } = useOsrh();
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const maximumBirthDate = new Date(
    new Date().setFullYear(new Date().getFullYear() - 18),
  )
    .toISOString()
    .slice(0, 10);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setLoading(true);
    setError(null);
    const form = new FormData(event.currentTarget);
    try {
      await apiRequest<SessionResponse>("/api/v1/accounts/driver", {
        method: "POST",
        body: JSON.stringify({
          fullName: form.get("fullName"),
          email: form.get("email"),
          phone: form.get("phone"),
          dateOfBirth: form.get("dateOfBirth"),
          password: form.get("password"),
          passwordConfirm: form.get("passwordConfirm"),
          idCardNumber: form.get("idCardNumber"),
          licenseNumber: form.get("licenseNumber"),
        }),
      });
      await refreshSession();
      router.push("/driver/dashboard");
    } catch (caught) {
      setError(errorMessage(caught));
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="card registration-card driver-registration-card">
      <div className="card-header registration-heading">
        <div>
          <h1 className="card-title">Driver Registration</h1>
          <p>Create your driver account</p>
        </div>
      </div>
      {error ? <div className="flash flash-error">{error}</div> : null}
      <form onSubmit={submit}>
        <FormSection title="Personal Information">
          <Field label="Full Name *" name="fullName" required />
          <Field label="Email *" name="email" type="email" required />
          <Field label="Phone *" name="phone" type="tel" required />
          <Field
            label="Date of Birth *"
            name="dateOfBirth"
            type="date"
            max={maximumBirthDate}
            required
          />
        </FormSection>
        <FormSection title="Password">
          <Field
            label="Password *"
            name="password"
            type="password"
            minLength={8}
            required
          />
          <Field
            label="Confirm Password *"
            name="passwordConfirm"
            type="password"
            minLength={8}
            required
          />
        </FormSection>
        <FormSection title="Documents">
          <Field label="ID Card Number *" name="idCardNumber" required />
          <Field
            label="Driver License Number *"
            name="licenseNumber"
            required
          />
          <p className="form-help">
            Document numbers are protected with one-way HMAC storage. Uploads
            can be added from Driver → Documents after approval.
          </p>
        </FormSection>
        <button
          type="submit"
          className="btn btn-primary btn-block"
          disabled={loading}
        >
          {loading ? "Submitting…" : "Register as Driver"}
        </button>
        <p className="auth-footnote">
          Already registered? <Link href="/login">Sign in</Link>
        </p>
      </form>
    </div>
  );
}

function FormSection({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <section className="form-section">
      <h3>{title}</h3>
      {children}
    </section>
  );
}

function Field({
  label,
  name,
  hint,
  ...props
}: {
  label: string;
  name: string;
  hint?: string;
} & React.InputHTMLAttributes<HTMLInputElement>) {
  return (
    <div className="form-group">
      <label className="form-label" htmlFor={name}>
        {label}
      </label>
      <input id={name} name={name} className="form-control" {...props} />
      {hint ? <div className="form-help">{hint}</div> : null}
    </div>
  );
}

function Check({
  name,
  label,
  hint,
  defaultChecked,
}: {
  name: string;
  label: string;
  hint: string;
  defaultChecked?: boolean;
}) {
  return (
    <div className="form-group">
      <label className="checkbox-label">
        <input type="checkbox" name={name} defaultChecked={defaultChecked} />
        <span>{label}</span>
      </label>
      <div className="form-help checkbox-hint">{hint}</div>
    </div>
  );
}
