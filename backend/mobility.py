from __future__ import annotations

import hashlib
import hmac
import math
from datetime import timedelta
from typing import Any, Mapping

from bson import ObjectId
from pymongo import ReturnDocument
from pymongo.database import Database
from pymongo.errors import DuplicateKeyError, PyMongoError

from backend.accounts import require_role
from backend.db import utcnow
from backend.errors import bad_request, conflict, forbidden, not_found, unavailable
from backend.platform import record_system_log
from backend.presentation import json_value
from backend.schemas import (
    AutonomousRatingCreate,
    AutonomousRideCreate,
    AutonomousVehicleStatusUpdate,
    CarshareBookingCreate,
    CarshareRegistration,
    CarshareRentalEnd,
    CarshareTeleDriveCreate,
)
from backend.settings import Settings

AUTO_VEHICLES = (
    ("AV-PAF-001", "AV-001-CY", "Waymo", "One", "White", 34.7780, 32.4290, 95, False),
    (
        "AV-PAF-002",
        "AV-002-CY",
        "Jaguar",
        "I-PACE AV",
        "Silver",
        34.7850,
        32.4350,
        88,
        True,
    ),
    ("AV-PAF-003", "AV-003-CY", "Waymo", "One", "White", 34.7920, 32.4420, 92, False),
    ("AV-LIM-001", "AV-004-CY", "Waymo", "One", "White", 34.6950, 33.0350, 97, False),
    ("AV-LIM-002", "AV-005-CY", "Cruise", "Origin", "Blue", 34.7050, 33.0450, 85, True),
    (
        "AV-LIM-003",
        "AV-006-CY",
        "Jaguar",
        "I-PACE AV",
        "Black",
        34.7150,
        33.0250,
        90,
        False,
    ),
    ("AV-LIM-004", "AV-007-CY", "Waymo", "One", "White", 34.7250, 33.0550, 78, False),
    ("AV-LAR-001", "AV-008-CY", "Waymo", "One", "White", 34.9250, 33.6150, 94, False),
    (
        "AV-LAR-002",
        "AV-009-CY",
        "Cruise",
        "Origin",
        "Silver",
        34.9350,
        33.6050,
        89,
        True,
    ),
    (
        "AV-LAR-003",
        "AV-010-CY",
        "Jaguar",
        "I-PACE AV",
        "Gray",
        34.9150,
        33.5950,
        96,
        False,
    ),
    ("AV-NIC-001", "AV-011-CY", "Waymo", "One", "White", 35.1580, 33.3550, 91, False),
    (
        "AV-NIC-002",
        "AV-012-CY",
        "Cruise",
        "Origin",
        "White",
        35.1480,
        33.3450,
        87,
        True,
    ),
    (
        "AV-NIC-003",
        "AV-013-CY",
        "Jaguar",
        "I-PACE AV",
        "Black",
        35.1520,
        33.3650,
        93,
        False,
    ),
    ("AV-NIC-004", "AV-014-CY", "Waymo", "One", "Silver", 35.1380, 33.3350, 82, False),
    (
        "AV-NIC-005",
        "AV-015-CY",
        "Cruise",
        "Origin",
        "Blue",
        35.1280,
        33.3750,
        99,
        False,
    ),
)

CARSHARE_TYPES = {
    "economy": ("Economy", 4, False, 0.19, 9.0, 45.0),
    "compact": ("Compact", 5, False, 0.25, 12.0, 55.0),
    "premium": ("Premium", 5, False, 0.45, 20.0, 95.0),
    "cabrio": ("Convertible", 2, False, 0.55, 25.0, 110.0),
    "electric": ("Electric", 5, True, 0.22, 10.0, 50.0),
    "hybrid": ("Hybrid", 5, False, 0.23, 11.0, 52.0),
    "van": ("Van", 9, False, 0.35, 18.0, 85.0),
    "suv": ("SUV", 5, False, 0.40, 18.0, 80.0),
}

CARSHARE_ZONES = (
    ("zone-1", "Nicosia Old Town", "Nicosia", 35.1725, 33.3617, 380),
    ("zone-2", "Nicosia Mall", "Nicosia", 35.1521, 33.3750, 330),
    ("zone-3", "University of Cyprus", "Nicosia", 35.1422, 33.4106, 380),
    ("zone-4", "Nicosia Bus Station", "Nicosia", 35.1698, 33.3578, 280),
    ("zone-5", "Nicosia Industrial Area", "Nicosia", 35.1350, 33.3900, 480),
    ("zone-6", "Limassol Marina", "Limassol", 34.6700, 33.0400, 320),
    ("zone-7", "Limassol Old Town", "Limassol", 34.6730, 33.0420, 380),
    ("zone-8", "My Mall Limassol", "Limassol", 34.7058, 33.0228, 330),
    ("zone-9", "Limassol University", "Limassol", 34.6798, 33.0444, 380),
    ("zone-10", "Limassol Port", "Limassol", 34.6650, 33.0500, 420),
    ("zone-11", "Larnaca Airport", "Larnaca", 34.8756, 33.6228, 480),
    ("zone-12", "Larnaca Promenade", "Larnaca", 34.9119, 33.6353, 330),
    ("zone-13", "Larnaca Mall", "Larnaca", 34.8970, 33.6150, 330),
    ("zone-14", "Paphos Airport", "Paphos", 34.7180, 32.4856, 420),
    ("zone-15", "Paphos Harbour", "Paphos", 34.7530, 32.4067, 380),
    ("zone-16", "Kings Avenue Mall", "Paphos", 34.7611, 32.4156, 330),
    ("zone-17", "Ayia Napa Center", "Ayia Napa", 34.9878, 33.9994, 380),
    ("zone-18", "Protaras Strip", "Paralimni", 35.0125, 34.0575, 360),
    ("zone-19", "Nicosia Eleftheria Square", "Nicosia", 35.1720, 33.3645, 420),
    ("zone-20", "Limassol Saripolou Hub", "Limassol", 34.6755, 33.0440, 400),
    ("zone-21", "Larnaca Europe Square", "Larnaca", 34.9168, 33.6360, 360),
    ("zone-22", "Paphos Town Hall", "Paphos", 34.7723, 32.4295, 360),
    ("zone-23", "Ayia Napa Harbour North", "Ayia Napa", 34.9945, 34.0007, 360),
)

