from __future__ import annotations

import base64
import binascii
import hashlib
from collections import OrderedDict
from typing import Any, Mapping

from bson import ObjectId
from bson.binary import Binary
from pymongo import ReturnDocument
from pymongo.database import Database
from pymongo.errors import DuplicateKeyError, PyMongoError

from backend.accounts import require_approved_driver, require_role
from backend.db import utcnow
from backend.errors import bad_request, conflict, forbidden, not_found, unavailable
from backend.presentation import json_value, public_ride
from backend.schemas import (
    DriverAvailability,
    DriverDocumentUpload,
    DriverDocumentVerification,
    DriverVerificationUpdate,
    GdprRequestCreate,
    GdprRequestReview,
    MessageCreate,
    RideRatingCreate,
    SafetyInspectionCreate,
    VehicleCreate,
)

MAX_DRIVER_DOCUMENT_BYTES = 5 * 1024 * 1024


def _object_id(value: str, label: str) -> ObjectId:
    try:
        return ObjectId(value)
    except Exception as exc:
        raise bad_request(f"{label} is invalid") from exc


def _account_id(user: Mapping[str, Any]) -> Any:
    account_id = user.get("accountId")
    if account_id is None:
        raise bad_request("Create or sign in to an OSRH account to use this feature")
    return account_id


def _wallet_id(user: Mapping[str, Any]) -> Any | None:
    return user.get("_id") if user.get("address") else None


def record_system_log(
    db: Database[dict[str, Any]],
    user: Mapping[str, Any],
    action_type: str,
    description: str,
    *,
    status: str = "completed",
    severity: str = "info",
    reference_type: str | None = None,
    reference_id: Any | None = None,
    metadata: Mapping[str, Any] | None = None,
    session: Any | None = None,
) -> dict[str, Any]:
    now = utcnow()
    document = {
        "createdAt": now,
        "actorId": _account_id(user),
        "actorName": user.get("fullName") or user.get("displayName") or "OSRH account",
        "actorRole": user.get("role"),
        "actionType": action_type,
        "actionDescription": description,
        "status": status,
        "severity": severity,
        "referenceType": reference_type,
        "referenceId": str(reference_id) if reference_id is not None else None,
        "metadata": dict(metadata or {}),
    }
    options = {"session": session} if session is not None else {}
    result = db.system_logs.insert_one(document, **options)
    document["_id"] = result.inserted_id
    return document


def dashboard_summary(
    db: Database[dict[str, Any]], user: Mapping[str, Any]
) -> dict[str, Any]:
    wallet_id = _wallet_id(user)
    if wallet_id is None:
        rides: list[dict[str, Any]] = []
    else:
        rides = list(
            db.rides.find(
                {"$or": [{"passengerId": wallet_id}, {"driverId": wallet_id}]}
            )
            .sort("updatedAt", -1)
            .limit(100)
        )
    terminal = {"settled", "refunded", "cancelled"}
    active = next(
        (public_ride(item) for item in rides if item.get("status") not in terminal),
        None,
    )
    completed = [item for item in rides if item.get("status") == "settled"]
    total_sompi = sum(int(item.get("quotedFareSompi", 0)) for item in completed)
    return {
        "activeRide": active,
        "recentRides": [public_ride(item) for item in rides[:5]],
        "stats": {
            "totalRides": len(rides),
            "completedRides": len(completed),
            "cancelledRides": sum(
                1 for item in rides if item.get("status") == "cancelled"
            ),
            "totalKas": f"{total_sompi / 100_000_000:.8f}".rstrip("0").rstrip(".")
            or "0",
        },
        "unreadMessages": db.messages.count_documents(
            {"recipientId": _account_id(user), "readAt": None}, limit=100
        ),
    }


