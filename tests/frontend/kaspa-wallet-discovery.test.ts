import { afterEach, describe, expect, it, vi } from "vitest";
import {
  createKasKeeperProviderDetail,
  createKaswareProviderDetail,
  normalizeKaswareNetworkId,
  normalizeKaswareSignature,
  subscribeKaspaWallets,
  toKasKeeperNetworkId,
  toKaswareNetworkId,
  type InjectedKaswareProvider,
  type InjectedKasKeeperProvider,
} from "@/lib/kaspa-wallet-discovery";

function mockKasware(): InjectedKaswareProvider {
  return {
    requestAccounts: vi.fn(async () => ["kaspatest:demo"]),
    getAccounts: vi.fn(async () => ["kaspatest:demo"]),
    getNetwork: vi.fn(async () => "kaspa_testnet_10"),
    switchNetwork: vi.fn(async () => undefined),
    getPublicKey: vi.fn(async () => "02".padEnd(66, "1")),
    signMessage: vi.fn(async () => "signature"),
    signPskt: vi.fn(async ({ txJsonString }) => txJsonString),
    disconnect: vi.fn(async () => undefined),
    on: vi.fn(),
    removeListener: vi.fn(),
  };
}

function mockKasKeeper({
  covenant = false,
  layer = "L1",
}: { covenant?: boolean; layer?: "L1" | "L2" } = {}): InjectedKasKeeperProvider {
  return {
    requestAccounts: vi.fn(async () => ["kaspatest:keeper"]),
    getAccounts: vi.fn(async () => ["kaspatest:keeper"]),
    getNetwork: vi.fn(async () => "kaspa_testnet"),
    switchNetwork: vi.fn(async () => undefined),
    getLayer: vi.fn(async () => layer),
    switchLayer: vi.fn(async () => undefined),
    getPublicKey: vi.fn(async () => "03".padEnd(66, "2")),
    signMessage: vi.fn(async () => "keeper-signature"),
    signPskt: covenant
      ? vi.fn(async ({ txJsonString }) => txJsonString)
      : undefined,
    disconnect: vi.fn(async () => undefined),
    on: vi.fn(),
    removeListener: vi.fn(),
  };
}

afterEach(() => {
  Reflect.deleteProperty(window, "kasware");
  Reflect.deleteProperty(window, "Kaskeeper");
  vi.restoreAllMocks();
});

describe("KasWare compatibility", () => {
  it("normalizes legacy and current KasWare network names", () => {
    expect(normalizeKaswareNetworkId("kaspa_mainnet")).toBe("mainnet");
    expect(normalizeKaswareNetworkId("livenet")).toBe("mainnet");
    expect(normalizeKaswareNetworkId("kaspa_testnet_10")).toBe("testnet-10");
    expect(toKaswareNetworkId("testnet-10")).toBe("kaspa_testnet_10");
    expect(toKasKeeperNetworkId("testnet-10")).toBe("kaspa_testnet");
  });

  it("converts KasWare's 64-byte base64 Schnorr signature to hex", () => {
    const signatureBytes = String.fromCharCode(...new Uint8Array(64).fill(171));
    const signatureBase64 = window.btoa(signatureBytes);

    expect(normalizeKaswareSignature(signatureBase64)).toBe("ab".repeat(64));
    expect(normalizeKaswareSignature(`0x${"CD".repeat(64)}`)).toBe(
      "cd".repeat(64),
    );
    expect(normalizeKaswareSignature("unknown-format")).toBe("unknown-format");
  });

  it("adapts the injected provider without changing signing inputs", async () => {
    const kasware = mockKasware();
    const detail = createKaswareProviderDetail(kasware);
    const signingArgument = {
      txJsonString: '{"version":1}',
      options: { signInputs: [{ index: 2, sighashType: 1 }] },
    };

    expect(detail.info.name).toBe("KasWare");
    expect(await detail.provider.getNetwork?.()).toBe("testnet-10");
    await detail.provider.signMessage?.("challenge");
    expect(kasware.signMessage).toHaveBeenCalledWith("challenge", {
      type: "schnorr",
    });
    await detail.provider.switchNetwork?.("testnet-10");
    expect(kasware.switchNetwork).toHaveBeenCalledWith("kaspa_testnet_10");
    expect(await detail.provider.signPskt?.(signingArgument)).toBe(
      signingArgument.txJsonString,
    );
    expect(kasware.signPskt).toHaveBeenCalledWith(signingArgument);

    const handler = vi.fn();
    detail.provider.on?.("chainChanged", handler);
    expect(kasware.on).toHaveBeenCalledWith("networkChanged", handler);
  });

  it("detects KasWare when the extension injects after hydration", () => {
    const announcements: string[] = [];
    const unsubscribe = subscribeKaspaWallets((detail) =>
      announcements.push(detail.info.name),
    );

    window.kasware = mockKasware();
    window.dispatchEvent(new Event("kasware#initialized"));

    expect(announcements).toEqual(["KasWare"]);
    unsubscribe();
  });
});

describe("KasKeeper compatibility", () => {
  it("adapts the injected provider for account authentication", async () => {
    const kaskeeper = mockKasKeeper();
    const detail = createKasKeeperProviderDetail(kaskeeper);

    expect(detail.info.name).toBe("KasKeeper");
    expect(detail.info.rdns).toBe("com.kaskeeper");
    expect(detail.info.methods).not.toContain("kaspa:signPskt");
    expect(detail.provider.signPskt).toBeUndefined();
    expect(await detail.provider.getNetwork?.()).toBe("testnet-10");
    await detail.provider.signMessage?.("challenge");
    expect(kaskeeper.signMessage).toHaveBeenCalledWith(
      "challenge",
      "schnorr",
    );
    await detail.provider.switchNetwork?.("testnet-10");
    expect(kaskeeper.switchNetwork).toHaveBeenCalledWith("kaspa_testnet");

    const handler = vi.fn();
    detail.provider.on?.("chainChanged", handler);
    expect(kaskeeper.on).toHaveBeenCalledWith("networkChanged", handler);
  });

  it("advertises and passes through signPskt only when a future release provides it", async () => {
    const kaskeeper = mockKasKeeper({ covenant: true });
    const detail = createKasKeeperProviderDetail(kaskeeper);
    const signingArgument = {
      txJsonString: '{"version":1}',
      options: { signInputs: [{ index: 0, sighashType: 1 }] },
    };

    expect(detail.info.methods).toContain("kaspa:signPskt");
    expect(await detail.provider.signPskt?.(signingArgument)).toBe(
      signingArgument.txJsonString,
    );
    expect(kaskeeper.signPskt).toHaveBeenCalledWith(signingArgument);
  });

  it("returns to Kaspa L1 before requesting an account", async () => {
    const kaskeeper = mockKasKeeper({ layer: "L2" });
    const detail = createKasKeeperProviderDetail(kaskeeper);

    await detail.provider.requestAccounts();

    expect(kaskeeper.switchLayer).toHaveBeenCalledWith("L1");
    expect(kaskeeper.requestAccounts).toHaveBeenCalledOnce();
  });

  it("detects KasKeeper when it injects after hydration", () => {
    const announcements: string[] = [];
    const unsubscribe = subscribeKaspaWallets((detail) =>
      announcements.push(detail.info.name),
    );

    window.Kaskeeper = mockKasKeeper();
    window.dispatchEvent(new Event("Kaskeeper#initialized"));

    expect(announcements).toEqual(["KasKeeper"]);
    unsubscribe();
  });
});
