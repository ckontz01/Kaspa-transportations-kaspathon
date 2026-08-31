from __future__ import annotations

import hashlib
import json
import math
import secrets
from datetime import timedelta
from typing import Any, Callable, Mapping, Sequence

from bson import ObjectId
from kaspa import PublicKey
from pymongo import ReturnDocument
from pymongo.database import Database
from pymongo.errors import DuplicateKeyError, PyMongoError

from backend.accounts import hydrate_account_principal
from backend.covenant import RideEscrowState, protocol_metadata
from backend.db import utcnow
from backend.errors import bad_request, conflict, forbidden, not_found, unauthorized, unavailable
from backend.presentation import public_draft, public_ride, public_user
from backend.schemas import (
    CreateRideRequest,
    QuoteRequest,
    WalletAuthRequest,
    WalletVerifyRequest,
)
from backend.security import (
    create_challenge_message,
    new_session_token,
    token_hash,
    verify_wallet_message,
    wallet_identity,
)
from backend.settings import Settings
from backend.transactions import (
    BuiltTransaction,
    Signer,
    build_accept_transaction,
    build_funding_transaction,
    build_terminal_transaction,
    fetch_fee_rate,
    fetch_utxos,
    input_signature_scripts,
    matching_utxo,
    rpc_client,
    select_auth_utxo,
    submit_transaction,
    transaction_from_safe_json,
    transaction_fingerprint,
)


QUOTE_TTL = timedelta(minutes=10)
DRAFT_TTL = timedelta(minutes=20)
IDEMPOTENCY_TTL = timedelta(hours=24)
REFUND_AFTER_DAA = 43_200
BASE_FARE_SOMPI = 75_000_000
PER_KM_SOMPI = 18_000_000
PER_MINUTE_SOMPI = 2_000_000
PRICING_VERSION = "normal-ride-2026-08"


def normalize_network(value: str) -> str:
    clean = value.strip().lower()
    if clean.startswith("kaspa_"):
        clean = clean.removeprefix("kaspa_").replace("_", "-")
    return clean


def create_auth_challenge(
    db: Database[dict[str, Any]], request: WalletAuthRequest, settings: Settings, ip: str | None
) -> dict[str, Any]:
    if normalize_network(request.network) != settings.kaspa_network:
        raise bad_request(
            f"Switch the wallet to {settings.kaspa_network} before signing in",
            "wrong_network",
        )
    try:
        identity = wallet_identity(request.address, request.public_key, settings.network_type)
    except ValueError as exc:
        raise bad_request(str(exc), "invalid_wallet_identity") from exc

    recent = db.auth_challenges.count_documents(
        {
            "address": identity.address,
            "createdAt": {"$gt": utcnow() - timedelta(minutes=1)},
        },
        limit=11,
    )
    if recent >= 10:
        raise conflict("Too many sign-in requests. Wait one minute and try again.", "rate_limited")

    challenge_id = secrets.token_urlsafe(24)
    nonce, message, expires_at = create_challenge_message(identity.address, settings.app_origin)
    db.auth_challenges.insert_one(
        {
            "_id": challenge_id,
            "address": identity.address,
            "publicKey": identity.public_key,
            "publicKeyHash": identity.public_key_hash,
            "network": settings.kaspa_network,
            "nonceHash": hashlib.sha256(nonce.encode("ascii")).hexdigest(),
            "message": message,
            "ipHash": (
                hashlib.sha256(ip.encode("utf-8")).hexdigest() if ip else None
            ),
            "createdAt": utcnow(),
            "expiresAt": expires_at,
            "usedAt": None,
        }
    )
    return {
        "challengeId": challenge_id,
        "message": message,
        "expiresAt": expires_at.isoformat(),
        "network": settings.kaspa_network,
    }


def verify_auth_challenge(
    db: Database[dict[str, Any]], request: WalletVerifyRequest, settings: Settings
) -> tuple[dict[str, Any], str, Any]:
    if normalize_network(request.network) != settings.kaspa_network:
        raise bad_request("Wallet network changed during sign-in", "wrong_network")
    try:
        identity = wallet_identity(request.address, request.public_key, settings.network_type)
    except ValueError as exc:
        raise bad_request(str(exc), "invalid_wallet_identity") from exc
    challenge = db.auth_challenges.find_one(
        {
            "_id": request.challenge_id,
            "address": identity.address,
            "publicKeyHash": identity.public_key_hash,
            "usedAt": None,
            "expiresAt": {"$gt": utcnow()},
        }
    )
    if not challenge:
        raise unauthorized("The sign-in challenge is missing, expired, or already used")
    if not verify_wallet_message(challenge["message"], request.signature, identity.public_key):
        raise unauthorized("The wallet signature is invalid")
    consumed = db.auth_challenges.update_one(
        {"_id": challenge["_id"], "usedAt": None}, {"$set": {"usedAt": utcnow()}}
    )
    if consumed.modified_count != 1:
        raise conflict("This sign-in challenge was already consumed", "challenge_replayed")

    now = utcnow()
    legacy_link = db.legacy_wallet_links.find_one({"address": identity.address})
    identity_set: dict[str, Any] = {
        "publicKey": identity.public_key,
        "xOnlyPublicKey": identity.x_only_public_key,
        "publicKeyHash": identity.public_key_hash,
        "network": settings.kaspa_network,
        "lastAuthenticatedAt": now,
        "updatedAt": now,
    }
    if legacy_link:
        identity_set.update(
            {
                "legacyUserId": legacy_link["legacyUserId"],
                "legacyHistoryClaimedAt": now,
            }
        )
    user = db.users.find_one_and_update(
        {"address": identity.address},
        {
            "$set": identity_set,
            "$setOnInsert": {"createdAt": now, "displayName": None},
        },
        upsert=True,
        return_document=ReturnDocument.AFTER,
    )
    if not user:
        raise unavailable("Could not create the wallet account")
    session_token, session_hash, expires_at = new_session_token()
    session_document: dict[str, Any] = {
        "tokenHash": session_hash,
        "userId": user["_id"],
        "address": identity.address,
        "principalType": "wallet",
        "createdAt": now,
        "lastSeenAt": now,
        "expiresAt": expires_at,
        "revokedAt": None,
    }
    principal = user
    if user.get("accountId") is not None:
        account = db.accounts.find_one({"_id": user["accountId"]})
        if account:
            session_document["accountId"] = account["_id"]
            session_document["principalType"] = "account"
            principal = hydrate_account_principal(db, account)
    db.sessions.insert_one(session_document)
    return public_user(principal), session_token, expires_at