def list_payment_history(
    db: Database[dict[str, Any]], user: Mapping[str, Any], limit: int = 100
) -> list[dict[str, Any]]:
    wallet_id = _wallet_id(user)
    if wallet_id is None:
        return []
    cursor = (
        db.rides.find(
            {
                "$or": [{"passengerId": wallet_id}, {"driverId": wallet_id}],
                "status": {"$in": ["settled", "refunded"]},
            }
        )
        .sort("updatedAt", -1)
        .limit(min(max(limit, 1), 100))
    )
    result = []
    for ride in cursor:
        payment = ride.get("payment", {})
        result.append(
            json_value(
                {
                    "_id": ride["_id"],
                    "rideId": ride["_id"],
                    "status": ride.get("status"),
                    "kind": payment.get("kind", "covenant_settlement"),
                    "transactionId": payment.get("transactionId")
                    or ride.get("escrow", {}).get("txId"),
                    "amountSompi": int(
                        payment.get("amountSompi", ride.get("quotedFareSompi", 0))
                    ),
                    "beneficiaryAddress": payment.get("beneficiaryAddress"),
                    "createdAt": ride.get("updatedAt"),
                    "pickup": ride.get("pickup"),
                    "dropoff": ride.get("dropoff"),
                }
            )
        )
    return result


def driver_earnings(
    db: Database[dict[str, Any]], user: Mapping[str, Any]
) -> dict[str, Any]:
    require_role(user, "driver")
    wallet_id = _wallet_id(user)
    if wallet_id is None:
        settled: list[dict[str, Any]] = []
    else:
        settled = list(
            db.rides.find({"driverId": wallet_id, "status": "settled"}).sort(
                "updatedAt", -1
            )
        )
    total = sum(int(item.get("quotedFareSompi", 0)) for item in settled)
    return {
        "totalSompi": str(total),
        "totalKas": f"{total / 100_000_000:.8f}".rstrip("0").rstrip(".") or "0",
        "completedTrips": len(settled),
        "averageKas": (
            f"{(total / len(settled)) / 100_000_000:.8f}".rstrip("0").rstrip(".")
            if settled
            else "0"
        ),
        "recentPayments": [public_ride(item) for item in settled[:25]],
    }


def list_vehicles(
    db: Database[dict[str, Any]], user: Mapping[str, Any]
) -> list[dict[str, Any]]:
    require_role(user, "driver")
    account_id = _account_id(user)
    return [
        json_value(item)
        for item in db.vehicles.find({"accountId": account_id}).sort("createdAt", -1)
    ]


def create_vehicle(
    db: Database[dict[str, Any]], user: Mapping[str, Any], payload: VehicleCreate
) -> dict[str, Any]:
    require_role(user, "driver")
    account_id = _account_id(user)
    now = utcnow()
    document: dict[str, Any] = {
        "accountId": account_id,
        "vehicleType": payload.vehicle_type,
        "plateNumber": payload.plate_number.strip().upper(),
        "make": payload.make,
        "model": payload.model,
        "year": payload.year,
        "color": payload.color,
        "seatingCapacity": payload.seating_capacity,
        "wheelchairReady": payload.wheelchair_ready,
        "status": "pending_inspection",
        "isActive": False,
        "createdAt": now,
        "updatedAt": now,
    }

    def register(session: Any) -> dict[str, Any]:
        result = db.vehicles.insert_one(document, session=session)
        document["_id"] = result.inserted_id
        db.safety_inspections.insert_one(
            {
                "vehicleId": result.inserted_id,
                "vehicleAccountId": account_id,
                "inspectionDate": now,
                "inspectorName": "System",
                "inspectionType": "Registration",
                "result": "pending",
                "notes": (
                    "Initial safety inspection required before this vehicle "
                    "can go online."
                ),
                "createdAt": now,
                "updatedAt": now,
            },
            session=session,
        )
        record_system_log(
            db,
            user,
            "Vehicle Registration",
            f"Vehicle {document['plateNumber']} registered for safety inspection",
            status="pending",
            reference_type="vehicle",
            reference_id=result.inserted_id,
            session=session,
        )
        return document

    try:
        with db.client.start_session() as session:
            created = session.with_transaction(register)
    except DuplicateKeyError as exc:
        raise conflict(
            "This plate number is already registered", "vehicle_exists"
        ) from exc
    except PyMongoError as exc:
        raise unavailable("Could not register the vehicle atomically") from exc
    return json_value(created)


