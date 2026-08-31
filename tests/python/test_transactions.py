from __future__ import annotations

import hashlib
import json

from kaspa import Address, PrivateKey, pay_to_address_script

from backend.covenant import RideEscrowState, covenant_address, script_public_key
from backend.security import wallet_identity
from backend.transactions import (
    Signer,
    build_accept_transaction,
    build_funding_transaction,
    build_terminal_transaction,
    transaction_fingerprint,
)


def regular_utxo(address: str, transaction_byte: str, amount: int) -> dict:
    return {
        "address": address,
        "outpoint": {"transactionId": transaction_byte * 32, "index": 0},
        "utxoEntry": {
            "amount": amount,
            "scriptPublicKey": {
                "version": 0,
                "script": pay_to_address_script(Address(address)).script,
            },
            "blockDaaScore": 1,
            "isCoinbase": False,
            "covenantId": None,
        },
    }


def identities():
    passenger_key = PrivateKey("01" * 32).to_public_key()
    driver_key = PrivateKey("02" * 32).to_public_key()
    resolver_key = PrivateKey("03" * 32).to_public_key()
    passenger = wallet_identity(
        passenger_key.to_address("testnet").to_string(), passenger_key.to_string(), "testnet"
    )
    driver = wallet_identity(
        driver_key.to_address("testnet").to_string(), driver_key.to_string(), "testnet"
    )
    resolver = wallet_identity(
        resolver_key.to_address("testnet").to_string(), resolver_key.to_string(), "testnet"
    )
    return passenger, driver, resolver


def state() -> RideEscrowState:
    passenger, _, resolver = identities()
    return RideEscrowState(
        passenger_key_hash=passenger.public_key_hash,
        resolver_key_hash=resolver.public_key_hash,
        ride_commitment=hashlib.blake2b(b"ride-1", digest_size=32).hexdigest(),
        refund_after_daa=43_200,
        quoted_fare_sompi=200_000_000,
    )


def test_funding_builds_v1_genesis_covenant_and_exact_fare() -> None:
    passenger, _, _ = identities()
    built = build_funding_transaction(
        state=state(),
        passenger_address=passenger.address,
        passenger_utxos=[regular_utxo(passenger.address, "11", 250_000_000)],
        network_id="testnet-10",
        network_type="testnet",
        fee_rate=1,
    )
    document = built.transaction.to_dict()
    assert document["version"] == 1
    assert document["outputs"][0]["value"] == 200_000_000
    assert document["outputs"][0]["covenant"]["authorizingInput"] == 0
    assert built.signers == ((passenger.address, (0,)),)
    assert built.covenant_address == covenant_address(state(), "testnet")


def test_accept_preserves_escrow_value_and_covenant_id() -> None:
    passenger, driver, _ = identities()
    current = state()
    covenant_id = "ab" * 32
    escrow = {
        "address": covenant_address(current, "testnet"),
        "outpoint": {"transactionId": "44" * 32, "index": 0},
        "utxoEntry": {
            "amount": current.quoted_fare_sompi,
            "scriptPublicKey": {
                "version": 0,
                "script": script_public_key(current).script,
            },
            "blockDaaScore": 10,
            "isCoinbase": False,
            "covenantId": covenant_id,
        },
    }
    built = build_accept_transaction(
        current_state=current,
        escrow_utxo=escrow,
        covenant_id=covenant_id,
        passenger=Signer(
            passenger.address,
            passenger.public_key,
            regular_utxo(passenger.address, "55", 5_000_000),
        ),
        driver=Signer(
            driver.address,
            driver.public_key,
            regular_utxo(driver.address, "66", 5_000_000),
        ),
        driver_key_hash=driver.public_key_hash,
        network_id="testnet-10",
        network_type="testnet",
        fee_rate=1,
        compute_budget=3_000,
    )
    document = built.transaction.to_dict()
    assert document["outputs"][0]["value"] == current.quoted_fare_sompi
    assert document["outputs"][0]["covenant"]["covenantId"] == covenant_id
    assert document["inputs"][0]["signatureScript"]
    assert built.signers == ((passenger.address, (1,)), (driver.address, (2,)))


def test_terminal_settlement_has_no_successor_and_is_tamper_evident() -> None:
    passenger, driver, _ = identities()
    current = state().accepted(driver.public_key_hash)
    escrow = {
        "address": covenant_address(current, "testnet"),
        "outpoint": {"transactionId": "77" * 32, "index": 0},
        "utxoEntry": {
            "amount": current.quoted_fare_sompi,
            "scriptPublicKey": {
                "version": 0,
                "script": script_public_key(current).script,
            },
            "blockDaaScore": 20,
            "isCoinbase": False,
            "covenantId": "ab" * 32,
        },
    }
    built = build_terminal_transaction(
        action="settle",
        entry_name="settle",
        entry_arguments={
            "supplied_ride": current.ride_commitment,
            "passenger": passenger.public_key,
            "passenger_input_index": 1,
            "selected_driver": driver.public_key,
            "driver_input_index": 2,
            "payout_output_index": 0,
        },
        current_state=current,
        escrow_utxo=escrow,
        covenant_id="ab" * 32,
        signers=(
            Signer(
                passenger.address,
                passenger.public_key,
                regular_utxo(passenger.address, "88", 5_000_000),
            ),
            Signer(
                driver.address,
                driver.public_key,
                regular_utxo(driver.address, "99", 5_000_000),
            ),
        ),
        beneficiary_address=driver.address,
        network_id="testnet-10",
        network_type="testnet",
        fee_rate=1,
        compute_budget=3_000,
    )
    document = built.transaction.to_dict()
    assert document["outputs"][0]["value"] == current.quoted_fare_sompi
    assert document["outputs"][0]["covenant"] is None

    safe_json = built.to_safe_json()
    allowed = [1, 2]
    fingerprint = transaction_fingerprint(safe_json, allowed)
    signed = json.loads(safe_json)
    signed["inputs"][1]["signatureScript"] = "aa"
    signed["inputs"][2]["signatureScript"] = "bb"
    assert transaction_fingerprint(json.dumps(signed), allowed) == fingerprint
    signed["outputs"][0]["value"] -= 1
    assert transaction_fingerprint(json.dumps(signed), allowed) != fingerprint
