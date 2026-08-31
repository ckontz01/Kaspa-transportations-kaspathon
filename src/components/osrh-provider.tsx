"use client";

import { createContext, useContext } from "react";
import { useKaspaWallet } from "@/lib/use-kaspa-wallet";

type OsrhContextValue = ReturnType<typeof useKaspaWallet>;

const OsrhContext = createContext<OsrhContextValue | null>(null);

export function OsrhProvider({ children }: { children: React.ReactNode }) {
  const value = useKaspaWallet();
  return <OsrhContext.Provider value={value}>{children}</OsrhContext.Provider>;
}

export function useOsrh() {
  const value = useContext(OsrhContext);
  if (!value) throw new Error("useOsrh must be used inside OsrhProvider");
  return value;
}