def authenticated_user(
    db: Database[dict[str, Any]], session_token: str | None
) -> dict[str, Any]:
    if not session_token:
        raise unauthorized()
    session = db.sessions.find_one(
        {
            "tokenHash": token_hash(session_token),
            "revokedAt": None,
            "expiresAt": {"$gt": utcnow()},
        }
    )
    if not session:
        raise unauthorized("The session expired; sign in again")
    if session.get("accountId") is not None:
        account = db.accounts.find_one({"_id": session["accountId"]})
        if not account:
            raise unauthorized("The OSRH account no longer exists")
        user = hydrate_account_principal(db, account)
    else:
        user = db.users.find_one({"_id": session.get("userId")})
        if not user:
            raise unauthorized("The wallet account no longer exists")
        user = {
            **user,
            "role": user.get("role", "passenger"),
            "status": user.get("status", "active"),
        }
    db.sessions.update_one(
        {"_id": session["_id"]}, {"$set": {"lastSeenAt": utcnow()}}
    )
    return user


def revoke_session(db: Database[dict[str, Any]], session_token: str | None) -> None:
    if session_token:
        db.sessions.update_one(
            {"tokenHash": token_hash(session_token), "revokedAt": None},
            {"$set": {"revokedAt": utcnow()}},
        )


def update_display_name(
    db: Database[dict[str, Any]], user: Mapping[str, Any], display_name: str
) -> dict[str, Any]:
    if user.get("accountId") is not None:
        updated_account = db.accounts.find_one_and_update(
            {"_id": user["accountId"]},
            {"$set": {"fullName": display_name, "updatedAt": utcnow()}},
            return_document=ReturnDocument.AFTER,
        )
        if not updated_account:
            raise not_found("OSRH account not found")
        db.users.update_one(
            {"accountId": user["accountId"]},
            {"$set": {"displayName": display_name, "updatedAt": utcnow()}},
        )
        return public_user(hydrate_account_principal(db, updated_account))
    updated = db.users.find_one_and_update(
        {"_id": user["_id"]},
        {"$set": {"displayName": display_name, "updatedAt": utcnow()}},
        return_document=ReturnDocument.AFTER,
    )
    if not updated:
        raise not_found("Wallet account not found")
    return public_user(updated)


def _haversine_meters(a: Mapping[str, Any], b: Mapping[str, Any]) -> int:
    radius = 6_371_000
    lat1 = math.radians(float(a["latitude"]))
    lat2 = math.radians(float(b["latitude"]))
    delta_lat = lat2 - lat1
    delta_lon = math.radians(float(b["longitude"]) - float(a["longitude"]))
    value = (
        math.sin(delta_lat / 2) ** 2
        + math.cos(lat1) * math.cos(lat2) * math.sin(delta_lon / 2) ** 2
    )
    return round(2 * radius * math.asin(math.sqrt(value)))


def create_quote(
    db: Database[dict[str, Any]], user: Mapping[str, Any], request: QuoteRequest
) -> dict[str, Any]:
    pickup = request.pickup.model_dump()
    dropoff = request.dropoff.model_dump()
    direct_distance = _haversine_meters(pickup, dropoff)
    if direct_distance < 100:
        raise bad_request("Pickup and drop-off must be at least 100 metres apart")
    if direct_distance > 150_000:
        raise bad_request("Normal rides are limited to 150 km")
    route_distance = math.ceil(direct_distance * 1.27)
    duration_seconds = math.ceil(route_distance / 8.3 + 240)
    distance_km_rounded_up = math.ceil(route_distance / 1_000)
    duration_minutes_rounded_up = math.ceil(duration_seconds / 60)
    fare = (
        BASE_FARE_SOMPI
        + distance_km_rounded_up * PER_KM_SOMPI
        + duration_minutes_rounded_up * PER_MINUTE_SOMPI
    )
    fare = math.ceil(fare / 10_000) * 10_000
    now = utcnow()
    quote = {
        "passengerId": user["_id"],
        "pickup": pickup,
        "dropoff": dropoff,
        "directDistanceMeters": direct_distance,
        "routeDistanceMeters": route_distance,
        "estimatedDurationSeconds": duration_seconds,
        "quotedFareSompi": fare,
        "pricingVersion": PRICING_VERSION,
        "serviceType": request.service_type,
        "luggageVolume": request.luggage_volume,
        "wheelchairNeeded": request.wheelchair_needed,
        "passengerNotes": request.passenger_notes,
        "useSimulation": request.use_simulation,
        "createdAt": now,
        "expiresAt": now + QUOTE_TTL,
        "usedAt": None,
    }
    result = db.quotes.insert_one(quote)
    quote["_id"] = result.inserted_id
    return {
        "id": str(quote["_id"]),
        "pickup": pickup,
        "dropoff": dropoff,
        "routeDistanceMeters": route_distance,
        "estimatedDurationSeconds": duration_seconds,
        "quotedFareSompi": str(fare),
        "quotedFareKas": f"{fare / 100_000_000:.8f}".rstrip("0").rstrip("."),
        "pricingVersion": PRICING_VERSION,
        "serviceType": quote["serviceType"],
        "luggageVolume": quote.get("luggageVolume"),
        "wheelchairNeeded": quote.get("wheelchairNeeded", False),
        "passengerNotes": quote.get("passengerNotes"),
        "useSimulation": quote.get("useSimulation", False),
        "expiresAt": quote["expiresAt"].isoformat(),
    }


def _resolver_identity(settings: Settings) -> tuple[str, str]:
    settings.assert_covenants_ready()
    public_key = PublicKey(settings.kaspa_resolver_public_key or "")
    x_only = public_key.to_x_only_public_key().to_string().lower()
    key_hash = hashlib.blake2b(bytes.fromhex(x_only), digest_size=32).hexdigest()
    return public_key.to_string().lower(), key_hash


