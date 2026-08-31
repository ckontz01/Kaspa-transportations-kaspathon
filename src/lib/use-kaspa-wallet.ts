"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import {
  normalizeKaspaNetworkId,
  requestKaspaWallets,
  type KaspaProviderDetail,
} from "kaspa-wallet-standard";
import { apiRequest, errorMessage } from "@/lib/api";
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
  const [address, setAddress] = useState<string | null>(null);
  const [network, setNetwork] = useState<string | null>(null);
  const [phase, setPhase] = useState<WalletState["phase"]>("discovering");
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const unsubscribe = requestKaspaWallets((detail) => {
      const identity = detail.info.rdns ?? detail.info.uuid;
      setProviders((current) => {
        if (current.some((item) => (item.info.rdns ?? item.info.uuid) === identity)) {
          return current;
        }
        return [...current, detail];
      });
      setPhase((current) => (current === "discovering" ? "idle" : current));
    });
    const timer = window.setTimeout(() => {
      setPhase((current) => (current === "discovering" ? "idle" : current));
    }, 500);
    return () => {
      unsubscribe();
      window.clearTimeout(timer);
    };
  }, []);

  const refreshSession = useCallback(async () => {
    try {
      const session = await apiRequest<SessionResponse>("/api/v1/session");
      setUser(session.user);
      setAddress(session.user.address);
      setNetwork(session.network);
      return session.user;
    } catch {
      setUser(null);
      return null;
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
      setError("Wallet account or network changed. Select the wallet again before signing.");
    };
    active.provider.on("accountsChanged", invalidate);
    active.provider.on("chainChanged", invalidate);
    return () => {
      active.provider.removeListener?.("accountsChanged", invalidate);
      active.provider.removeListener?.("chainChanged", invalidate);
    };
  }, [active]);

  const connect = useCallback(async (detail: KaspaProviderDetail) => {
    setPhase("connecting");
    setError(null);
    try {
      const provider = detail.provider;
      if (!provider.getPublicKey || !provider.getNetwork || !provider.signMessage) {
        throw new Error("This wallet cannot provide KIP-5 authentication capabilities.");
      }
      if (!provider.signPskt) {
        throw new Error("This wallet does not expose KIP-12 signPskt for covenant transactions.");
      }
      const accounts = await provider.requestAccounts();
      if (!accounts[0]) throw new Error("The wallet returned no account.");
      let walletNetwork = normalizeKaspaNetworkId(await provider.getNetwork());
      if (walletNetwork !== REQUIRED_NETWORK && provider.switchNetwork) {
        await provider.switchNetwork(REQUIRED_NETWORK);
        walletNetwork = normalizeKaspaNetworkId(await provider.getNetwork());
      }
      if (walletNetwork !== REQUIRED_NETWORK) {
        throw new Error(`Switch the wallet to ${REQUIRED_NETWORK} and connect again.`);
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
      const verified = await apiRequest<SessionResponse>("/api/v1/auth/verify", {
        method: "POST",
        body: JSON.stringify({
          address: accounts[0],
          publicKey,
          network: walletNetwork,
          challengeId: challenge.challengeId,
          signature,
        }),
      });
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
  }, []);

  const disconnect = useCallback(async () => {
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
        throw new Error("Select a KIP-12 wallet again before signing this transaction.");
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
      address,
      network,
      phase,
      error,
      canSignCovenants: Boolean(active?.provider.signPskt && user),
    }),
    [active, address, error, network, phase, providers, user],
  );

  return { state, connect, disconnect, signDraft, refreshSession, setError };
}
