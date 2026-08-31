from __future__ import annotations

import hashlib
import hmac
from datetime import timedelta
from typing import Any, Mapping

from argon2 import PasswordHasher
from argon2.exceptions import InvalidHashError, VerificationError, VerifyMismatchError
from pymongo import ReturnDocument
from pymongo.database import Database
from pymongo.errors import DuplicateKeyError, PyMongoError

from backend.db import utcnow
from backend.errors import (
    bad_request,
    conflict,
    forbidden,
    not_found,
    unauthorized,
    unavailable,
)
from backend.schemas import (
    AccountLogin,
    DriverRegistration,
    PassengerRegistration,
    PasswordChange,
    PreferencesUpdate,
    ProfileUpdate,
    WalletVerifyRequest,
)
from backend.security import (
    new_session_token,
    verify_wallet_message,
    wallet_identity,
)
from backend.settings import Settings

PASSWORD_HASHER = PasswordHasher(
    time_cost=3,
    memory_cost=65_536,
    parallelism=2,
    hash_len=32,
    salt_len=16,
)
LOGIN_WINDOW = timedelta(minutes=15)
MAX_LOGIN_ATTEMPTS = 10


def normalize_email(value: str) -> str:
    return value.strip().casefold()


def _clean_optional(value: str | None) -> str | None:
    if value is None:
        return None
    clean = " ".join(value.split())
    return clean or None


def _new_account_session(
    db: Database[dict[str, Any]], account_id: Any
) -> tuple[str, Any]:
    token, session_hash, expires_at = new_session_token()
    now = utcnow()
    db.sessions.insert_one(
        {
            "tokenHash": session_hash,
            "accountId": account_id,
            "principalType": "account",
            "createdAt": now,
            "lastSeenAt": now,
            "expiresAt": expires_at,
            "revokedAt": None,
        }
    )
    return token, expires_at


def hydrate_account_principal(
    db: Database[dict[str, Any]], account: Mapping[str, Any]
) -> dict[str, Any]:
    account_id = account["_id"]
    wallet = None
    wallet_id = account.get("walletIdentityId")
    if wallet_id is not None:
        wallet = db.users.find_one({"_id": wallet_id, "accountId": account_id})

    principal: dict[str, Any] = {
        "_id": wallet["_id"] if wallet else account_id,
        "accountId": account_id,
        "email": account.get("email"),
        "phone": account.get("phone"),
        "fullName": account.get("fullName"),
        "displayName": account.get("fullName"),
        "role": account.get("role", "passenger"),
        "status": account.get("status", "active"),
        "verificationStatus": account.get("verificationStatus"),
        "dateOfBirth": account.get("dateOfBirth"),
        "addressProfile": account.get("addressProfile", {}),
        "preferences": account.get("preferences", {}),
        "driverProfile": account.get("driverProfile"),
        "createdAt": account.get("createdAt"),
        "updatedAt": account.get("updatedAt"),
        "address": None,
        "publicKey": None,
        "xOnlyPublicKey": None,
        "publicKeyHash": None,
        "network": None,
    }
    if wallet:
        for key in (
            "address",
            "publicKey",
            "xOnlyPublicKey",
            "publicKeyHash",
            "network",
            "legacyUserId",
        ):
            principal[key] = wallet.get(key)
        principal["walletIdentityId"] = wallet["_id"]
    return principal


