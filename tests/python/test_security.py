from kaspa import PrivateKey, sign_message

from backend.security import verify_wallet_message, wallet_identity


def test_wallet_identity_binds_public_key_to_address() -> None:
    private_key = PrivateKey("01" * 32)
    public_key = private_key.to_public_key()
    address = public_key.to_address("testnet").to_string()
    identity = wallet_identity(address, public_key.to_string(), "testnet")
    assert identity.address == address
    assert len(identity.x_only_public_key) == 64
    assert len(identity.public_key_hash) == 64


def test_kip5_message_verification_uses_official_sdk() -> None:
    private_key = PrivateKey("02" * 32)
    message = "Kaspa Transportations test challenge"
    signature = sign_message(message, private_key, no_aux_rand=True)
    public_key = private_key.to_public_key().to_string()
    assert verify_wallet_message(message, signature, public_key)
    assert not verify_wallet_message(message + "!", signature, public_key)
