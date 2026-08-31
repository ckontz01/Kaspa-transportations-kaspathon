from __future__ import annotations

from datetime import datetime
from typing import Any, Mapping

from bson import ObjectId


PRIVATE_RIDE_FIELDS = {
    "activePassengerId",
    "activeDriverId",
    "pendingDraftId",
}


def json_value(value: Any) -> Any:
    if isinstance(value, ObjectId):
        return str(value)
    if isinstance(value, datetime):
        return value.isoformat()
    if isinstance(value, Mapping):
        return {
            ("id" if key == "_id" else key): json_value(item)
            for key, item in value.items()
        }
    if isinstance(value, (list, tuple)):
        return [json_value(item) for item in value]
    return value


def public_user(user: Mapping[str, Any]) -> dict[str, Any]:
    account_id = user.get("accountId")
    return json_value(
        {
            "_id": account_id or user["_id"],
            "paymentIdentityId": user["_id"] if user.get("address") else None,
            "address": user.get("address"),
            "displayName": user.get("displayName") or user.get("fullName"),
            "fullName": user.get("fullName") or user.get("displayName"),
            "email": user.get("email"),
            "phone": user.get("phone"),
            "role": user.get("role", "passenger"),
            "status": user.get("status", "active"),
            "verificationStatus": user.get("verificationStatus"),
            "publicKey": user.get("publicKey"),
            "publicKeyHash": user.get("publicKeyHash"),
            "network": user.get("network"),
            "addressProfile": user.get("addressProfile", {}),
            "preferences": user.get("preferences", {}),
            "driverProfile": user.get("driverProfile"),
            "createdAt": user.get("createdAt"),
            "updatedAt": user.get("updatedAt"),
        }
    )


def public_ride(ride: Mapping[str, Any]) -> dict[str, Any]:
    visible = {key: value for key, value in ride.items() if key not in PRIVATE_RIDE_FIELDS}
    return json_value(visible)


def public_draft(draft: Mapping[str, Any], current_address: str) -> dict[str, Any]:
    signers = draft["signers"]
    signer = next(item for item in signers if item["address"] == current_address)
    return json_value(
        {
            "_id": draft["_id"],
            "rideId": draft["rideId"],
            "action": draft["action"],
            "status": draft["status"],
            "currentSigner": draft.get("currentSigner"),
            "signingPosition": draft.get("signingPosition", 0),
            "signerCount": len(draft["signingOrder"]),
            "transactionJson": draft["transactionJson"],
            "signInputs": [
                {"index": index, "sighashType": signer.get("sighashType", 1)}
                for index in signer["inputIndices"]
            ],
            "expiresAt": draft["expiresAt"],
        }
    )