def set_driver_availability(
    db: Database[dict[str, Any]], user: Mapping[str, Any], payload: DriverAvailability
) -> dict[str, Any]:
    require_approved_driver(user)
    account_id = _account_id(user)
    vehicle_id = None
    if payload.available:
        if not payload.vehicle_id:
            raise bad_request("Select a vehicle before going online")
        vehicle_id = _object_id(payload.vehicle_id, "vehicleId")
        vehicle = db.vehicles.find_one(
            {"_id": vehicle_id, "accountId": account_id, "isActive": True}
        )
        if not vehicle:
            raise not_found("Vehicle not found")
    now = utcnow()
    update = {
        "driverProfile.isAvailable": payload.available,
        "driverProfile.activeVehicleId": vehicle_id,
        "driverProfile.useGps": payload.use_gps,
        "driverProfile.currentLatitude": payload.latitude,
        "driverProfile.currentLongitude": payload.longitude,
        "driverProfile.locationUpdatedAt": now if payload.available else None,
        "updatedAt": now,
    }
    account = db.accounts.find_one_and_update(
        {"_id": account_id, "role": "driver"},
        {"$set": update},
        return_document=ReturnDocument.AFTER,
    )
    if not account:
        raise not_found("Driver account not found")
    return json_value(
        {
            "available": account.get("driverProfile", {}).get("isAvailable", False),
            "activeVehicleId": account.get("driverProfile", {}).get("activeVehicleId"),
            "latitude": account.get("driverProfile", {}).get("currentLatitude"),
            "longitude": account.get("driverProfile", {}).get("currentLongitude"),
            "useGps": account.get("driverProfile", {}).get("useGps", False),
            "updatedAt": account.get("updatedAt"),
        }
    )


def list_driver_applications(
    db: Database[dict[str, Any]], user: Mapping[str, Any]
) -> list[dict[str, Any]]:
    require_role(user, "operator", "admin")
    return [
        json_value(
            {
                "_id": item["_id"],
                "fullName": item.get("fullName"),
                "email": item.get("email"),
                "phone": item.get("phone"),
                "dateOfBirth": item.get("dateOfBirth"),
                "status": item.get("status"),
                "verificationStatus": item.get("verificationStatus"),
                "driverProfile": item.get("driverProfile"),
                "createdAt": item.get("createdAt"),
            }
        )
        for item in db.accounts.find({"role": "driver"}).sort("createdAt", -1)
    ]


def update_driver_verification(
    db: Database[dict[str, Any]],
    user: Mapping[str, Any],
    driver_account_id: str,
    payload: DriverVerificationUpdate,
) -> dict[str, Any]:
    require_role(user, "operator", "admin")
    account_id = _object_id(driver_account_id, "driverAccountId")
    status = "active" if payload.status == "approved" else payload.status
    updated = db.accounts.find_one_and_update(
        {"_id": account_id, "role": "driver"},
        {
            "$set": {
                "verificationStatus": payload.status,
                "status": status,
                "verifiedAt": utcnow() if payload.status == "approved" else None,
                "verifiedBy": _account_id(user),
                "updatedAt": utcnow(),
            }
        },
        return_document=ReturnDocument.AFTER,
    )
    if not updated:
        raise not_found("Driver application not found")
    record_system_log(
        db,
        user,
        "Driver Verification",
        (
            f"Driver {updated.get('fullName') or driver_account_id} "
            f"marked {payload.status}"
        ),
        status=payload.status,
        reference_type="driver_account",
        reference_id=account_id,
    )
    return _account_summary(updated)


def _public_driver_document(item: Mapping[str, Any]) -> dict[str, Any]:
    return json_value(
        {
            "_id": item["_id"],
            "accountId": item["accountId"],
            "documentType": item["documentType"],
            "filename": item["filename"],
            "contentType": item["contentType"],
            "size": item["size"],
            "sha256": item["sha256"],
            "status": item["status"],
            "createdAt": item["createdAt"],
            "updatedAt": item["updatedAt"],
        }
    )


