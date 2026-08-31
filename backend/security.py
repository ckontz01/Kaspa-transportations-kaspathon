from __future__ import annotations

import hashlib
import hmac
import secrets
from dataclasses import dataclass
from datetime import timedelta

from kaspa import PublicKey, verify_message

from backend.db import utcnow


SESSION_TTL = timedelta(days=14)
CHALLENGE_TTL = timedelta(minutes=5)


@dataclass(frozen=True)
class WalletIdentity:
    address: str
    public_key: str
    x_only_public_key: str
    public_key_hash: str


def normalize_hex(value: str, *, expected_bytes: int | None = None) -> str:
    clean = value.strip().lower()
    if clean.startswith("0x"):
        clean = clean[2:]
    try:
        raw = bytes.fromhex(clean)
    except ValueError as exc:
        raise ValueError("value must be valid hexadecimal") from exc
    if expected_bytes is not None and len(raw) != expected_bytes:
        raise ValueError(f"value must be exactly {expected_bytes} bytes")
    return clean


def wallet_identity(address: str, public_key_hex: str, network_type: str) -> WalletIdentity:
    public_key = PublicKey(normalize_hex(public_key_hex))
    x_only = public_key.to_x_only_public_key().to_string().lower()
    derived_address = public_key.to_address(network_type).to_string()
    if not hmac.compare_digest(derived_address, address.strip().lower()):
        raise ValueError("public key does not derive the supplied wallet address")
    key_hash = hashlib.blake2b(bytes.fromhex(x_only), digest_size=32).hexdigest()
    return WalletIdentity(
        address=derived_address,
        public_key=public_key.to_string().lower(),
        x_only_public_key=x_only,
        public_key_hash=key_hash,
    )


def verify_wallet_message(message: str, signature_hex: str, public_key_hex: str) -> bool:
    try:
        return bool(
            verify_message(
                message,
                normalize_hex(signature_hex),
                PublicKey(normalize_hex(public_key_hex)),
            )
        )
    except (ValueError, RuntimeError):
        return False


def create_challenge_message(address: str, origin: str) -> tuple[str, str, object]:
    nonce = secrets.token_hex(24)
    expires_at = utcnow() + CHALLENGE_TTL
    message = (
        "Kaspa Transportations sign-in\n\n"
        f"Origin: {origin}\n"
        f"Address: {address}\n"
        f"Nonce: {nonce}\n"
        f"Expires: {expires_at.isoformat()}\n\n"
        "This request authenticates your wallet. It does not authorize a transaction."
    )
    return nonce, message, expires_at


def new_session_token() -> tuple[str, str, object]:
    token = secrets.token_urlsafe(48)
    return token, token_hash(token), utcnow() + SESSION_TTL


def token_hash(token: str) -> str:
    return hashlib.sha256(token.encode("utf-8")).hexdigest()
