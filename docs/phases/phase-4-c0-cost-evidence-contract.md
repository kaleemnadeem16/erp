[← Phase 4 plan](phase-4-sales-quotations-and-handover.md)

# C0 — Cost-evidence contract for owner approval

**Date/baseline:** 2026-10-09, `26571c5`; unrelated Sales/UI work remains outside this proposal.
**State:** technical proposal, not accepted or implemented; [proposed ADR 0110](../decisions/0110-version-cost-evidence-without-changing-legacy-estimates.md).
Accepted 0077/0109 and [R2 compatibility evidence](phase-4-r2-compatibility-audit.md) are reused.
No new ERP costing method or R3 pricing rule is researched here.

## Problem and recommendation

A recipe's missing pigment cost currently becomes zero; missing labour/plant rate can do the same.
An estimate may therefore show material 200.0000 and unconfigured labour 0.0000 as total 200.0000.
That is useful legacy arithmetic but cannot establish a complete cost for trusted margin decisions.
By contrast, an explicitly configured labour rate 0.0000 is valid evidence of genuinely zero cost.

**Recommend a separate evidence contract and endpoints**, with an explicit schema version under the
existing API namespace. Keep legacy numeric endpoints, types, totals and unsupported-method failures
unchanged. Migrate identified consumers explicitly; new financial/control code must never fall back
from evidence failure to legacy numbers. No global API v2 or broad estimator replacement is needed.

## Certainty and amount contract

| Status | Meaning | Complete amounts | Known contribution |
|---|---|---|---|
| COSTED | Every applicable required input/quantity has valid evidence, including proven zero | `unit_cost` and `total_cost` numeric decimal strings | `known_cost` equals total; no missing requirement |
| PARTIAL | Some applicable input/quantity is evidenced and some is not | Both null; no inferred average for the whole requirement | `known_cost` contains only established contributions, possibly genuine 0.0000 |
| UNKNOWN | No applicable required cost is established, or context/method/date cannot be supported | Both null | Null; never manufacture zero as a known subtotal |

For a material request of 10 units with 6 evidenced at 2.0000, PARTIAL means known cost 12.0000,
covered quantity 6, unresolved quantity 4; complete total/unit price remain null. Never divide
12.0000 by 10 to imply a complete rate. Coverage quantities belong to that Item/stock UoM; an
Assembly must not aggregate kilograms and labour hours into a spurious coverage percentage.

A positive active AVCO pool can provide an estimate rate for the full requested quantity even
when stock is insufficient; record physical shortage separately, not as cost uncertainty. FIFO or
explicit chosen-stock allocation costs only supported coverage. These coverage rules need owner
approval; they do not authorise reservation or guaranteed fulfilment.

**Zero evidence:** a valid zero resource rate/authorised override or valid zero-valued Inventory
basis can be COSTED. Require an actual valid source, not a missing/new empty pool or a last-valid
rate borrowed from an unusable state. No overhead policy is intentional zero overhead. Zero demand
where already allowed contributes zero without proving an unavailable rate for positive work;
standard-versus-zero-plan variance still needs standard cost evidence. Absolute cost bands may
use known zero; percentage bands with a zero denominator report not-evaluable, not an invented
percentage/pass. R5 owns adoption handling. Do not broaden quantity rules.

## Evidence shape (proposed typed value)

```text
CostEvidence v1
  schema_version: 1
  status: COSTED | PARTIAL | UNKNOWN
  diagnostics[]: code, affected obligation/path, dependency reason (projection-controlled)
  complete: unit_cost?, total_cost?, currency_id, unit_basis
  known_cost?; coverage[]: required, costed, unresolved, item/stock_uom
  context: company, source/version, quantity/UoM/conversion, recipient account if applicable
  scope_requested, scope_used, scope_changed, scope_policy_version?
  source_type; valuation_method/strategy; requested_as_of?, effective_as_of, observed_at
  basis[]: copied relevant pool/layer/rate/override/overhead values and source identities
  basis_fingerprint?; projection: SUMMARY | DETAIL
```

Money uses decimal(19,4) strings, never floats. Preserve existing supported rounding boundaries:
component/recipe cost, output-unit cost and overhead arithmetic must match fully costed regression
fixtures. New query quantities are positive decimal strings, proposed maximum 12 integer and 9
fractional digits, with exact coverage/conversion; reject out-of-range input/results rather than
truncate. This does not widen six-place legacy preview input or decide quotation precision. New Assembly/
preview evidence entry retains its existing work-quantity bounds; nine-place canonical material
requirements are passed internally to C1 without rounding them to stock document storage precision.

