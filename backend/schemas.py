from __future__ import annotations

from datetime import date
from typing import Literal

from pydantic import (
    BaseModel,
    ConfigDict,
    EmailStr,
    Field,
    field_validator,
    model_validator,
)


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)


class WalletAuthRequest(StrictModel):
    address: str = Field(min_length=20, max_length=140)
    public_key: str = Field(alias="publicKey", min_length=64, max_length=68)
    network: str = Field(min_length=3, max_length=32)


class WalletVerifyRequest(WalletAuthRequest):
    challenge_id: str = Field(alias="challengeId", min_length=24, max_length=64)
    signature: str = Field(min_length=128, max_length=132)


class PrivacyPreferences(StrictModel):
    location_tracking: bool = Field(default=True, alias="locationTracking")
    notifications: bool = True
    email_updates: bool = Field(default=True, alias="emailUpdates")
    data_sharing: bool = Field(default=False, alias="dataSharing")


class PassengerRegistration(StrictModel):
    full_name: str = Field(alias="fullName", min_length=2, max_length=200)
    email: EmailStr
    phone: str | None = Field(default=None, max_length=30)
    street_address: str | None = Field(
        default=None, alias="streetAddress", max_length=255
    )
    city: str | None = Field(default=None, max_length=100)
    postal_code: str | None = Field(default=None, alias="postalCode", max_length=20)
    country: str = Field(default="Cyprus", min_length=2, max_length=100)
    password: str = Field(min_length=8, max_length=128)
    password_confirm: str = Field(alias="passwordConfirm", min_length=8, max_length=128)
    preferences: PrivacyPreferences = Field(default_factory=PrivacyPreferences)

    @field_validator("full_name")
    @classmethod
    def clean_full_name(cls, value: str) -> str:
        return " ".join(value.split())

    @model_validator(mode="after")
    def passwords_match(self) -> "PassengerRegistration":
        if self.password != self.password_confirm:
            raise ValueError("Passwords do not match")
        return self


class DriverRegistration(StrictModel):
    full_name: str = Field(alias="fullName", min_length=2, max_length=200)
    email: EmailStr
    phone: str = Field(min_length=5, max_length=30)
    date_of_birth: date = Field(alias="dateOfBirth")
    password: str = Field(min_length=8, max_length=128)
    password_confirm: str = Field(alias="passwordConfirm", min_length=8, max_length=128)
    id_card_number: str = Field(alias="idCardNumber", min_length=3, max_length=100)
    license_number: str = Field(alias="licenseNumber", min_length=3, max_length=100)

    @field_validator("full_name")
    @classmethod
    def clean_full_name(cls, value: str) -> str:
        return " ".join(value.split())

    @model_validator(mode="after")
    def validate_driver(self) -> "DriverRegistration":
        if self.password != self.password_confirm:
            raise ValueError("Passwords do not match")
        today = date.today()
        age = (
            today.year
            - self.date_of_birth.year
            - (
                (today.month, today.day)
                < (self.date_of_birth.month, self.date_of_birth.day)
            )
        )
        if age < 18:
            raise ValueError("Drivers must be at least 18 years old")
        return self


class AccountLogin(StrictModel):
    email: EmailStr
    password: str = Field(min_length=1, max_length=128)


class ProfileUpdate(StrictModel):
    full_name: str = Field(alias="fullName", min_length=2, max_length=200)
    phone: str | None = Field(default=None, max_length=30)
    street_address: str | None = Field(
        default=None, alias="streetAddress", max_length=255
    )
    city: str | None = Field(default=None, max_length=100)
    postal_code: str | None = Field(default=None, alias="postalCode", max_length=20)
    country: str | None = Field(default=None, max_length=100)

    @field_validator("full_name")
    @classmethod
    def clean_full_name(cls, value: str) -> str:
        return " ".join(value.split())