CARSHARE_VEHICLES = (
    (
        "LCA-101",
        "economy",
        "Toyota",
        "Yaris",
        2022,
        "White",
        "zone-11",
        34.8760,
        33.6230,
        85,
    ),
    (
        "LCA-102",
        "economy",
        "Kia",
        "Picanto",
        2023,
        "Red",
        "zone-11",
        34.8755,
        33.6225,
        92,
    ),
    (
        "NIC-101",
        "economy",
        "Toyota",
        "Yaris",
        2021,
        "Silver",
        "zone-1",
        35.1728,
        33.3620,
        78,
    ),
    (
        "NIC-102",
        "economy",
        "Hyundai",
        "i10",
        2023,
        "Blue",
        "zone-2",
        35.1525,
        33.3755,
        95,
    ),
    (
        "LIM-101",
        "economy",
        "Fiat",
        "500",
        2022,
        "Yellow",
        "zone-7",
        34.6732,
        33.0425,
        88,
    ),
    (
        "LCA-201",
        "compact",
        "Volkswagen",
        "Golf",
        2022,
        "Black",
        "zone-11",
        34.8758,
        33.6232,
        90,
    ),
    (
        "NIC-201",
        "compact",
        "Toyota",
        "Corolla",
        2023,
        "White",
        "zone-3",
        35.1425,
        33.4110,
        82,
    ),
    (
        "NIC-202",
        "compact",
        "Honda",
        "Civic",
        2022,
        "Gray",
        "zone-1",
        35.1722,
        33.3615,
        75,
    ),
    ("LIM-201", "compact", "Mazda", "3", 2023, "Red", "zone-6", 34.6702, 33.0405, 88),
    (
        "PAF-201",
        "compact",
        "Ford",
        "Focus",
        2022,
        "Blue",
        "zone-14",
        34.7182,
        32.4858,
        95,
    ),
    (
        "LIM-301",
        "premium",
        "BMW",
        "3 Series",
        2023,
        "Black",
        "zone-6",
        34.6705,
        33.0408,
        85,
    ),
    (
        "NIC-301",
        "premium",
        "Mercedes",
        "C-Class",
        2022,
        "Silver",
        "zone-2",
        35.1520,
        33.3748,
        78,
    ),
    (
        "PAF-301",
        "premium",
        "Audi",
        "A4",
        2023,
        "White",
        "zone-15",
        34.7532,
        32.4070,
        92,
    ),
    (
        "AYN-401",
        "cabrio",
        "BMW",
        "4 Cabrio",
        2023,
        "White",
        "zone-17",
        34.9880,
        33.9996,
        88,
    ),
    (
        "LIM-401",
        "cabrio",
        "Mini",
        "Convertible",
        2022,
        "Red",
        "zone-6",
        34.6698,
        33.0402,
        80,
    ),
    (
        "NIC-501",
        "electric",
        "Nissan",
        "Leaf",
        2023,
        "Blue",
        "zone-1",
        35.1726,
        33.3618,
        85,
    ),
    (
        "LCA-501",
        "electric",
        "Tesla",
        "Model 3",
        2023,
        "White",
        "zone-11",
        34.8752,
        33.6228,
        92,
    ),
    (
        "LIM-501",
        "electric",
        "Volkswagen",
        "ID.4",
        2023,
        "Gray",
        "zone-7",
        34.6735,
        33.0428,
        78,
    ),
    (
        "NIC-601",
        "hybrid",
        "Toyota",
        "Prius",
        2023,
        "Silver",
        "zone-3",
        35.1428,
        33.4108,
        88,
    ),
    (
        "LCA-601",
        "hybrid",
        "Honda",
        "Insight",
        2022,
        "Black",
        "zone-12",
        34.9122,
        33.6355,
        82,
    ),
    (
        "NIC-701",
        "van",
        "Mercedes",
        "Vito",
        2022,
        "White",
        "zone-4",
        35.1700,
        33.3580,
        75,
    ),
    (
        "LIM-701",
        "van",
        "Volkswagen",
        "Transporter",
        2021,
        "Gray",
        "zone-10",
        34.6655,
        33.0505,
        68,
    ),
    (
        "LCA-801",
        "suv",
        "Toyota",
        "RAV4",
        2023,
        "Green",
        "zone-11",
        34.8754,
        33.6226,
        90,
    ),
    (
        "PAF-801",
        "suv",
        "Nissan",
        "X-Trail",
        2022,
        "Black",
        "zone-15",
        34.7528,
        32.4065,
        85,
    ),
    (
        "AYN-801",
        "suv",
        "Jeep",
        "Compass",
        2023,
        "White",
        "zone-17",
        34.9882,
        33.9998,
        92,
    ),
)


