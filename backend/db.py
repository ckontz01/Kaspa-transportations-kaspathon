from __future__ import annotations

from datetime import datetime, timezone
from threading import Lock
from typing import Any

from pymongo import ASCENDING, DESCENDING, MongoClient
from pymongo.database import Database

from backend.settings import get_settings


_client: MongoClient[dict[str, Any]] | None = None
_client_lock = Lock()
_indexes_ready = False
_indexes_lock = Lock()


def utcnow() -> datetime:
    return datetime.now(timezone.utc)


def get_database() -> Database[dict[str, Any]]:
    global _client
    settings = get_settings()
    settings.assert_database_ready()
    if _client is None:
        with _client_lock:
            if _client is None:
                _client = MongoClient(
                    settings.mongodb_uri,
                    appname="kaspa-transportations-v2",
                    connect=False,
                    retryWrites=True,
                    serverSelectionTimeoutMS=5_000,
                    connectTimeoutMS=5_000,
                    socketTimeoutMS=15_000,
                    maxPoolSize=30,
                    minPoolSize=0,
                    tz_aware=True,
                )
    return _client[settings.mongodb_database]


def ensure_indexes(db: Database[dict[str, Any]] | None = None) -> None:
    """Create idempotent indexes once per warm function instance."""

    global _indexes_ready
    if _indexes_ready:
        return
    with _indexes_lock:
        if _indexes_ready:
            return
        database = db if db is not None else get_database()
        database.users.create_index("address", unique=True, name="users_address_unique")
        database.users.create_index("publicKeyHash", unique=True, name="users_key_hash_unique")
        database.users.create_index(
            "legacyUserId", unique=True, sparse=True, name="users_legacy_user_unique"
        )

        database.auth_challenges.create_index(
            "expiresAt", expireAfterSeconds=0, name="challenge_expiry_ttl"
        )
        database.auth_challenges.create_index(
            [("address", ASCENDING), ("usedAt", ASCENDING)], name="challenge_lookup"
        )
        database.sessions.create_index("tokenHash", unique=True, name="session_token_unique")
        database.sessions.create_index("expiresAt", expireAfterSeconds=0, name="session_expiry_ttl")
        database.sessions.create_index("userId", name="session_user")

        database.quotes.create_index("expiresAt", expireAfterSeconds=0, name="quote_expiry_ttl")
        database.quotes.create_index(
            [("passengerId", ASCENDING), ("usedAt", ASCENDING)], name="quote_passenger_unused"
        )

        database.rides.create_index("rideCommitment", unique=True, name="ride_commitment_unique")
        database.rides.create_index(
            "activePassengerId", unique=True, sparse=True, name="one_active_ride_per_passenger"
        )
        database.rides.create_index(
            "activeDriverId", unique=True, sparse=True, name="one_active_ride_per_driver"
        )
        database.rides.create_index(
            "escrow.outpointKey", unique=True, sparse=True, name="escrow_outpoint_unique"
        )
        database.rides.create_index(
            [("status", ASCENDING), ("updatedAt", DESCENDING)], name="ride_status_recent"
        )

        database.signing_drafts.create_index(
            "expiresAt", expireAfterSeconds=0, name="draft_expiry_ttl"
        )
        database.signing_drafts.create_index(
            [("currentSigner", ASCENDING), ("status", ASCENDING), ("createdAt", DESCENDING)],
            name="draft_signer_pending",
        )
        database.signing_drafts.create_index("fingerprint", name="draft_fingerprint")

        database.payment_events.create_index("eventKey", unique=True, name="payment_event_unique")
        database.payment_events.create_index(
            [("rideId", ASCENDING), ("createdAt", ASCENDING)], name="payment_event_timeline"
        )
        database.idempotency.create_index(
            [("scope", ASCENDING), ("key", ASCENDING)], unique=True, name="idempotency_scope_key"
        )
        database.idempotency.create_index(
            "expiresAt", expireAfterSeconds=0, name="idempotency_expiry_ttl"
        )

        database.legacy_identities.create_index(
            "legacyUserId", unique=True, name="legacy_identity_user_unique"
        )
        database.legacy_wallet_links.create_index(
            "address", unique=True, name="legacy_wallet_address_unique"
        )
        database.legacy_wallet_links.create_index(
            "legacyUserId", name="legacy_wallet_user"
        )
        database.legacy_rides.create_index(
            [("passengerUserId", ASCENDING), ("requestedAt", DESCENDING)],
            name="legacy_ride_passenger_recent",
        )
        database.legacy_rides.create_index(
            [("driverUserIds", ASCENDING), ("requestedAt", DESCENDING)],
            name="legacy_ride_driver_recent",
        )
        database.legacy_payments.create_index(
            "tripId", name="legacy_payment_trip"
        )
        database.migration_runs.create_index(
            "snapshotSha256", unique=True, name="migration_snapshot_unique"
        )
        _indexes_ready = True