def list_driver_documents(
    db: Database[dict[str, Any]],
    user: Mapping[str, Any],
    driver_account_id: str | None = None,
) -> list[dict[str, Any]]:
    if driver_account_id is None:
        require_role(user, "driver")
        account_id = _account_id(user)
    else:
        require_role(user, "operator", "admin")
        account_id = _object_id(driver_account_id, "driverAccountId")
    return [
        _public_driver_document(item)
        for item in db.driver_documents.find({"accountId": account_id}).sort(
            "documentType", 1
        )
    ]


def save_driver_document(
    db: Database[dict[str, Any]], user: Mapping[str, Any], payload: DriverDocumentUpload
) -> dict[str, Any]:
    require_role(user, "driver")
    try:
        raw = base64.b64decode(payload.base64_data, validate=True)
    except (binascii.Error, ValueError) as exc:
        raise bad_request("Document data is not valid base64") from exc
    if not raw or len(raw) > MAX_DRIVER_DOCUMENT_BYTES:
        raise bad_request("Driver documents must be between 1 byte and 5 MB")
    signatures = {
        "image/jpeg": (b"\xff\xd8\xff",),
        "image/png": (b"\x89PNG\r\n\x1a\n",),
        "image/webp": (b"RIFF",),
        "application/pdf": (b"%PDF-",),
    }
    if not any(raw.startswith(prefix) for prefix in signatures[payload.content_type]):
        raise bad_request("The uploaded document contents do not match its file type")
    now = utcnow()
    item = db.driver_documents.find_one_and_update(
        {
            "accountId": _account_id(user),
            "documentType": payload.document_type,
        },
        {
            "$set": {
                "filename": payload.filename.replace("\r", "").replace("\n", ""),
                "contentType": payload.content_type,
                "size": len(raw),
                "sha256": hashlib.sha256(raw).hexdigest(),
                "data": Binary(raw),
                "status": "pending",
                "updatedAt": now,
            },
            "$setOnInsert": {"createdAt": now},
        },
        upsert=True,
        return_document=ReturnDocument.AFTER,
    )
    if not item:
        raise not_found("Driver document could not be saved")
    return _public_driver_document(item)


def get_driver_document(
    db: Database[dict[str, Any]], user: Mapping[str, Any], document_id: str
) -> dict[str, Any]:
    item = db.driver_documents.find_one({"_id": _object_id(document_id, "documentId")})
    if not item:
        raise not_found("Driver document not found")
    if user.get("role") not in {"operator", "admin"} and item[
        "accountId"
    ] != _account_id(user):
        raise forbidden("This driver document belongs to another account")
    return item


def verify_driver_document(
    db: Database[dict[str, Any]],
    user: Mapping[str, Any],
    document_id: str,
    payload: DriverDocumentVerification,
) -> dict[str, Any]:
    require_role(user, "operator", "admin")
    item = db.driver_documents.find_one_and_update(
        {"_id": _object_id(document_id, "documentId")},
        {
            "$set": {
                "status": payload.status,
                "verifiedBy": _account_id(user),
                "updatedAt": utcnow(),
            }
        },
        return_document=ReturnDocument.AFTER,
    )
    if not item:
        raise not_found("Driver document not found")
    record_system_log(
        db,
        user,
        "Document Review",
        f"Driver document {document_id} marked {payload.status}",
        status=payload.status,
        reference_type="driver_document",
        reference_id=document_id,
    )
    return _public_driver_document(item)


def _account_summary(account: Mapping[str, Any]) -> dict[str, Any]:
    return json_value(
        {
            "_id": account["_id"],
            "fullName": account.get("fullName"),
            "role": account.get("role"),
            "status": account.get("status"),
        }
    )