def create_ride(
    db: Database[dict[str, Any]],
    user: Mapping[str, Any],
    request: CreateRideRequest,
    settings: Settings,
) -> dict[str, Any]:
    try:
        quote_id = ObjectId(request.quote_id)
    except Exception as exc:
        raise bad_request("quoteId is invalid") from exc
    resolver_public_key, resolver_key_hash = _resolver_identity(settings)
    ride_id = ObjectId()
    now = utcnow()

    def create(session: Any) -> dict[str, Any]:
        quote = db.quotes.find_one_and_update(
            {
                "_id": quote_id,
                "passengerId": user["_id"],
                "usedAt": None,
                "expiresAt": {"$gt": now},
            },
            {"$set": {"usedAt": now, "rideId": ride_id}},
            return_document=ReturnDocument.BEFORE,
            session=session,
        )
        if not quote:
            raise conflict("The quote expired or was already used", "quote_unavailable")
        commitment_payload = json.dumps(
            {
                "version": 1,
                "rideId": str(ride_id),
                "quoteId": str(quote_id),
                "passengerKeyHash": user["publicKeyHash"],
                "fareSompi": str(quote["quotedFareSompi"]),
                "pickup": quote["pickup"],
                "dropoff": quote["dropoff"],
                "pricingVersion": quote["pricingVersion"],
                "serviceType": quote.get("serviceType", "standard"),
                "luggageVolume": quote.get("luggageVolume"),
                "wheelchairNeeded": quote.get("wheelchairNeeded", False),
                "passengerNotes": quote.get("passengerNotes"),
            },
            sort_keys=True,
            separators=(",", ":"),
        )
        commitment = hashlib.blake2b(
            b"kaspa-ride-escrow-v1\0" + commitment_payload.encode("utf-8"), digest_size=32
        ).hexdigest()
        state = RideEscrowState(
            passenger_key_hash=user["publicKeyHash"],
            resolver_key_hash=resolver_key_hash,
            ride_commitment=commitment,
            refund_after_daa=REFUND_AFTER_DAA,
            quoted_fare_sompi=int(quote["quotedFareSompi"]),
        ).validated()
        ride = {
            "_id": ride_id,
            "quoteId": quote_id,
            "passengerId": user["_id"],
            "passengerAddress": user["address"],
            "passengerPublicKey": user["publicKey"],
            "pickup": quote["pickup"],
            "dropoff": quote["dropoff"],
            "routeDistanceMeters": quote["routeDistanceMeters"],
            "estimatedDurationSeconds": quote["estimatedDurationSeconds"],
            "quotedFareSompi": int(quote["quotedFareSompi"]),
            "pricingVersion": quote["pricingVersion"],
            "serviceType": quote.get("serviceType", "standard"),
            "luggageVolume": quote.get("luggageVolume"),
            "wheelchairNeeded": quote.get("wheelchairNeeded", False),
            "passengerNotes": quote.get("passengerNotes"),
            "useSimulation": quote.get("useSimulation", False),
            "rideCommitment": commitment,
            "status": "awaiting_funding",
            "version": 0,
            "network": settings.kaspa_network,
            "activePassengerId": user["_id"],
            "resolverPublicKey": resolver_public_key,
            "escrow": {
                "templateHash": protocol_metadata()["templateHash"],
                "state": state.to_document(),
                "confirmationStatus": "not_submitted",
            },
            "createdAt": now,
            "updatedAt": now,
        }
        db.rides.insert_one(ride, session=session)
        db.payment_events.insert_one(
            {
                "eventKey": f"ride:{ride_id}:created",
                "rideId": ride_id,
                "type": "ride_created",
                "network": settings.kaspa_network,
                "data": {"quotedFareSompi": str(quote["quotedFareSompi"])},
                "createdAt": now,
            },
            session=session,
        )
        return ride

    try:
        with db.client.start_session() as session:
            ride = session.with_transaction(create)
    except DuplicateKeyError as exc:
        raise conflict(
            "This passenger already has an active ride", "passenger_already_active"
        ) from exc
    except PyMongoError as exc:
        raise unavailable("MongoDB could not create the ride atomically") from exc
    return public_ride(ride)


def get_ride_for_user(
    db: Database[dict[str, Any]], ride_id: str, user: Mapping[str, Any]
) -> dict[str, Any]:
    try:
        object_id = ObjectId(ride_id)
    except Exception as exc:
        raise not_found("Ride not found") from exc
    ride = db.rides.find_one({"_id": object_id})
    if not ride:
        raise not_found("Ride not found")
    ride = release_expired_ride_lock(db, ride)
    if user["_id"] not in (ride.get("passengerId"), ride.get("driverId")):
        raise forbidden("This ride belongs to another wallet")
    return ride


def list_user_rides(
    db: Database[dict[str, Any]], user: Mapping[str, Any], limit: int = 20
) -> list[dict[str, Any]]:
    cursor = db.rides.find(
        {"$or": [{"passengerId": user["_id"]}, {"driverId": user["_id"]}]}
    ).sort("updatedAt", -1).limit(min(max(limit, 1), 50))
    return [public_ride(release_expired_ride_lock(db, ride)) for ride in cursor]


def list_legacy_rides(
    db: Database[dict[str, Any]], user: Mapping[str, Any], limit: int = 50
) -> list[dict[str, Any]]:
    legacy_user_id = user.get("legacyUserId")
    if legacy_user_id is None:
        return []
    cursor = db.legacy_rides.find(
        {
            "$or": [
                {"passengerUserId": legacy_user_id},
                {"driverUserIds": legacy_user_id},
            ]
        },
        {"_id": 0, "source": 0, "importedAt": 0},
    ).sort("requestedAt", -1).limit(min(max(limit, 1), 100))
    return list(cursor)


def release_expired_ride_lock(
    db: Database[dict[str, Any]], ride: Mapping[str, Any]
) -> dict[str, Any]:
    expires_at = ride.get("pendingDraftExpiresAt")
    rollback_status = ride.get("pendingDraftRollbackStatus")
    if not expires_at or not rollback_status or expires_at > utcnow():
        return dict(ride)
    unset_fields = {
        "pendingDraftId": "",
        "pendingDraftExpiresAt": "",
        "pendingDraftRollbackStatus": "",
    }
    if ride.get("status") == "acceptance_signatures_pending":
        unset_fields.update(
            {
                "driverId": "",
                "driverAddress": "",
                "driverPublicKey": "",
                "driverPublicKeyHash": "",
                "activeDriverId": "",
            }
        )
    now = utcnow()
    updated = db.rides.find_one_and_update(
        {
            "_id": ride["_id"],
            "pendingDraftId": ride.get("pendingDraftId"),
            "pendingDraftExpiresAt": {"$lte": now},
        },
        {
            "$set": {"status": rollback_status, "updatedAt": now},
            "$unset": unset_fields,
            "$inc": {"version": 1},
        },
        return_document=ReturnDocument.AFTER,
    )
    if updated:
        db.signing_drafts.update_one(
            {"_id": ride.get("pendingDraftId"), "status": "pending"},
            {"$set": {"status": "expired", "updatedAt": now}},
        )
        return updated
    return db.rides.find_one({"_id": ride["_id"]}) or dict(ride)