def ensure_mobility_seed(db: Database[dict[str, Any]]) -> None:
    if db.system_state.find_one({"_id": "mobility_seed_v1"}):
        return
    now = utcnow()
    for code, plate, make, model, color, lat, lon, battery, accessible in AUTO_VEHICLES:
        db.autonomous_vehicles.update_one(
            {"_id": code},
            {
                "$setOnInsert": {
                    "plateNumber": plate,
                    "make": make,
                    "model": model,
                    "year": 2024,
                    "color": color,
                    "seatingCapacity": 4,
                    "wheelchairReady": accessible,
                    "status": "available",
                    "latitude": lat,
                    "longitude": lon,
                    "batteryLevel": battery,
                    "createdAt": now,
                    "updatedAt": now,
                }
            },
            upsert=True,
        )
    for zone_id, name, city, lat, lon, radius in CARSHARE_ZONES:
        db.carshare_zones.update_one(
            {"_id": zone_id},
            {
                "$setOnInsert": {
                    "name": name,
                    "city": city,
                    "latitude": lat,
                    "longitude": lon,
                    "radiusMeters": radius,
                    "active": True,
                }
            },
            upsert=True,
        )
    for (
        plate,
        type_code,
        make,
        model,
        year,
        color,
        zone_id,
        lat,
        lon,
        level,
    ) in CARSHARE_VEHICLES:
        type_name, seats, electric, per_minute, per_hour, per_day = CARSHARE_TYPES[
            type_code
        ]
        db.carshare_vehicles.update_one(
            {"_id": plate},
            {
                "$setOnInsert": {
                    "plateNumber": plate,
                    "typeCode": type_code,
                    "typeName": type_name,
                    "make": make,
                    "model": model,
                    "year": year,
                    "color": color,
                    "seatingCapacity": seats,
                    "electric": electric,
                    "zoneId": zone_id,
                    "latitude": lat,
                    "longitude": lon,
                    "energyLevel": level,
                    "pricePerMinute": per_minute,
                    "pricePerHour": per_hour,
                    "pricePerDay": per_day,
                    "status": "available",
                    "active": True,
                    "createdAt": now,
                    "updatedAt": now,
                }
            },
            upsert=True,
        )
    db.system_state.update_one(
        {"_id": "mobility_seed_v1"},
        {
            "$setOnInsert": {
                "createdAt": now,
                "autonomousVehicles": len(AUTO_VEHICLES),
                "carshareVehicles": len(CARSHARE_VEHICLES),
            }
        },
        upsert=True,
    )


def _account_id(user: Mapping[str, Any]) -> Any:
    account_id = user.get("accountId")
    if account_id is None:
        raise forbidden("An OSRH password account is required")
    return account_id


def _object_id(value: str, label: str) -> ObjectId:
    try:
        return ObjectId(value)
    except Exception as exc:
        raise not_found(f"{label} not found") from exc


def _sensitive_digest(value: str, account_id: Any, purpose: str) -> str:
    """Create a non-reversible, purpose-bound digest for regulated identifiers."""
    key = Settings().session_secret.encode("utf-8")
    message = f"carshare:{purpose}:v1\0{account_id}\0{value}".encode("utf-8")
    return hmac.new(key, message, hashlib.sha256).hexdigest()


def _distance(a: Mapping[str, Any], b: Mapping[str, Any]) -> float:
    radius = 6_371_000
    lat1, lat2 = math.radians(float(a["latitude"])), math.radians(float(b["latitude"]))
    dlat = lat2 - lat1
    dlon = math.radians(float(b["longitude"]) - float(a["longitude"]))
    value = (
        math.sin(dlat / 2) ** 2
        + math.cos(lat1) * math.cos(lat2) * math.sin(dlon / 2) ** 2
    )
    return 2 * radius * math.asin(math.sqrt(value))


def list_autonomous_vehicles(
    db: Database[dict[str, Any]], user: Mapping[str, Any]
) -> list[dict[str, Any]]:
    require_role(user, "passenger", "operator", "admin")
    ensure_mobility_seed(db)
    return [json_value(item) for item in db.autonomous_vehicles.find().sort("_id", 1)]


def operator_mobility_snapshot(
    db: Database[dict[str, Any]], user: Mapping[str, Any]
) -> dict[str, Any]:
    require_role(user, "operator", "admin")
    ensure_mobility_seed(db)
    drivers = []
    for account in db.accounts.find(
        {"role": "driver", "driverProfile.isAvailable": True}
    ):
        profile = account.get("driverProfile", {})
        latitude = profile.get("currentLatitude")
        longitude = profile.get("currentLongitude")
        if latitude is None or longitude is None:
            continue
        vehicle = None
        if profile.get("activeVehicleId") is not None:
            vehicle = db.vehicles.find_one({"_id": profile["activeVehicleId"]})
        drivers.append(
            json_value(
                {
                    "id": account["_id"],
                    "label": account.get("fullName") or "Driver",
                    "latitude": latitude,
                    "longitude": longitude,
                    "vehicle": {
                        "plateNumber": vehicle.get("plateNumber") if vehicle else None,
                        "make": vehicle.get("make") if vehicle else None,
                        "model": vehicle.get("model") if vehicle else None,
                    },
                    "locationUpdatedAt": profile.get("locationUpdatedAt"),
                }
            )
        )
    return {
        "drivers": drivers,
        "autonomousVehicles": [
            json_value(item) for item in db.autonomous_vehicles.find().sort("_id", 1)
        ],
        "carshareVehicles": [
            json_value(item)
            for item in db.carshare_vehicles.find({"active": True}).sort("_id", 1)
        ],
        "carshareZones": [
            json_value(item)
            for item in db.carshare_zones.find({"active": True}).sort("_id", 1)
        ],
    }