def message_contacts(
    db: Database[dict[str, Any]], user: Mapping[str, Any]
) -> list[dict[str, Any]]:
    account_id = _account_id(user)
    wallet_id = _wallet_id(user)
    contact_ids: set[Any] = set()
    if wallet_id is not None:
        for ride in db.rides.find(
            {"$or": [{"passengerId": wallet_id}, {"driverId": wallet_id}]},
            {"passengerId": 1, "driverId": 1},
        ):
            other_wallet_id = (
                ride.get("driverId")
                if ride.get("passengerId") == wallet_id
                else ride.get("passengerId")
            )
            if other_wallet_id:
                wallet = db.users.find_one({"_id": other_wallet_id}, {"accountId": 1})
                if wallet and wallet.get("accountId"):
                    contact_ids.add(wallet["accountId"])
    contact_ids.update(
        item["senderId"]
        for item in db.messages.find({"recipientId": account_id}, {"senderId": 1})
    )
    contact_ids.update(
        item["recipientId"]
        for item in db.messages.find({"senderId": account_id}, {"recipientId": 1})
    )
    operators = db.accounts.find(
        {"role": {"$in": ["operator", "admin"]}, "status": "active"}
    )
    contact_ids.update(item["_id"] for item in operators)
    return [
        _account_summary(item)
        for item in db.accounts.find({"_id": {"$in": list(contact_ids)}}).sort(
            "fullName", 1
        )
        if item["_id"] != account_id
    ]


def _can_message(
    db: Database[dict[str, Any]],
    sender: Mapping[str, Any],
    recipient: Mapping[str, Any],
) -> bool:
    if sender.get("role") in {"operator", "admin"} or recipient.get("role") in {
        "operator",
        "admin",
    }:
        return True
    sender_wallet = sender.get("walletIdentityId")
    recipient_wallet = recipient.get("walletIdentityId")
    if not sender_wallet or not recipient_wallet:
        return False
    return (
        db.rides.count_documents(
            {
                "$or": [
                    {"passengerId": sender_wallet, "driverId": recipient_wallet},
                    {"passengerId": recipient_wallet, "driverId": sender_wallet},
                ]
            },
            limit=1,
        )
        == 1
    )


def send_message(
    db: Database[dict[str, Any]], user: Mapping[str, Any], payload: MessageCreate
) -> dict[str, Any]:
    sender_id = _account_id(user)
    recipient_id = _object_id(payload.recipient_id, "recipientId")
    if sender_id == recipient_id:
        raise bad_request("You cannot message yourself")
    sender = db.accounts.find_one({"_id": sender_id})
    recipient = db.accounts.find_one(
        {"_id": recipient_id, "status": {"$ne": "deleted_gdpr"}}
    )
    if not sender or not recipient:
        raise not_found("Message recipient not found")
    if not _can_message(db, sender, recipient):
        raise forbidden(
            "Messages are available to ride participants and support operators"
        )
    now = utcnow()
    ids = sorted((str(sender_id), str(recipient_id)))
    document = {
        "conversationKey": ":".join(ids),
        "senderId": sender_id,
        "recipientId": recipient_id,
        "content": payload.content.strip(),
        "createdAt": now,
        "readAt": None,
    }
    result = db.messages.insert_one(document)
    document["_id"] = result.inserted_id
    return json_value(document)


def list_conversations(
    db: Database[dict[str, Any]], user: Mapping[str, Any]
) -> list[dict[str, Any]]:
    account_id = _account_id(user)
    recent = (
        db.messages.find(
            {"$or": [{"senderId": account_id}, {"recipientId": account_id}]}
        )
        .sort("createdAt", -1)
        .limit(500)
    )
    grouped: OrderedDict[Any, dict[str, Any]] = OrderedDict()
    for item in recent:
        other_id = (
            item["recipientId"] if item["senderId"] == account_id else item["senderId"]
        )
        if other_id not in grouped:
            account = db.accounts.find_one({"_id": other_id})
            if account:
                grouped[other_id] = {
                    "contact": _account_summary(account),
                    "lastMessage": json_value(item),
                    "unread": 0,
                }
        if (
            other_id in grouped
            and item["recipientId"] == account_id
            and item.get("readAt") is None
        ):
            grouped[other_id]["unread"] += 1
    return list(grouped.values())