def _ride_state(ride: Mapping[str, Any]) -> RideEscrowState:
    try:
        return RideEscrowState(**ride["escrow"]["state"]).validated()
    except (KeyError, TypeError, ValueError) as exc:
        raise conflict("The stored escrow state is invalid", "invalid_escrow_state") from exc


async def _fresh_escrow_utxo(client: Any, ride: Mapping[str, Any]) -> dict[str, Any]:
    escrow = ride.get("escrow", {})
    if not escrow.get("address") or not escrow.get("txId"):
        raise conflict("The ride escrow has not been submitted", "escrow_not_submitted")
    utxos = await fetch_utxos(client, escrow["address"])
    match = matching_utxo(utxos, escrow["txId"], int(escrow.get("outputIndex", 0)))
    if not match:
        raise conflict(
            "The escrow output is not confirmed or has already been spent",
            "escrow_not_spendable",
        )
    expected_covenant = escrow.get("covenantId")
    if expected_covenant and match["utxoEntry"].get("covenantId") != expected_covenant:
        raise conflict("The on-chain covenant id does not match the ride", "covenant_mismatch")
    return match


def _signer(user: Mapping[str, Any], utxos: Sequence[dict[str, Any]]) -> Signer:
    return Signer(
        address=user["address"],
        public_key=user["publicKey"],
        utxo=select_auth_utxo(utxos, user["address"]),
    )


def _create_signing_draft(
    db: Database[dict[str, Any]],
    *,
    ride: Mapping[str, Any],
    expected_statuses: Sequence[str],
    expected_version: int,
    locked_status: str,
    built: BuiltTransaction,
    signing_order: Sequence[str],
    outcome: Mapping[str, Any],
    lock_fields: Mapping[str, Any] | None = None,
) -> dict[str, Any]:
    if ride["version"] != expected_version:
        raise conflict("The ride changed; refresh before signing", "stale_ride_version")
    signer_documents = built.signers_document()
    signer_addresses = {item["address"] for item in signer_documents}
    if set(signing_order) != signer_addresses or len(signing_order) != len(signer_addresses):
        raise ValueError("signing order must contain each signer exactly once")
    transaction_json = built.to_safe_json()
    signable_indices = sorted(
        index for signer in signer_documents for index in signer["inputIndices"]
    )
    now = utcnow()
    draft_id = secrets.token_urlsafe(24)
    draft = {
        "_id": draft_id,
        "rideId": ride["_id"],
        "rideVersion": expected_version + 1,
        "action": built.action,
        "network": ride["network"],
        "status": "pending",
        "revision": 0,
        "transactionJson": transaction_json,
        "fingerprint": transaction_fingerprint(transaction_json, signable_indices),
        "signableIndices": signable_indices,
        "inputScripts": input_signature_scripts(transaction_json),
        "signers": [dict(item, signedAt=None) for item in signer_documents],
        "signingOrder": list(signing_order),
        "signingPosition": 0,
        "currentSigner": signing_order[0],
        "outcome": dict(outcome),
        "createdAt": now,
        "updatedAt": now,
        "expiresAt": now + DRAFT_TTL,
    }
    ride_set = {
        "status": locked_status,
        "pendingDraftId": draft_id,
        "pendingDraftExpiresAt": draft["expiresAt"],
        "pendingDraftRollbackStatus": ride["status"],
        "updatedAt": now,
        **dict(lock_fields or {}),
    }

    def lock(session: Any) -> None:
        result = db.rides.update_one(
            {
                "_id": ride["_id"],
                "status": {"$in": list(expected_statuses)},
                "version": expected_version,
                "pendingDraftId": {"$exists": False},
            },
            {"$set": ride_set, "$inc": {"version": 1}},
            session=session,
        )
        if result.modified_count != 1:
            raise conflict("The ride changed while the transaction was being prepared")
        db.signing_drafts.insert_one(draft, session=session)

    try:
        with db.client.start_session() as session:
            session.with_transaction(lock)
    except DuplicateKeyError as exc:
        raise conflict("The selected driver already has an active ride", "driver_already_active") from exc
    except PyMongoError as exc:
        raise unavailable("MongoDB could not lock the signing workflow atomically") from exc
    return draft


async def plan_funding(
    db: Database[dict[str, Any]],
    ride_id: str,
    user: Mapping[str, Any],
    version: int,
    settings: Settings,
) -> dict[str, Any]:
    settings.assert_covenants_ready()
    ride = get_ride_for_user(db, ride_id, user)
    if ride["passengerId"] != user["_id"]:
        raise forbidden("Only the passenger can fund this ride")
    if ride["status"] != "awaiting_funding":
        raise conflict("This ride is not waiting for escrow funding")
    state = _ride_state(ride)
    async with rpc_client(settings) as client:
        utxos = await fetch_utxos(client, user["address"])
        fee_rate = await fetch_fee_rate(client)
    try:
        built = build_funding_transaction(
            state=state,
            passenger_address=user["address"],
            passenger_utxos=utxos,
            network_id=settings.kaspa_network,
            network_type=settings.network_type,
            fee_rate=fee_rate,
            priority_fee=settings.kaspa_priority_fee_sompi,
        )
    except ValueError as exc:
        raise conflict(str(exc), "funding_utxo_unavailable") from exc
    draft = _create_signing_draft(
        db,
        ride=ride,
        expected_statuses=("awaiting_funding",),
        expected_version=version,
        locked_status="funding_signature_pending",
        built=built,
        signing_order=(user["address"],),
        outcome={
            "submittedStatus": "funding_submitted",
            "confirmedStatus": "funded",
            "successorState": state.to_document(),
            "covenantAddress": built.covenant_address,
            "covenantOutputIndex": built.covenant_output_index,
        },
    )
    return public_draft(draft, user["address"])


