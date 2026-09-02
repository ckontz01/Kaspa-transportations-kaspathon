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

## CI/CD Pipeline & Automated Deployment

A GitHub Actions workflow (`.github/workflows/ci-cd.yml`) handles continuous integration and continuous deployment:

* **On every Push to `main`**: Runs TypeScript checks, Vitest frontend tests, Pytest backend/covenant tests, Next.js build, and automatically deploys to Vercel production.
* **On Pull Requests targeting `main`**: Runs the full test suite and deploys an ephemeral Vercel preview URL.

### Required GitHub Repository Secrets

To enable automated Vercel deployment from GitHub Actions, configure these secrets under **Settings > Secrets and variables > Actions** in the GitHub repository:

1. `VERCEL_TOKEN`: Personal Access Token from [Vercel Account Tokens](https://vercel.com/account/tokens).
2. `VERCEL_ORG_ID` *(optional)*: `team_zp2cqDeinRPTnK5u3NmLPgOF` (defaults to project settings if omitted).
3. `VERCEL_PROJECT_ID` *(optional)*: `prj_S5GX9Nli2Tnrj8o9uvAsHxN0pupQ` (defaults to project settings if omitted).

*Note: If `VERCEL_TOKEN` is not yet configured, the workflow still runs all test suites to protect the repository on every push and pull request.*

## Manual Deploy (CLI Fallback)

If deploying manually from a local checkout:

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

## Bootstrap the first operator

Public registration creates only passenger or driver accounts. After the intended
operator has registered normally, assign the privileged role from a trusted local
checkout with the Vercel development environment pulled:

```powershell
uv run python scripts\promote_operator.py --email operator@example.com
```

The command requires the email to be typed a second time, changes the role and
writes its audit record in one Atlas transaction, and exposes no privilege-change
endpoint to the public application. Use `--role admin` only for an account that
needs the broader administrative role. The user's next authenticated request reads
the new role directly from Atlas.

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
