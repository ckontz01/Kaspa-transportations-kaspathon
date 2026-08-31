# SQL Server normal-ride migration

## Scope

The migrator exports only the legacy entities needed for normal ride history:

- passenger and driver identities;
- Kaspa wallet-address links;
- ride requests, locations, and trips;
- normal `Payment` rows and linked `KaspaTransaction` records.

It does not export password history, operators, uploads, autonomous rides, car-sharing, or their payment tables.

## Current source status

The repository config names `localhost\\SQLEXPRESS` / `OSRH_DB`. SQL Server Express is running, but `OSRH_DB` is not attached; only `master`, `model`, `msdb`, and `tempdb` exist. No `.bak`, `.mdf`, `.bacpac`, or BCP export exists in the workspace. Consequently, no real records were available to import during this migration.

## Export and validation

```powershell
.\.venv\Scripts\python.exe scripts\migrate_legacy_normal_rides.py `
  --server "localhost\SQLEXPRESS" `
  --database OSRH_DB
```

The command uses Windows integrated authentication by default. For SQL authentication, pass `--sql-user` and provide the password only through the `SQLCMDPASSWORD` environment variable.

The snapshot is written below `migration-data/`, which is Git-ignored because it can contain personal data. Without `--apply`, the command performs a dry run and prints counts only.

## Review checklist

Before importing, verify:

- counts match SQL Server for `User` roles, `RideRequest`, `Trip`, and `Payment`;
- every wallet address has the expected network prefix;
- no `PasswordHistory` fields appear;
- autonomous/car-sharing IDs and tables are absent;
- currency and amount fields have not been reinterpreted;
- the snapshot is transferred and retained according to the project's data policy.

## Idempotent import

```powershell
.\.venv\Scripts\python.exe scripts\migrate_legacy_normal_rides.py `
  --from-snapshot `
  --snapshot migration-data/normal-rides.snapshot.json `
  --apply
```

Documents use deterministic IDs such as `mssql:user:7` and `mssql:ride:19`, so rerunning the same or corrected snapshot upserts rather than duplicates. `migration_runs` stores the SHA-256 checksum, status, and per-collection counts.

## Identity claim

Legacy passwords are not accepted by the new app. A migrated wallet address creates a `legacy_wallet_links` entry. When that address completes a fresh KIP-5 signature, the wallet-native user atomically receives the corresponding `legacyUserId`; `GET /api/v1/legacy/rides` then returns that history.

The unique `users.legacyUserId` index prevents two modern accounts from claiming the same legacy identity.

## Rollback

Active wallet-native collections are separate from `legacy_*`, so a historical migration cannot alter covenant rides. To roll back a specific snapshot, first export the affected deterministic IDs and verify the matching `migration_runs.snapshotSha256`; remove only those `legacy_*` records in a reviewed maintenance operation. Do not drop the Atlas database or active collections.