async def plan_acceptance(
    db: Database[dict[str, Any]],
    ride_id: str,
    driver: Mapping[str, Any],
    version: int,
    settings: Settings,
) -> dict[str, Any]:
    settings.assert_covenants_ready()
    try:
        object_id = ObjectId(ride_id)
    except Exception as exc:
        raise not_found("Ride not found") from exc
    ride = db.rides.find_one({"_id": object_id})
    if not ride:
        raise not_found("Ride not found")
    if ride["status"] != "funded":
        raise conflict("The ride is not available for driver acceptance")
    if ride["passengerId"] == driver["_id"]:
        raise forbidden("A passenger cannot accept their own ride as driver")
    passenger = db.users.find_one({"_id": ride["passengerId"]})
    if not passenger:
        raise conflict("The passenger wallet account is missing")
    state = _ride_state(ride)
    async with rpc_client(settings) as client:
        escrow_utxo = await _fresh_escrow_utxo(client, ride)
        passenger_utxos = await fetch_utxos(client, passenger["address"])
        driver_utxos = await fetch_utxos(client, driver["address"])
        fee_rate = await fetch_fee_rate(client)
    try:
        built = build_accept_transaction(
            current_state=state,
            escrow_utxo=escrow_utxo,
            covenant_id=ride["escrow"]["covenantId"],
            passenger=_signer(passenger, passenger_utxos),
            driver=_signer(driver, driver_utxos),
            driver_key_hash=driver["publicKeyHash"],
            network_id=settings.kaspa_network,
            network_type=settings.network_type,
            fee_rate=fee_rate,
            compute_budget=settings.kaspa_covenant_compute_budget,
            priority_fee=settings.kaspa_priority_fee_sompi,
        )
    except ValueError as exc:
        raise conflict(str(exc), "authorization_utxo_unavailable") from exc
    next_state = state.accepted(driver["publicKeyHash"])
    draft = _create_signing_draft(
        db,
        ride=ride,
        expected_statuses=("funded",),
        expected_version=version,
        locked_status="acceptance_signatures_pending",
        built=built,
        signing_order=(driver["address"], passenger["address"]),
        outcome={
            "submittedStatus": "acceptance_submitted",
            "confirmedStatus": "accepted",
            "successorState": next_state.to_document(),
            "covenantAddress": built.covenant_address,
            "covenantOutputIndex": built.covenant_output_index,
        },
        lock_fields={
            "driverId": driver["_id"],
            "driverAddress": driver["address"],
            "driverPublicKey": driver["publicKey"],
            "driverPublicKeyHash": driver["publicKeyHash"],
            "activeDriverId": driver["_id"],
        },
    )
    return public_draft(draft, driver["address"])


def start_ride(
    db: Database[dict[str, Any]], ride_id: str, driver: Mapping[str, Any], version: int
) -> dict[str, Any]:
    ride = get_ride_for_user(db, ride_id, driver)
    if ride.get("driverId") != driver["_id"]:
        raise forbidden("Only the assigned driver can start this ride")
    updated = db.rides.find_one_and_update(
        {"_id": ride["_id"], "status": "accepted", "version": version},
        {
            "$set": {"status": "in_progress", "startedAt": utcnow(), "updatedAt": utcnow()},
            "$inc": {"version": 1},
        },
        return_document=ReturnDocument.AFTER,
    )
    if not updated:
        raise conflict("The ride changed before it could be started")
    return public_ride(updated)


def _ride_participants(
    db: Database[dict[str, Any]], ride: Mapping[str, Any]
) -> tuple[dict[str, Any], dict[str, Any]]:
    passenger = db.users.find_one({"_id": ride["passengerId"]})
    driver = db.users.find_one({"_id": ride.get("driverId")})
    if not passenger or not driver:
        raise conflict("A ride participant wallet account is missing")
    return passenger, driver


async def plan_settlement(
    db: Database[dict[str, Any]],
    ride_id: str,
    initiator: Mapping[str, Any],
    version: int,
    settings: Settings,
) -> dict[str, Any]:
    ride = get_ride_for_user(db, ride_id, initiator)
    if ride["status"] != "in_progress":
        raise conflict("Only an in-progress ride can be settled")
    passenger, driver = _ride_participants(db, ride)
    state = _ride_state(ride)
    async with rpc_client(settings) as client:
        escrow_utxo = await _fresh_escrow_utxo(client, ride)
        passenger_utxos = await fetch_utxos(client, passenger["address"])
        driver_utxos = await fetch_utxos(client, driver["address"])
        fee_rate = await fetch_fee_rate(client)
    try:
        built = build_terminal_transaction(
            action="settle",
            entry_name="settle",
            entry_arguments={
                "supplied_ride": state.ride_commitment,
                "passenger": passenger["publicKey"],
                "passenger_input_index": 1,
                "selected_driver": driver["publicKey"],
                "driver_input_index": 2,
                "payout_output_index": 0,
            },
            current_state=state,
            escrow_utxo=escrow_utxo,
            covenant_id=ride["escrow"]["covenantId"],
            signers=(
                _signer(passenger, passenger_utxos),
                _signer(driver, driver_utxos),
            ),
            beneficiary_address=driver["address"],
            network_id=settings.kaspa_network,
            network_type=settings.network_type,
            fee_rate=fee_rate,
            compute_budget=settings.kaspa_covenant_compute_budget,
            priority_fee=settings.kaspa_priority_fee_sompi,
        )
    except ValueError as exc:
        raise conflict(str(exc), "authorization_utxo_unavailable") from exc
    other = driver if initiator["_id"] == passenger["_id"] else passenger
    draft = _create_signing_draft(
        db,
        ride=ride,
        expected_statuses=("in_progress",),
        expected_version=version,
        locked_status="settlement_signatures_pending",
        built=built,
        signing_order=(initiator["address"], other["address"]),
        outcome={
            "submittedStatus": "settled",
            "confirmedStatus": "settled",
            "terminal": True,
            "beneficiaryAddress": driver["address"],
            "terminalKind": "driver_payout",
        },
    )
    return public_draft(draft, initiator["address"])


