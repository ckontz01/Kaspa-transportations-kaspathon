from __future__ import annotations

import copy
import hashlib
import json
import math
from contextlib import asynccontextmanager
from dataclasses import dataclass
from typing import Any, AsyncIterator, Iterable, Mapping, Sequence

from kaspa import (
    Address,
    CovenantBinding,
    GenesisCovenantGroup,
    Hash,
    Resolver,
    RpcClient,
    Transaction,
    TransactionInput,
    TransactionOutput,
    UtxoEntryReference,
    calculate_transaction_mass,
    pay_to_address_script,
)

from backend.covenant import (
    RideEscrowState,
    build_entry_signature_script,
    covenant_address,
    script_public_key,
)
from backend.settings import Settings


TX_VERSION = 1
SUBNETWORK_ID = bytes(20)
COMPUTE_BUDGET_MASS = 100
FEE_MASS_SLACK = 400
MIN_CHANGE_SOMPI = 1_000
MAX_SAFE_JSON_BYTES = 1_000_000


@dataclass(frozen=True)
class Signer:
    address: str
    public_key: str
    utxo: dict[str, Any]


@dataclass(frozen=True)
class BuiltTransaction:
    transaction: Transaction
    signers: tuple[tuple[str, tuple[int, ...]], ...]
    action: str
    covenant_output_index: int | None
    covenant_address: str | None

    def to_safe_json(self) -> str:
        return safe_transaction_json(self.transaction)

    def signers_document(self) -> list[dict[str, Any]]:
        return [
            {
                "address": address,
                "inputIndices": list(indices),
                "sighashType": 1,
            }
            for address, indices in self.signers
        ]


@asynccontextmanager
async def rpc_client(settings: Settings) -> AsyncIterator[RpcClient]:
    if settings.kaspa_rpc_url:
        client = RpcClient(url=settings.kaspa_rpc_url, network_id=settings.kaspa_network)
    else:
        client = RpcClient(
            resolver=Resolver(tls=True),
            network_id=settings.kaspa_network,
        )
    await client.connect(strategy="fallback", timeout_duration=10_000)
    try:
        yield client
    finally:
        await client.disconnect()


async def fetch_fee_rate(client: RpcClient) -> int:
    result = await client.get_fee_estimate()
    raw = result["estimate"]["priorityBucket"]["feerate"]
    return max(1, math.ceil(float(raw)))


async def fetch_utxos(client: RpcClient, address: str) -> list[dict[str, Any]]:
    result = await client.get_utxos_by_addresses({"addresses": [Address(address)]})
    return [normalize_utxo(item, address) for item in result.get("entries", [])]


async def submit_transaction(client: RpcClient, safe_json: str) -> str:
    transaction = transaction_from_safe_json(safe_json)
    result = await client.submit_transaction(
        {"transaction": transaction, "allowOrphan": False}
    )
    return str(result["transactionId"])


def normalize_utxo(item: Mapping[str, Any], address: str | None = None) -> dict[str, Any]:
    normalized = copy.deepcopy(dict(item))
    if "utxoEntry" not in normalized:
        normalized = {
            "address": normalized.get("address") or address,
            "outpoint": normalized["outpoint"],
            "utxoEntry": {
                "amount": normalized["amount"],
                "scriptPublicKey": normalized["scriptPublicKey"],
                "blockDaaScore": normalized.get("blockDaaScore", 0),
                "isCoinbase": normalized.get("isCoinbase", False),
                "covenantId": normalized.get("covenantId"),
            },
        }
    normalized["address"] = normalized.get("address") or address
    normalized["utxoEntry"].setdefault("blockDaaScore", 0)
    normalized["utxoEntry"].setdefault("isCoinbase", False)
    normalized["utxoEntry"].setdefault("covenantId", None)
    return normalized


def outpoint_key(utxo: Mapping[str, Any]) -> str:
    outpoint = utxo["outpoint"]
    return f'{outpoint["transactionId"]}:{outpoint["index"]}'