def update_autonomous_vehicle_status(
    db: Database[dict[str, Any]],
    user: Mapping[str, Any],
    vehicle_id: str,
    payload: AutonomousVehicleStatusUpdate,
) -> dict[str, Any]:
    require_role(user, "operator", "admin")
    ensure_mobility_seed(db)
    now = utcnow()

    def update(session: Any) -> dict[str, Any] | None:
        vehicle = db.autonomous_vehicles.find_one_and_update(
            {
                "_id": vehicle_id,
                "status": {"$nin": ["reserved", "busy"]},
            },
            {"$set": {"status": payload.status, "updatedAt": now}},
            return_document=ReturnDocument.AFTER,
            session=session,
        )
        if vehicle:
            record_system_log(
                db,
                user,
                "Autonomous Fleet",
                f"Autonomous vehicle {vehicle_id} marked {payload.status}",
                status=payload.status,
                reference_type="autonomous_vehicle",
                reference_id=vehicle_id,
                session=session,
            )
        return vehicle

    try:
        with db.client.start_session() as session:
            vehicle = session.with_transaction(update)
    except PyMongoError as exc:
        raise unavailable("Could not update the autonomous vehicle atomically") from exc
    if not vehicle:
        raise conflict("A reserved autonomous vehicle cannot be changed")
    return json_value(vehicle)


def create_autonomous_ride(
    db: Database[dict[str, Any]],
    user: Mapping[str, Any],
    payload: AutonomousRideCreate,
) -> dict[str, Any]:
    require_role(user, "passenger")
    ensure_mobility_seed(db)
    account_id = _account_id(user)
    pickup, dropoff = payload.pickup.model_dump(), payload.dropoff.model_dump()
    distance = max(100, round(_distance(pickup, dropoff) * 1.27))
    now = utcnow()

    def reserve_and_create(session: Any) -> dict[str, Any]:
        if db.autonomous_rides.count_documents(
            {
                "accountId": account_id,
                "status": {"$nin": ["completed", "cancelled"]},
            },
            limit=1,
            session=session,
        ):
            raise conflict(
                "You already have an active autonomous ride",
                "autonomous_ride_active",
            )
        candidates = sorted(
            db.autonomous_vehicles.find({"status": "available"}, session=session),
            key=lambda item: _distance(item, pickup),
        )
        selected = None
        for candidate in candidates:
            selected = db.autonomous_vehicles.find_one_and_update(
                {"_id": candidate["_id"], "status": "available"},
                {
                    "$set": {
                        "status": "reserved",
                        "reservedBy": account_id,
                        "updatedAt": now,
                    }
                },
                return_document=ReturnDocument.AFTER,
                session=session,
            )
            if selected:
                break
        if not selected:
            raise conflict(
                "No autonomous vehicle is currently available",
                "autonomous_unavailable",
            )
        ride = {
            "accountId": account_id,
            "activePassengerId": account_id,
            "vehicleId": selected["_id"],
            "vehicle": {
                key: selected[key]
                for key in ("plateNumber", "make", "model", "color", "batteryLevel")
            },
            "vehicleStart": {
                "label": selected["_id"],
                "latitude": selected["latitude"],
                "longitude": selected["longitude"],
            },
            "vehiclePosition": {
                "label": selected["_id"],
                "latitude": selected["latitude"],
                "longitude": selected["longitude"],
            },
            "pickup": pickup,
            "dropoff": dropoff,
            "pickupDescription": payload.pickup_description,
            "dropoffDescription": payload.dropoff_description,
            "paymentMethod": payload.payment_method,
            "paymentStatus": "pending",
            "notes": payload.notes,
            "distanceMeters": distance,
            "estimatedDurationSeconds": math.ceil(distance / 8.3 + 180),
            "estimatedFare": round(4.0 + distance / 1000 * 1.05, 2),
            "status": "vehicle_dispatched",
            "simulationSpeed": 1,
            "createdAt": now,
            "updatedAt": now,
        }
        result = db.autonomous_rides.insert_one(ride, session=session)
        ride["_id"] = result.inserted_id
        return ride

    try:
        with db.client.start_session() as session:
            ride = session.with_transaction(reserve_and_create)
    except DuplicateKeyError as exc:
        raise conflict(
            "You already have an active autonomous ride",
            "autonomous_ride_active",
        ) from exc
    except PyMongoError as exc:
        raise unavailable("Could not reserve an autonomous vehicle atomically") from exc
    return json_value(ride)