def _register_account(
    db: Database[dict[str, Any]],
    payload: PassengerRegistration | DriverRegistration,
    role: str,
) -> tuple[dict[str, Any], str, Any]:
    now = utcnow()
    email = normalize_email(str(payload.email))
    base: dict[str, Any] = {
        "email": email,
        "emailNormalized": email,
        "passwordHash": PASSWORD_HASHER.hash(payload.password),
        "fullName": payload.full_name,
        "phone": _clean_optional(payload.phone),
        "role": role,
        "status": "active",
        "createdAt": now,
        "updatedAt": now,
        "lastAuthenticatedAt": now,
    }
    if isinstance(payload, PassengerRegistration):
        base.update(
            {
                "addressProfile": {
                    "streetAddress": _clean_optional(payload.street_address),
                    "city": _clean_optional(payload.city),
                    "postalCode": _clean_optional(payload.postal_code),
                    "country": _clean_optional(payload.country) or "Cyprus",
                },
                "preferences": payload.preferences.model_dump(by_alias=True),
                "loyaltyLevel": "standard",
            }
        )
    else:
        settings = Settings()
        base.update(
            {
                "status": "pending",
                "verificationStatus": "pending",
                "dateOfBirth": payload.date_of_birth.isoformat(),
                "driverProfile": {
                    "driverType": "partner",
                    "isAvailable": False,
                    "useGps": False,
                    "idCardHash": hmac.new(
                        settings.session_secret.encode("utf-8"),
                        payload.id_card_number.encode("utf-8"),
                        hashlib.sha256,
                    ).hexdigest(),
                    "idCardLast4": payload.id_card_number[-4:],
                    "licenseHash": hmac.new(
                        settings.session_secret.encode("utf-8"),
                        payload.license_number.encode("utf-8"),
                        hashlib.sha256,
                    ).hexdigest(),
                    "licenseLast4": payload.license_number[-4:],
                },
                "preferences": {
                    "locationTracking": True,
                    "notifications": True,
                    "emailUpdates": True,
                    "dataSharing": False,
                },
            }
        )
    try:
        result = db.accounts.insert_one(base)
    except DuplicateKeyError as exc:
        raise conflict(
            "An account with this email already exists", "email_registered"
        ) from exc
    base["_id"] = result.inserted_id
    token, expires_at = _new_account_session(db, result.inserted_id)
    return hydrate_account_principal(db, base), token, expires_at


def register_passenger(
    db: Database[dict[str, Any]], payload: PassengerRegistration
) -> tuple[dict[str, Any], str, Any]:
    return _register_account(db, payload, "passenger")


def register_driver(
    db: Database[dict[str, Any]], payload: DriverRegistration
) -> tuple[dict[str, Any], str, Any]:
    return _register_account(db, payload, "driver")


def _login_attempt_key(email: str, ip: str | None) -> str:
    bucket = int(utcnow().timestamp()) // int(LOGIN_WINDOW.total_seconds())
    raw = f"{email}\0{ip or 'unknown'}\0{bucket}".encode("utf-8")
    return hashlib.sha256(raw).hexdigest()


def authenticate_account(
    db: Database[dict[str, Any]], payload: AccountLogin, ip: str | None
) -> tuple[dict[str, Any], str, Any]:
    email = normalize_email(str(payload.email))
    key = _login_attempt_key(email, ip)
    now = utcnow()
    attempt = db.login_attempts.find_one_and_update(
        {"_id": key},
        {
            "$inc": {"count": 1},
            "$setOnInsert": {
                "createdAt": now,
                "expiresAt": now + LOGIN_WINDOW,
            },
        },
        upsert=True,
        return_document=ReturnDocument.AFTER,
    )
    if attempt and int(attempt.get("count", 0)) > MAX_LOGIN_ATTEMPTS:
        raise conflict(
            "Too many sign-in attempts. Wait fifteen minutes and try again.",
            "rate_limited",
        )

    account = db.accounts.find_one({"emailNormalized": email})
    valid = False
    if account:
        try:
            valid = PASSWORD_HASHER.verify(account["passwordHash"], payload.password)
        except (InvalidHashError, VerificationError, VerifyMismatchError, KeyError):
            valid = False
    if not account or not valid:
        raise unauthorized("The email or password is incorrect")
    if account.get("status") in {"blocked", "deleted_gdpr"}:
        raise forbidden("This account is not available")

    if PASSWORD_HASHER.check_needs_rehash(account["passwordHash"]):
        db.accounts.update_one(
            {"_id": account["_id"]},
            {
                "$set": {
                    "passwordHash": PASSWORD_HASHER.hash(payload.password),
                    "updatedAt": now,
                }
            },
        )
    db.login_attempts.delete_one({"_id": key})
    db.accounts.update_one(
        {"_id": account["_id"]},
        {"$set": {"lastAuthenticatedAt": now, "updatedAt": now}},
    )
    account["lastAuthenticatedAt"] = now
    account["updatedAt"] = now
    token, expires_at = _new_account_session(db, account["_id"])
    return hydrate_account_principal(db, account), token, expires_at