def select_regular_utxos(
    utxos: Iterable[dict[str, Any]], minimum_total: int
) -> list[dict[str, Any]]:
    regular = [
        normalize_utxo(item)
        for item in utxos
        if not normalize_utxo(item)["utxoEntry"].get("covenantId")
        and not normalize_utxo(item)["utxoEntry"].get("isCoinbase")
    ]
    regular.sort(key=lambda item: int(item["utxoEntry"]["amount"]), reverse=True)
    selected: list[dict[str, Any]] = []
    total = 0
    for item in regular:
        selected.append(item)
        total += int(item["utxoEntry"]["amount"])
        if total >= minimum_total:
            return selected
    raise ValueError("wallet does not have enough mature non-covenant UTXOs")


def select_auth_utxo(utxos: Iterable[dict[str, Any]], address: str) -> dict[str, Any]:
    selected = select_regular_utxos(utxos, MIN_CHANGE_SOMPI)[0]
    expected = pay_to_address_script(Address(address))
    reference = UtxoEntryReference.from_dict(selected)
    if reference.script_public_key != expected:
        raise ValueError("authorization UTXO is not locked to the authenticated address")
    return selected


def _input_from_utxo(
    utxo: Mapping[str, Any],
    *,
    signature_script: bytes = b"",
    sig_op_count: int = 1,
    compute_budget: int = 0,
) -> TransactionInput:
    reference = UtxoEntryReference.from_dict(normalize_utxo(utxo))
    return TransactionInput(
        reference.outpoint,
        signature_script,
        sequence=0,
        sig_op_count=sig_op_count,
        compute_budget=compute_budget,
        utxo=reference,
    )


def _new_transaction(
    inputs: Sequence[TransactionInput], outputs: Sequence[TransactionOutput], mass: int = 0
) -> Transaction:
    return Transaction(
        TX_VERSION,
        inputs,
        outputs,
        lock_time=0,
        subnetwork_id=SUBNETWORK_ID,
        gas=0,
        payload=b"",
        mass=mass,
    )


def _fee_for(
    transaction: Transaction,
    *,
    network_id: str,
    fee_rate: int,
    priority_fee: int,
) -> tuple[int, int]:
    mass = calculate_transaction_mass(network_id, transaction)
    compute_mass = sum(item.compute_budget for item in transaction.inputs) * COMPUTE_BUDGET_MASS
    fee = (mass + compute_mass + FEE_MASS_SLACK) * fee_rate + priority_fee
    return mass, fee


def build_funding_transaction(
    *,
    state: RideEscrowState,
    passenger_address: str,
    passenger_utxos: Sequence[dict[str, Any]],
    network_id: str,
    network_type: str,
    fee_rate: int,
    priority_fee: int = 0,
) -> BuiltTransaction:
    reserve = max(1_000_000, priority_fee + 100_000)
    selected = select_regular_utxos(
        passenger_utxos, state.quoted_fare_sompi + reserve
    )
    total = sum(int(item["utxoEntry"]["amount"]) for item in selected)
    inputs = [_input_from_utxo(item) for item in selected]
    change_script = pay_to_address_script(Address(passenger_address))
    escrow_script = script_public_key(state)
    fee = 0
    mass = 0
    for _ in range(8):
        change = total - state.quoted_fare_sompi - fee
        if change < MIN_CHANGE_SOMPI:
            raise ValueError("wallet UTXOs cannot cover the escrow amount and network fee")
        outputs = [
            TransactionOutput(state.quoted_fare_sompi, escrow_script),
            TransactionOutput(change, change_script),
        ]
        draft = _new_transaction(inputs, outputs)
        draft.populate_genesis_covenants(
            [GenesisCovenantGroup(authorizing_input=0, outputs=[0])]
        )
        new_mass, new_fee = _fee_for(
            draft,
            network_id=network_id,
            fee_rate=fee_rate,
            priority_fee=priority_fee,
        )
        mass = new_mass
        if new_fee == fee:
            break
        fee = new_fee
    final_change = total - state.quoted_fare_sompi - fee
    transaction = _new_transaction(
        inputs,
        [
            TransactionOutput(state.quoted_fare_sompi, escrow_script),
            TransactionOutput(final_change, change_script),
        ],
        mass=mass,
    )
    transaction.populate_genesis_covenants(
        [GenesisCovenantGroup(authorizing_input=0, outputs=[0])]
    )
    return BuiltTransaction(
        transaction=transaction,
        signers=((passenger_address, tuple(range(len(inputs)))),),
        action="fund",
        covenant_output_index=0,
        covenant_address=covenant_address(state, network_type),
    )