def _refresh_autonomous(
    db: Database[dict[str, Any]], ride: Mapping[str, Any]
) -> dict[str, Any]:
    if ride["status"] in {"completed", "cancelled"}:
        return dict(ride)
    elapsed = max(
        0.0,
        (utcnow() - ride["createdAt"]).total_seconds()
        * int(ride.get("simulationSpeed", 1)),
    )
    timeline = (
        (8, "vehicle_dispatched"),
        (22, "vehicle_arriving"),
        (35, "vehicle_arrived"),
        (50, "in_progress"),
        (95, "arriving_destination"),
        (110, "completed"),
    )
    status = "completed"
    for threshold, label in timeline:
        if elapsed < threshold:
            status = label
            break
    start, pickup, dropoff = ride["vehicleStart"], ride["pickup"], ride["dropoff"]
    if elapsed < 35:
        fraction = min(1.0, elapsed / 35)
        origin, destination = start, pickup
    else:
        fraction = min(1.0, (elapsed - 35) / 75)
        origin, destination = pickup, dropoff
    position = {
        "label": ride["vehicleId"],
        "latitude": origin["latitude"]
        + (destination["latitude"] - origin["latitude"]) * fraction,
        "longitude": origin["longitude"]
        + (destination["longitude"] - origin["longitude"]) * fraction,
    }
    now = utcnow()
    update: dict[str, Any] = {
        "status": status,
        "vehiclePosition": position,
        "updatedAt": now,
    }
    unset: dict[str, str] = {}
    if status == "completed":
        update.update({"paymentStatus": "awaiting_payment", "completedAt": now})
        unset["activePassengerId"] = ""
    query: dict[str, Any] = {"$set": update}
    if unset:
        query["$unset"] = unset

    if status != "completed":
        refreshed = db.autonomous_rides.find_one_and_update(
            {"_id": ride["_id"], "status": ride["status"]},
            query,
            return_document=ReturnDocument.AFTER,
        )
        if refreshed:
            return refreshed
        return db.autonomous_rides.find_one({"_id": ride["_id"]}) or dict(ride)

    def transition(session: Any) -> dict[str, Any] | None:
        refreshed = db.autonomous_rides.find_one_and_update(
            {"_id": ride["_id"], "status": ride["status"]},
            query,
            return_document=ReturnDocument.AFTER,
            session=session,
        )
        if refreshed and status == "completed":
            db.autonomous_vehicles.update_one(
                {"_id": ride["vehicleId"], "reservedBy": ride["accountId"]},
                {
                    "$set": {
                        "status": "available",
                        "latitude": dropoff["latitude"],
                        "longitude": dropoff["longitude"],
                        "updatedAt": now,
                    },
                    "$unset": {"reservedBy": ""},
                },
                session=session,
            )
        return refreshed

    try:
        with db.client.start_session() as session:
            refreshed = session.with_transaction(transition)
    except PyMongoError as exc:
        raise unavailable("Could not update autonomous ride state atomically") from exc
    if refreshed:
        return refreshed
    return db.autonomous_rides.find_one({"_id": ride["_id"]}) or dict(ride)


def get_autonomous_ride(
    db: Database[dict[str, Any]], user: Mapping[str, Any], ride_id: str
) -> dict[str, Any]:
    item = db.autonomous_rides.find_one({"_id": _object_id(ride_id, "Autonomous ride")})
    if not item:
        raise not_found("Autonomous ride not found")
    if user.get("role") not in {"operator", "admin"} and item[
        "accountId"
    ] != _account_id(user):
        raise forbidden("This autonomous ride belongs to another account")
    return json_value(_refresh_autonomous(db, item))


def list_autonomous_rides(
    db: Database[dict[str, Any]], user: Mapping[str, Any]
) -> list[dict[str, Any]]:
    require_role(user, "passenger", "operator", "admin")
    query = (
        {}
        if user.get("role") in {"operator", "admin"}
        else {"accountId": _account_id(user)}
    )
    return [
        json_value(_refresh_autonomous(db, item))
        for item in db.autonomous_rides.find(query).sort("createdAt", -1).limit(100)
    ]


def cancel_autonomous_ride(
    db: Database[dict[str, Any]], user: Mapping[str, Any], ride_id: str
) -> dict[str, Any]:
    account_id = _account_id(user)
    object_id = _object_id(ride_id, "Autonomous ride")
    now = utcnow()

    def cancel(session: Any) -> dict[str, Any] | None:
        item = db.autonomous_rides.find_one_and_update(
            {
                "_id": object_id,
                "accountId": account_id,
                "status": {
                    "$in": ["vehicle_dispatched", "vehicle_arriving", "vehicle_arrived"]
                },
            },
            {
                "$set": {"status": "cancelled", "cancelledAt": now, "updatedAt": now},
                "$unset": {"activePassengerId": ""},
            },
            return_document=ReturnDocument.AFTER,
            session=session,
        )
        if item:
            db.autonomous_vehicles.update_one(
                {"_id": item["vehicleId"], "reservedBy": account_id},
                {
                    "$set": {"status": "available", "updatedAt": now},
                    "$unset": {"reservedBy": ""},
                },
                session=session,
            )
        return item

    try:
        with db.client.start_session() as session:
            item = session.with_transaction(cancel)
    except PyMongoError as exc:
        raise unavailable("Could not cancel the autonomous ride atomically") from exc
    if not item:
        raise conflict("This autonomous ride can no longer be cancelled")
    return json_value(item)


