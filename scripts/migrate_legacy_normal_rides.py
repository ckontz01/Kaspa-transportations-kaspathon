from __future__ import annotations

import argparse
import hashlib
import json
import os
import subprocess
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable, Mapping

from pymongo import UpdateOne

from backend.db import ensure_indexes, get_database


USERS_SQL = r"""
SET NOCOUNT ON;
SELECT
    u.UserID AS userId,
    u.Email AS email,
    u.Phone AS phone,
    u.FullName AS fullName,
    u.Status AS status,
    u.CreatedAt AS createdAt,
    p.PassengerID AS passengerId,
    p.LoyaltyLevel AS loyaltyLevel,
    d.DriverID AS driverId,
    d.DriverType AS driverType,
    d.VerificationStatus AS driverVerificationStatus,
    d.RatingAverage AS driverRatingAverage,
    JSON_QUERY((
        SELECT
            w.WalletID AS walletId,
            LOWER(w.WalletAddress) AS address,
            w.AddressPrefix AS addressPrefix,
            w.IsVerified AS isVerified,
            w.VerifiedAt AS verifiedAt,
            w.WalletType AS walletType,
            w.IsDefault AS isDefault,
            w.IsActive AS isActive
        FROM dbo.KaspaWallet w
        WHERE w.UserID = u.UserID
        FOR JSON PATH, INCLUDE_NULL_VALUES
    )) AS wallets
FROM dbo.[User] u
LEFT JOIN dbo.Passenger p ON p.UserID = u.UserID
LEFT JOIN dbo.Driver d ON d.UserID = u.UserID
WHERE p.PassengerID IS NOT NULL OR d.DriverID IS NOT NULL
FOR JSON PATH, INCLUDE_NULL_VALUES;
"""


RIDES_SQL = r"""
SET NOCOUNT ON;
SELECT
    rr.RideRequestID AS rideRequestId,
    p.UserID AS passengerUserId,
    rr.RequestedAt AS requestedAt,
    rr.Status AS status,
    rr.PassengerNotes AS passengerNotes,
    rr.EstimatedDistanceKm AS estimatedDistanceKm,
    rr.EstimatedDurationMin AS estimatedDurationMin,
    rr.EstimatedFare AS estimatedFare,
    JSON_QUERY((
        SELECT
            pickup.Description AS label,
            pickup.StreetAddress AS streetAddress,
            pickup.LatDegrees AS latitude,
            pickup.LonDegrees AS longitude
        FOR JSON PATH, WITHOUT_ARRAY_WRAPPER, INCLUDE_NULL_VALUES
    )) AS pickup,
    JSON_QUERY((
        SELECT
            dropoff.Description AS label,
            dropoff.StreetAddress AS streetAddress,
            dropoff.LatDegrees AS latitude,
            dropoff.LonDegrees AS longitude
        FOR JSON PATH, WITHOUT_ARRAY_WRAPPER, INCLUDE_NULL_VALUES
    )) AS dropoff,
    JSON_QUERY((
        SELECT
            t.TripID AS tripId,
            d.UserID AS driverUserId,
            t.DispatchTime AS dispatchTime,
            t.StartTime AS startTime,
            t.EndTime AS endTime,
            t.TotalDistanceKm AS totalDistanceKm,
            t.TotalDurationSec AS totalDurationSec,
            t.Status AS status,
            t.ActualCost AS actualCost,
            t.DriverPayout AS driverPayout,
            t.PlatformFee AS platformFee
        FROM dbo.Trip t
        INNER JOIN dbo.Driver d ON d.DriverID = t.DriverID
        WHERE t.RideRequestID = rr.RideRequestID
        FOR JSON PATH, INCLUDE_NULL_VALUES
    )) AS trips
FROM dbo.RideRequest rr
INNER JOIN dbo.Passenger p ON p.PassengerID = rr.PassengerID
INNER JOIN dbo.Location pickup ON pickup.LocationID = rr.PickupLocationID
INNER JOIN dbo.Location dropoff ON dropoff.LocationID = rr.DropoffLocationID
FOR JSON PATH, INCLUDE_NULL_VALUES;
"""


