from __future__ import annotations

from datetime import date, timedelta
from types import SimpleNamespace

import pytest
from bson import ObjectId
from pydantic import ValidationError

from backend.accounts import PASSWORD_HASHER, normalize_email
from backend.mobility import _sensitive_digest, register_carshare
from backend.schemas import (
    CarshareRegistration,
    DriverDocumentUpload,
    DriverRegistration,
    PassengerRegistration,
    SafetyInspectionCreate,
)


def passenger_payload(**overrides: object) -> dict[str, object]:
    payload: dict[str, object] = {
        "fullName": "  Ada   Lovelace  ",
        "email": "Ada@example.com",
        "password": "correct horse battery staple",
        "passwordConfirm": "correct horse battery staple",
    }
    payload.update(overrides)
    return payload


def carshare_payload(**overrides: object) -> dict[str, object]:
    today = date.today()
    payload: dict[str, object] = {
        "licenseNumber": "CY-ABC-1234",
        "licenseCountry": "Cyprus",
        "licenseIssueDate": today - timedelta(days=365),
        "licenseExpiryDate": today + timedelta(days=365),
        "dateOfBirth": today.replace(year=today.year - 25),
        "nationalId": "ID-998877",
        "preferredLanguage": "en",
        "termsAccepted": True,
    }
    payload.update(overrides)
    return payload


def test_email_normalization_and_password_hash_round_trip() -> None:
    assert normalize_email("  Ada.Lovelace@Example.COM ") == "ada.lovelace@example.com"
    encoded = PASSWORD_HASHER.hash("correct horse battery staple")
    assert encoded.startswith("$argon2id$")
    assert PASSWORD_HASHER.verify(encoded, "correct horse battery staple")


def test_passenger_registration_preserves_legacy_fields_and_validates_passwords() -> (
    None
):
    registration = PassengerRegistration.model_validate(passenger_payload())
    assert registration.full_name == "Ada Lovelace"
    assert registration.preferences.location_tracking is True
    with pytest.raises(ValidationError, match="Passwords do not match"):
        PassengerRegistration.model_validate(
            passenger_payload(passwordConfirm="different password")
        )


def test_driver_registration_rejects_underage_driver() -> None:
    today = date.today()
    with pytest.raises(ValidationError, match="at least 18"):
        DriverRegistration.model_validate(
            {
                "fullName": "Young Driver",
                "email": "young@example.com",
                "phone": "+357 99 000000",
                "dateOfBirth": today.replace(year=today.year - 17),
                "password": "driver password",
                "passwordConfirm": "driver password",
                "idCardNumber": "ID-1",
                "licenseNumber": "LIC-1",
            }
        )


@pytest.mark.parametrize(
    ("overrides", "message"),
    [
        ({"termsAccepted": False}, "terms must be accepted"),
        ({"dateOfBirth": date.today()}, "at least 18"),
        ({"licenseExpiryDate": date.today()}, "must not be expired"),
        (
            {"licenseIssueDate": date.today() + timedelta(days=1)},
            "cannot be in the future",
        ),
    ],
)
def test_carshare_registration_validation(
    overrides: dict[str, object], message: str
) -> None:
    with pytest.raises(ValidationError, match=message):
        CarshareRegistration.model_validate(carshare_payload(**overrides))


def test_sensitive_digests_are_keyed_and_purpose_bound() -> None:
    account_id = ObjectId()
    first = _sensitive_digest("CY-ABC-1234", account_id, "license")
    assert first == _sensitive_digest("CY-ABC-1234", account_id, "license")
    assert first != _sensitive_digest("CY-ABC-1234", account_id, "national-id")
    assert first != _sensitive_digest("CY-ABC-1234", ObjectId(), "license")
    assert "CY-ABC-1234" not in first


def test_carshare_registration_stores_no_plain_identifiers_or_bson_dates() -> None:
    inserted: dict[str, object] = {}

    class Collection:
        def insert_one(self, document: dict[str, object]) -> SimpleNamespace:
            inserted.update(document)
            return SimpleNamespace(inserted_id=ObjectId())

    db = SimpleNamespace(carshare_customers=Collection())
    account_id = ObjectId()
    payload = CarshareRegistration.model_validate(carshare_payload())
    response = register_carshare(
        db, {"accountId": account_id, "role": "passenger"}, payload
    )

    assert inserted["licenseHashVersion"] == "hmac-sha256-v1"
    assert inserted["licenseLast4"] == "1234"
    assert inserted["licenseIssueDate"] == payload.license_issue_date.isoformat()
    assert inserted["licenseExpiryDate"] == payload.license_expiry_date.isoformat()
    assert inserted["dateOfBirth"] == payload.date_of_birth.isoformat()
    assert payload.license_number not in str(inserted)
    assert payload.national_id not in str(inserted)
    assert response["verificationStatus"] == "pending"


def test_driver_document_schema_restricts_content_type() -> None:
    document = DriverDocumentUpload.model_validate(
        {
            "documentType": "driver_license",
            "filename": "licence.pdf",
            "contentType": "application/pdf",
            "base64Data": "JVBERi0x",
        }
    )
    assert document.content_type == "application/pdf"
    with pytest.raises(ValidationError):
        DriverDocumentUpload.model_validate(
            {
                "documentType": "driver_license",
                "filename": "licence.svg",
                "contentType": "image/svg+xml",
                "base64Data": "PHN2Zz4=",
            }
        )


def test_operator_safety_schema_and_restored_api_routes() -> None:
    inspection = SafetyInspectionCreate.model_validate(
        {
            "vehicleId": str(ObjectId()),
            "status": "needs_followup",
            "notes": "Recheck tyre wear.",
        }
    )
    assert inspection.status == "needs_followup"

    from api.index import app

    routes = {route.path for route in app.routes}
    assert {
        "/api/v1/operator/operations",
        "/api/v1/operator/safety-inspections",
        "/api/v1/operator/system-logs",
        "/api/v1/operator/data",
        "/api/v1/operator/fleet",
        "/api/v1/operator/autonomous/vehicles/{vehicle_id}",
    } <= routes
