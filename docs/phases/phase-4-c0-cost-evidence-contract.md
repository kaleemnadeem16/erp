[← Phase 4 plan](phase-4-sales-quotations-and-handover.md)

# C0 — Correct the existing cost contract: revised owner proposal

**Date:** 2026-10-10. **State:** owner-approved contract; C1a–c/C2a–b implementation authorised, C1d excluded; [ADR 0110](../decisions/0110-version-cost-evidence-without-changing-legacy-estimates.md).
**Constraint:** owner confirms no real business/production data; local commits only, no remote push.
The compatibility-first draft at `9dced38` is historical. [Verified dependencies](phase-4-cost-dependency-map.md)
replace assumptions about preserving legacy consumers. R2 stays closed; Q41 is resolved by owner acceptance of 0110.

## Business example and correction

Materials cost 200.0000 and labour has no configured rate. Today's arithmetic may show 200.0000
as the whole cost. Revised output shows known contribution 200.0000, PARTIAL, complete total null.
If labour has an eligible rate explicitly set to 0.0000, the same total can be COSTED: the evidence,
not whether the number is positive, distinguishes the cases.

The previous plan kept misleading numeric responses beside separate evidence APIs until a later
retirement decision.
With migratable development consumers, that doubles contracts and leaves the old ambiguity accessible.
**Recommend correcting existing operations and migrating their complete consumer graph together.**
No `/cost-evidence` or `/material-preview-evidence` compatibility route, global API v2, scalar
zero fallback or legacy result adapter remains after migration.

## Alternatives and effects

| Alternative | Effect | Recommendation |
|---|---|---|
| Correct existing cost APIs/DTOs and migrate all readers | Intentional response/type change; one correct contract | Preferred; verified repository dependencies can migrate in coordinated groups. |
| Extend existing APIs with evidence | Useful if complete fields also become correctly nullable and every reader changes | Use typed evidence within the corrected response, not status next to knowingly wrong totals. |
| Separate operation/endpoints | Appropriate for genuinely different business questions | Existing per-unit costing and contextual preview are already different operations. No extra route merely to preserve old numeric behaviour. |

Do not assume no quotations means no dependencies. The [map](phase-4-cost-dependency-map.md) includes
recursive Actions, proposal/mix/variance/approval advice, direct tests, six UI components, generated
clients and demos. No external consumer was found in-repo; the owner's development constraint,
not a claim of exhaustive external discovery, permits coordinated change.

## One authoritative resolution

```text
CostResolution
  schema_version: 1
  status: COSTED | PARTIAL | UNKNOWN
  amount_basis: REQUIRED_QUANTITY | OUTPUT_BATCH | WORK_QUANTITY | SELECTED_DRAW
  total_cost?, unit_cost?, known_cost?, currency_id, unit_basis, valued_quantity
  coverage[]: item/stock_uom, required, costed, unresolved (where applicable)
  diagnostics[]: code, dependency/path (authorised projection only)
  source_type/method/strategy, scope_requested/used/changed, policy_version?
  requested_as_of?, effective_as_of, observed_at, copied basis[], basis_fingerprint?
```

| Status | Complete amount | Known contribution |
|---|---|---|
| COSTED | Valid evidence covers all applicable required costs, including true zero | Equal to complete total |
| PARTIAL | Total/unit cost null; never extrapolate a known subset | Established contributions only, possibly genuine 0.0000 |
| UNKNOWN | Total/unit cost null; no applicable reliable cost or unsupported context | Null, not an invented zero |

A material requirement of 10 units with 6 supported at 2.0000 has known cost 12.0000 and incomplete
requirement cost. A selected draw of precisely those 6 units can itself be COSTED at 12.0000 while
leaving shortage 4. The amount basis/quantity must make that distinction explicit. Stock-mix control
can compare evidenced covered-draw unit costs; it must not certify the whole requirement as complete.
Do not sum kilograms and labour hours into an Assembly coverage percentage.

Valid active positive-quantity AVCO evidence can value the entire requested estimate quantity even
if physical stock is insufficient; availability/fulfilment is separate. FIFO or specifically chosen
stock cannot invent cost for uncovered quantity. These coverage semantics remain for owner approval.

Genuine zero requires an actual valid rate/override/Inventory basis. Missing/empty/frozen cost state
is not zero; no last-valid average borrowed from an unusable state. No overhead policy is intentional
zero overhead, not proof unknown direct cost is complete. No required cost at an already legal zero
quantity means zero amount, not a valid unit rate for positive demand. Do not change quantity rules.