Inventory resolves base-currency cost from its own pool/layer values and copies available original
currency/FX evidence. No current FX conversion, purchase price fallback, standard cost or Sales price
is invented. Resource/override rates retain their declared basis and actual source values; existing
fully costed examples must remain identical. Unsupported monetary range/context is an explicit
diagnostic/refusal, not a zero. R4 owns future valuation/rounding policy beyond this contract.

## Propagation rules

1. Resolve technical requirements first, preserving current version selection, context, nesting,
   wastage, multipliers and dimension conversion. Cycles, invalid units and invalid IDs remain
   validation/domain errors; they are not swallowed as UNKNOWN cost.
2. Material leaves use Inventory evidence; resources resolve an actual eligible rate or explicit
   override. Missing material and missing resource receive distinct codes, e.g.
   `NO_COST_EVIDENCE` versus `RESOURCE_RATE_MISSING`.
3. A parent is COSTED only if every applicable required cost input is COSTED. Mixed established
   and unresolved obligations produce PARTIAL; wholly unresolved required cost produces UNKNOWN.
   Absent optional overhead does not falsely promote wholly unknown direct costs to PARTIAL.
4. Scale known contributions and unresolved obligations through every formulation level. A nested
   recipe with known pigment 50.0000 and unknown binder remains partial; adding known labour
   20.0000 gives known contribution 70.0000, never complete cost 70.0000.
5. Percentage overhead needs its actual complete driver. If that driver is incomplete, complete
   overhead remains null with a dependency reason; do not compute it from the known subtotal.
   Independent work/hour drivers can remain evidenced when their quantities/rates are known.
6. Complete estimate-derived selling guidance is null when its required base cost is incomplete.
   Keep known cost separate; do not perform a fake margin calculation on it. COSTED guidance is
   still not maintained Sales pricing, commercial approval or an adopted/issued baseline.

## Scope, time and consistent reads

ADR 0077 precedence remains chosen stock → explicit document scope → governed company default
(COMPANY initially) → unknown if unsupported. Preserve requested and actually used scope, including
company-wide AVCO used for a warehouse request. Company requests over warehouse AVCO pools are
UNKNOWN, not an invented blend. Batch-scoped pools need identified batch; SPECIFIC needs selected
batch/serial evidence. Never silently expand warehouse scope to find cheaper or available stock.

**Current snapshot initially:** pools/remaining layers are mutable. Neither `received_at <= date`
nor an old resource-rate row establishes historical material stock cost. C1 captures one consistent
Inventory read snapshot and effective observation time; explicit historical/future stock valuation
outside that supported snapshot returns UNKNOWN / `AS_OF_NOT_SUPPORTED`. Preserve requested time;
do not relabel today's value. Saved historical evidence is read from its actual saved values.
A future historical replay reader is separate work, not a posting/recalculation operation.

A standalone evidence session owns a repeatable-read read-only transaction for all dependent reads;
it acquires no allocation locks, reservations or business writes. When eventually used inside an
adoption transaction, R5 owns its compatible snapshot/revalidation boundary; do not change caller
isolation or assume independent per-leaf reads are atomic. Resource effective date is explicit in
the session; pin chosen rate IDs/effective dates and report their time basis separately from stock
observation. No future quotation valuation-date rule is decided here. An explicitly mixed resource-effective/
stock-observation basis must be identified as such; it cannot be labelled one historical valuation.
COSTED alone grants no trusted commercial valuation status.

Fingerprint copied basis/context deterministically, including quantities/scopes/methods/source
values and applicable rate dates. Observation timestamp alone must not create false drift. Return
fingerprints only where their underlying evidence may be disclosed; no hash side channel or client
hash as proof of approval. Material preview's existing fingerprint remains a legacy preview token.

## API recommendation and exact compatibility boundary

New proposed routes (all read-only POST calculations under `/api/v1`):