PAYMENTS_SQL = r"""
SET NOCOUNT ON;
SELECT
    payment.PaymentID AS paymentId,
    payment.TripID AS tripId,
    payment.SegmentID AS segmentId,
    payment.Amount AS amount,
    payment.CurrencyCode AS currencyCode,
    payment.ProviderReference AS providerReference,
    payment.Status AS status,
    payment.CreatedAt AS createdAt,
    payment.CompletedAt AS completedAt,
    payment.BaseFare AS baseFare,
    payment.DistanceFare AS distanceFare,
    payment.TimeFare AS timeFare,
    payment.ServiceFeeAmount AS serviceFeeAmount,
    payment.DriverEarnings AS driverEarnings,
    payment.TipAmount AS tipAmount,
    JSON_QUERY((
        SELECT
            tx.KaspaTransactionID AS kaspaTransactionId,
            tx.FromUserID AS fromUserId,
            tx.ToUserID AS toUserId,
            LOWER(tx.FromWalletAddress) AS fromWalletAddress,
            LOWER(tx.ToWalletAddress) AS toWalletAddress,
            tx.AmountKAS AS amountKas,
            tx.AmountSompi AS amountSompi,
            tx.NetworkID AS network,
            tx.TransactionHash AS transactionHash,
            tx.BlockDaaScore AS blockDaaScore,
            tx.Confirmations AS confirmations,
            tx.Status AS status,
            tx.TransactionType AS transactionType,
            tx.CreatedAt AS createdAt,
            tx.ConfirmedAt AS confirmedAt
        FROM dbo.KaspaTransaction tx
        WHERE tx.PaymentID = payment.PaymentID
          AND tx.AutonomousRideID IS NULL
          AND tx.RentalID IS NULL
        FOR JSON PATH, INCLUDE_NULL_VALUES
    )) AS kaspaTransactions
FROM dbo.Payment payment
FOR JSON PATH, INCLUDE_NULL_VALUES;
"""


def _sqlcmd_json(
    query: str, *, server: str, database: str, username: str | None = None
) -> list[dict[str, Any]]:
    command = [
        "sqlcmd",
        "-S",
        server,
        "-d",
        database,
        "-b",
        "-w",
        "65535",
        "-y",
        "0",
        "-f",
        "65001",
    ]
    if username:
        if not os.environ.get("SQLCMDPASSWORD"):
            raise RuntimeError("Set SQLCMDPASSWORD when --sql-user is supplied")
        command.extend(["-U", username])
    else:
        command.append("-E")
    command.extend(["-Q", query])
    completed = subprocess.run(
        command,
        check=False,
        capture_output=True,
        text=True,
        encoding="utf-8-sig",
    )
    if completed.returncode != 0:
        detail = completed.stderr.strip().splitlines()[-1] if completed.stderr.strip() else "unknown error"
        raise RuntimeError(f"SQL Server export failed: {detail}")
    output = "".join(line.strip() for line in completed.stdout.splitlines()).strip()
    start = output.find("[")
    end = output.rfind("]")
    payload = output[start : end + 1] if start >= 0 and end >= start else ""
    return json.loads(payload or "[]")


def export_snapshot(
    *, server: str, database: str, username: str | None = None
) -> dict[str, Any]:
    return {
        "format": "kaspa-transportations-legacy-normal-rides-v1",
        "exportedAt": datetime.now(timezone.utc).isoformat(),
        "source": {"engine": "mssql", "database": database},
        "users": _sqlcmd_json(USERS_SQL, server=server, database=database, username=username),
        "rides": _sqlcmd_json(RIDES_SQL, server=server, database=database, username=username),
        "payments": _sqlcmd_json(
            PAYMENTS_SQL, server=server, database=database, username=username
        ),
    }


def _documents_by_collection(
    snapshot: Mapping[str, Any], *, imported_at: datetime
) -> dict[str, list[dict[str, Any]]]:
    if snapshot.get("format") != "kaspa-transportations-legacy-normal-rides-v1":
        raise ValueError("Unsupported legacy snapshot format")
    collections: dict[str, list[dict[str, Any]]] = {
        "legacy_identities": [],
        "legacy_wallet_links": [],
        "legacy_rides": [],
        "legacy_payments": [],
    }
    source_database = str(snapshot.get("source", {}).get("database") or "OSRH_DB")
    source_name = f"mssql:{source_database}"
    for row in snapshot.get("users", []):
        user_id = int(row["userId"])
        roles = []
        if row.get("passengerId") is not None:
            roles.append("passenger")
        if row.get("driverId") is not None:
            roles.append("driver")
        wallets = row.get("wallets") or []
        identity = {
            "_id": f"mssql:user:{user_id}",
            "legacyUserId": user_id,
            "email": row.get("email"),
            "phone": row.get("phone"),
            "fullName": row.get("fullName"),
            "status": row.get("status"),
            "roles": roles,
            "passengerId": row.get("passengerId"),
            "driverId": row.get("driverId"),
            "driverType": row.get("driverType"),
            "driverVerificationStatus": row.get("driverVerificationStatus"),
            "driverRatingAverage": row.get("driverRatingAverage"),
            "wallets": wallets,
            "source": source_name,
            "importedAt": imported_at,
        }
        collections["legacy_identities"].append(identity)
        for wallet in wallets:
            address = str(wallet.get("address") or "").strip().lower()
            if not address or not wallet.get("isActive", True):
                continue
            collections["legacy_wallet_links"].append(
                {
                    "_id": f"mssql:wallet:{int(wallet['walletId'])}",
                    "legacyUserId": user_id,
                    "address": address,
                    "networkPrefix": wallet.get("addressPrefix"),
                    "wasVerified": bool(wallet.get("isVerified")),
                    "source": source_name,
                    "importedAt": imported_at,
                }
            )
    for row in snapshot.get("rides", []):
        ride_id = int(row["rideRequestId"])
        trips = row.get("trips") or []
        collections["legacy_rides"].append(
            {
                "_id": f"mssql:ride:{ride_id}",
                "legacyRideRequestId": ride_id,
                "passengerUserId": int(row["passengerUserId"]),
                "driverUserIds": sorted(
                    {
                        int(trip["driverUserId"])
                        for trip in trips
                        if trip.get("driverUserId") is not None
                    }
                ),
                "requestedAt": row.get("requestedAt"),
                "status": row.get("status"),
                "pickup": row.get("pickup"),
                "dropoff": row.get("dropoff"),
                "estimatedDistanceKm": row.get("estimatedDistanceKm"),
                "estimatedDurationMin": row.get("estimatedDurationMin"),
                "estimatedFare": row.get("estimatedFare"),
                "passengerNotes": row.get("passengerNotes"),
                "trips": trips,
                "source": source_name,
                "importedAt": imported_at,
            }
        )
    for row in snapshot.get("payments", []):
        payment_id = int(row["paymentId"])
        collections["legacy_payments"].append(
            {
                "_id": f"mssql:payment:{payment_id}",
                "legacyPaymentId": payment_id,
                **{key: value for key, value in row.items() if key != "paymentId"},
                "source": source_name,
                "importedAt": imported_at,
            }
        )
    return collections


