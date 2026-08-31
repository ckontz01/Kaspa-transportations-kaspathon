# Operations runbook

## Provisioned services

### MongoDB Atlas

- Organization: `Chris's Org - 2026-08-03`
- Project: `PERIOPSIS`
- Cluster: `PERIOPSIS`
- Application database: `kaspa_transportations`
- Application user: `kaspa_transportations_app`
- Custom role: `kaspa_transportations_rw`
- Inherited permission: `readWrite@kaspa_transportations`

The password is not stored in Git. Access to the pre-existing `PeriopsiStorage` database was tested and denied.

### Vercel

- Scope: `ckontz01s-projects`
- Project: `kaspa-transportations-covenants`
- Project ID: `prj_S5GX9Nli2Tnrj8o9uvAsHxN0pupQ`

Development, preview, and production environments contain the Atlas URI, database name, testnet network, resolver public key, compute budget, priority fee, session secret, reconciler secret, and application origin.

## Deploy

```powershell
npm run typecheck
npm test
.\.venv\Scripts\python.exe -m pytest
npm run build
vercel deploy
vercel deploy --prod
```

After deployment, verify:

1. `/` renders without a framework overlay or console error;
2. `/api/health` reports database and resolver configured;
3. `/api/v1/protocol` reports transaction version 1 and the pinned template hash;
4. an unauthenticated `/api/v1/session` returns 401;
5. Atlas shows function connections and expected indexes;
6. a testnet wallet can request and sign a challenge;
7. a funded test ride follows the full accept/settle or cancel path.

## Rotate secrets

For `SESSION_SECRET` or `INTERNAL_RECONCILER_SECRET`, generate 32 random bytes, replace the variable in all Vercel environments, and redeploy. Rotating the session secret invalidates the assumptions around current sessions; revoke session documents if an immediate global logout is required.

For the Atlas password:

1. change `kaspa_transportations_app` in Atlas;
2. update `MONGODB_URI` in all Vercel environments;
3. pull development variables again;
4. redeploy and verify `/api/health` plus an authenticated request;
5. confirm the user still has only `kaspa_transportations_rw`.

## Resolver key

The testnet recovery file `.resolver.testnet-10.key` is local and Git-ignored. The application deploys only `PUBLIC_KEY`. A dispute workflow must be implemented through explicit offline/organizational signing before mainnet; never add the private key to Vercel.

## Atlas indexes

Index creation is idempotent and runs on FastAPI startup/warm initialization. It can also be verified directly:

```powershell
.\.venv\Scripts\python.exe -c "from backend.db import get_database, ensure_indexes; db=get_database(); ensure_indexes(db); print(db.command('ping')['ok'])"
```

## Failure recovery

- `broadcast_failed`: signed transaction is preserved; the final signer may retry.
- `recording`: chain broadcast succeeded; retry recording without re-signing.
- expired `pending` draft: the ride lock rolls back on the next read.
- `*_submitted`: read the ride to reconcile the expected outpoint through Kaspa wRPC.
- Atlas outage after broadcast: do not build another transaction; recover the recorded draft/transaction ID first.

## Mainnet change control

Do not switch only `KASPA_NETWORK`. Mainnet requires the audit gate described in `docs/security.md`, a new resolver governance process, production Atlas network/backups, wallet compatibility evidence, and a controlled low-value canary rollout.