def _consume_wallet_challenge(
    db: Database[dict[str, Any]], payload: WalletVerifyRequest, settings: Settings
) -> Any:
    try:
        identity = wallet_identity(
            payload.address, payload.public_key, settings.network_type
        )
    except ValueError as exc:
        raise bad_request(str(exc), "invalid_wallet_identity") from exc
    challenge = db.auth_challenges.find_one(
        {
            "_id": payload.challenge_id,
            "address": identity.address,
            "publicKeyHash": identity.public_key_hash,
            "network": settings.kaspa_network,
            "usedAt": None,
            "expiresAt": {"$gt": utcnow()},
        }
    )
    if not challenge:
        raise unauthorized("The wallet challenge is missing, expired, or already used")
    if not verify_wallet_message(
        challenge["message"], payload.signature, identity.public_key
    ):
        raise unauthorized("The wallet signature is invalid")
    consumed = db.auth_challenges.update_one(
        {"_id": challenge["_id"], "usedAt": None}, {"$set": {"usedAt": utcnow()}}
    )
    if consumed.modified_count != 1:
        raise conflict(
            "This wallet challenge was already consumed", "challenge_replayed"
        )
    return identity


def link_wallet(
    db: Database[dict[str, Any]],
    account_user: Mapping[str, Any],
    payload: WalletVerifyRequest,
    settings: Settings,
) -> dict[str, Any]:
    account_id = account_user.get("accountId")
    if account_id is None:
        raise bad_request(
            "Create or sign in to an OSRH account before linking a wallet"
        )
    identity = _consume_wallet_challenge(db, payload, settings)
    now = utcnow()

    def link(session: Any) -> dict[str, Any]:
        existing = db.users.find_one({"address": identity.address}, session=session)
        if existing and existing.get("accountId") not in (None, account_id):
            raise conflict(
                "This wallet is linked to another account", "wallet_already_linked"
            )
        account = db.accounts.find_one({"_id": account_id}, session=session)
        if not account:
            raise not_found("Account not found")
        current_wallet_id = account.get("walletIdentityId")
        if current_wallet_id is not None and (
            not existing or current_wallet_id != existing.get("_id")
        ):
            raise conflict(
                "This account already has a different wallet", "account_wallet_exists"
            )

        legacy_link = db.legacy_wallet_links.find_one(
            {"address": identity.address}, session=session
        )
        identity_fields: dict[str, Any] = {
            "publicKey": identity.public_key,
            "xOnlyPublicKey": identity.x_only_public_key,
            "publicKeyHash": identity.public_key_hash,
            "network": settings.kaspa_network,
            "accountId": account_id,
            "lastAuthenticatedAt": now,
            "updatedAt": now,
        }
        if legacy_link:
            identity_fields.update(
                {
                    "legacyUserId": legacy_link["legacyUserId"],
                    "legacyHistoryClaimedAt": now,
                }
            )
        wallet = db.users.find_one_and_update(
            {"address": identity.address},
            {
                "$set": identity_fields,
                "$setOnInsert": {
                    "createdAt": now,
                    "displayName": account.get("fullName"),
                },
            },
            upsert=True,
            return_document=ReturnDocument.AFTER,
            session=session,
        )
        if not wallet:
            raise unavailable("Could not link the wallet")
        updated = db.accounts.find_one_and_update(
            {
                "_id": account_id,
                "$or": [
                    {"walletIdentityId": {"$exists": False}},
                    {"walletIdentityId": wallet["_id"]},
                ],
            },
            {"$set": {"walletIdentityId": wallet["_id"], "updatedAt": now}},
            return_document=ReturnDocument.AFTER,
            session=session,
        )
        if not updated:
            raise conflict(
                "This account already has a different wallet", "account_wallet_exists"
            )
        return updated

    try:
        with db.client.start_session() as session:
            account = session.with_transaction(link)
    except (DuplicateKeyError, PyMongoError) as exc:
        raise conflict(
            "The wallet could not be linked atomically", "wallet_link_conflict"
        ) from exc
    return hydrate_account_principal(db, account)