def rate_autonomous_ride(
    db: Database[dict[str, Any]],
    user: Mapping[str, Any],
    ride_id: str,
    payload: AutonomousRatingCreate,
) -> dict[str, Any]:
    ride = db.autonomous_rides.find_one(
        {
            "_id": _object_id(ride_id, "Autonomous ride"),
            "accountId": _account_id(user),
            "status": "completed",
        }
    )
    if not ride:
        raise not_found("Completed autonomous ride not found")
    now = utcnow()
    item = db.autonomous_ratings.find_one_and_update(
        {"rideId": ride["_id"]},
        {
            "$set": {
                "score": payload.score,
                "comment": payload.comment,
                "updatedAt": now,
            },
            "$setOnInsert": {"accountId": _account_id(user), "createdAt": now},
        },
        upsert=True,
        return_document=ReturnDocument.AFTER,
    )
    return json_value(item)


def get_carshare_state(
    db: Database[dict[str, Any]], user: Mapping[str, Any]
) -> dict[str, Any]:
    require_role(user, "passenger", "operator", "admin")
    ensure_mobility_seed(db)
    account_id = _account_id(user)
    now = utcnow()
    expired = list(
        db.carshare_bookings.find(
            {"accountId": account_id, "status": "reserved", "expiresAt": {"$lte": now}}
        )
    )
    for booking in expired:

        def expire(session: Any, booking: Mapping[str, Any] = booking) -> None:
            expired_booking = db.carshare_bookings.find_one_and_update(
                {
                    "_id": booking["_id"],
                    "status": "reserved",
                    "expiresAt": {"$lte": now},
                },
                {
                    "$set": {"status": "expired", "updatedAt": now},
                    "$unset": {"activeAccountId": ""},
                },
                return_document=ReturnDocument.AFTER,
                session=session,
            )
            if expired_booking:
                db.carshare_vehicles.update_one(
                    {"_id": booking["vehicleId"], "reservedBy": account_id},
                    {
                        "$set": {"status": "available", "updatedAt": now},
                        "$unset": {"reservedBy": ""},
                    },
                    session=session,
                )

        try:
            with db.client.start_session() as session:
                session.with_transaction(expire)
        except PyMongoError as exc:
            raise unavailable(
                "Could not expire a carshare reservation atomically"
            ) from exc
    profile = db.carshare_customers.find_one({"accountId": account_id})
    active = db.carshare_bookings.find_one(
        {"accountId": account_id, "status": {"$in": ["reserved", "in_progress"]}}
    )
    history = list(
        db.carshare_bookings.find(
            {
                "accountId": account_id,
                "status": {"$in": ["completed", "cancelled", "expired"]},
            }
        )
        .sort("updatedAt", -1)
        .limit(25)
    )
    if active:
        active["vehicle"] = db.carshare_vehicles.find_one({"_id": active["vehicleId"]})
        active["teleDrive"] = _refresh_tele_drive(
            db, db.carshare_teledrives.find_one({"bookingId": active["_id"]})
        )
    return json_value({"profile": profile, "activeBooking": active, "history": history})


def register_carshare(
    db: Database[dict[str, Any]], user: Mapping[str, Any], payload: CarshareRegistration
) -> dict[str, Any]:
    require_role(user, "passenger")
    account_id = _account_id(user)
    now = utcnow()
    document = {
        "accountId": account_id,
        "licenseHash": _sensitive_digest(payload.license_number, account_id, "license"),
        "licenseHashVersion": "hmac-sha256-v1",
        "licenseLast4": payload.license_number[-4:],
        "licenseCountry": payload.license_country,
        "licenseIssueDate": payload.license_issue_date.isoformat(),
        "licenseExpiryDate": payload.license_expiry_date.isoformat(),
        "dateOfBirth": payload.date_of_birth.isoformat(),
        "nationalIdHash": (
            _sensitive_digest(payload.national_id, account_id, "national-id")
            if payload.national_id
            else None
        ),
        "preferredLanguage": payload.preferred_language,
        "verificationStatus": "pending",
        "createdAt": now,
        "updatedAt": now,
    }
    try:
        result = db.carshare_customers.insert_one(document)
    except DuplicateKeyError as exc:
        raise conflict(
            "A carshare profile already exists", "carshare_profile_exists"
        ) from exc
    document["_id"] = result.inserted_id
    return json_value(document)


def list_carshare_vehicles(
    db: Database[dict[str, Any]], user: Mapping[str, Any]
) -> dict[str, Any]:
    require_role(user, "passenger", "operator", "admin")
    ensure_mobility_seed(db)
    zones = {item["_id"]: item for item in db.carshare_zones.find({"active": True})}
    vehicles = []
    for item in db.carshare_vehicles.find({"active": True}).sort("_id", 1):
        item["zone"] = zones.get(item["zoneId"])
        vehicles.append(json_value(item))
    return {
        "vehicles": vehicles,
        "zones": [json_value(item) for item in zones.values()],
    }