def cancel_unfunded_ride(
    db: Database[dict[str, Any]],
    ride_id: str,
    passenger: Mapping[str, Any],
    version: int,
) -> dict[str, Any]:
    ride = get_ride_for_user(db, ride_id, passenger)
    if ride["passengerId"] != passenger["_id"]:
        raise forbidden("Only the passenger can cancel an unfunded ride")
    updated = db.rides.find_one_and_update(
        {"_id": ride["_id"], "status": "awaiting_funding", "version": version},
        {
            "$set": {"status": "cancelled", "cancelledAt": utcnow(), "updatedAt": utcnow()},
            "$unset": {"activePassengerId": ""},
            "$inc": {"version": 1},
        },
        return_document=ReturnDocument.AFTER,
    )
    if not updated:
        raise conflict("The ride changed before it could be cancelled")
    return public_ride(updated)


async def plan_cancellation(
    db: Database[dict[str, Any]],
    ride_id: str,
    initiator: Mapping[str, Any],
    version: int,
    settings: Settings,
) -> dict[str, Any]:
    ride = get_ride_for_user(db, ride_id, initiator)
    if ride["status"] not in ("funded", "accepted", "in_progress"):
        raise conflict("This ride cannot enter an on-chain cancellation now")
    state = _ride_state(ride)
    passenger = db.users.find_one({"_id": ride["passengerId"]})
    if not passenger:
        raise conflict("The passenger wallet account is missing")
    async with rpc_client(settings) as client:
        escrow_utxo = await _fresh_escrow_utxo(client, ride)
        passenger_utxos = await fetch_utxos(client, passenger["address"])
        fee_rate = await fetch_fee_rate(client)
        driver_utxos: list[dict[str, Any]] = []
        driver: dict[str, Any] | None = None
        if state.phase == 1:
            _, driver = _ride_participants(db, ride)
            driver_utxos = await fetch_utxos(client, driver["address"])
    if state.phase == 0:
        if initiator["_id"] != passenger["_id"]:
            raise forbidden("Only the passenger can cancel before driver acceptance")
        entry_name = "cancel_unaccepted"
        entry_arguments = {
            "supplied_ride": state.ride_commitment,
            "passenger": passenger["publicKey"],
            "passenger_input_index": 1,
            "refund_output_index": 0,
        }
        signers = (_signer(passenger, passenger_utxos),)
        signing_order = (passenger["address"],)
        locked_status = "cancellation_signature_pending"
    else:
        if not driver:
            raise conflict("The assigned driver is missing")
        entry_name = "cancel_accepted"
        entry_arguments = {
            "supplied_ride": state.ride_commitment,
            "passenger": passenger["publicKey"],
            "passenger_input_index": 1,
            "selected_driver": driver["publicKey"],
            "driver_input_index": 2,
            "refund_output_index": 0,
        }
        signers = (
            _signer(passenger, passenger_utxos),
            _signer(driver, driver_utxos),
        )
        other = driver if initiator["_id"] == passenger["_id"] else passenger
        signing_order = (initiator["address"], other["address"])
        locked_status = "cancellation_signatures_pending"
    try:
        built = build_terminal_transaction(
            action=entry_name,
            entry_name=entry_name,
            entry_arguments=entry_arguments,
            current_state=state,
            escrow_utxo=escrow_utxo,
            covenant_id=ride["escrow"]["covenantId"],
            signers=signers,
            beneficiary_address=passenger["address"],
            network_id=settings.kaspa_network,
            network_type=settings.network_type,
            fee_rate=fee_rate,
            compute_budget=settings.kaspa_covenant_compute_budget,
            priority_fee=settings.kaspa_priority_fee_sompi,
        )
    except ValueError as exc:
        raise conflict(str(exc), "authorization_utxo_unavailable") from exc
    draft = _create_signing_draft(
        db,
        ride=ride,
        expected_statuses=(ride["status"],),
        expected_version=version,
        locked_status=locked_status,
        built=built,
        signing_order=signing_order,
        outcome={
            "submittedStatus": "refunded",
            "confirmedStatus": "refunded",
            "terminal": True,
            "beneficiaryAddress": passenger["address"],
            "terminalKind": "passenger_refund",
        },
    )
    return public_draft(draft, initiator["address"])


async def plan_timeout_refund(
    db: Database[dict[str, Any]],
    ride_id: str,
    passenger: Mapping[str, Any],
    version: int,
    settings: Settings,
) -> dict[str, Any]:
    ride = get_ride_for_user(db, ride_id, passenger)
    if ride["passengerId"] != passenger["_id"]:
        raise forbidden("Only the passenger can claim the timeout refund")
    if ride["status"] not in ("accepted", "in_progress"):
        raise conflict("The timeout refund only applies to an accepted escrow")
    state = _ride_state(ride)
    async with rpc_client(settings) as client:
        escrow_utxo = await _fresh_escrow_utxo(client, ride)
        info = await client.get_block_dag_info()
        virtual_daa = int(info.get("virtualDaaScore", info.get("virtualDAAScore", 0)))
        escrow_daa = int(escrow_utxo["utxoEntry"].get("blockDaaScore", 0))
        if virtual_daa - escrow_daa < state.refund_after_daa:
            remaining = state.refund_after_daa - (virtual_daa - escrow_daa)
            raise conflict(
                f"The covenant timeout has {remaining} DAA score remaining",
                "timeout_not_reached",
            )
        passenger_utxos = await fetch_utxos(client, passenger["address"])
        fee_rate = await fetch_fee_rate(client)
    signer = _signer(passenger, passenger_utxos)
    try:
        built = build_terminal_transaction(
            action="timeout_refund",
            entry_name="timeout_refund",
            entry_arguments={
                "supplied_ride": state.ride_commitment,
                "passenger": passenger["publicKey"],
                "passenger_input_index": 1,
                "refund_output_index": 0,
            },
            current_state=state,
            escrow_utxo=escrow_utxo,
            covenant_id=ride["escrow"]["covenantId"],
            signers=(signer,),
            beneficiary_address=passenger["address"],
            network_id=settings.kaspa_network,
            network_type=settings.network_type,
            fee_rate=fee_rate,
            compute_budget=settings.kaspa_covenant_compute_budget,
            priority_fee=settings.kaspa_priority_fee_sompi,
        )
    except ValueError as exc:
        raise conflict(str(exc), "authorization_utxo_unavailable") from exc
    draft = _create_signing_draft(
        db,
        ride=ride,
        expected_statuses=(ride["status"],),
        expected_version=version,
        locked_status="timeout_refund_signature_pending",
        built=built,
        signing_order=(passenger["address"],),
        outcome={
            "submittedStatus": "refunded",
            "confirmedStatus": "refunded",
            "terminal": True,
            "beneficiaryAddress": passenger["address"],
            "terminalKind": "timeout_refund",
        },
    )
    return public_draft(draft, passenger["address"])