def update_profile(
    db: Database[dict[str, Any]], user: Mapping[str, Any], payload: ProfileUpdate
) -> dict[str, Any]:
    account_id = user.get("accountId")
    if account_id is None:
        raise bad_request("This wallet-only session does not have an OSRH profile")
    now = utcnow()
    updated = db.accounts.find_one_and_update(
        {"_id": account_id},
        {
            "$set": {
                "fullName": payload.full_name,
                "phone": _clean_optional(payload.phone),
                "addressProfile": {
                    "streetAddress": _clean_optional(payload.street_address),
                    "city": _clean_optional(payload.city),
                    "postalCode": _clean_optional(payload.postal_code),
                    "country": _clean_optional(payload.country) or "Cyprus",
                },
                "updatedAt": now,
            }
        },
        return_document=ReturnDocument.AFTER,
    )
    if not updated:
        raise not_found("Account not found")
    if updated.get("walletIdentityId"):
        db.users.update_one(
            {"_id": updated["walletIdentityId"], "accountId": account_id},
            {"$set": {"displayName": payload.full_name, "updatedAt": now}},
        )
    return hydrate_account_principal(db, updated)


def update_preferences(
    db: Database[dict[str, Any]], user: Mapping[str, Any], payload: PreferencesUpdate
) -> dict[str, Any]:
    account_id = user.get("accountId")
    if account_id is None:
        raise bad_request("This wallet-only session does not have OSRH preferences")
    updated = db.accounts.find_one_and_update(
        {"_id": account_id},
        {
            "$set": {
                "preferences": payload.model_dump(by_alias=True),
                "updatedAt": utcnow(),
            }
        },
        return_document=ReturnDocument.AFTER,
    )
    if not updated:
        raise not_found("Account not found")
    return hydrate_account_principal(db, updated)


def change_password(
    db: Database[dict[str, Any]], user: Mapping[str, Any], payload: PasswordChange
) -> None:
    account_id = user.get("accountId")
    if account_id is None:
        raise bad_request("This wallet-only session does not have a password")
    account = db.accounts.find_one({"_id": account_id})
    if not account:
        raise not_found("Account not found")
    try:
        valid = PASSWORD_HASHER.verify(
            account["passwordHash"], payload.current_password
        )
    except (InvalidHashError, VerificationError, VerifyMismatchError, KeyError):
        valid = False
    if not valid:
        raise unauthorized("The current password is incorrect")
    now = utcnow()
    db.accounts.update_one(
        {"_id": account_id},
        {
            "$set": {
                "passwordHash": PASSWORD_HASHER.hash(payload.new_password),
                "passwordChangedAt": now,
                "updatedAt": now,
            }
        },
    )
    db.sessions.update_many(
        {"accountId": account_id, "revokedAt": None}, {"$set": {"revokedAt": now}}
    )


def require_role(user: Mapping[str, Any], *roles: str) -> None:
    if user.get("role", "passenger") not in roles:
        raise forbidden("This account does not have permission for that role")
    if user.get("status") in {"blocked", "deleted_gdpr", "rejected"}:
        raise forbidden("This account is not active")


def require_approved_driver(user: Mapping[str, Any]) -> None:
    require_role(user, "driver")
    if user.get("verificationStatus") != "approved":
        raise forbidden("The driver account is waiting for operator approval")


def require_wallet(user: Mapping[str, Any]) -> None:
    if not all(user.get(key) for key in ("address", "publicKey", "publicKeyHash")):
        raise forbidden(
            "Link and verify a KIP-12 Kaspa wallet before using ride payments"
        )
