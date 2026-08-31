# Security model and mainnet gate

## Current release decision

Kaspa Toccata programmability is live on mainnet, but the SilverScript repository still labels the compiler experimental and recommends testnet-10 until its first stable release. On 2026-08-13, the project published critical advisory `GHSA-c6jq-qp3r-835q` describing multiple compiler/runtime interpretation mismatches with no patched version listed.

This application is therefore ready for end-to-end testnet-10 use but deliberately refuses mainnet covenant creation unless all of the following are true:

1. the compiled artifact and source receive an independent security audit;
2. the audit checks generated Kaspa bytecode, not only SilverScript source;
3. the compiler revision and artifact hash are re-pinned after any fix;
4. transaction builders and wallets are tested against the target mainnet node version;
5. `KASPA_NETWORK=mainnet` and `ENABLE_MAINNET_COVENANTS=I_HAVE_COMPLETED_A_CONTRACT_AUDIT` are explicitly set.

Consensus availability is not equivalent to compiler maturity.

## Advisory-safe language subset

`ride-escrow.sil` does not use the constructs identified by the current advisory:

- loops or runtime loop bounds;
- ternary expressions;
- user-defined or nested structs;
- struct reassignment;
- boolean arrays;
- `.split()` destructuring;
- unsafe signature/data-signature casts;
- contract constants or duplicate constructor/function names;
- user overrides of `validateOutputState`;
- legacy `#[covenant(...)]` annotations.

`tests/python/test_silverscript_security_subset.py` enforces that restricted subset. This reduces exposure but is not a substitute for a bytecode audit.

## Trust boundaries

### Passenger and driver wallets

Wallet extensions are untrusted providers until the user explicitly connects. Provider name and icon are presentation hints only. The app discovers providers using `kaspa-wallet-standard`, requires the configured network, uses KIP-5 for identity, and calls `signPskt` only after a fresh user action.

The wallet remains the final signing boundary. Users should inspect network, inputs, beneficiary, fare, and fees in the wallet prompt.

### Backend

The backend can quote, coordinate, and propose transactions. It cannot sign passenger or driver inputs. It validates returned signed transactions before broadcast and stores no participant private key.

The resolver is not a routine backend signer. Only its public key is deployed. The ignored local recovery key exists for testnet dispute-path testing and must be replaced by an organizationally controlled signer before any audited mainnet release.

### MongoDB Atlas

Atlas stores coordination and projections, not payment authority. The app credential inherits a custom `readWrite` role only on `kaspa_transportations`; a live scope check confirmed access to the pre-existing database is denied.

The current free cluster uses a public Atlas endpoint because Vercel functions do not have fixed egress in this setup. Before processing production PII or meaningful funds, move to an Atlas tier with backups and use private/static-egress connectivity or an equivalent network control. Database-scoped credentials and TLS remain required regardless.

## Protected invariants

- One active ride per passenger and per driver.
- One covenant input from the ride lineage.
- Exactly one successor on acceptance and none on terminal spends.
- Exact fare in the covenant input and beneficiary output.
- Passenger cannot accept their own ride.
- Selected driver cannot equal passenger or resolver.
- Resolver cannot spend alone or redirect the payout.
- Network fees never reduce escrow value.
- Broadcast and recording are separately recoverable.
- Mainnet is fail-closed.

## Secret handling

- `.env*`, Vercel link metadata, migration snapshots, and resolver recovery keys are Git-ignored.
- Production and preview Mongo/session values are marked sensitive in Vercel.
- Session tokens are random; only hashes are stored in Atlas.
- Authentication challenges are one-time, rate-limited, IP-hashed, and TTL-expiring.
- Legacy password hashes are intentionally excluded from migration.

## Remaining audit work

- Independent SilverScript source and bytecode review.
- Execute every entry against the authoritative Kaspa script engine with adversarial transactions.
- Wallet interoperability testing across supported KIP-12 providers.
- Resolver governance and offline signing procedure.
- Rate limiting at the Vercel/edge layer for public production traffic.
- Paid Atlas backup/restore drill and network isolation.
