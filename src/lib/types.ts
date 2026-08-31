import type { KaspaProviderDetail } from "kaspa-wallet-standard";

export type ApiUser = {
  id: string;
  address: string;
  displayName: string | null;
  publicKey: string;
  publicKeyHash: string;
  network: string;
  createdAt: string;
};

export type LocationInput = {
  label: string;
  latitude: number;
  longitude: number;
};

export type RideQuote = {
  id: string;
  pickup: LocationInput;
  dropoff: LocationInput;
  routeDistanceMeters: number;
  estimatedDurationSeconds: number;
  quotedFareSompi: string;
  quotedFareKas: string;
  pricingVersion: string;
  expiresAt: string;
};

export type EscrowState = {
  passenger_key_hash: string;
  resolver_key_hash: string;
  ride_commitment: string;
  refund_after_daa: number;
  quoted_fare_sompi: number;
  driver_key_hash: string;
  phase: number;
};

export type RideStatus =
  | "awaiting_funding"
  | "funding_signature_pending"
  | "funding_submitted"
  | "funded"
  | "acceptance_signatures_pending"
  | "acceptance_submitted"
  | "accepted"
  | "in_progress"
  | "settlement_signatures_pending"
  | "settled"
  | "cancellation_signature_pending"
  | "cancellation_signatures_pending"
  | "timeout_refund_signature_pending"
  | "refunded"
  | "cancelled";

export type Ride = {
  id: string;
  passengerId: string;
  passengerAddress: string;
  passengerPublicKey: string;
  driverId?: string;
  driverAddress?: string;
  driverPublicKey?: string;
  pickup: LocationInput;
  dropoff: LocationInput;
  routeDistanceMeters: number;
  estimatedDurationSeconds: number;
  quotedFareSompi: number;
  pricingVersion: string;
  rideCommitment: string;
  status: RideStatus;
  version: number;
  network: string;
  escrow: {
    templateHash: string;
    state: EscrowState;
    confirmationStatus: string;
    address?: string;
    txId?: string;
    outputIndex?: number;
    covenantId?: string;
    confirmedAt?: string;
  };
  payment?: {
    transactionId: string;
    beneficiaryAddress: string;
    amountSompi: number;
    kind: string;
  };
  createdAt: string;
  updatedAt: string;
};

export type SigningDraft = {
  id: string;
  rideId: string;
  action: string;
  status: string;
  currentSigner: string;
  signingPosition: number;
  signerCount: number;
  transactionJson: string;
  signInputs: Array<{ index: number; sighashType: number }>;
  expiresAt: string;
};

export type WalletState = {
  providers: KaspaProviderDetail[];
  active: KaspaProviderDetail | null;
  user: ApiUser | null;
  address: string | null;
  network: string | null;
  phase: "discovering" | "idle" | "connecting" | "authenticated" | "error";
  error: string | null;
  canSignCovenants: boolean;
};

export type SubmitDraftResult = {
  draftId: string;
  status: "awaiting_next_signer" | "submitted";
  nextSigner?: string;
  transactionId?: string;
  ride?: Ride;
};
