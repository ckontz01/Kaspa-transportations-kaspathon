"use client";

import { useEffect, useMemo, useState } from "react";
import {
  CircleMarker,
  MapContainer,
  Polygon,
  Polyline,
  Popup,
  TileLayer,
  useMap,
  useMapEvents,
} from "react-leaflet";
import type { LatLngBoundsExpression, LatLngExpression } from "leaflet";
import { apiRequest } from "@/lib/api";
import type { RideMapProps } from "@/components/ride-map";
import type { LocationInput } from "@/lib/types";

type GeofenceResponse = {
  geofences: Array<{
    name: string;
    description: string;
    points: Array<{ lat: number; lng: number }>;
  }>;
  bridges: Array<{ name: string; lat: number; lng: number; connects: string }>;
};

const EMPTY_MARKERS: NonNullable<RideMapProps["markers"]> = [];

function ClickSelector({
  selectionMode,
  onSelect,
}: Pick<RideMapProps, "selectionMode" | "onSelect">) {
  useMapEvents({
    click(event) {
      if (!selectionMode || !onSelect) return;
      onSelect(selectionMode, {
        label:
          selectionMode === "pickup"
            ? "Selected pickup"
            : "Selected destination",
        latitude: Number(event.latlng.lat.toFixed(6)),
        longitude: Number(event.latlng.lng.toFixed(6)),
      });
    },
  });
  return null;
}

function FitRoute({ points }: { points: LatLngExpression[] }) {
  const map = useMap();
  useEffect(() => {
    if (points.length === 1) map.setView(points[0], 13);
    else if (points.length >= 2)
      map.fitBounds(points as LatLngBoundsExpression, { padding: [36, 36] });
  }, [map, points]);
  return null;
}

export default function RideMapClient({
  pickup,
  dropoff,
  driver,
  markers = EMPTY_MARKERS,
  selectionMode = null,
  onSelect,
  onRoute,
  showGeofences = true,
}: RideMapProps) {
  const [route, setRoute] = useState<LatLngExpression[]>([]);
  const [geofences, setGeofences] = useState<GeofenceResponse>({
    geofences: [],
    bridges: [],
  });
  const [routeNotice, setRouteNotice] = useState<string | null>(null);

  useEffect(() => {
    if (!showGeofences) return;
    void apiRequest<GeofenceResponse>("/api/v1/geofences")
      .then(setGeofences)
      .catch(() => setGeofences({ geofences: [], bridges: [] }));
  }, [showGeofences]);

  useEffect(() => {
    if (!pickup || !dropoff) {
      setRoute([]);
      return;
    }
    const controller = new AbortController();
    const url = `https://router.project-osrm.org/route/v1/driving/${pickup.longitude},${pickup.latitude};${dropoff.longitude},${dropoff.latitude}?overview=full&geometries=geojson`;
    void fetch(url, { signal: controller.signal })
      .then(async (response) => {
        if (!response.ok) throw new Error("Routing service unavailable");
        return response.json() as Promise<{
          routes?: Array<{
            distance: number;
            duration: number;
            geometry: { coordinates: number[][] };
          }>;
        }>;
      })
      .then((data) => {
        const selected = data.routes?.[0];
        if (!selected) throw new Error("No road route found");
        setRoute(selected.geometry.coordinates.map(([lng, lat]) => [lat, lng]));
        setRouteNotice(null);
        onRoute?.({
          distanceMeters: selected.distance,
          durationSeconds: selected.duration,
        });
      })
      .catch((error: unknown) => {
        if (error instanceof DOMException && error.name === "AbortError")
          return;
        setRoute([
          [pickup.latitude, pickup.longitude],
          [dropoff.latitude, dropoff.longitude],
        ]);
        setRouteNotice(
          "Road routing is unavailable; showing a direct-line preview.",
        );
      });
    return () => controller.abort();
  }, [dropoff, onRoute, pickup]);

  const fitPoints = useMemo<LatLngExpression[]>(() => {
    const points: LatLngExpression[] = [];
    if (driver) points.push([driver.latitude, driver.longitude]);
    if (pickup) points.push([pickup.latitude, pickup.longitude]);
    if (dropoff) points.push([dropoff.latitude, dropoff.longitude]);
    for (const marker of markers)
      points.push([marker.latitude, marker.longitude]);
    return points;
  }, [driver, dropoff, markers, pickup]);

  return (
    <div className="ride-map-shell">
      <MapContainer
        center={[35.1667, 33.3667]}
        zoom={13}
        className="ride-map"
        scrollWheelZoom
      >
        <TileLayer
          attribution="&copy; OpenStreetMap contributors"
          url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
          maxZoom={19}
        />
        <ClickSelector selectionMode={selectionMode} onSelect={onSelect} />
        {fitPoints.length ? <FitRoute points={fitPoints} /> : null}
        {showGeofences
          ? geofences.geofences.map((item, index) => (
              <Polygon
                key={item.name}
                positions={item.points.map((point): [number, number] => [
                  point.lat,
                  point.lng,
                ])}
                pathOptions={{
                  color: ["#2563eb", "#dc2626", "#16a34a", "#d97706"][
                    index % 4
                  ],
                  fillOpacity: 0.08,
                  weight: 2,
                  dashArray: "5 5",
                }}
              >
                <Popup>{item.description}</Popup>
              </Polygon>
            ))
          : null}
        {showGeofences
          ? geofences.bridges.map((bridge) => (
              <CircleMarker
                key={bridge.name}
                center={[bridge.lat, bridge.lng]}
                radius={6}
                pathOptions={{
                  color: "#d97706",
                  fillColor: "#fbbf24",
                  fillOpacity: 1,
                }}
              >
                <Popup>
                  <strong>Transfer point</strong>
                  <br />
                  {bridge.connects}
                </Popup>
              </CircleMarker>
            ))
          : null}
        {route.length ? (
          <Polyline
            positions={route}
            pathOptions={{ color: "#3b82f6", weight: 5, opacity: 0.82 }}
          />
        ) : null}
        {driver ? (
          <MapPoint location={driver} color="#3b82f6" label="Driver" />
        ) : null}
        {pickup ? (
          <MapPoint location={pickup} color="#22c55e" label="Pickup" />
        ) : null}
        {dropoff ? (
          <MapPoint location={dropoff} color="#ef4444" label="Dropoff" />
        ) : null}
        {markers.map((marker) => (
          <MapPoint
            key={marker.id}
            location={marker}
            color={marker.color || "#8b5cf6"}
            label={marker.category || "Vehicle"}
          />
        ))}
      </MapContainer>
      {selectionMode ? (
        <div className="map-selection-overlay">
          Click the map to set{" "}
          {selectionMode === "pickup" ? "pickup" : "dropoff"}
        </div>
      ) : null}
      {routeNotice ? (
        <div className="map-route-notice">{routeNotice}</div>
      ) : null}
    </div>
  );
}

function MapPoint({
  location,
  color,
  label,
}: {
  location: LocationInput;
  color: string;
  label: string;
}) {
  return (
    <CircleMarker
      center={[location.latitude, location.longitude]}
      radius={10}
      pathOptions={{
        color: "#ffffff",
        weight: 3,
        fillColor: color,
        fillOpacity: 1,
      }}
    >
      <Popup>
        <strong>{label}</strong>
        <br />
        {location.label}
      </Popup>
    </CircleMarker>
  );
}