Money remains decimal(19,4) strings; internal decimal factors/coverage retain needed precision.
Approved initial query limit is 12 integer/9 fractional stock-unit digits; existing public technical
inputs retain their six-place bounds. Reject overflow/unrepresentable input, never silently truncate.
No new quotation precision decision, historical migration edit or new stock posting precision follows.

## Propagation, quantities and derived amounts

- Preserve technical selection/nesting, rate context, output/work units, conversion, wastage and
  multipliers. Invalid units/cycles/IDs are still errors, not swallowed as UNKNOWN.
- Material leaves use Inventory resolution. Resources use eligible dated rate/explicit override;
  missing material and missing resource have separate diagnostics. No positive-only evidence filter.
- Parent COSTED requires all applicable required obligations resolved. Mixed known/unresolved becomes
  PARTIAL; wholly unresolved remains UNKNOWN. Non-applicable zero cost kinds cannot promote certainty.
- Gather actual required material quantities across nested/formulation/component paths before
  quantity-dependent costing. Group identical Item/scope/selection demands within one estimate so
  FIFO layers are not repeatedly reused. Preserve contribution provenance; propose stable path-order
  attribution of returned allocations, with rounding residual accounted for exactly once. Attribution
  is an explicit C0 review item, not a new Inventory valuation method. Standard and what-if plans
  are separate hypothetical scenarios on the same read snapshot, not one combined consumption.
- A one-unit FIFO estimate cannot simply be multiplied into a 250-unit job. Preview costs the full
  resolved demand; per-unit endpoints answer their own unit/batch question. Preserve all existing
  fully costed supported arithmetic/rounding when the source and quantity basis are equivalent.
- Percentage overhead requires its complete driver. Unknown driver means unknown complete overhead;
  do not apply the percentage to a known subtotal. Independent work/hour drivers remain evidenced
  when their own inputs are known. Keep scope precedence and avoid overhead feeding itself.
- `sell_per_unit` remains estimate guidance, nullable for incomplete required base cost. It is not
  maintained/approved Sales pricing. No second markup or guidance formula over partial totals.
- Variance complete impact is null when its comparison is incomplete; labelled known impact only
  covers jointly evidenced matching obligations. FIFO/nonlinear cost bands need resolved standard
  and planned scenarios, not quantity delta multiplied by a guessed constant rate. Never subtract unrelated known subtotals and call
  that a reliable difference. Quantity-only control outcomes remain valid independently.

Preserve existing legitimate zero-base control distinctions: `EvaluateMaterialVariance` cannot
judge a cost percentage with zero standard cost, although an absolute amount can be judged.
`JudgeStockMix` treats evidenced zero-versus-zero as no cost increase; positive cost above zero is
not evaluable as a percentage. Missing evidence never takes the zero-versus-zero branch. R5 owns
adoption consequences; C0 does not automatically pass unevaluable controls.

## Scope, time, currency and fingerprints

0077 scope rule remains chosen stock → explicit document scope → governed company default
(COMPANY initially) → unknown if unsupported. Preserve actual scope change; no automatic warehouse
blend or hidden fallback. Batch/serial choices are explicit for applicable AVCO/SPECIFIC cases.
Scope authority is intersected, never widened to obtain a cheaper rate. Current location/batch-only
preview mix input cannot always identify serial-specific cost. Return identification-required UNKNOWN
until a deliberate serial-aware input/DTO/UI extension is included; never use a batch average to
pretend a serial was chosen. Owner review must choose the initial exposure scope.

Initial material valuation is a consistent current read. Mutable pool/layer balances cannot provide
arbitrary historical cost by filtering `received_at`. Unsupported historical/future requests return
UNKNOWN / `AS_OF_NOT_SUPPORTED` with requested time retained. Resource effective date and material
observation basis are separate explicit evidence; do not relabel a mixed basis as one historical date.
Saved adopted/published evidence is never recalculated from this query.

Standalone read sessions use one consistent read-only snapshot, no reservation/allocation locks or
writes. Future adoption transaction/isolation/revalidation belongs to R5. Fingerprint deterministic
source/context/quantity values, not observation timestamp alone. Protected evidence/hash must not
leak hidden recipe/stock changes; client hashes never authorise adoption.

Inventory owns base-currency pool/layer values and copied original currency/FX evidence. No current
FX revaluation, supplier-price fallback or new estimating-cost family is introduced; R4 owns remaining
valuation policies. Resource/override amounts keep their declared existing basis.

## Existing API operations — coordinated corrected schema

All paths below are existing `/api/v1` operations; keep operation identity and migrate responses.

