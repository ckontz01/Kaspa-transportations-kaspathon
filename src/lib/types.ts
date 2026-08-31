import type { KaspaProviderDetail } from "kaspa-wallet-standard";

export type ApiUser = {
  id: string;
  paymentIdentityId?: string | null;
  address: string | null;
  displayName: string | null;
  fullName?: string | null;
  email?: string | null;
  phone?: string | null;
  role: "passenger" | "driver" | "operator" | "admin";
  status: string;
  verificationStatus?: string | null;
  publicKey: string | null;
  publicKeyHash: string | null;
  network: string | null;
  addressProfile?: {
    streetAddress?: string | null;
    city?: string | null;
    postalCode?: string | null;
    country?: string | null;
  };
  preferences?: {
    locationTracking?: boolean;
    notifications?: boolean;
    emailUpdates?: boolean;
    dataSharing?: boolean;
  };
  driverProfile?: {
    isAvailable?: boolean;
    activeVehicleId?: string | null;
    useGps?: boolean;
    currentLatitude?: number | null;
    currentLongitude?: number | null;
  } | null;
  createdAt: string;
  updatedAt?: string | null;
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
  serviceType: "standard" | "comfort" | "accessible" | "cargo";
  luggageVolume?: number | null;
  wheelchairNeeded: boolean;
  passengerNotes?: string | null;
  useSimulation: boolean;
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
  serviceType?: "standard" | "comfort" | "accessible" | "cargo";
  luggageVolume?: number | null;
  wheelchairNeeded?: boolean;
  passengerNotes?: string | null;
  useSimulation?: boolean;
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

export type Vehicle = {
  id: string;
  vehicleType: string;
  plateNumber: string;
  make: string;
  model: string;
  year: number;
  color?: string | null;
  seatingCapacity: number;
  wheelchairReady: boolean;
  status: string;
  isActive: boolean;
};

export type PaymentRecord = {
  id: string;
  rideId: string;
  status: string;
  kind: string;
  transactionId?: string | null;
  amountSompi: number;
  beneficiaryAddress?: string | null;
  createdAt: string;
  pickup: LocationInput;
  dropoff: LocationInput;
};

export type Contact = {
  id: string;
  fullName: string;
  role: ApiUser["role"];
  status: string;
};

export type MessageRecord = {
  id: string;
  senderId: string;
  recipientId: string;
  content: string;
  createdAt: string;
  readAt?: string | null;
};

export type SubmitDraftResult = {
  draftId: string;
  status: "awaiting_next_signer" | "submitted";
  nextSigner?: string;
  transactionId?: string;
  ride?: Ride;
};