def conversation_messages(
    db: Database[dict[str, Any]], user: Mapping[str, Any], contact_id: str
) -> list[dict[str, Any]]:
    account_id = _account_id(user)
    other_id = _object_id(contact_id, "contactId")
    key = ":".join(sorted((str(account_id), str(other_id))))
    db.messages.update_many(
        {"conversationKey": key, "recipientId": account_id, "readAt": None},
        {"$set": {"readAt": utcnow()}},
    )
    return [
        json_value(item)
        for item in db.messages.find({"conversationKey": key})
        .sort("createdAt", 1)
        .limit(500)
    ]


def rate_ride(
    db: Database[dict[str, Any]],
    user: Mapping[str, Any],
    ride_id: str,
    payload: RideRatingCreate,
) -> dict[str, Any]:
    wallet_id = _wallet_id(user)
    if wallet_id is None:
        raise forbidden("Link a wallet before rating a ride")
    object_id = _object_id(ride_id, "rideId")
    ride = db.rides.find_one(
        {
            "_id": object_id,
            "status": "settled",
            "$or": [{"passengerId": wallet_id}, {"driverId": wallet_id}],
        }
    )
    if not ride:
        raise not_found("Completed ride not found")
    now = utcnow()
    rating = db.ride_ratings.find_one_and_update(
        {"rideId": object_id, "raterId": wallet_id},
        {
            "$set": {
                "score": payload.score,
                "comment": payload.comment,
                "updatedAt": now,
            },
            "$setOnInsert": {"createdAt": now},
        },
        upsert=True,
        return_document=ReturnDocument.AFTER,
    )
    return json_value(rating)


def create_gdpr_request(
    db: Database[dict[str, Any]], user: Mapping[str, Any], payload: GdprRequestCreate
) -> dict[str, Any]:
    account_id = _account_id(user)
    if db.gdpr_requests.count_documents(
        {"accountId": account_id, "status": {"$in": ["submitted", "in_progress"]}},
        limit=1,
    ):
        raise conflict(
            "An active privacy request already exists", "gdpr_request_active"
        )
    now = utcnow()
    document = {
        "accountId": account_id,
        "requestType": payload.request_type,
        "notes": payload.notes,
        "status": "submitted",
        "createdAt": now,
        "updatedAt": now,
    }
    result = db.gdpr_requests.insert_one(document)
    document["_id"] = result.inserted_id
    return json_value(document)


def list_gdpr_requests(
    db: Database[dict[str, Any]], user: Mapping[str, Any]
) -> list[dict[str, Any]]:
    return [
        json_value(item)
        for item in db.gdpr_requests.find({"accountId": _account_id(user)}).sort(
            "createdAt", -1
        )
    ]


def list_all_gdpr_requests(
    db: Database[dict[str, Any]], user: Mapping[str, Any]
) -> list[dict[str, Any]]:
    require_role(user, "operator", "admin")
    results: list[dict[str, Any]] = []
    for item in db.gdpr_requests.find().sort("createdAt", -1).limit(500):
        account = db.accounts.find_one(
            {"_id": item["accountId"]}, {"fullName": 1, "email": 1, "role": 1}
        )
        results.append(
            json_value(
                {
                    **item,
                    "account": {
                        "id": str(item["accountId"]),
                        "fullName": account.get("fullName")
                        if account
                        else "Deleted account",
                        "email": account.get("email") if account else None,
                        "role": account.get("role") if account else None,
                    },
                }
            )
        )
    return results


def review_gdpr_request(
    db: Database[dict[str, Any]],
    user: Mapping[str, Any],
    request_id: str,
    payload: GdprRequestReview,
) -> dict[str, Any]:
    require_role(user, "operator", "admin")
    item = db.gdpr_requests.find_one_and_update(
        {"_id": _object_id(request_id, "requestId")},
        {
            "$set": {
                "status": payload.status,
                "response": payload.response,
                "reviewedBy": _account_id(user),
                "updatedAt": utcnow(),
            }
        },
        return_document=ReturnDocument.AFTER,
    )
    if not item:
        raise not_found("Privacy request not found")
    record_system_log(
        db,
        user,
        "GDPR Review",
        f"Privacy request {request_id} marked {payload.status}",
        status=payload.status,
        reference_type="gdpr_request",
        reference_id=request_id,
    )
    return json_value(item)


