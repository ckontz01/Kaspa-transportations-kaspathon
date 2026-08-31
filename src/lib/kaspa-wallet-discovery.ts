import {
  requestKaspaWallets,
  type KaspaProvider,
  type KaspaProviderDetail,
  type KaspaProviderInfo,
  type KaspaSignPsktArg,
} from "kaspa-wallet-standard";

export const WALLET_DISCOVERY_TIMEOUT_MS = 2_000;

type InjectedListener = (payload: unknown) => void;

export type InjectedKaswareProvider = {
  requestAccounts: () => Promise<string[]>;
  getAccounts?: () => Promise<string[]>;
  getNetwork?: () => Promise<string | number>;
  switchNetwork?: (network: string) => Promise<unknown>;
  getPublicKey?: () => Promise<string>;
  signMessage?: (
    message: string,
    options?: { type?: "auto" | "schnorr" | "ecdsa"; noAuxRand?: boolean },
  ) => Promise<string>;
  signPskt?: (argument: KaspaSignPsktArg) => Promise<string>;
  disconnect?: (origin: string) => Promise<unknown>;
  on?: (event: string, handler: InjectedListener) => void;
  removeListener?: (event: string, handler: InjectedListener) => void;
};

declare global {
  interface Window {
    kasware?: InjectedKaswareProvider;
  }
}

const KASWARE_METHODS: KaspaProviderInfo["methods"] = [
  "kaspa:requestAccounts",
  "kaspa:chainId",
  "kaspa:getPublicKey",
  "kaspa:signPersonal",
  "kaspa:signPskt",
];

function isInjectedKasware(value: unknown): value is InjectedKaswareProvider {
  return (
    typeof value === "object" &&
    value !== null &&
    typeof (value as { requestAccounts?: unknown }).requestAccounts ===
      "function"
  );
}

export function normalizeKaswareNetworkId(value: string | number): string {
  const normalized = String(value).trim().toLowerCase().replace(/_/g, "-");
  if (
    ["0", "kaspa", "livenet", "kaspa-mainnet", "mainnet"].includes(normalized)
  ) {
    return "mainnet";
  }
  if (
    ["kaspatest", "testnet", "kaspa-testnet", "kaspa-testnet-10"].includes(
      normalized,
    )
  ) {
    return "testnet-10";
  }
  return normalized.replace(/^kaspa-/, "");
}

export function toKaswareNetworkId(value: string): string {
  switch (normalizeKaswareNetworkId(value)) {
    case "mainnet":
      return "kaspa_mainnet";
    case "testnet-10":
      return "kaspa_testnet_10";
    case "testnet-11":
      return "kaspa_testnet_11";
    case "testnet-12":
      return "kaspa_testnet_12";
    case "devnet":
      return "kaspa_devnet";
    default:
      return value;
  }
}

export function normalizeKaswareSignature(value: string): string {
  const clean = value.trim();
  const possibleHex = clean.replace(/^0x/i, "");
  if (/^[0-9a-f]{128}$/i.test(possibleHex)) {
    return possibleHex.toLowerCase();
  }

  try {
    const decoded = window.atob(clean.replace(/-/g, "+").replace(/_/g, "/"));
    if (decoded.length !== 64) return clean;
    return Array.from(decoded, (character) =>
      character.charCodeAt(0).toString(16).padStart(2, "0"),
    ).join("");
  } catch {
    return clean;
  }
}

function kaswareEventName(event: string): string {
  return event === "chainChanged" ? "networkChanged" : event;
}

export function createKaswareProvider(
  kasware: InjectedKaswareProvider,
): KaspaProvider {
  const provider: KaspaProvider = {
    requestAccounts: () => kasware.requestAccounts(),
  };

  if (kasware.getAccounts) {
    provider.getAccounts = () => kasware.getAccounts!();
  }
  if (kasware.getNetwork) {
    provider.getNetwork = async () =>
      normalizeKaswareNetworkId(await kasware.getNetwork!());
  }
  if (kasware.switchNetwork) {
    provider.switchNetwork = async (networkId) => {
      await kasware.switchNetwork!(toKaswareNetworkId(networkId));
    };
  }
  if (kasware.getPublicKey) {
    provider.getPublicKey = () => kasware.getPublicKey!();
  }
  if (kasware.signMessage) {
    provider.signMessage = async (message) =>
      normalizeKaswareSignature(
        await kasware.signMessage!(message, { type: "schnorr" }),
      );
  }
  if (kasware.signPskt) {
    provider.signPskt = (argument) => kasware.signPskt!(argument);
  }
  if (kasware.disconnect) {
    provider.disconnect = async (origin) => {
      await kasware.disconnect!(origin ?? window.location.origin);
    };
  }
  if (kasware.on) {
    provider.on = ((event, handler) => {
      kasware.on!(
        kaswareEventName(event),
        handler as unknown as InjectedListener,
      );
    }) as NonNullable<KaspaProvider["on"]>;
  }
  if (kasware.removeListener) {
    provider.removeListener = ((event, handler) => {
      kasware.removeListener!(
        kaswareEventName(event),
        handler as unknown as InjectedListener,
      );
    }) as NonNullable<KaspaProvider["removeListener"]>;
  }

  return provider;
}

export function createKaswareProviderDetail(
  kasware: InjectedKaswareProvider,
): KaspaProviderDetail {
  return {
    info: {
      id: "hklhheigdmpoolooomdihmhlpjjdbklf",
      name: "KasWare",
      icon: "",
      methods: KASWARE_METHODS,
      uuid:
        window.crypto.randomUUID?.() ?? "37d135f9-36c1-4d7d-96d3-ec871734e7c9",
      rdns: "com.kasware",
    },
    provider: createKaswareProvider(kasware),
  };
}

function readInjectedKasware(): InjectedKaswareProvider | null {
  try {
    return isInjectedKasware(window.kasware) ? window.kasware : null;
  } catch {
    return null;
  }
}

export function subscribeKaspaWallets(
  onAnnounce: (detail: KaspaProviderDetail) => void,
): () => void {
  let lastKasware: InjectedKaswareProvider | null = null;

  const detectKasware = () => {
    const kasware = readInjectedKasware();
    if (!kasware || kasware === lastKasware) return;
    lastKasware = kasware;
    onAnnounce(createKaswareProviderDetail(kasware));
  };

  window.addEventListener("kasware#initialized", detectKasware);
  window.addEventListener("focus", detectKasware);
  // Prefer the built-in adapter when both APIs exist because current KasWare
  // releases expose a richer direct API than their provider announcement.
  detectKasware();
  const unsubscribeStandard = requestKaspaWallets(onAnnounce);
  const timers = [250, 750, WALLET_DISCOVERY_TIMEOUT_MS].map((delay) =>
    window.setTimeout(detectKasware, delay),
  );

  return () => {
    unsubscribeStandard();
    window.removeEventListener("kasware#initialized", detectKasware);
    window.removeEventListener("focus", detectKasware);
    timers.forEach((timer) => window.clearTimeout(timer));
  };
}