| Evidence route | Contract/input | Legacy route retained |
|---|---|---|
| `formulation-versions/{version}/cost-evidence` | Schema v1; existing technical version plus quantity, costing context and supported time request | Existing GET `cost-breakdown` |
| `assembly-versions/{version}/cost-evidence` | Schema v1; pinned version, work quantity/UoM, resource date and costing context | Existing GET `cost-breakdown`, including `sell_per_unit` |
| `assemblies/{assembly}/material-preview-evidence` | Existing preview technical/variance/mix context plus evidence calculation; no adoption | Existing POST `material-preview` |

Valid requests with unavailable cost return a typed result, not an invented complete value. Invalid
input uses normal 422 errors; foreign/non-visible records 404; missing route capability 403. Fatal
programming/database failures are not relabelled UNKNOWN. Resource responses use typed Resources,
OpenAPI schema and generated clients; no hand-authored duplicate DTO shape in the frontend.

Inventory C1 is initially an internal query, not an extra authenticated-only material cost endpoint.
New routes add surfaces rather than changing old payload semantics; no generic `/api/v2` duplication.
A future incompatible CostEvidence schema gets another explicitly versioned contract. Legacy retirement
is a separate owner decision after consumer migration; no silent nullability or method-support change.

## Authority, projection and consumers

- Cost-evidence endpoints require `estimating.internal_cost.view` at Request and Action. Technical
  preview retains `estimating.material_preview.run` and can run without cost disclosure; cost fields
  are omitted, not returned as UNKNOWN to confuse lack of permission with lack of evidence.
- Inventory callers use `inventory.valuation.view`; Estimating callers use
  `estimating.internal_cost.view` through registered adapters, not a caller-chosen capability string. Guarded adapters authorise the query; the query rechecks tenant/scoped IDs. No Sales
  capability is invented, and a caller-supplied actor/company/scope cannot assert its own authority.
- Detail projection also respects `estimating.recipe.view`, stock detail/availability capabilities
  and warehouse scope. Summary cannot reveal hidden leaf quantities, source locations or hashes.
  Chosen-stock input still requires choose/availability authority; scope limits are intersected,
  never broadened. Refuse limited scopes until integrated; do not claim Q26 is implemented.
- Migrate `assembly-cost-breakdown.tsx`, `formulation-cost-breakdown.tsx` and the material-preview
  screen's cost/variance/mix evidence path explicitly in C2. Show known contribution as incomplete,
  missing diagnostics where permitted, no complete guidance for partial/unknown. Technical preview
  and existing no-cost personas remain usable; do not fetch protected APIs then hide their output.
- R3/R5 future consumers take server evidence, never legacy scalar totals or browser-calculated
  certainty. C3 guidance labels remain an independent wording task.

## Tests, migrations and owner decisions

Preserve existing `FormulationExplosionTest::test_an_item_never_received_costs_zero` and
`AssemblyCostRollUpTest::test_a_labour_item_with_no_resource_rate_configured_costs_zero_not_an_error`.
Legacy FIFO/scoped-AVCO refusal tests also remain. Add evidence tests for the corrected semantics;
only explicit legacy retirement would change those expectations. Numeric compatibility and new
completeness are separate assertions. Detailed coverage is in [C1/C2 slices](phase-4-c1-c2-cost-evidence-slices.md).

C1 query/C2 result DTOs and read APIs need no migration/backfill. Governed configurable default-scope
history is a separate C1 configuration slice with a concrete additive schema need; future adoption
persistence belongs to R5. No migration is created now. OpenAPI/client generation, typechecks/build
and affected browser tests accompany actual API/UI delivery, preserving other agents' generated work.

Owner approval required for: (1) separate evidence API and coexistence/consumer migration;
(2) null complete amounts, known contribution and propagation rules; (3) current-only temporal
support/coverage/quantity bounds; (4) scope/permission projections and configuration staging.
Approve proposed 0110 only after this review; C1/C2 code and any scope-history migration then need
explicit implementation authorisation. No business cost-completeness gate for all quotations is added.

## Related

- [Proposed ADR 0110](../decisions/0110-version-cost-evidence-without-changing-legacy-estimates.md) · [C1/C2 slices](phase-4-c1-c2-cost-evidence-slices.md)
- [R2 closure](phase-4-r2-quotation-structure.md) · [Compatibility plan](phase-4-r2-compatibility-plan.md)
- [ADR 0077](../decisions/0077-generic-material-cost-resolution-with-a-configurable-costing-scope.md)
- [API conventions](../architecture/api-conventions.md) · [Authorisation](../architecture/authorization.md)
