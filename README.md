# Kaspa Transportations — covenant ride payments

The normal ride-hailing path has been rebuilt as a wallet-native Next.js and FastAPI application. A passenger funds an exact-fare Kaspa transaction-v1 covenant before dispatch; driver acceptance changes the covenant state; completion, cancellation, disputes, and timeout refunds are constrained by the UTXO itself.

The current deployment target is `testnet-10`. Toccata consensus features are live on Kaspa mainnet, but [SilverScript is still explicitly experimental](https://github.com/kaspanet/silverscript/blob/master/README.md) and has an open critical compiler advisory. Mainnet covenant creation therefore fails closed until an independent contract/artifact audit is completed.

## What changed

| Legacy | Replacement |
| --- | --- |
| PHP pages and mutable session auth | Next.js 16 App Router, React 19, TypeScript, signed-wallet authentication |
| SQL Server tables, procedures, and race-prone dispatch writes | MongoDB Atlas transactions, optimistic versions, unique active-ride indexes, TTL locks |
| University PHP server | One Vercel project with a static Next.js frontend and FastAPI function |
| Pay the driver after the trip and poll an explorer | Upfront transaction-v1 covenant escrow, direct Kaspa RPC reconciliation |
| Server-trusted payment status | On-chain covenant lineage plus idempotent database projections |
| Backend-held payment authority | Explicit KIP-12 wallet signing; no routine server private key |

The PHP and T-SQL directories remain as migration evidence, but `.vercelignore` excludes them from the new deployment. Car-sharing and autonomous mobility are intentionally outside this phase.

## Payment protocol

1. The passenger connects a KIP-12 wallet and proves ownership with a KIP-5 message signature.
2. The server issues a ten-minute fixed-fare quote and commits the route, fare, passenger key, and pricing version into a BLAKE2b ride commitment.
3. The passenger signs a transaction that creates one covenant output containing the complete fare.
4. A selected driver and the passenger sign ordinary P2PK authorization inputs. The `accept` entry creates exactly one successor in the same covenant lineage with the assigned driver fixed in state.
5. Normal settlement requires passenger and driver authorization and pays the exact escrow value to the assigned driver.
6. Unaccepted cancellation is passenger-only; accepted cancellation is cooperative; resolver outcomes require the resolver and beneficiary; the passenger has a relative-DAA timeout escape hatch.

Miner fees and optional tips must come from ordinary wallet inputs. The covenant fare cannot be shaved to pay fees or redirected to an arbitrary output.

Contract source: [`contracts/ride-escrow.sil`](contracts/ride-escrow.sil)

- Compiler revision: `c7d17a15ac88610d013ec9ffffa9520aeb69929b`
- Template hash: `8aa2a011dcb03332d2b42e3d201d54c6586a5d41f62b70e622772581dca4f3fe`
- Compiled bytecode: 1,820 bytes
- State preimage: offset 1, length 159 bytes

See [`docs/architecture.md`](docs/architecture.md) for the state machine and [`docs/security.md`](docs/security.md) for the threat model and current compiler restrictions.

## Local setup

Prerequisites are Node.js 24, Python 3.12+, and a KIP-12 wallet capable of `signPskt`.

```powershell
npm install
python -m venv .venv
.\.venv\Scripts\python.exe -m pip install -e ".[dev]"
vercel link
vercel env pull .env.local --environment=development --yes
```

Run FastAPI in one terminal:

```powershell
.\.venv\Scripts\python.exe -m uvicorn api.index:app --host 127.0.0.1 --port 8000 --reload
```

Run Next.js in a second terminal:

```powershell
npm run dev
```

During development, Next.js proxies `/api/*` to `LOCAL_API_ORIGIN` (default `http://127.0.0.1:8000`). Vercel production routes `/api/*` to `api/index.py` through `vercel.json`.

The health and protocol endpoints are:

- `GET /api/health`
- `GET /api/v1/protocol`
- `GET /api/docs`

## Verification

```powershell
npm run typecheck
npm test
npm run build
.\.venv\Scripts\python.exe -m pytest
```

The test suite covers contract artifact integrity, state encoding, ABI unlocking vectors, transaction-v1 construction, KIP-5 authentication, protected wallet-signing fields, terminal fingerprints, legacy migration, and the compiler-advisory-safe language subset.

## Configuration

Copy `.env.example` for a manual environment or pull the linked Vercel development environment.

| Variable | Purpose |
| --- | --- |
| `MONGODB_URI` | Atlas SRV URI for the app-only database user |
| `MONGODB_DATABASE` | `kaspa_transportations` |
| `KASPA_NETWORK` | Defaults to `testnet-10` |
| `KASPA_RPC_URL` | Optional direct wRPC endpoint; otherwise the SDK resolver is used |
| `KASPA_RESOLVER_PUBLIC_KEY` | Public key used only for two-party dispute outcomes |
| `KASPA_COVENANT_COMPUTE_BUDGET` | Transaction-v1 covenant input compute budget |
| `SESSION_SECRET` | Hashing/session secret |
| `INTERNAL_RECONCILER_SECRET` | Reserved for authenticated reconciliation jobs |
| `APP_ORIGIN` | Origin embedded in wallet authentication challenges |
| `ENABLE_MAINNET_COVENANTS` | Must equal `I_HAVE_COMPLETED_A_CONTRACT_AUDIT` on mainnet |

The local testnet resolver recovery key is ignored by Git and stored in `.resolver.testnet-10.key`. The deployed application receives only its public key.

## SQL Server history migration

The configured local SQL Server instance currently has no `OSRH_DB` database attached, so there was no live dataset to copy. The migration is ready for the real database or a reviewed snapshot:

```powershell
# Export and validate only. The PII-bearing snapshot is Git-ignored.
.\.venv\Scripts\python.exe scripts\migrate_legacy_normal_rides.py `
  --server "localhost\SQLEXPRESS" `
  --database OSRH_DB

# Review migration-data/normal-rides.snapshot.json, then apply idempotently.
.\.venv\Scripts\python.exe scripts\migrate_legacy_normal_rides.py `
  --from-snapshot `
  --snapshot migration-data/normal-rides.snapshot.json `
  --apply
```

Password hashes are never exported. Historical normal rides and payments go to isolated `legacy_*` collections. When the owner of a migrated Kaspa address signs in, the same-wallet proof claims that history without trusting an email/password mapping.

See [`docs/migration.md`](docs/migration.md) for validation and rollback guidance.

## Deployment

The repository is linked to the Vercel project `ckontz01s-projects/kaspa-transportations-covenants`. Atlas, session, resolver-public-key, and origin settings are configured for development, preview, and production.

Live testnet deployment: [kaspa-transportations-covenants.vercel.app](https://kaspa-transportations-covenants.vercel.app)

```powershell
vercel deploy
vercel deploy --prod
```

Vercel discovers `src/app` as Next.js and `api/index.py` as FastAPI in the same project. Operational details are in [`docs/operations.md`](docs/operations.md).

## Repository map

```text
api/                     FastAPI routes deployed by Vercel
backend/                 auth, MongoDB, covenant, transactions, services
contracts/               SilverScript source, pinned artifact, ABI fixture
src/                     Next.js application and KIP-12 wallet client
scripts/                 contract generator and SQL Server migration
tests/                   Python, Vitest, and browser tests
OSRH_KASPA_PHP/          legacy reference only; not deployed
Database/                legacy SQL reference only; not deployed
```

## Scope

This release replaces the normal ride request, dispatch, escrow, signing, start, settlement, cancellation, refund, and history-migration paths. Car-sharing, autonomous rides, operator back-office features, document uploads, and the legacy geofence simulator have not been ported into the new runtime.

License: MIT.