def create_carshare_booking(
    db: Database[dict[str, Any]],
    user: Mapping[str, Any],
    payload: CarshareBookingCreate,
) -> dict[str, Any]:
    require_role(user, "passenger")
    account_id = _account_id(user)
    profile = db.carshare_customers.find_one(
        {"accountId": account_id, "verificationStatus": "approved"}
    )
    if not profile:
        raise forbidden("An approved carshare registration is required")
    now = utcnow()

    def reserve_and_create(session: Any) -> dict[str, Any]:
        if db.carshare_bookings.count_documents(
            {
                "accountId": account_id,
                "status": {"$in": ["reserved", "in_progress"]},
            },
            limit=1,
            session=session,
        ):
            raise conflict(
                "You already have an active carshare booking",
                "carshare_booking_active",
            )
        vehicle = db.carshare_vehicles.find_one_and_update(
            {"_id": payload.vehicle_id, "status": "available", "active": True},
            {
                "$set": {
                    "status": "reserved",
                    "reservedBy": account_id,
                    "updatedAt": now,
                }
            },
            return_document=ReturnDocument.AFTER,
            session=session,
        )
        if not vehicle:
            raise conflict(
                "The selected carshare vehicle is no longer available",
                "carshare_vehicle_unavailable",
            )
        booking = {
            "accountId": account_id,
            "activeAccountId": account_id,
            "vehicleId": vehicle["_id"],
            "pricingMode": payload.pricing_mode,
            "status": "reserved",
            "reservedAt": now,
            "expiresAt": now + timedelta(minutes=15),
            "createdAt": now,
            "updatedAt": now,
        }
        result = db.carshare_bookings.insert_one(booking, session=session)
        booking["_id"] = result.inserted_id
        booking["vehicle"] = vehicle
        return booking

    try:
        with db.client.start_session() as session:
            booking = session.with_transaction(reserve_and_create)
    except DuplicateKeyError as exc:
        raise conflict(
            "You already have an active carshare booking",
            "carshare_booking_active",
        ) from exc
    except PyMongoError as exc:
        raise unavailable("Could not reserve the carshare vehicle atomically") from exc
    return json_value(booking)


def start_carshare_rental(
    db: Database[dict[str, Any]], user: Mapping[str, Any], booking_id: str
) -> dict[str, Any]:
    account_id = _account_id(user)
    object_id = _object_id(booking_id, "Booking")
    now = utcnow()

    def start(session: Any) -> dict[str, Any] | None:
        tele_drive = db.carshare_teledrives.find_one(
            {"bookingId": object_id, "status": "in_progress"}, session=session
        )
        if tele_drive:
            raise conflict("Wait for the tele-drive request to arrive before unlocking")
        item = db.carshare_bookings.find_one_and_update(
            {
                "_id": object_id,
                "accountId": account_id,
                "status": "reserved",
                "expiresAt": {"$gt": now},
            },
            {"$set": {"status": "in_progress", "startedAt": now, "updatedAt": now}},
            return_document=ReturnDocument.AFTER,
            session=session,
        )
        if item:
            db.carshare_vehicles.update_one(
                {"_id": item["vehicleId"], "reservedBy": account_id},
                {"$set": {"status": "rented", "updatedAt": now}},
                session=session,
            )
        return item

    try:
        with db.client.start_session() as session:
            item = session.with_transaction(start)
    except PyMongoError as exc:
        raise unavailable("Could not start the carshare rental atomically") from exc
    if not item:
        raise conflict("This carshare reservation cannot be started")
    return json_value(item)


def end_carshare_rental(
    db: Database[dict[str, Any]],
    user: Mapping[str, Any],
    booking_id: str,
    payload: CarshareRentalEnd,
) -> dict[str, Any]:
    account_id = _account_id(user)
    object_id = _object_id(booking_id, "Booking")
    now = utcnow()

    def finish(session: Any) -> dict[str, Any] | None:
        item = db.carshare_bookings.find_one(
            {"_id": object_id, "accountId": account_id, "status": "in_progress"},
            session=session,
        )
        if not item:
            return None
        vehicle = db.carshare_vehicles.find_one(
            {"_id": item["vehicleId"]}, session=session
        )
        if not vehicle:
            raise not_found("Carshare vehicle not found")
        minutes = max(1, math.ceil((now - item["startedAt"]).total_seconds() / 60))
        if item["pricingMode"] == "day":
            amount = math.ceil(minutes / 1440) * float(vehicle["pricePerDay"])
        elif item["pricingMode"] == "hour":
            amount = math.ceil(minutes / 60) * float(vehicle["pricePerHour"])
        else:
            amount = minutes * float(vehicle["pricePerMinute"])
        updated = db.carshare_bookings.find_one_and_update(
            {"_id": item["_id"], "status": "in_progress"},
            {
                "$set": {
                    "status": "completed",
                    "endedAt": now,
                    "durationMinutes": minutes,
                    "amount": round(amount, 2),
                    "paymentStatus": "pending",
                    "dropoff": {
                        "latitude": payload.latitude,
                        "longitude": payload.longitude,
                    },
                    "updatedAt": now,
                },
                "$unset": {"activeAccountId": ""},
            },
            return_document=ReturnDocument.AFTER,
            session=session,
        )
        if updated:
            db.carshare_vehicles.update_one(
                {"_id": item["vehicleId"], "reservedBy": account_id},
                {
                    "$set": {
                        "status": "available",
                        "latitude": payload.latitude,
                        "longitude": payload.longitude,
                        "updatedAt": now,
                    },
                    "$unset": {"reservedBy": ""},
                },
                session=session,
            )
        return updated

    try:
        with db.client.start_session() as session:
            updated = session.with_transaction(finish)
    except PyMongoError as exc:
        raise unavailable("Could not end the carshare rental atomically") from exc
    if not updated:
        raise conflict("No active carshare rental was found")
    return json_value(updated)


