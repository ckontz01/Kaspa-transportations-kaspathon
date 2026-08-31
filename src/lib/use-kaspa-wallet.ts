"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import {
  normalizeKaspaNetworkId,
  type KaspaProviderDetail,
} from "kaspa-wallet-standard";
import { apiRequest, errorMessage } from "@/lib/api";
import {
  subscribeKaspaWallets,
  WALLET_DISCOVERY_TIMEOUT_MS,
} from "@/lib/kaspa-wallet-discovery";
import type {
  ApiUser,
  SigningDraft,
  SubmitDraftResult,
  WalletState,
} from "@/lib/types";

const REQUIRED_NETWORK = "testnet-10";

type SessionResponse = { user: ApiUser; network: string };

export function useKaspaWallet() {
  const [providers, setProviders] = useState<KaspaProviderDetail[]>([]);
  const [active, setActive] = useState<KaspaProviderDetail | null>(null);
  const [user, setUser] = useState<ApiUser | null>(null);
  const [sessionReady, setSessionReady] = useState(false);
  const [address, setAddress] = useState<string | null>(null);
  const [network, setNetwork] = useState<string | null>(null);
  const [phase, setPhase] = useState<WalletState["phase"]>("discovering");
  const [error, setError] = useState<string | null>(null);
  const [discoveryAttempt, setDiscoveryAttempt] = useState(0);

  useEffect(() => {
    const unsubscribe = subscribeKaspaWallets((detail) => {
      const identity = detail.info.rdns ?? detail.info.uuid;
      setProviders((current) => {
        if (
          current.some(
            (item) => (item.info.rdns ?? item.info.uuid) === identity,
          )
        ) {
          return current;
        }
        return [...current, detail];
      });
      setPhase((current) => (current === "discovering" ? "idle" : current));
    });
    const timer = window.setTimeout(() => {
      setPhase((current) => (current === "discovering" ? "idle" : current));
    }, WALLET_DISCOVERY_TIMEOUT_MS);
    return () => {
      unsubscribe();
      window.clearTimeout(timer);
    };
  }, [discoveryAttempt]);

  const rediscover = useCallback(() => {
    setProviders([]);
    setError(null);
    setPhase("discovering");
    setDiscoveryAttempt((current) => current + 1);
  }, []);

  const refreshSession = useCallback(async () => {
    try {
      const session = await apiRequest<SessionResponse>("/api/v1/session");
      setUser(session.user);
      setAddress(session.user.address);
      setNetwork(session.network);
      setPhase("authenticated");
      setError(null);
      return session.user;
    } catch {
      setUser(null);
      setAddress(null);
      setNetwork(null);
      setPhase((current) =>
        current === "discovering" ? "discovering" : "idle",
      );
      return null;
    } finally {
      setSessionReady(true);
    }
  }, []);

  useEffect(() => {
    void refreshSession();
  }, [refreshSession]);

  useEffect(() => {
    if (!active?.provider.on) return;
    const invalidate = () => {
      setActive(null);
      setPhase("idle");
      setError(
        "Wallet account or network changed. Select the wallet again before signing.",
      );
    };
    active.provider.on("accountsChanged", invalidate);
    active.provider.on("chainChanged", invalidate);
    return () => {
      active.provider.removeListener?.("accountsChanged", invalidate);
      active.provider.removeListener?.("chainChanged", invalidate);
    };
  }, [active]);

  const connect = useCallback(
    async (detail: KaspaProviderDetail) => {
      setPhase("connecting");
      setError(null);
      try {
        const provider = detail.provider;
        if (
          !provider.getPublicKey ||
          !provider.getNetwork ||
          !provider.signMessage
        ) {
          throw new Error(
            "This wallet cannot provide KIP-5 authentication capabilities.",
          );
        }
        const accounts = await provider.requestAccounts();
        if (!accounts[0]) throw new Error("The wallet returned no account.");
        let walletNetwork = normalizeKaspaNetworkId(
          await provider.getNetwork(),
        );
        if (walletNetwork !== REQUIRED_NETWORK && provider.switchNetwork) {
          await provider.switchNetwork(REQUIRED_NETWORK);
          walletNetwork = normalizeKaspaNetworkId(await provider.getNetwork());
        }
        if (walletNetwork !== REQUIRED_NETWORK) {
          throw new Error(
            `Switch the wallet to ${REQUIRED_NETWORK} and connect again.`,
          );
        }
        const publicKey = await provider.getPublicKey();
        const challenge = await apiRequest<{
          challengeId: string;
          message: string;
        }>("/api/v1/auth/challenge", {
          method: "POST",
          body: JSON.stringify({
            address: accounts[0],
            publicKey,
            network: walletNetwork,
          }),
        });
        const signature = await provider.signMessage(challenge.message);
        const verificationEndpoint = user?.email
          ? "/api/v1/accounts/link-wallet"
          : "/api/v1/auth/verify";
        const verified = await apiRequest<SessionResponse>(
          verificationEndpoint,
          {
            method: "POST",
            body: JSON.stringify({
              address: accounts[0],
              publicKey,
              network: walletNetwork,
              challengeId: challenge.challengeId,
              signature,
            }),
          },
        );
        setActive(detail);
        setAddress(verified.user.address);
        setNetwork(verified.network);
        setUser(verified.user);
        setPhase("authenticated");
        return verified.user;
      } catch (caught) {
        setActive(null);
        setPhase("error");
        setError(errorMessage(caught));
        throw caught;
      }
    },
    [user?.email],
  );

  const disconnect = useCallback(async () => {
    try {
      await active?.provider.disconnect?.(window.location.origin);
    } finally {
      setActive(null);
      setPhase("idle");
      setError(null);
    }
  }, [active]);

  const logout = useCallback(async () => {
    try {
      await apiRequest<void>("/api/v1/session", { method: "DELETE" });
      await active?.provider.disconnect?.(window.location.origin);
    } finally {
      setActive(null);
      setUser(null);
      setAddress(null);
      setNetwork(null);
      setPhase("idle");
      setError(null);
    }
  }, [active]);

  const signDraft = useCallback(
    async (draft: SigningDraft): Promise<SubmitDraftResult> => {
      const provider = active?.provider;
      if (!provider?.signPskt) {
        throw new Error(
          "This connected wallet can authenticate your account but does not expose KIP-12 signPskt. Reconnect with a covenant-capable wallet before signing.",
        );
      }
      const signed = await provider.signPskt({
        txJsonString: draft.transactionJson,
        options: { signInputs: draft.signInputs },
      });
      return apiRequest<SubmitDraftResult>(
        `/api/v1/signing-drafts/${encodeURIComponent(draft.id)}/submit`,
        {
          method: "POST",
          body: JSON.stringify({ signedTransactionJson: signed }),
        },
      );
    },
    [active],
  );

  const state = useMemo<WalletState>(
    () => ({
      providers,
      active,
      user,
      sessionReady,
      address,
      network,
      phase,
      error,
      canSignCovenants: Boolean(active?.provider.signPskt && user),
    }),
    [active, address, error, network, phase, providers, sessionReady, user],
  );

  return {
    state,
    connect,
    disconnect,
    logout,
    signDraft,
    refreshSession,
    rediscover,
    setError,
  };
}