def safety_inspection_state(
    db: Database[dict[str, Any]], user: Mapping[str, Any]
) -> dict[str, Any]:
    require_role(user, "operator", "admin")
    vehicles = list(db.vehicles.find().sort("createdAt", -1).limit(500))
    vehicle_by_id = {item["_id"]: item for item in vehicles}
    account_ids = list({item["accountId"] for item in vehicles})
    accounts = {
        item["_id"]: item
        for item in db.accounts.find(
            {"_id": {"$in": account_ids}}, {"fullName": 1, "email": 1}
        )
    }
    inspections = []
    for item in db.safety_inspections.find().sort("createdAt", -1).limit(200):
        vehicle = vehicle_by_id.get(item["vehicleId"])
        account = accounts.get(vehicle.get("accountId")) if vehicle else None
        inspections.append(
            json_value(
                {
                    **item,
                    "status": item.get("result"),
                    "vehicle": {
                        "id": str(item["vehicleId"]),
                        "plateNumber": vehicle.get("plateNumber")
                        if vehicle
                        else "Deleted",
                        "vehicleType": vehicle.get("vehicleType") if vehicle else None,
                        "make": vehicle.get("make") if vehicle else None,
                        "model": vehicle.get("model") if vehicle else None,
                        "isActive": vehicle.get("isActive", False)
                        if vehicle
                        else False,
                        "driverName": account.get("fullName") if account else None,
                    },
                }
            )
        )
    public_vehicles = [
        json_value(
            {
                **vehicle,
                "driverName": accounts.get(vehicle["accountId"], {}).get("fullName"),
                "driverEmail": accounts.get(vehicle["accountId"], {}).get("email"),
            }
        )
        for vehicle in vehicles
    ]
    return {"vehicles": public_vehicles, "inspections": inspections}


def record_safety_inspection(
    db: Database[dict[str, Any]],
    user: Mapping[str, Any],
    payload: SafetyInspectionCreate,
) -> dict[str, Any]:
    require_role(user, "operator", "admin")
    vehicle_id = _object_id(payload.vehicle_id, "vehicleId")
    now = utcnow()

    def inspect(session: Any) -> dict[str, Any]:
        vehicle = db.vehicles.find_one({"_id": vehicle_id}, session=session)
        if not vehicle:
            raise not_found("Vehicle not found")
        inspection = {
            "vehicleId": vehicle_id,
            "vehicleAccountId": vehicle["accountId"],
            "inspectionDate": now,
            "inspectorId": _account_id(user),
            "inspectorName": user.get("fullName")
            or user.get("displayName")
            or "Operator",
            "inspectionType": "General",
            "result": payload.status,
            "notes": payload.notes,
            "createdAt": now,
            "updatedAt": now,
        }
        result = db.safety_inspections.insert_one(inspection, session=session)
        inspection["_id"] = result.inserted_id
        is_active = payload.status == "passed"
        vehicle_status = {
            "passed": "active",
            "failed": "inspection_failed",
            "needs_followup": "inspection_followup",
        }[payload.status]
        db.vehicles.update_one(
            {"_id": vehicle_id},
            {
                "$set": {
                    "isActive": is_active,
                    "status": vehicle_status,
                    "lastInspectionId": result.inserted_id,
                    "lastInspectionAt": now,
                    "updatedAt": now,
                }
            },
            session=session,
        )
        if not is_active:
            db.accounts.update_one(
                {
                    "_id": vehicle["accountId"],
                    "driverProfile.activeVehicleId": vehicle_id,
                },
                {
                    "$set": {
                        "driverProfile.isAvailable": False,
                        "driverProfile.activeVehicleId": None,
                        "updatedAt": now,
                    }
                },
                session=session,
            )
        record_system_log(
            db,
            user,
            "Safety Inspection",
            f"Vehicle {vehicle['plateNumber']} inspection marked {payload.status}",
            status=payload.status,
            severity="warning" if payload.status != "passed" else "info",
            reference_type="vehicle",
            reference_id=vehicle_id,
            metadata={"inspectionId": str(result.inserted_id)},
            session=session,
        )
        inspection["vehicle"] = {
            "id": str(vehicle_id),
            "plateNumber": vehicle["plateNumber"],
            "vehicleType": vehicle["vehicleType"],
            "make": vehicle["make"],
            "model": vehicle["model"],
            "isActive": is_active,
        }
        inspection["status"] = payload.status
        return inspection

    try:
        with db.client.start_session() as session:
            inspection = session.with_transaction(inspect)
    except PyMongoError as exc:
        raise unavailable("Could not record the safety inspection atomically") from exc
    return json_value(inspection)


