[← Decisions](README.md)

# 0110 — Correct existing cost operations through one authoritative contract

- **Status:** proposed — revised C0; owner review and implementation authorisation pending
- **Date:** 2026-10-09
- **Affects:** Inventory material-cost query; Estimating Actions/DTOs, existing APIs and all consumers
- **History:** replaces the unaccepted compatibility-first draft at `9dced38`; retained filename/identifier preserves links.
- **Relationship:** retain 0077's query/scope/certainty and 0109's Sales/estimating boundaries; proposed refinement of 0077's runtime-deferral/legacy-retirement sequencing is explicit below.

## Context

Known materials 200.0000 plus unavailable labour must not be presented as complete cost 200.0000.
A recorded zero labour rate is different: that cost is genuinely known. The ERP has no real business
or production data, as confirmed by the owner. Existing repository consumers can be migrated together;
there is no demonstrated external contract requiring a permanent incorrect numeric path.

The previous proposal overvalued backward compatibility and proposed duplicate cost endpoints.
Actual inspection finds a connected graph of scalar Actions/DTOs, three current API operations,
generated hooks, cost panels, variance/mix controls, direct tests and demo fixtures. It requires a
coordinated correction, not a scalar-to-null change in isolation.

## Decision

**Proposed:** correct the existing cost APIs and their backend/frontend consumers together using
one authoritative cost-resolution contract. Preserve supported arithmetic and business controls;
retire missing-cost zero fallbacks and redundant compatibility paths after their callers migrate.
Do not add a second endpoint simply to preserve an obsolete response.

### One resolution, distinct business operations

COSTED means every required cost has evidence, including genuine zero. PARTIAL exposes established
contribution without a complete amount; UNKNOWN has no reliable applicable cost. Missing materials
and missing resource rates have distinct diagnostics. Complete totals/unit costs are nullable when
incomplete; a known subtotal is never labelled a complete total.

Reuse the same typed resolution for material leaves, formulations, Assemblies and selected draws.
Keep meaningful context and basis: per-output/work-unit costing, a contextual material preview and
a selected draw are different operations. Their existing routes remain; no parallel legacy/evidence
routes or global API v2 are justified by the current audit. Response shape and generated consumers
change deliberately and coherently under the [revised C0](../phases/phase-4-c0-cost-evidence-contract.md).

Nested uncertainty propagates; percentage overhead requires its complete driver, independent drivers
retain valid evidence, and incomplete base cost cannot produce complete margin/selling guidance.
Quantity-dependent FIFO must cost the actual combined material demand, not repeatedly reuse the
cheapest layers or scale a one-unit query into a large job without recalculation.

### What remains valid

Keep versions, nested formulations, rates/dimensions, technical conversions, wastage/multipliers,
fully costed arithmetic/rounding, explicit overrides, overhead precedence and margin-versus-markup.
Keep tenant/field authority, variance/stock-choice controls, immutable issued evidence and Inventory
posting intact. Rate lookup returning null is valid; its caller must stop converting that to zero.
COSTED is not approval, adopted evidence or future trust. R3/R5 own those boundaries.

Cost-control evaluation may be authorised without disclosing cost: material-preview and approval
advice must still work for their legitimate no-cost-read personas. Guarded business Actions authorise
internal evaluation; cost/recipe/stock evidence is projected only under the relevant read capabilities.
Do not grant all preview users valuation access or trust caller-supplied visibility booleans.

### Temporal and migration boundary

Current mutable pools/layers cannot answer arbitrary historical valuation by filtering receipt dates.
Initial query support remains current consistent read evidence; unsupported historical/future material
requests are explicit UNKNOWN. Preserve requested/effective/observed times and actual source values.
Inventory owns AVCO/FIFO/SPECIFIC evidence; no posting allocator/writer is invoked.

0077 explicitly planned the retirement of zero when Phase 4 callers migrate but also deferred runtime
changes until quotation adoption. This proposal retires the fallback during coordinated C1/C2 delivery,
before persisted quotation adoption. That sequencing refinement needs explicit owner approval; it
retains 0077's cost, scope, strategy and eventual snapshot obligations. Accepted 0077 is unchanged now;
formal limited supersession/retention must be reconciled on acceptance, not inferred from this draft.

No DTO/API certainty change requires a database backfill or historical migration edit. Governed scope
history needs its own authorised additive migration; future adoption persistence remains R5. Existing
development databases and idempotent demos remain valid; no reset is assumed or performed.

## Consequences

- **Positive:** one clear cost model, corrected displays and controls, no permanent incorrect legacy API.
- **Positive:** legitimate zero and unavailable evidence are distinguished end-to-end.
- **Negative:** this is an intentional API/DTO contract change; Actions, Resources, clients, UI, fixtures
  and tests must migrate as one completed integration unit for each affected operation.
- **Requires:** [verified dependency map](../phases/phase-4-cost-dependency-map.md), coordinated slice
  gates, generated-client checks, security/tenant and Inventory/Estimating regression coverage.
- **Requires:** no completed slice leaves an incompatible consumer; unexposed intermediate work must
  not become a second public cost contract. Remove redundant scalar adapters after their final caller moves.
- **Not authorised:** code, migrations, database resets, Sales pricing implementation or remote push.

## Alternatives considered

**Correct existing operations and migrate all consumers:** recommended; active development and the
verified consumer graph allow deliberate correction without a permanent compatibility burden.

**Only add status beside old zero-filled totals:** rejected; unaware callers still misread the number.
Additive evidence is useful only when the original amount itself acquires correct nullable semantics
and all readers honour it; then it is one corrected contract, not dual old/new authority.

**Parallel versioned endpoints:** rejected on present evidence; no distinct business operation or
unmigratable external consumer was established. A genuinely new operation can get a new route later.

**Broad estimator/Inventory rewrite:** rejected; valid technical resolution/arithmetic and posting
already exist. Change evidence semantics and their exact dependencies, not the whole costing system.

## Research basis

Reuses accepted 0077 cost-scope research, 0109 and the R2 compatibility assessment. The
[dependency map](../phases/phase-4-cost-dependency-map.md) records actual code/API/schema/test/demo/UI
inspection on 2026-10-09. This revises technical delivery under the owner's no-business-data/local-only
constraints; no new ERP valuation method or R3 business research is needed.

## Related

- [Revised C0](../phases/phase-4-c0-cost-evidence-contract.md) · [Revised C1/C2 slices](../phases/phase-4-c1-c2-cost-evidence-slices.md)
- [0077](0077-generic-material-cost-resolution-with-a-configurable-costing-scope.md) · [0109](0109-shared-commercial-quotation-lines-and-optional-estimating.md)
- [Dependency map](../phases/phase-4-cost-dependency-map.md) · [Phase 4 plan](../phases/phase-4-sales-quotations-and-handover.md)
