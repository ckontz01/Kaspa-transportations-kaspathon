from __future__ import annotations

from dataclasses import asdict, dataclass, replace
from typing import Any, Mapping, Sequence

from kaspa import PublicKey, ScriptBuilder, ScriptPublicKey, address_from_script_public_key

from backend.ride_escrow_artifact import (
    BYTECODE_HEX,
    ENTRIES,
    STATE_LENGTH,
    STATE_OFFSET,
    TEMPLATE_HASH_HEX,
)
from backend.security import normalize_hex


ZERO_HASH = "00" * 32


@dataclass(frozen=True)
class RideEscrowState:
    passenger_key_hash: str
    resolver_key_hash: str
    ride_commitment: str
    refund_after_daa: int
    quoted_fare_sompi: int
    driver_key_hash: str = ZERO_HASH
    phase: int = 0

    def validated(self) -> "RideEscrowState":
        for name in (
            "passenger_key_hash",
            "resolver_key_hash",
            "ride_commitment",
            "driver_key_hash",
        ):
            normalize_hex(getattr(self, name), expected_bytes=32)
        if not 0 < self.refund_after_daa < 2**63:
            raise ValueError("refund_after_daa must be a positive signed 64-bit integer")
        if not 0 < self.quoted_fare_sompi < 2**63:
            raise ValueError("quoted_fare_sompi must be a positive signed 64-bit integer")
        if self.phase not in (0, 1):
            raise ValueError("phase must be 0 or 1")
        if self.phase == 0 and self.driver_key_hash != ZERO_HASH:
            raise ValueError("unaccepted state must not pin a driver")
        if self.phase == 1 and self.driver_key_hash == ZERO_HASH:
            raise ValueError("accepted state must pin a driver")
        if self.passenger_key_hash == self.resolver_key_hash:
            raise ValueError("passenger and resolver keys must be distinct")
        return self

    def accepted(self, driver_key_hash: str) -> "RideEscrowState":
        return replace(self, driver_key_hash=driver_key_hash, phase=1).validated()

    def to_document(self) -> dict[str, Any]:
        return asdict(self)


def _fixed_push(raw: bytes) -> bytes:
    if len(raw) > 75:
        raise ValueError("state field is too large for an explicit direct push")
    return bytes([len(raw)]) + raw


def _fixed_i64_push(value: int) -> bytes:
    if not -(2**63) <= value < 2**63:
        raise ValueError("integer is outside the signed 64-bit range")
    return _fixed_push(value.to_bytes(8, byteorder="little", signed=True))


def encode_state(state: RideEscrowState) -> bytes:
    state.validated()
    encoded = b"".join(
        (
            _fixed_push(bytes.fromhex(state.passenger_key_hash)),
            _fixed_push(bytes.fromhex(state.resolver_key_hash)),
            _fixed_push(bytes.fromhex(state.ride_commitment)),
            _fixed_i64_push(state.refund_after_daa),
            _fixed_i64_push(state.quoted_fare_sompi),
            _fixed_push(bytes.fromhex(state.driver_key_hash)),
            _fixed_i64_push(state.phase),
        )
    )
    if len(encoded) != STATE_LENGTH:
        raise AssertionError(f"encoded state is {len(encoded)} bytes, expected {STATE_LENGTH}")
    return encoded


def materialize_redeem_script(state: RideEscrowState) -> bytes:
    template = bytes.fromhex(BYTECODE_HEX)
    prefix = template[:STATE_OFFSET]
    suffix = template[STATE_OFFSET + STATE_LENGTH :]
    return prefix + encode_state(state) + suffix


def script_public_key(state: RideEscrowState) -> ScriptPublicKey:
    redeem = materialize_redeem_script(state)
    return ScriptBuilder.from_script(
        redeem, covenants_enabled=True
    ).create_pay_to_script_hash_script()


def covenant_address(state: RideEscrowState, network_type: str) -> str:
    return address_from_script_public_key(script_public_key(state), network_type).to_string()


def x_only_public_key(public_key_hex: str) -> str:
    return PublicKey(normalize_hex(public_key_hex)).to_x_only_public_key().to_string().lower()


def build_entry_signature_script(
    state: RideEscrowState,
    entry_name: str,
    arguments: Mapping[str, Any] | Sequence[Any],
) -> bytes:
    entry = ENTRIES.get(entry_name)
    if entry is None:
        raise ValueError(f"unknown RideEscrow entry: {entry_name}")
    params = entry["params"]
    if isinstance(arguments, Mapping):
        try:
            values = [arguments[name] for name, _ in params]
        except KeyError as exc:
            raise ValueError(f"missing entry argument: {exc.args[0]}") from exc
    else:
        values = list(arguments)
    if len(values) != len(params):
        raise ValueError(f"{entry_name} expects {len(params)} arguments")

    builder = ScriptBuilder(covenants_enabled=True)
    for value, (name, type_info) in zip(values, params, strict=True):
        kind = type_info["kind"]
        if kind == "int":
            if isinstance(value, bool) or not isinstance(value, int):
                raise ValueError(f"{name} must be an integer")
            builder.add_i64(value)
        elif kind == "pubkey":
            builder.add_data(bytes.fromhex(x_only_public_key(str(value))))
        elif kind == "fixed_bytes":
            builder.add_data(
                bytes.fromhex(normalize_hex(str(value), expected_bytes=type_info["len"]))
            )
        else:
            raise ValueError(f"unsupported SilverScript ABI type: {kind}")
    builder.add_data(bytes.fromhex(entry["dispatch_tag"]))
    builder.add_data(materialize_redeem_script(state))
    return bytes.fromhex(builder.to_string())


def protocol_metadata() -> dict[str, Any]:
    return {
        "contract": "RideEscrow",
        "compilerVersion": "0.1.0",
        "templateHash": TEMPLATE_HASH_HEX,
        "entries": {name: item["dispatch_tag"] for name, item in ENTRIES.items()},
        "stateBytes": STATE_LENGTH,
    }