def list_system_logs(
    db: Database[dict[str, Any]], user: Mapping[str, Any], limit: int = 200
) -> list[dict[str, Any]]:
    require_role(user, "operator", "admin")
    return [
        json_value(item)
        for item in db.system_logs.find()
        .sort("createdAt", -1)
        .limit(min(max(limit, 1), 500))
    ]


def operator_operations_summary(
    db: Database[dict[str, Any]], user: Mapping[str, Any]
) -> dict[str, Any]:
    require_role(user, "operator", "admin")
    return {
        "accounts": db.accounts.count_documents({}),
        "passengers": db.accounts.count_documents({"role": "passenger"}),
        "drivers": db.accounts.count_documents({"role": "driver"}),
        "pendingDrivers": db.accounts.count_documents(
            {"role": "driver", "verificationStatus": "pending"}
        ),
        "onlineDrivers": db.accounts.count_documents(
            {"role": "driver", "driverProfile.isAvailable": True}
        ),
        "normalRides": db.rides.count_documents({}),
        "activeNormalRides": db.rides.count_documents(
            {"status": {"$nin": ["settled", "refunded", "cancelled"]}}
        ),
        "pendingInspections": db.safety_inspections.count_documents(
            {"result": {"$in": ["pending", "needs_followup"]}}
        ),
        "openPrivacyRequests": db.gdpr_requests.count_documents(
            {"status": {"$in": ["submitted", "in_progress"]}}
        ),
        "autonomousVehicles": db.autonomous_vehicles.count_documents({}),
        "carshareVehicles": db.carshare_vehicles.count_documents({"active": True}),
    }


def operator_data_snapshot(
    db: Database[dict[str, Any]], user: Mapping[str, Any]
) -> dict[str, Any]:
    require_role(user, "operator", "admin")
    accounts = [
        json_value(
            {
                "_id": item["_id"],
                "fullName": item.get("fullName"),
                "email": item.get("email"),
                "role": item.get("role"),
                "status": item.get("status"),
                "verificationStatus": item.get("verificationStatus"),
                "createdAt": item.get("createdAt"),
            }
        )
        for item in db.accounts.find().sort("createdAt", -1).limit(100)
    ]
    vehicles = [
        json_value(item)
        for item in db.vehicles.find(
            {},
            {
                "accountId": 1,
                "vehicleType": 1,
                "plateNumber": 1,
                "make": 1,
                "model": 1,
                "year": 1,
                "status": 1,
                "isActive": 1,
                "createdAt": 1,
            },
        )
        .sort("createdAt", -1)
        .limit(100)
    ]
    rides = [
        json_value(
            {
                "_id": item["_id"],
                "status": item.get("status"),
                "serviceType": item.get("serviceType"),
                "pickup": item.get("pickup"),
                "dropoff": item.get("dropoff"),
                "quotedFareSompi": item.get("quotedFareSompi"),
                "network": item.get("network"),
                "createdAt": item.get("createdAt"),
                "updatedAt": item.get("updatedAt"),
            }
        )
        for item in db.rides.find().sort("createdAt", -1).limit(100)
    ]
    return {
        "collectionCounts": {
            name: db[name].count_documents({})
            for name in (
                "accounts",
                "users",
                "vehicles",
                "rides",
                "payment_events",
                "messages",
                "gdpr_requests",
                "safety_inspections",
                "autonomous_vehicles",
                "autonomous_rides",
                "carshare_customers",
                "carshare_vehicles",
                "carshare_bookings",
            )
        },
        "accounts": accounts,
        "vehicles": vehicles,
        "rides": rides,
    }