| Operation | Corrected response proposal | Coupled consumers |
|---|---|---|
| GET `formulation-versions/{formulation_version}/cost-breakdown` | `version_id`, canonical `cost: CostResolution`, typed `lines[].cost` and recursive `sub_formulation`. Remove redundant scalar `total_cost`/`cost_per_output_unit` aliases; equivalents are `cost.total_cost`/`cost.unit_cost` with output basis | Formulation Action/DTO, Assembly/preview callers, Resource, generated hook and recursive panel |
| GET `assembly-versions/{assembly_version}/cost-breakdown` | Canonical `cost`, `cost_by_kind`, component resolutions, overhead resolution/driver, labour hours and nullable `sell_per_unit`. Old complete-cost scalars replaced, not retained as zero aliases | Assembly Action/DTO, preview, Resource, generated hook and panel |
| POST `assemblies/{assembly}/material-preview` | Technical data unchanged; cost breakdown uses same resolution; variance adds certainty/complete versus known impact; proposal/mix explicitly expose selected-draw cost and requirement coverage | Preview/control graph, Request/projection, generated types and four preview UI consumers |

Use typed Resources/OpenAPI definitions for recursive/null data, not untyped array casts. Schema
version identifies saved-value structure for future R5, not a parallel API generation. Optional
costing context on existing operations needs typed Request validation; chosen-stock input remains
preview-only until a genuinely different operation is justified. Do not turn GET into a write.

Valid missing evidence returns typed status, not an invented number; validation/authority/company
errors and unexpected failures remain distinct. Regenerate OpenAPI and clients, then migrate all
callers in the same completed integration unit. No transparent fallback or hand-edited generated code.

## Authority and control evaluation

Cost visibility and authorisation to evaluate an internal business control are different. Existing
preview/approval advice deliberately evaluates costs for authorised operations even when amounts
are hidden. Preserve that behaviour: guarded Actions establish permitted evaluation context; the
Inventory port checks company/scoped IDs, while public read Resources project costs only with the
appropriate capabilities. Do not require every preview user to gain `inventory.valuation.view`.

Standalone cost reads require `estimating.internal_cost.view` at Request and Action; preview retains
`estimating.material_preview.run`, recipe/availability/choose-stock distinctions and no-cost operation.
Current Estimating inventory input uses `estimating.inventory_availability.view`; do not replace it
with generic Inventory permission by implication. Recipe/stock detail, reasons and fingerprints remain
field-scoped. No caller-controlled `includeCost` boolean grants authority in the corrected entry Action.
Q26 limited-scope integration remains explicit: narrow or refuse rather than claim it already exists.

## Tests, migration and approval gates

Replace deliberately incorrect zero-fallback assertions with UNKNOWN/PARTIAL and real-zero tests;
update direct constructors, API fixtures and demo expectations. Do not preserve them as a second
numeric contract. Preserve hand-computed fully costed, technical quantity, version, control and posting
regressions. Support now-implemented methods/scope in tests without rewriting invalid-request refusals.

DTO/API correction needs no schema/backfill. Keep valid development rows/seeders; add idempotent
zero/missing cases. Configurable default-scope history remains a concrete separately authorised
additive migration; no local reset or historical migration rewrite. Every completed slice must have
compatible backend/frontend/generated consumers and appropriate tests. [Slice plan](phase-4-c1-c2-cost-evidence-slices.md)
defines coupling and local checks.

Owner review approved the revised in-place response/DTO contract, demand attribution and coverage,
query limits/current-only temporal support, projection/evaluation authority and implementation cohorts.
The owner confirmed 0077's legacy-retirement sequencing refinement and accepted 0110 on 2026-10-10.
C1a–c/C2a–b code is authorised; C1d migrations and serial-selection UI are excluded. R3 can research source-neutral pricing separately;
R5 owns adoption/staleness/approval invalidation. Not every quotation needs complete material costing.

## Related

- [Verified dependency map](phase-4-cost-dependency-map.md) · [C1/C2 slices](phase-4-c1-c2-cost-evidence-slices.md)
- [Accepted ADR 0110](../decisions/0110-version-cost-evidence-without-changing-legacy-estimates.md)
- [0077](../decisions/0077-generic-material-cost-resolution-with-a-configurable-costing-scope.md) · [0109](../decisions/0109-shared-commercial-quotation-lines-and-optional-estimating.md)
- [Compatibility plan](phase-4-r2-compatibility-plan.md) · [Phase 4 plan](phase-4-sales-quotations-and-handover.md)
