from __future__ import annotations

from functools import lru_cache
from typing import Literal

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=(".env.local", ".env"),
        env_file_encoding="utf-8",
        extra="ignore",
    )

    mongodb_uri: str | None = None
    mongodb_database: str = "kaspa_transportations"
    kaspa_network: Literal["mainnet", "testnet-10", "testnet-11", "devnet"] = "testnet-10"
    kaspa_rpc_url: str | None = None
    kaspa_resolver_public_key: str | None = None
    kaspa_covenant_compute_budget: int = 3_000
    kaspa_priority_fee_sompi: int = 0
    enable_mainnet_covenants: str | None = None
    session_secret: str = "development-only-change-me"
    internal_reconciler_secret: str = "development-only-change-me"
    app_origin: str = "http://localhost:3000"
    vercel_env: str | None = None

    @property
    def network_type(self) -> Literal["mainnet", "testnet", "devnet", "simnet"]:
        if self.kaspa_network == "mainnet":
            return "mainnet"
        if self.kaspa_network.startswith("testnet"):
            return "testnet"
        return "devnet"

    @property
    def secure_cookies(self) -> bool:
        return self.app_origin.startswith("https://") or self.vercel_env is not None

    def assert_database_ready(self) -> None:
        if not self.mongodb_uri:
            raise RuntimeError("MONGODB_URI is not configured")
        if self.vercel_env and self.session_secret == "development-only-change-me":
            raise RuntimeError("SESSION_SECRET must be configured on Vercel")

    def assert_covenants_ready(self) -> None:
        if not self.kaspa_resolver_public_key:
            raise RuntimeError("KASPA_RESOLVER_PUBLIC_KEY is not configured")
        if self.kaspa_network == "mainnet" and (
            self.enable_mainnet_covenants != "I_HAVE_COMPLETED_A_CONTRACT_AUDIT"
        ):
            raise RuntimeError(
                "Mainnet covenant creation is locked until the contract audit acknowledgement is set"
            )


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings()
