import { ExternalLink, MonitorDown, Smartphone } from "lucide-react";

export const KASKEEPER_LINKS = {
  desktop:
    "https://chromewebstore.google.com/detail/kaskeeper/bicbpicnddlclhekbmgafcbkemdikdem",
  ios: "https://apps.apple.com/us/app/kaskeeper/id6745931757",
  android: "https://play.google.com/store/apps/details?id=com.kaskeeper",
} as const;

export function KasKeeperInstallCard({ compact = false }: { compact?: boolean }) {
  return (
    <section
      className={`kaskeeper-install${compact ? " kaskeeper-install-compact" : ""}`}
      aria-label="Get KasKeeper"
    >
      <div className="kaskeeper-install-copy">
        <strong>KasKeeper</strong>
        <p>
          Detected automatically wherever its provider is injected. The current
          mobile release publishes no website handoff, and release 0.26.0 does
          not expose KIP-12 <code>signPskt</code>; ride escrow still needs a
          covenant-capable wallet.
        </p>
      </div>
      <div className="kaskeeper-install-links">
        <a href={KASKEEPER_LINKS.desktop} target="_blank" rel="noreferrer">
          <MonitorDown aria-hidden="true" size={17} />
          <span>Desktop</span>
          <ExternalLink aria-hidden="true" size={13} />
        </a>
        <a href={KASKEEPER_LINKS.ios} target="_blank" rel="noreferrer">
          <Smartphone aria-hidden="true" size={17} />
          <span>iPhone</span>
          <ExternalLink aria-hidden="true" size={13} />
        </a>
        <a href={KASKEEPER_LINKS.android} target="_blank" rel="noreferrer">
          <Smartphone aria-hidden="true" size={17} />
          <span>Android</span>
          <ExternalLink aria-hidden="true" size={13} />
        </a>
      </div>
    </section>
  );
}
