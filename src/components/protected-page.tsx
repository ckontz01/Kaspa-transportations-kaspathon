"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { useOsrh } from "@/components/osrh-provider";
import type { ApiUser } from "@/lib/types";

export function ProtectedPage({
  role,
  children,
}: {
  role?: ApiUser["role"] | ApiUser["role"][];
  children: React.ReactNode;
}) {
  const { state } = useOsrh();
  const router = useRouter();
  const allowed = role ? (Array.isArray(role) ? role : [role]) : null;

  useEffect(() => {
    if (state.sessionReady && !state.user) router.replace("/login");
    if (state.user && allowed && !allowed.includes(state.user.role)) {
      const area =
        state.user.role === "driver"
          ? "driver"
          : state.user.role === "operator" || state.user.role === "admin"
            ? "operator"
            : "passenger";
      router.replace(`/${area}/dashboard`);
    }
  }, [allowed, router, state.sessionReady, state.user]);

  if (!state.sessionReady || !state.user) {
    return <div className="card page-loading">Loading your account…</div>;
  }
  if (allowed && !allowed.includes(state.user.role)) return null;
  return children;
}