def _upserts(documents: Iterable[Mapping[str, Any]]) -> list[UpdateOne]:
    return [
        UpdateOne(
            {"_id": document["_id"]},
            {"$set": {key: value for key, value in document.items() if key != "_id"}},
            upsert=True,
        )
        for document in documents
    ]


def import_snapshot(snapshot: Mapping[str, Any], raw_snapshot: bytes) -> dict[str, int]:
    db = get_database()
    ensure_indexes(db)
    imported_at = datetime.now(timezone.utc)
    sha256 = hashlib.sha256(raw_snapshot).hexdigest()
    collections = _documents_by_collection(snapshot, imported_at=imported_at)
    db.migration_runs.update_one(
        {"snapshotSha256": sha256},
        {
            "$set": {"status": "running", "updatedAt": imported_at},
            "$setOnInsert": {
                "snapshotSha256": sha256,
                "format": snapshot["format"],
                "createdAt": imported_at,
            },
        },
        upsert=True,
    )
    counts: dict[str, int] = {}
    try:
        for collection_name, documents in collections.items():
            operations = _upserts(documents)
            if operations:
                db[collection_name].bulk_write(operations, ordered=False)
            counts[collection_name] = len(operations)
        db.migration_runs.update_one(
            {"snapshotSha256": sha256},
            {"$set": {"status": "complete", "counts": counts, "completedAt": imported_at}},
        )
    except Exception as exc:
        db.migration_runs.update_one(
            {"snapshotSha256": sha256},
            {"$set": {"status": "failed", "failureType": type(exc).__name__}},
        )
        raise
    return counts


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Export and idempotently migrate legacy normal-ride history to Atlas."
    )
    parser.add_argument("--server", default=r"localhost\SQLEXPRESS")
    parser.add_argument("--database", default="OSRH_DB")
    parser.add_argument("--sql-user", default=None)
    parser.add_argument(
        "--snapshot",
        type=Path,
        default=Path("migration-data/normal-rides.snapshot.json"),
    )
    parser.add_argument(
        "--from-snapshot",
        action="store_true",
        help="Skip SQL Server export and use the existing --snapshot file.",
    )
    parser.add_argument(
        "--apply",
        action="store_true",
        help="Import the validated snapshot into the configured Atlas database.",
    )
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    if args.from_snapshot:
        raw_snapshot = args.snapshot.read_bytes()
        snapshot = json.loads(raw_snapshot)
    else:
        snapshot = export_snapshot(
            server=args.server, database=args.database, username=args.sql_user
        )
        args.snapshot.parent.mkdir(parents=True, exist_ok=True)
        raw_snapshot = json.dumps(
            snapshot, ensure_ascii=False, indent=2, default=str
        ).encode("utf-8")
        args.snapshot.write_bytes(raw_snapshot)
    preview = _documents_by_collection(
        snapshot, imported_at=datetime.now(timezone.utc)
    )
    counts = {name: len(documents) for name, documents in preview.items()}
    print(json.dumps({"validated": True, "counts": counts}, sort_keys=True))
    if args.apply:
        imported = import_snapshot(snapshot, raw_snapshot)
        print(json.dumps({"imported": True, "counts": imported}, sort_keys=True))
    else:
        print("Dry run only. Re-run with --apply after reviewing the ignored snapshot file.")


if __name__ == "__main__":
    main()