class PasswordChange(StrictModel):
    current_password: str = Field(alias="currentPassword", min_length=1, max_length=128)
    new_password: str = Field(alias="newPassword", min_length=8, max_length=128)
    new_password_confirm: str = Field(
        alias="newPasswordConfirm", min_length=8, max_length=128
    )

    @model_validator(mode="after")
    def passwords_match(self) -> "PasswordChange":
        if self.new_password != self.new_password_confirm:
            raise ValueError("New passwords do not match")
        return self


class PreferencesUpdate(PrivacyPreferences):
    pass


class DriverAvailability(StrictModel):
    available: bool
    vehicle_id: str | None = Field(
        default=None, alias="vehicleId", min_length=24, max_length=24
    )
    latitude: float | None = Field(default=None, ge=-90, le=90)
    longitude: float | None = Field(default=None, ge=-180, le=180)
    use_gps: bool = Field(default=False, alias="useGps")

    @model_validator(mode="after")
    def online_fields(self) -> "DriverAvailability":
        if self.available and (self.latitude is None or self.longitude is None):
            raise ValueError("An online driver must provide a current location")
        return self


class DriverVerificationUpdate(StrictModel):
    status: Literal["pending", "approved", "rejected"]


class DriverDocumentUpload(StrictModel):
    document_type: Literal["id_card", "driver_license"] = Field(alias="documentType")
    filename: str = Field(min_length=1, max_length=180)
    content_type: Literal[
        "image/jpeg", "image/png", "image/webp", "application/pdf"
    ] = Field(alias="contentType")
    base64_data: str = Field(alias="base64Data", min_length=8, max_length=7_500_000)


class DriverDocumentVerification(StrictModel):
    status: Literal["pending", "approved", "rejected"]


class SafetyInspectionCreate(StrictModel):
    vehicle_id: str = Field(alias="vehicleId", min_length=24, max_length=24)
    status: Literal["passed", "failed", "needs_followup"]
    notes: str | None = Field(default=None, max_length=1_000)


class AutonomousVehicleStatusUpdate(StrictModel):
    status: Literal["available", "offline", "maintenance", "charging"]


class VehicleCreate(StrictModel):
    vehicle_type: str = Field(alias="vehicleType", min_length=2, max_length=60)
    plate_number: str = Field(alias="plateNumber", min_length=2, max_length=20)
    make: str = Field(min_length=1, max_length=100)
    model: str = Field(min_length=1, max_length=100)
    year: int = Field(ge=1980, le=2100)
    color: str | None = Field(default=None, max_length=50)
    seating_capacity: int = Field(default=4, alias="seatingCapacity", ge=1, le=20)
    wheelchair_ready: bool = Field(default=False, alias="wheelchairReady")


class MessageCreate(StrictModel):
    recipient_id: str = Field(alias="recipientId", min_length=24, max_length=24)
    content: str = Field(min_length=1, max_length=2_000)


class RideRatingCreate(StrictModel):
    score: int = Field(ge=1, le=5)
    comment: str | None = Field(default=None, max_length=500)


class GdprRequestCreate(StrictModel):
    request_type: Literal["access", "rectification", "erasure", "restriction"] = Field(
        alias="requestType"
    )
    notes: str | None = Field(default=None, max_length=1_000)


class GdprRequestReview(StrictModel):
    status: Literal["submitted", "in_progress", "completed", "rejected"]
    response: str | None = Field(default=None, max_length=1_000)


class Location(StrictModel):
    label: str = Field(min_length=2, max_length=180)
    latitude: float = Field(ge=-90, le=90)
    longitude: float = Field(ge=-180, le=180)


class AutonomousRideCreate(StrictModel):
    pickup: Location
    dropoff: Location
    pickup_description: str | None = Field(
        default=None, alias="pickupDescription", max_length=255
    )
    dropoff_description: str | None = Field(
        default=None, alias="dropoffDescription", max_length=255
    )
    payment_method: Literal["kaspa", "card"] = Field(
        default="kaspa", alias="paymentMethod"
    )
    notes: str | None = Field(default=None, max_length=500)


