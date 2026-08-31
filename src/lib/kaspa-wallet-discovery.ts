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

export type InjectedKasKeeperProvider = {
  requestAccounts: () => Promise<string[]>;
  getAccounts?: () => Promise<string[]>;
  getNetwork?: () => Promise<string | number>;
  switchNetwork?: (network: string) => Promise<unknown>;
  getLayer?: () => Promise<string>;
  switchLayer?: (layer: "L1" | "L2") => Promise<unknown>;
  getPublicKey?: () => Promise<string>;
  signMessage?: (
    message: string,
    type?: "auto" | "schnorr" | "ecdsa",
  ) => Promise<string>;
  /** Future-compatible: KasKeeper 0.26.0 does not expose this method yet. */
  signPskt?: (argument: KaspaSignPsktArg) => Promise<string>;
  disconnect?: () => Promise<unknown>;
  on?: (event: string, handler: InjectedListener) => void;
  removeListener?: (event: string, handler: InjectedListener) => void;
};

declare global {
  interface Window {
    kasware?: InjectedKaswareProvider;
    Kaskeeper?: InjectedKasKeeperProvider;
  }
}

const KASWARE_METHODS: KaspaProviderInfo["methods"] = [
  "kaspa:requestAccounts",
  "kaspa:chainId",
  "kaspa:getPublicKey",
  "kaspa:signPersonal",
  "kaspa:signPskt",
];

const KASKEEPER_BASE_METHODS: KaspaProviderInfo["methods"] = [
  "kaspa:requestAccounts",
  "kaspa:chainId",
  "kaspa:getPublicKey",
  "kaspa:signPersonal",
];

function isInjectedKasware(value: unknown): value is InjectedKaswareProvider {
  return (
    typeof value === "object" &&
    value !== null &&
    typeof (value as { requestAccounts?: unknown }).requestAccounts ===
      "function"
  );
}

function isInjectedKasKeeper(
  value: unknown,
): value is InjectedKasKeeperProvider {
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

export function toKasKeeperNetworkId(value: string): string {
  switch (normalizeKaswareNetworkId(value)) {
    case "mainnet":
      return "kaspa_mainnet";
    case "testnet-10":
      return "kaspa_testnet";
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

export function createKasKeeperProvider(
  kaskeeper: InjectedKasKeeperProvider,
): KaspaProvider {
  const provider: KaspaProvider = {
    requestAccounts: async () => {
      if (kaskeeper.getLayer) {
        const layer = String(await kaskeeper.getLayer()).toUpperCase();
        if (layer !== "L1") {
          if (!kaskeeper.switchLayer) {
            throw new Error("Switch KasKeeper to L1 and connect again.");
          }
          await kaskeeper.switchLayer("L1");
        }
      }
      return kaskeeper.requestAccounts();
    },
  };

  if (kaskeeper.getAccounts) {
    provider.getAccounts = () => kaskeeper.getAccounts!();
  }
  if (kaskeeper.getNetwork) {
    provider.getNetwork = async () =>
      normalizeKaswareNetworkId(await kaskeeper.getNetwork!());
  }
  if (kaskeeper.switchNetwork) {
    provider.switchNetwork = async (networkId) => {
      await kaskeeper.switchNetwork!(toKasKeeperNetworkId(networkId));
    };
  }
  if (kaskeeper.getPublicKey) {
    provider.getPublicKey = () => kaskeeper.getPublicKey!();
  }
  if (kaskeeper.signMessage) {
    provider.signMessage = async (message) =>
      normalizeKaswareSignature(
        await kaskeeper.signMessage!(message, "schnorr"),
      );
  }
  // Do not translate KasKeeper's Bitcoin-style signPsbt into KIP-12 signPskt.
  // They are different transaction formats. This pass-through only activates if
  // a future KasKeeper release publishes the covenant-safe KIP-12 method.
  if (kaskeeper.signPskt) {
    provider.signPskt = (argument) => kaskeeper.signPskt!(argument);
  }
  if (kaskeeper.disconnect) {
    provider.disconnect = async () => {
      await kaskeeper.disconnect!();
    };
  }
  if (kaskeeper.on) {
    provider.on = ((event, handler) => {
      kaskeeper.on!(
        kaswareEventName(event),
        handler as unknown as InjectedListener,
      );
    }) as NonNullable<KaspaProvider["on"]>;
  }
  if (kaskeeper.removeListener) {
    provider.removeListener = ((event, handler) => {
      kaskeeper.removeListener!(
        kaswareEventName(event),
        handler as unknown as InjectedListener,
      );
    }) as NonNullable<KaspaProvider["removeListener"]>;
  }

  return provider;
}

export function createKasKeeperProviderDetail(
  kaskeeper: InjectedKasKeeperProvider,
): KaspaProviderDetail {
  return {
    info: {
      id: "bicbpicnddlclhekbmgafcbkemdikdem",
      name: "KasKeeper",
      icon: "",
      methods: kaskeeper.signPskt
        ? [...KASKEEPER_BASE_METHODS, "kaspa:signPskt"]
        : KASKEEPER_BASE_METHODS,
      uuid:
        window.crypto.randomUUID?.() ?? "342c77c7-c774-44c9-847f-a4f5923eb915",
      rdns: "com.kaskeeper",
    },
    provider: createKasKeeperProvider(kaskeeper),
  };
}

function readInjectedKasware(): InjectedKaswareProvider | null {
  try {
    return isInjectedKasware(window.kasware) ? window.kasware : null;
  } catch {
    return null;
  }
}

function readInjectedKasKeeper(): InjectedKasKeeperProvider | null {
  try {
    return isInjectedKasKeeper(window.Kaskeeper) ? window.Kaskeeper : null;
  } catch {
    return null;
  }
}

export function subscribeKaspaWallets(
  onAnnounce: (detail: KaspaProviderDetail) => void,
): () => void {
  let lastKasware: InjectedKaswareProvider | null = null;
  let lastKasKeeper: InjectedKasKeeperProvider | null = null;

  const detectKasware = () => {
    const kasware = readInjectedKasware();
    if (!kasware || kasware === lastKasware) return;
    lastKasware = kasware;
    onAnnounce(createKaswareProviderDetail(kasware));
  };

  const detectKasKeeper = () => {
    const kaskeeper = readInjectedKasKeeper();
    if (!kaskeeper || kaskeeper === lastKasKeeper) return;
    lastKasKeeper = kaskeeper;
    onAnnounce(createKasKeeperProviderDetail(kaskeeper));
  };

  const detectInjectedWallets = () => {
    detectKasware();
    detectKasKeeper();
  };

  window.addEventListener("kasware#initialized", detectInjectedWallets);
  window.addEventListener("Kaskeeper#initialized", detectInjectedWallets);
  window.addEventListener("focus", detectInjectedWallets);
  // Direct adapters cover current releases that do not announce through KIP-12.
  detectInjectedWallets();
  const unsubscribeStandard = requestKaspaWallets(onAnnounce);
  const timers = [250, 750, WALLET_DISCOVERY_TIMEOUT_MS].map((delay) =>
    window.setTimeout(detectInjectedWallets, delay),
  );

  return () => {
    unsubscribeStandard();
    window.removeEventListener("kasware#initialized", detectInjectedWallets);
    window.removeEventListener(
      "Kaskeeper#initialized",
      detectInjectedWallets,
    );
    window.removeEventListener("focus", detectInjectedWallets);
    timers.forEach((timer) => window.clearTimeout(timer));
  };
}
