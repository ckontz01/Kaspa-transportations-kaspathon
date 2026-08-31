import { ArrowRight } from "lucide-react";

export function ProtocolBand() {
  return (
    <section className="protocol-band" id="protocol" aria-labelledby="protocol-heading">
      <div className="protocol-copy">
        <p className="mono-label">RideEscrow / SilverScript 0.1.0</p>
        <h2 id="protocol-heading">The UTXO holds the agreement.</h2>
        <p>
          The fare, passenger, resolver, ride commitment, timeout, assigned driver, and phase are
          covenant state. MongoDB indexes the lifecycle; it cannot rewrite the spend rules.
        </p>
        <a href="https://github.com/kaspanet/silverscript" target="_blank" rel="noreferrer">
          Inspect SilverScript
          <ArrowRight aria-hidden="true" size={16} strokeWidth={1.8} />
        </a>
      </div>
      <div className="protocol-code" aria-label="RideEscrow enforcement excerpt">
        <div className="protocol-code-heading">
          <span>contracts/ride-escrow.sil</span>
          <strong>8aa2a011…f3fe</strong>
        </div>
        <pre>
          <code>{`require(tx.inputs[active].value == quoted_fare_sompi);
require(tx.outputs[payout].value == quoted_fare_sompi);
require(OpAuthOutputCount(active) == 0);

// fees and tips come from ordinary wallet inputs
require_payout(payout, assigned_driver);`}</code>
        </pre>
      </div>
    </section>
  );
}
