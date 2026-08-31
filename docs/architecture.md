# Architecture and protocol

## Runtime topology

```text
KIP-12 wallet
    │ KIP-5 auth + explicit PSKT signatures
    ▼
Next.js 16 / React 19
    │ same-origin JSON
    ▼
FastAPI on Vercel Functions ──────► Kaspa wRPC
    │                                build, verify, broadcast, reconcile
    ▼
MongoDB Atlas
coordination, locks, projections, history
```

Kaspa is the payment authority. Atlas coordinates participants and indexes state, but cannot produce a valid covenant successor, alter the fixed fare, or choose a different terminal beneficiary.

## Ride commitment

The server serializes a canonical payload containing:

- schema version;
- ride and quote IDs;
- passenger public-key hash;
- exact fare in sompi;
- pickup and drop-off facts;
- pricing version.

The commitment is `BLAKE2b-256("kaspa-ride-escrow-v1\\0" || canonical_json)`. It is stored in covenant state and supplied to every entry, preventing a transaction built for one ride from being reused for another.

## Covenant state

| Field | Size/meaning |
| --- | --- |
| `passenger_key_hash` | BLAKE2b-256 of the passenger x-only key |
| `resolver_key_hash` | BLAKE2b-256 of the dispute resolver x-only key |
| `ride_commitment` | Fixed off-chain ride-facts commitment |
| `refund_after_daa` | Relative DAA timeout |
| `quoted_fare_sompi` | Exact escrow value |
| `driver_key_hash` | Zero before acceptance, assigned driver afterward |
| `phase` | `0` unaccepted, `1` accepted |

The state is serialized into the P2SH redeem-program preimage. A state change therefore changes the script public key while the covenant ID preserves lineage.

## Transitions

| Entry | Phase | Required ordinary authorization inputs | Outcome |
| --- | --- | --- | --- |
| `accept` | 0 | Passenger + selected driver | One exact-value successor, phase 1 |
| `cancel_unaccepted` | 0 | Passenger | Exact-value passenger refund, terminal |
| `settle` | 1 | Passenger + assigned driver | Exact-value driver payout, terminal |
| `cancel_accepted` | 1 | Passenger + assigned driver | Exact-value passenger refund, terminal |
| `resolve_driver` | 1 | Assigned driver + resolver | Exact-value driver payout, terminal |
| `resolve_passenger` | 1 | Passenger + resolver | Exact-value passenger refund, terminal |
| `timeout_refund` | 1 | Passenger after relative DAA age | Exact-value passenger refund, terminal |

Every entry checks the ride commitment, key hashes, input bounds, P2PK authorization script, and exact escrow value. Transition entries require one authorized successor; terminal entries require zero authorized successors.

## Transaction building and wallet safety

All covenant transactions use transaction version 1. Covenant compute budget is assigned only to the covenant input. Fees and tips are selected from ordinary P2PK UTXOs.

The server sends a KIP-12 signing draft containing the full transaction plus an explicit list of wallet-owned input indexes. On return it recomputes a fingerprint over every protected field and rejects:

- changed outputs, values, covenant bindings, payload, or network fields;
- signatures added to another participant's input;
- missing signatures on requested inputs;
- altered input counts or previous scripts.

Multi-party signatures advance sequentially through one MongoDB draft revision. Two participants must sign the same updated transaction, not separate copies.

## Concurrency model

- Quote consumption and ride creation run in one Atlas transaction.
- `activePassengerId` and `activeDriverId` are sparse unique indexes, so concurrent requests have one winner.
- Every ride mutation includes an expected `version`.
- A signing draft and its ride lock are created in one Atlas transaction.
- Draft advancement uses `revision` compare-and-swap updates.
- Draft TTLs and an explicit rollback status release abandoned workflows.
- Payment-event keys and ride commitments are unique and idempotent.
- Broadcast state separates `broadcasting`, `broadcast_failed`, `recording`, and `submitted`, so a chain-accepted transaction can be recovered after a database failure.

## Reconciliation

Funding and acceptance are projected as `*_submitted` immediately after broadcast. A ride read queries the covenant address through Kaspa wRPC and advances the database only if amount, covenant ID, script public key, and outpoint all match the signed plan.

Terminal transactions are immutable outcomes once broadcast. The database stores the beneficiary, amount, transaction ID, terminal kind, and payment event; it does not infer payment success from an explorer page.

## MongoDB collections

| Collection | Role |
| --- | --- |
| `users` | Signed wallet identities and optional legacy claim |
| `auth_challenges`, `sessions` | One-time KIP-5 auth and hashed sessions |
| `quotes` | Expiring fixed-fare offers |
| `rides` | Current ride state and on-chain projection |
| `signing_drafts` | One-time sequential PSKT workflows |
| `payment_events` | Idempotent payment timeline |
| `idempotency` | API operation keys |
| `legacy_*` | Isolated historical SQL Server records |
| `migration_runs` | Snapshot checksum and migration result |