def pending_signing_drafts(
    db: Database[dict[str, Any]], user: Mapping[str, Any]
) -> list[dict[str, Any]]:
    drafts = db.signing_drafts.find(
        {
            "currentSigner": user["address"],
            "status": "pending",
            "expiresAt": {"$gt": utcnow()},
        }
    ).sort("createdAt", -1).limit(20)
    return [public_draft(draft, user["address"]) for draft in drafts]


async def submit_draft_signature(
    db: Database[dict[str, Any]],
    draft_id: str,
    user: Mapping[str, Any],
    signed_transaction_json: str,
    settings: Settings,
) -> dict[str, Any]:
    draft = db.signing_drafts.find_one(
        {
            "_id": draft_id,
            "status": "pending",
            "currentSigner": user["address"],
            "expiresAt": {"$gt": utcnow()},
        }
    )
    if not draft:
        raise conflict("This signing request is unavailable or no longer current")
    try:
        fingerprint = transaction_fingerprint(
            signed_transaction_json, draft["signableIndices"]
        )
        new_scripts = input_signature_scripts(signed_transaction_json)
    except ValueError as exc:
        raise bad_request(str(exc), "invalid_transaction") from exc
    if fingerprint != draft["fingerprint"]:
        raise bad_request(
            "The wallet changed a protected transaction field",
            "transaction_tampered",
        )
    old_scripts = draft["inputScripts"]
    if len(new_scripts) != len(old_scripts):
        raise bad_request("The wallet changed the transaction input count", "transaction_tampered")
    signer = next(
        item for item in draft["signers"] if item["address"] == user["address"]
    )
    current_indices = set(signer["inputIndices"])
    for index in draft["signableIndices"]:
        if index in current_indices:
            if not new_scripts[index] or new_scripts[index] == old_scripts[index]:
                raise bad_request(
                    f"Wallet did not sign required input {index}", "missing_signature"
                )
        elif new_scripts[index] != old_scripts[index]:
            raise bad_request(
                f"Wallet modified unrequested input {index}",
                "wallet_fund_safety_violation",
            )

    now = utcnow()
    signers = [dict(item) for item in draft["signers"]]
    for item in signers:
        if item["address"] == user["address"]:
            item["signedAt"] = now
    next_position = int(draft["signingPosition"]) + 1
    common_set = {
        "transactionJson": signed_transaction_json,
        "inputScripts": new_scripts,
        "signers": signers,
        "updatedAt": now,
    }
    if next_position < len(draft["signingOrder"]):
        next_signer = draft["signingOrder"][next_position]
        result = db.signing_drafts.update_one(
            {
                "_id": draft_id,
                "status": "pending",
                "currentSigner": user["address"],
                "revision": draft["revision"],
            },
            {
                "$set": {
                    **common_set,
                    "signingPosition": next_position,
                    "currentSigner": next_signer,
                },
                "$inc": {"revision": 1},
            },
        )
        if result.modified_count != 1:
            raise conflict("Another request advanced this signing workflow")
        return {
            "draftId": draft_id,
            "status": "awaiting_next_signer",
            "nextSigner": next_signer,
            "signingPosition": next_position,
            "signerCount": len(draft["signingOrder"]),
        }

    reserved = db.signing_drafts.update_one(
        {
            "_id": draft_id,
            "status": "pending",
            "currentSigner": user["address"],
            "revision": draft["revision"],
        },
        {
            "$set": {
                **common_set,
                "status": "broadcasting",
                "currentSigner": None,
                "lastSigner": user["address"],
                "signingPosition": next_position,
            },
            "$inc": {"revision": 1},
        },
    )
    if reserved.modified_count != 1:
        raise conflict("Another request advanced this signing workflow")
    return await _broadcast_draft(db, draft_id, signed_transaction_json, settings)


async def retry_draft_broadcast(
    db: Database[dict[str, Any]],
    draft_id: str,
    user: Mapping[str, Any],
    settings: Settings,
) -> dict[str, Any]:
    draft = db.signing_drafts.find_one({"_id": draft_id})
    if not draft or draft.get("lastSigner") != user["address"]:
        raise not_found("Broadcast recovery request not found")
    if draft["status"] == "recording" and draft.get("broadcastTxId"):
        return _record_broadcast_outcome(
            db, draft_id, draft["transactionJson"], draft["broadcastTxId"]
        )
    if draft["status"] != "broadcast_failed":
        raise conflict("This draft is not waiting for a broadcast retry")
    reserved = db.signing_drafts.update_one(
        {"_id": draft_id, "status": "broadcast_failed", "revision": draft["revision"]},
        {
            "$set": {"status": "broadcasting", "updatedAt": utcnow()},
            "$unset": {"broadcastError": ""},
            "$inc": {"revision": 1},
        },
    )
    if reserved.modified_count != 1:
        raise conflict("Another request is retrying this broadcast")
    return await _broadcast_draft(db, draft_id, draft["transactionJson"], settings)


async def _broadcast_draft(
    db: Database[dict[str, Any]], draft_id: str, transaction_json: str, settings: Settings
) -> dict[str, Any]:
    try:
        async with rpc_client(settings) as client:
            transaction_id = await submit_transaction(client, transaction_json)
    except Exception as exc:
        db.signing_drafts.update_one(
            {"_id": draft_id, "status": "broadcasting"},
            {
                "$set": {
                    "status": "broadcast_failed",
                    "broadcastError": type(exc).__name__,
                    "updatedAt": utcnow(),
                },
                "$inc": {"revision": 1},
            },
        )
        raise unavailable(
            "The signed transaction was preserved, but the Kaspa node did not accept the broadcast"
        ) from exc
    db.signing_drafts.update_one(
        {"_id": draft_id, "status": "broadcasting"},
        {
            "$set": {
                "status": "recording",
                "broadcastTxId": transaction_id,
                "updatedAt": utcnow(),
            },
            "$inc": {"revision": 1},
        },
    )
    return _record_broadcast_outcome(db, draft_id, transaction_json, transaction_id)


