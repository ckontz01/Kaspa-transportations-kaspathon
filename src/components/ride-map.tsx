"use client";

import dynamic from "next/dynamic";
import type { LocationInput } from "@/lib/types";

const RideMapClient = dynamic(() => import("@/components/ride-map-client"), {
  ssr: false,
  loading: () => <div className="map-loading">Loading interactive map…</div>,
});

export type RideMapProps = {
  pickup?: LocationInput | null;
  dropoff?: LocationInput | null;
  driver?: LocationInput | null;
  markers?: Array<
    LocationInput & {
      id: string;
      color?: string;
      category?: string;
    }
  >;
  selectionMode?: "pickup" | "dropoff" | null;
  onSelect?: (mode: "pickup" | "dropoff", location: LocationInput) => void;
  onRoute?: (route: {
    distanceMeters: number;
    durationSeconds: number;
  }) => void;
  showGeofences?: boolean;
};

export function RideMap(props: RideMapProps) {
  return <RideMapClient {...props} />;
}