def create_tele_drive(
    db: Database[dict[str, Any]],
    user: Mapping[str, Any],
    payload: CarshareTeleDriveCreate,
) -> dict[str, Any]:
    account_id = _account_id(user)
    booking_id = _object_id(payload.booking_id, "Booking")
    now = utcnow()

    def schedule(session: Any) -> dict[str, Any]:
        booking = db.carshare_bookings.find_one_and_update(
            {
                "_id": booking_id,
                "accountId": account_id,
                "status": "reserved",
                "expiresAt": {"$gt": now},
            },
            {"$set": {"teleDriveRequestedAt": now, "updatedAt": now}},
            return_document=ReturnDocument.AFTER,
            session=session,
        )
        if not booking:
            raise conflict("Tele-drive requires an active reservation")
        vehicle = db.carshare_vehicles.find_one(
            {"_id": booking["vehicleId"], "reservedBy": account_id}, session=session
        )
        if not vehicle:
            raise not_found("Carshare vehicle not found")
        item = db.carshare_teledrives.find_one_and_update(
            {"bookingId": booking_id},
            {
                "$setOnInsert": {
                    "accountId": account_id,
                    "vehicleId": vehicle["_id"],
                    "startLatitude": vehicle["latitude"],
                    "startLongitude": vehicle["longitude"],
                    "targetLatitude": payload.target_latitude,
                    "targetLongitude": payload.target_longitude,
                    "speedMultiplier": payload.speed_multiplier,
                    "status": "in_progress",
                    "startedAt": now,
                    "createdAt": now,
                    "updatedAt": now,
                }
            },
            upsert=True,
            return_document=ReturnDocument.AFTER,
            session=session,
        )
        if not item:
            raise unavailable("Could not schedule the tele-drive request")
        return item

    try:
        with db.client.start_session() as session:
            item = session.with_transaction(schedule)
    except PyMongoError as exc:
        raise unavailable("Could not schedule tele-drive atomically") from exc
    return json_value(_refresh_tele_drive(db, item))


def _refresh_tele_drive(
    db: Database[dict[str, Any]], item: Mapping[str, Any] | None
) -> dict[str, Any] | None:
    if not item:
        return None
    if item.get("status") == "arrived":
        db.carshare_vehicles.update_one(
            {"_id": item["vehicleId"]},
            {
                "$set": {
                    "latitude": item["targetLatitude"],
                    "longitude": item["targetLongitude"],
                    "updatedAt": utcnow(),
                }
            },
        )
        return dict(item)
    seconds = (utcnow() - item["startedAt"]).total_seconds() * int(
        item.get("speedMultiplier", 1)
    )
    progress = min(1.0, seconds / 60)
    lat = (
        item["startLatitude"]
        + (item["targetLatitude"] - item["startLatitude"]) * progress
    )
    lon = (
        item["startLongitude"]
        + (item["targetLongitude"] - item["startLongitude"]) * progress
    )
    status = "arrived" if progress >= 1 else "in_progress"
    updated = db.carshare_teledrives.find_one_and_update(
        {"_id": item["_id"]},
        {
            "$set": {
                "status": status,
                "progressPercent": round(progress * 100),
                "currentLatitude": lat,
                "currentLongitude": lon,
                "remainingSeconds": max(
                    0,
                    math.ceil(
                        (60 - seconds) / max(1, int(item.get("speedMultiplier", 1)))
                    ),
                ),
                "updatedAt": utcnow(),
            }
        },
        return_document=ReturnDocument.AFTER,
    )
    if status == "arrived":
        db.carshare_vehicles.update_one(
            {"_id": item["vehicleId"]},
            {
                "$set": {
                    "latitude": item["targetLatitude"],
                    "longitude": item["targetLongitude"],
                    "updatedAt": utcnow(),
                }
            },
        )
    return updated


def list_carshare_customers(
    db: Database[dict[str, Any]], user: Mapping[str, Any]
) -> list[dict[str, Any]]:
    require_role(user, "operator", "admin")
    customers = list(db.carshare_customers.find().sort("createdAt", -1))
    account_ids = [item["accountId"] for item in customers]
    accounts = {
        item["_id"]: item for item in db.accounts.find({"_id": {"$in": account_ids}})
    }
    return [
        json_value(
            {
                **item,
                "account": {
                    "fullName": accounts.get(item["accountId"], {}).get("fullName"),
                    "email": accounts.get(item["accountId"], {}).get("email"),
                },
            }
        )
        for item in customers
    ]


def verify_carshare_customer(
    db: Database[dict[str, Any]], user: Mapping[str, Any], customer_id: str, status: str
) -> dict[str, Any]:
    require_role(user, "operator", "admin")
    if status not in {"pending", "approved", "rejected"}:
        raise bad_request("Invalid carshare verification status")
    item = db.carshare_customers.find_one_and_update(
        {"_id": _object_id(customer_id, "Carshare customer")},
        {
            "$set": {
                "verificationStatus": status,
                "verifiedBy": _account_id(user),
                "updatedAt": utcnow(),
            }
        },
        return_document=ReturnDocument.AFTER,
    )
    if not item:
        raise not_found("Carshare customer not found")
    record_system_log(
        db,
        user,
        "Carshare Verification",
        f"Carshare customer {customer_id} marked {status}",
        status=status,
        reference_type="carshare_customer",
        reference_id=customer_id,
    )
    return json_value(item)
