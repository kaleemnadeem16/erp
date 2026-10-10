[← Domain](README.md)

# Material cost resolution

Inventory owns the current read-only `QueryMaterialCost` port (ADR 0077/0110). It supports
AVCO, FIFO and SPECIFIC through a registered strategy contract. This is advisory evidence;
it does not promise stock, reserve it or call the stock posting allocator.

## Certainty and quantity basis

`CostResolution` distinguishes COSTED, PARTIAL and UNKNOWN. Only COSTED carries complete
total/unit amounts. PARTIAL exposes a known contribution; UNKNOWN has no known amount.
A valid configured zero is COSTED. A zero requirement has a zero total and no unit rate.
Quantities are stock-unit decimals: at most twelve integer and nine fractional digits internally;
existing public adapters keep their own limits. Inputs exceeding bounds are refused, never truncated.

Ten required units with only six supported by layers have PARTIAL requirement evidence.
The selected six-unit draw can independently be COSTED. Every result names its amount basis,
required/costed/unresolved coverage, stock unit, base currency, method and copied source facts.

AVCO reads a positive-quantity ACTIVE pool, including a supported zero average. It may cost
more than current stock using that estimate rate; availability remains a separate question.
Warehouse pools require a warehouse and are never blended into a company average. Company-wide
pools explicitly disclose a different used scope when a warehouse was requested. Batch pools need
an identified batch. An absent, frozen or empty pool never falls back to a last-valid average.

FIFO reads usable, QA-released layers in `(received_at,id)` order. SPECIFIC requires the actual
batch or serial. Separate demands share one `CostReadSession` per hypothetical scenario so layers
cannot be reused. Attribution follows caller demand order; callers must use stable component/path
order. Cumulative four-place rounding attributes each layer’s residual deterministically. A new
standard/what-if scenario starts fresh and consumes no actual stock.

## Context and authority

Chosen stock is explicit context; otherwise the supplied warehouse/document scope applies,
then built-in COMPANY. Configurable governed default history is C1d and is not implemented here.
Item, location, warehouse and batch identities are checked against the active company and each
other. `ReadMaterialCost` reuses `estimating.internal_cost.view` and refuses limited grants where
the read cannot yet be safely narrowed. No extra Inventory valuation capability is granted.
The Inventory port is internal: guarded business-control Actions may evaluate costs without
permission to disclose them. Those consumers must protect source facts, diagnostics and hashes.

Standalone scenarios use PostgreSQL REPEATABLE READ / READ ONLY. An enclosing transaction owns
its isolation; consumers needing a multi-read snapshot must enter the scenario before their reads.
Current observation is the initial supported context. Explicit different historical/future instants
return UNKNOWN / AS_OF_NOT_SUPPORTED. Resource-rate effective dates remain a separate context.
Copied evidence is immutable as a value; adoption/history persistence is still Phase 4/R5 work.
Fingerprints include quantity/scope/source facts and exclude clock ticks alone; disclosure remains
capability-dependent. No public endpoint or saved evidence was added by C1.

## Related

- [ADR 0077](../decisions/0077-generic-material-cost-resolution-with-a-configurable-costing-scope.md)
- [ADR 0110](../decisions/0110-version-cost-evidence-without-changing-legacy-estimates.md)
- [C1/C2 slices](../phases/phase-4-c1-c2-cost-evidence-slices.md)
- [Inventory module](../../backend/app/Modules/Inventory/README.md)