def _string_value(value: Any) -> str:
    to_string = getattr(value, "to_string", None)
    return str(to_string()) if callable(to_string) else str(value)


def _record_broadcast_outcome(
    db: Database[dict[str, Any]], draft_id: str, transaction_json: str, transaction_id: str
) -> dict[str, Any]:
    draft = db.signing_drafts.find_one(
        {"_id": draft_id, "status": {"$in": ["recording", "broadcasting"]}}
    )
    if not draft:
        completed = db.signing_drafts.find_one({"_id": draft_id, "status": "submitted"})
        if completed:
            ride = db.rides.find_one({"_id": completed["rideId"]})
            return {
                "draftId": draft_id,
                "status": "submitted",
                "transactionId": completed["broadcastTxId"],
                "ride": public_ride(ride) if ride else None,
            }
        raise conflict("The broadcast result cannot be attached to this draft")
    transaction = transaction_from_safe_json(transaction_json)
    outcome = draft["outcome"]
    now = utcnow()
    ride_set: dict[str, Any] = {
        "status": outcome["submittedStatus"],
        "updatedAt": now,
        "lastChainTransactionId": transaction_id,
    }
    ride_unset: dict[str, str] = {
        "pendingDraftId": "",
        "pendingDraftExpiresAt": "",
        "pendingDraftRollbackStatus": "",
    }
    if outcome.get("terminal"):
        output = transaction.outputs[0].to_dict()
        ride_set.update(
            {
                "escrow.confirmationStatus": "spent",
                "escrow.spentByTxId": transaction_id,
                "payment": {
                    "transactionId": transaction_id,
                    "outputIndex": 0,
                    "beneficiaryAddress": outcome["beneficiaryAddress"],
                    "amountSompi": int(output["value"]),
                    "kind": outcome["terminalKind"],
                    "submittedAt": now,
                },
                (
                    "completedAt"
                    if outcome["submittedStatus"] == "settled"
                    else "refundedAt"
                ): now,
            }
        )
        ride_unset.update({"activePassengerId": "", "activeDriverId": ""})
    else:
        output_index = int(outcome["covenantOutputIndex"])
        output = transaction.outputs[output_index].to_dict()
        covenant = output["covenant"]
        covenant_id = _string_value(covenant["covenantId"])
        script = output["scriptPublicKey"]
        ride_set.update(
            {
                "escrow.state": outcome["successorState"],
                "escrow.address": outcome["covenantAddress"],
                "escrow.txId": transaction_id,
                "escrow.outputIndex": output_index,
                "escrow.outpointKey": f"{transaction_id}:{output_index}",
                "escrow.covenantId": covenant_id,
                "escrow.scriptPublicKey": script,
                "escrow.valueSompi": int(output["value"]),
                "escrow.confirmationStatus": "submitted",
                "escrow.confirmedStatus": outcome["confirmedStatus"],
                "escrow.submittedAt": now,
            }
        )

    event_key = f'{draft["network"]}:{transaction_id}:{draft["action"]}'

    def record(session: Any) -> dict[str, Any]:
        ride = db.rides.find_one_and_update(
            {"_id": draft["rideId"], "pendingDraftId": draft_id},
            {"$set": ride_set, "$unset": ride_unset, "$inc": {"version": 1}},
            return_document=ReturnDocument.AFTER,
            session=session,
        )
        if not ride:
            existing = db.rides.find_one(
                {"_id": draft["rideId"], "lastChainTransactionId": transaction_id},
                session=session,
            )
            if not existing:
                raise conflict("The ride changed before the broadcast result was recorded")
            ride = existing
        db.payment_events.update_one(
            {"eventKey": event_key},
            {
                "$setOnInsert": {
                    "eventKey": event_key,
                    "rideId": draft["rideId"],
                    "type": f'{draft["action"]}_submitted',
                    "network": draft["network"],
                    "transactionId": transaction_id,
                    "data": {"draftId": draft_id},
                    "createdAt": now,
                }
            },
            upsert=True,
            session=session,
        )
        db.signing_drafts.update_one(
            {"_id": draft_id, "status": {"$in": ["recording", "broadcasting"]}},
            {
                "$set": {
                    "status": "submitted",
                    "broadcastTxId": transaction_id,
                    "submittedAt": now,
                    "updatedAt": now,
                },
                "$inc": {"revision": 1},
            },
            session=session,
        )
        return ride

    try:
        with db.client.start_session() as session:
            ride = session.with_transaction(record)
    except PyMongoError as exc:
        raise unavailable(
            "The chain accepted the transaction, but its database outcome still needs recovery"
        ) from exc
    return {
        "draftId": draft_id,
        "status": "submitted",
        "transactionId": transaction_id,
        "ride": public_ride(ride),
    }


async def reconcile_ride(
    db: Database[dict[str, Any]], ride: Mapping[str, Any], settings: Settings
) -> dict[str, Any]:
    if ride.get("status") not in ("funding_submitted", "acceptance_submitted"):
        return dict(ride)
    escrow = ride.get("escrow", {})
    async with rpc_client(settings) as client:
        utxos = await fetch_utxos(client, escrow["address"])
    confirmed = matching_utxo(
        utxos, escrow["txId"], int(escrow.get("outputIndex", 0))
    )
    if not confirmed:
        return dict(ride)
    entry = confirmed["utxoEntry"]
    if (
        int(entry["amount"]) != int(escrow["valueSompi"])
        or entry.get("covenantId") != escrow.get("covenantId")
        or entry["scriptPublicKey"] != escrow["scriptPublicKey"]
    ):
        raise conflict("The confirmed escrow output does not match the signed plan")
    now = utcnow()
    updated = db.rides.find_one_and_update(
        {
            "_id": ride["_id"],
            "status": ride["status"],
            "escrow.outpointKey": escrow["outpointKey"],
        },
        {
            "$set": {
                "status": escrow["confirmedStatus"],
                "escrow.confirmationStatus": "confirmed",
                "escrow.confirmedAt": now,
                "escrow.blockDaaScore": int(entry.get("blockDaaScore", 0)),
                "updatedAt": now,
            },
            "$inc": {"version": 1},
        },
        return_document=ReturnDocument.AFTER,
    )
    return updated or db.rides.find_one({"_id": ride["_id"]}) or dict(ride)
