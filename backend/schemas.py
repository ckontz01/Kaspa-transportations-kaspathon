from __future__ import annotations

from pydantic import BaseModel, ConfigDict, Field, field_validator


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)


class WalletAuthRequest(StrictModel):
    address: str = Field(min_length=20, max_length=140)
    public_key: str = Field(alias="publicKey", min_length=64, max_length=68)
    network: str = Field(min_length=3, max_length=32)


class WalletVerifyRequest(WalletAuthRequest):
    challenge_id: str = Field(alias="challengeId", min_length=24, max_length=64)
    signature: str = Field(min_length=128, max_length=132)


class Location(StrictModel):
    label: str = Field(min_length=2, max_length=180)
    latitude: float = Field(ge=-90, le=90)
    longitude: float = Field(ge=-180, le=180)


class QuoteRequest(StrictModel):
    pickup: Location
    dropoff: Location


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
