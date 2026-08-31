from __future__ import annotations

import json
from pathlib import Path

import pytest
from kaspa import PrivateKey

from backend.covenant import (
    RideEscrowState,
    ZERO_HASH,
    build_entry_signature_script,
    covenant_address,
    encode_state,
    materialize_redeem_script,
    protocol_metadata,
)


ROOT = Path(__file__).resolve().parents[2]
ARTIFACT = json.loads(
    (ROOT / "contracts" / "artifacts" / "ride-escrow.json").read_text(encoding="utf-8")
)


def fixture_state() -> RideEscrowState:
    return RideEscrowState(
        passenger_key_hash="01" * 32,
        resolver_key_hash="02" * 32,
        ride_commitment="03" * 32,
        refund_after_daa=216_000,
        quoted_fare_sompi=100_000_000,
    )


def test_materialized_fixture_is_byte_exact_silverc_artifact() -> None:
    compiled = bytes(ARTIFACT["contracts"]["RideEscrow"]["compiled"]["bytecode"])
    assert materialize_redeem_script(fixture_state()) == compiled
    assert len(encode_state(fixture_state())) == 159


def test_dynamic_state_preserves_the_audited_template() -> None:
    fixture = materialize_redeem_script(fixture_state())
    changed = materialize_redeem_script(
        RideEscrowState(
            passenger_key_hash="11" * 32,
            resolver_key_hash="22" * 32,
            ride_commitment="33" * 32,
            refund_after_daa=43_200,
            quoted_fare_sompi=987_654_321,
        )
    )
    assert fixture[:1] == changed[:1]
    assert fixture[160:] == changed[160:]
    assert fixture[1:160] != changed[1:160]
    assert protocol_metadata()["templateHash"] == (
        "8aa2a011dcb03332d2b42e3d201d54c6586a5d41f62b70e622772581dca4f3fe"
    )


def test_accept_unlocking_call_matches_official_rust_abi_vector() -> None:
    passenger = PrivateKey("01" * 32).to_public_key().to_string()
    driver = PrivateKey("02" * 32).to_public_key().to_string()
    signature_script = build_entry_signature_script(
        fixture_state(),
        "accept",
        {
            "supplied_ride": "03" * 32,
            "passenger": passenger,
            "passenger_input_index": 1,
            "selected_driver": driver,
            "driver_input_index": 2,
        },
    )
    official_abi_call = bytes.fromhex(
        "200303030303030303030303030303030303030303030303030303030303030303"
        "201b84c5567b126440995d3ed5aaba0565d71e1834604819ff9c17f5e9d5dd078f"
        "51"
        "204d4b6cd1361032ca9bd2aeb9d900aa4d45d9ead80ac9423374c451a7254d0766"
        "52"
        "0453b984a5"
    )
    assert signature_script.startswith(official_abi_call)
    assert signature_script.endswith(materialize_redeem_script(fixture_state()))


def test_state_rejects_invalid_participant_and_phase_combinations() -> None:
    with pytest.raises(ValueError, match="distinct"):
        RideEscrowState(
            passenger_key_hash="01" * 32,
            resolver_key_hash="01" * 32,
            ride_commitment="03" * 32,
            refund_after_daa=1,
            quoted_fare_sompi=1,
        ).validated()
    with pytest.raises(ValueError, match="must pin a driver"):
        RideEscrowState(
            passenger_key_hash="01" * 32,
            resolver_key_hash="02" * 32,
            ride_commitment="03" * 32,
            refund_after_daa=1,
            quoted_fare_sompi=1,
            driver_key_hash=ZERO_HASH,
            phase=1,
        ).validated()


def test_contract_address_is_network_scoped() -> None:
    assert covenant_address(fixture_state(), "testnet").startswith("kaspatest:")
    assert covenant_address(fixture_state(), "mainnet").startswith("kaspa:")
