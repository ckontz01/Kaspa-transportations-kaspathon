import type { RideStatus } from "@/lib/types";

export const terminalStatuses = new Set<RideStatus>([
  "settled",
  "refunded",
  "cancelled",
]);

export const rideStatusLabel: Record<RideStatus, string> = {
  awaiting_funding: "Waiting for escrow funding",
  funding_signature_pending: "Funding signature reserved",
  funding_submitted: "Funding submitted to Kaspa",
  funded: "Escrow funded; awaiting driver",
  acceptance_signatures_pending: "Driver and passenger signatures pending",
  acceptance_submitted: "Assignment submitted to Kaspa",
  accepted: "Driver accepted",
  in_progress: "Ride in progress",
  settlement_signatures_pending: "Settlement signatures pending",
  settled: "Completed and paid",
  cancellation_signature_pending: "Cancellation signature pending",
  cancellation_signatures_pending: "Cooperative cancellation pending",
  timeout_refund_signature_pending: "Timeout refund signature pending",
  refunded: "Fare refunded",
  cancelled: "Cancelled",
};

export function formatKas(sompi: number | string) {
  const amount = Number(sompi) / 100_000_000;
  return `${amount.toLocaleString(undefined, { maximumFractionDigits: 8 })} KAS`;
}

export function formatDistance(meters: number) {
  return `${(meters / 1_000).toFixed(2)} km`;
}

export function formatDuration(seconds: number) {
  return `${Math.max(1, Math.ceil(seconds / 60))} min`;
}

export function shortHash(value?: string | null, start = 12, end = 8) {
  if (!value) return "—";
  return value.length > start + end
    ? `${value.slice(0, start)}…${value.slice(-end)}`
    : value;
}