def build_accept_transaction(
    *,
    current_state: RideEscrowState,
    escrow_utxo: dict[str, Any],
    covenant_id: str,
    passenger: Signer,
    driver: Signer,
    driver_key_hash: str,
    network_id: str,
    network_type: str,
    fee_rate: int,
    compute_budget: int,
    priority_fee: int = 0,
) -> BuiltTransaction:
    next_state = current_state.accepted(driver_key_hash)
    return _build_covenant_spend(
        action="accept",
        entry_name="accept",
        entry_arguments={
            "supplied_ride": current_state.ride_commitment,
            "passenger": passenger.public_key,
            "passenger_input_index": 1,
            "selected_driver": driver.public_key,
            "driver_input_index": 2,
        },
        current_state=current_state,
        escrow_utxo=escrow_utxo,
        covenant_id=covenant_id,
        signers=(passenger, driver),
        payer_index=0,
        destination_script=script_public_key(next_state),
        destination_value=current_state.quoted_fare_sompi,
        successor_state=next_state,
        network_id=network_id,
        network_type=network_type,
        fee_rate=fee_rate,
        compute_budget=compute_budget,
        priority_fee=priority_fee,
    )


def build_terminal_transaction(
    *,
    action: str,
    entry_name: str,
    entry_arguments: Mapping[str, Any],
    current_state: RideEscrowState,
    escrow_utxo: dict[str, Any],
    covenant_id: str,
    signers: Sequence[Signer],
    beneficiary_address: str,
    network_id: str,
    network_type: str,
    fee_rate: int,
    compute_budget: int,
    priority_fee: int = 0,
) -> BuiltTransaction:
    return _build_covenant_spend(
        action=action,
        entry_name=entry_name,
        entry_arguments=entry_arguments,
        current_state=current_state,
        escrow_utxo=escrow_utxo,
        covenant_id=covenant_id,
        signers=tuple(signers),
        payer_index=0,
        destination_script=pay_to_address_script(Address(beneficiary_address)),
        destination_value=current_state.quoted_fare_sompi,
        successor_state=None,
        network_id=network_id,
        network_type=network_type,
        fee_rate=fee_rate,
        compute_budget=compute_budget,
        priority_fee=priority_fee,
    )


