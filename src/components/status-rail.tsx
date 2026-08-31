import type { RideStatus } from "@/lib/types";

const steps = [
  { key: "quote", label: "Quote", detail: "Server-priced and expiring" },
  { key: "fund", label: "Fund", detail: "Genesis covenant output" },
  { key: "accept", label: "Accept", detail: "Passenger + driver approval" },
  { key: "ride", label: "Ride", detail: "Assignment fixed in state" },
  { key: "settle", label: "Settle", detail: "Exact fare to beneficiary" },
] as const;

const stageByStatus: Record<RideStatus, number> = {
  awaiting_funding: 2,
  funding_signature_pending: 2,
  funding_submitted: 2,
  funded: 3,
  acceptance_signatures_pending: 3,
  acceptance_submitted: 3,
  accepted: 4,
  in_progress: 4,
  settlement_signatures_pending: 5,
  settled: 6,
  cancellation_signature_pending: 5,
  cancellation_signatures_pending: 5,
  timeout_refund_signature_pending: 5,
  refunded: 6,
  cancelled: 6,
};

export function StatusRail({ status }: { status?: RideStatus }) {
  const stage = status ? stageByStatus[status] : 0;
  const terminalLabel = status?.includes("refund") || status === "refunded"
    ? { label: "Refund", detail: "Fare returned to passenger" }
    : status?.includes("cancellation") || status === "cancelled"
      ? { label: "Cancel", detail: "Escrow closed cooperatively" }
      : null;
  return (
    <ol className="status-rail" aria-label="Ride payment lifecycle">
      {steps.map((step, index) => {
        const number = index + 1;
        const display = index === steps.length - 1 && terminalLabel ? terminalLabel : step;
        const state = number < stage ? "complete" : number === stage ? "active" : "pending";
        return (
          <li key={step.key} data-state={state}>
            <span className="step-index">{number.toString().padStart(2, "0")}</span>
            <span>
              <strong>{display.label}</strong>
              <small>{display.detail}</small>
            </span>
          </li>
        );
      })}
    </ol>
  );
}