class AutonomousRatingCreate(RideRatingCreate):
    pass


class CarshareRegistration(StrictModel):
    license_number: str = Field(alias="licenseNumber", min_length=3, max_length=100)
    license_country: str = Field(alias="licenseCountry", min_length=2, max_length=100)
    license_issue_date: date = Field(alias="licenseIssueDate")
    license_expiry_date: date = Field(alias="licenseExpiryDate")
    date_of_birth: date = Field(alias="dateOfBirth")
    national_id: str | None = Field(default=None, alias="nationalId", max_length=100)
    preferred_language: Literal["en", "el", "tr"] = Field(
        default="en", alias="preferredLanguage"
    )
    terms_accepted: bool = Field(alias="termsAccepted")

    @model_validator(mode="after")
    def valid_carshare_registration(self) -> "CarshareRegistration":
        if not self.terms_accepted:
            raise ValueError("Carshare terms must be accepted")
        today = date.today()
        age = (
            today.year
            - self.date_of_birth.year
            - (
                (today.month, today.day)
                < (self.date_of_birth.month, self.date_of_birth.day)
            )
        )
        if age < 18:
            raise ValueError("You must be at least 18 years old to use car-sharing")
        if self.license_expiry_date <= date.today():
            raise ValueError("Driver licence must not be expired")
        if self.license_issue_date > today:
            raise ValueError("Licence issue date cannot be in the future")
        if self.license_issue_date >= self.license_expiry_date:
            raise ValueError("Licence issue date must precede expiry date")
        return self


class CarshareBookingCreate(StrictModel):
    vehicle_id: str = Field(alias="vehicleId", min_length=2, max_length=40)
    pricing_mode: Literal["minute", "hour", "day"] = Field(
        default="minute", alias="pricingMode"
    )


class CarshareRentalEnd(StrictModel):
    latitude: float = Field(ge=-90, le=90)
    longitude: float = Field(ge=-180, le=180)


class CarshareTeleDriveCreate(StrictModel):
    booking_id: str = Field(alias="bookingId", min_length=24, max_length=24)
    target_latitude: float = Field(alias="targetLatitude", ge=-90, le=90)
    target_longitude: float = Field(alias="targetLongitude", ge=-180, le=180)
    speed_multiplier: int = Field(default=1, alias="speedMultiplier", ge=1, le=50)


class QuoteRequest(StrictModel):
    pickup: Location
    dropoff: Location
    service_type: Literal["standard", "comfort", "accessible", "cargo"] = Field(
        default="standard", alias="serviceType"
    )
    luggage_volume: float | None = Field(
        default=None, alias="luggageVolume", ge=0, le=20
    )
    wheelchair_needed: bool = Field(default=False, alias="wheelchairNeeded")
    passenger_notes: str | None = Field(
        default=None, alias="passengerNotes", max_length=500
    )
    use_simulation: bool = Field(default=False, alias="useSimulation")


class CreateRideRequest(StrictModel):
    quote_id: str = Field(alias="quoteId", min_length=24, max_length=24)


class DraftSubmission(StrictModel):
    signed_transaction_json: str = Field(
        alias="signedTransactionJson", min_length=2, max_length=1_000_000
    )


class RideVersionRequest(StrictModel):
    version: int = Field(ge=0)


class StartRideRequest(RideVersionRequest):
    pass


class RidePlanRequest(RideVersionRequest):
    pass


class TimeoutRefundRequest(RideVersionRequest):
    pass


class DisplayNameRequest(StrictModel):
    display_name: str = Field(alias="displayName", min_length=2, max_length=60)

    @field_validator("display_name")
    @classmethod
    def clean_display_name(cls, value: str) -> str:
        return " ".join(value.split())