def _build_covenant_spend(
    *,
    action: str,
    entry_name: str,
    entry_arguments: Mapping[str, Any],
    current_state: RideEscrowState,
    escrow_utxo: dict[str, Any],
    covenant_id: str,
    signers: Sequence[Signer],
    payer_index: int,
    destination_script: Any,
    destination_value: int,
    successor_state: RideEscrowState | None,
    network_id: str,
    network_type: str,
    fee_rate: int,
    compute_budget: int,
    priority_fee: int,
) -> BuiltTransaction:
    if not signers:
        raise ValueError("a covenant spend needs at least one authorization signer")
    normalized_signers = tuple(
        Signer(item.address, item.public_key, select_auth_utxo([item.utxo], item.address))
        for item in signers
    )
    covenant_sigscript = build_entry_signature_script(
        current_state, entry_name, entry_arguments
    )
    covenant_input = _input_from_utxo(
        escrow_utxo,
        signature_script=covenant_sigscript,
        sig_op_count=0,
        compute_budget=compute_budget,
    )
    auth_inputs = [_input_from_utxo(item.utxo) for item in normalized_signers]
    inputs = [covenant_input, *auth_inputs]
    auth_values = [int(item.utxo["utxoEntry"]["amount"]) for item in normalized_signers]
    change_scripts = [pay_to_address_script(Address(item.address)) for item in normalized_signers]
    fee = 0
    mass = 0
    binding = CovenantBinding(authorizing_input=0, covenant_id=Hash(covenant_id))
    for _ in range(8):
        changes = list(auth_values)
        changes[payer_index] -= fee
        if changes[payer_index] < MIN_CHANGE_SOMPI:
            raise ValueError("fee payer needs a larger ordinary authorization UTXO")
        primary = TransactionOutput(
            destination_value,
            destination_script,
            binding if successor_state is not None else None,
        )
        outputs = [
            primary,
            *(
                TransactionOutput(value, script)
                for value, script in zip(changes, change_scripts, strict=True)
            ),
        ]
        draft = _new_transaction(inputs, outputs)
        new_mass, new_fee = _fee_for(
            draft,
            network_id=network_id,
            fee_rate=fee_rate,
            priority_fee=priority_fee,
        )
        mass = new_mass
        if new_fee == fee:
            break
        fee = new_fee
    final_changes = list(auth_values)
    final_changes[payer_index] -= fee
    transaction = _new_transaction(
        inputs,
        [
            TransactionOutput(
                destination_value,
                destination_script,
                binding if successor_state is not None else None,
            ),
            *(
                TransactionOutput(value, script)
                for value, script in zip(final_changes, change_scripts, strict=True)
            ),
        ],
        mass=mass,
    )
    return BuiltTransaction(
        transaction=transaction,
        signers=tuple(
            (signer.address, (index + 1,))
            for index, signer in enumerate(normalized_signers)
        ),
        action=action,
        covenant_output_index=0 if successor_state is not None else None,
        covenant_address=(
            covenant_address(successor_state, network_type) if successor_state is not None else None
        ),
    )


def safe_transaction_json(transaction: Transaction) -> str:
    return json.dumps(
        transaction.to_dict(),
        sort_keys=True,
        separators=(",", ":"),
        default=_json_default,
    )


def transaction_from_safe_json(value: str) -> Transaction:
    if len(value.encode("utf-8")) > MAX_SAFE_JSON_BYTES:
        raise ValueError("transaction JSON is too large")
    try:
        parsed = json.loads(value)
    except json.JSONDecodeError as exc:
        raise ValueError("transaction is not valid JSON") from exc
    if not isinstance(parsed, dict):
        raise ValueError("transaction JSON must be an object")
    return Transaction.from_dict(parsed)


def transaction_fingerprint(value: str, signable_indices: Iterable[int]) -> str:
    transaction = transaction_from_safe_json(value)
    document = transaction.to_dict()
    document.pop("id", None)
    inputs = document.get("inputs", [])
    for index in signable_indices:
        if index < 0 or index >= len(inputs):
            raise ValueError("signable input index is outside the transaction")
        inputs[index]["signatureScript"] = ""
    canonical = json.dumps(
        document,
        sort_keys=True,
        separators=(",", ":"),
        default=_json_default,
    )
    return hashlib.sha256(canonical.encode("utf-8")).hexdigest()


def input_signature_scripts(value: str) -> list[str]:
    transaction = transaction_from_safe_json(value)
    return [item.signature_script_as_hex or "" for item in transaction.inputs]


def _json_default(value: Any) -> str:
    to_string = getattr(value, "to_string", None)
    if callable(to_string):
        return str(to_string())
    raise TypeError(f"{type(value).__name__} is not JSON serializable")


def matching_utxo(
    utxos: Iterable[dict[str, Any]], transaction_id: str, output_index: int
) -> dict[str, Any] | None:
    for item in utxos:
        normalized = normalize_utxo(item)
        outpoint = normalized["outpoint"]
        if (
            outpoint["transactionId"] == transaction_id
            and int(outpoint["index"]) == output_index
        ):
            return normalized
    return None
