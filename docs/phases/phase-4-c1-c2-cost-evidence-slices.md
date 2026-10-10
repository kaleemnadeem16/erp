[← Phase 4 plan](phase-4-sales-quotations-and-handover.md)

# C1/C2 — Coherent correction slices for the revised C0

**Date:** 2026-10-10. **State:** C1a–c/C2a–b authorised by the owner; C1a–c internal query delivered; C2 integration remains in progress.
C1d remains unauthorised.
[Revised C0](phase-4-c0-cost-evidence-contract.md) and [accepted 0110](../decisions/0110-version-cost-evidence-without-changing-legacy-estimates.md)
replace the `9dced38` compatibility-first plan. [Dependency map](phase-4-cost-dependency-map.md)
identifies every affected repository cost path. Commit locally only; do not push without explicit request.

## Local C1 checkpoint — 2026-10-10

C1a–c is delivered as an internal query, registered strategies and guarded cost-read adapter.
Existing public APIs/DTOs and screens have not yet migrated; their old behaviour is historical
implementation awaiting C2, not a second endorsed cost contract. C1d remains unauthorised.

Validation: 1,252 Inventory/architecture tests passed (6,552 assertions), then 23 focused query/
availability tests passed (95 assertions) including added QA, rounding and overflow cases.
Pint passed; full Larastan passed. No posting file, migration, generated client or frontend consumer
changed in this slice. Frontend/browser/codegen checks do not apply until C2 changes those surfaces.
No public demo surface exists yet; C2 owns the demo scenarios alongside the migrated screens.

Read-only applicability: no new persisted business fact, transition, permission, route, settings or
job, so migration/rollback, audit writes, idempotency and rollout grants are not applicable. Existing
capabilities and tenant/limited-scope guards are tested. Snapshot/history persistence remains R5.


## Local C2a checkpoint — 2026-10-10

C2a is implemented and locally verified as one cohort: `IngredientCostResolver` is removed; formulation,
Assembly, preview cost and variance use canonical `CostResolution`s; both GET cost-breakdown routes and the
material-preview cost/variance fields carry typed Resources; the four panels and `variance-what-if` read the
regenerated client. Cost disclosure is field-scoped: a holder of only `estimating.internal_cost.view` receives no
pool, layer, location, batch, serial, on-hand or source-document facts, and a fingerprint that does not move with
stock (`CostResolution::forReader`). Draw/mix pricing and ranking (C2b) follow in the next checkpoint. C1d remains unauthorised.

Validation: focused cost cohort and ratchets pass; the full backend suite passed apart from two `LoginTest`
failures that exist at HEAD (the login route carries no `throttle:5,1`; unrelated to costing); Pint and Larastan
clean; client regenerated to completion with only explained drift; the three typechecks and `vite build` pass;
cost, preview, estimating, variance, selection and accessibility browser specs pass. `recipe-access.spec.ts`
fails on a stale "Pack conversions" heading changed by the Items commit f01848b (not cost-related).

## Local C2b checkpoint — 2026-10-10

C2b is implemented: `QueryStockAvailability` no longer reads cost (its positive-only average could not tell a genuine
zero from absence). Balances are ranked, and proposals, alternatives and hand-built mixes are priced, through
`QueryMaterialCost` by `PriceStockDraw` as `SELECTED_DRAW` resolutions in separate scenarios on one snapshot. The
draw's cost is complete when its own lines are costed even with a shortage; the shortage stays separate. A genuine zero
ranks first, so `AllocateInventory::RULES_VERSION` is 2; unknown evidence ranks last and the plan says it is cheapest
only among evidenced balances. `JudgeStockMix` is unchanged: an incompletely costed draw is `NOT_JUDGED`, zero-versus-zero
is no increase. The preview's scalar `unit_cost`/`line_cost`/`estimated_cost`/`cost_method` fields are replaced by typed
`cost` resolutions on plans, lines and mixes; `inventory-proposal-panel` and `stock-mix-editor` read them. Serial-aware
selection is not added; unidentified SPECIFIC stock remains UNKNOWN.

## Delivery principle and dependency order

C0 approval → C1a query/access/session → C1b strategies → C1c scope/evidence integration →
C2a complete estimate integration cohort → C2b draw/control integration cohort → milestone regression.
C1d governed default-scope persistence is separately authorised after C1c; built-in COMPANY and
explicit scopes remain usable before it. C3 wording and R3 research are independent.

C1 introduces an internal port without changing existing public consumers; tests prove it works.
C2 corrects the existing APIs in place. No permanent old/new endpoints or fallback arithmetic paths.
Each cohort includes backend, direct callers, generated client and UI before it is a completed slice.
Temporary work on an integration branch is not a completed release or an extra public contract.

Formulations feed Assemblies, which feed preview; changing one non-null DTO in isolation breaks
the downstream graph. Those linked changes belong in one integration cohort. Do not pretend each
file is an independently shippable feature. Keep each cohort small by excluding unrelated pricing,
persistence, catalogue redesign and Inventory posting.

## C1 — Inventory-owned query (no public API change)

| Slice / owner | Exact work | Completion/test boundary |
|---|---|---|
| C1a — Inventory + access review | Typed `MaterialCostRequest/Resolution`, strategy registry and current read session; guarded domain adapters authorise read versus internal control evaluation. Explicit item/stock quantity/scope/selected stock and temporal context; no new standalone HTTP endpoint | DTO/status/decimal/overflow, same-company/scope checks, direct guard tests, summary/detail projection, unsupported method/date UNKNOWN; read-only transaction/no reservation/no allocation locks. Existing APIs and all consumers unchanged. |
| C1b — Inventory | AVCO reads actual active pool values; FIFO read-only eligible layers ordered `(received_at,id)`; SPECIFIC identified stock; genuine zero accepted when source valid. No company blend of warehouse pools or last-valid invalid-pool fallback | Company/warehouse/batch scopes and differing requested/used scope; missing/empty/frozen evidence; zero versus absence; quantity shortage/partial FIFO; SPECIFIC identification; QA/usable selection and decimals. Existing posting/valuation regressions unchanged. |
| C1c — Inventory + Estimating scope integration | Apply chosen → document → governed/default COMPANY → unknown. Copy real values/currency/time/scopes/coverage/fingerprint; batch equivalent Item/scope demands for one estimate without reusing layers | Consistent concurrent read snapshot; current-only time refusal, stable/drifting/protected fingerprint; duplicate selected basis, no hidden scope expansion; batched versus independent demands demonstrate no double-use. Unchanged existing consumers until C2 activation. |
| C1d — Estimating settings | Governed default-scope history, reasons/actor/version, capability registry/grants after vocabulary review; additive migration only when specifically authorised | Built-in COMPANY with no configuration; eligible warehouse default/future-only changes; immutable history, HTTP/Action authority, concurrent effective selection, isolated fresh/rollback/reapply and idempotent demo. Not required to expose advanced settings for every company. |

C1a–c can be reviewed/committed as tested internal functionality while existing APIs remain running;
that short integration interval is not endorsement of the old zeros or delivery of corrected consumers.
C1 completion must distinguish delivered query from still-owed configurable defaults/adoption snapshots.

Use Inventory `AvcoPool`, `StockLayer`, current eligibility facts and currency/base evidence.
Do not use availability weighted-average/positive-only cost as the authoritative query, invoke
`LayerAllocator::lockIssueLayers/recordConsumption`, or change `PostInventoryMovement`. Narrow
extraction of pure eligibility facts requires posting regression parity. Initial historical/future
valuation unsupported → UNKNOWN, not today's value under a different date.

## C2a — Estimate graph, existing cost APIs and their readers

**Owner:** Estimating backend/API and estimating frontend together. Dependencies: approved C0 + C1a–c.
This is one completed integration unit because the non-null DTO graph is tightly coupled.

1. Correct `IngredientCostResolver` and all four callers: `ExplodeFormulationVersion`,
   `ExplodeAssemblyVersion`, `PreviewAssemblyMaterials`, `PreviewMaterialVariance`. Prefer a thin
   0077 port adapter only if it has a real domain purpose; otherwise remove the scalar resolver.
   No zero-preserving compatibility adapter after the final caller migrates.
2. Change existing formulation/line and Assembly/component result DTOs to canonical resolutions;
   propagate missing/partial leaf/resource state. Keep rate lookup nullable and actual zero valid.
   Gather actual demand/context before quantity-dependent material costing; deterministic attribution
   retains component/nested provenance. Keep complete arithmetic and rounding fixtures identical.
3. Correct dependent overhead and selling guidance; context-resolved preview must not silently use
   incompatible static materials. Correct variance cost evidence and complete versus known impact.
   Standard/what-if scenarios share an observation, not simulated consumption; nonlinear cost bands
   use resolved comparisons rather than an assumed scalar unit rate. Internal policy evaluation still works for authorised preview users without revealing amounts.
4. Migrate both existing GET cost-breakdown routes and existing POST material-preview cost/variance
   fields. Typed Resources, Request/Action read guards and source projections; no duplicate routes.
5. Regenerate affected clients and migrate Assembly/formulation cost panels, preview cost display
   and `variance-what-if` together. Remove their duplicate hand-written non-null interfaces/casts.
   Do not hand-edit generated code or overwrite other agents' in-progress client output.
6. Update direct test constructors/DTO reads, API fixtures and relevant demo expectations. Replace
   zero-fallback assertions with absent-evidence and real-zero cases; remove obsolete adapters/types.

**Completion tests:** nested/wastage/unit/cycle/version and context parity; missing/all-missing/mixed
obligations; genuine zero resource/override/pool; partial/full/null totals and known-only labels;
percentage versus independent overhead; complete 88.5500 cost/106.2600 guidance; no null coercion to
zero; capability/recipe/stock/company/scope projection and fingerprint leakage; blind policy advice;
OpenAPI nullable recursive types, generated-client drift, all three typechecks/build and impacted UI
journeys. Current inventory proposal quantities/selection are retained until their C2b cost migration.

C2a uses one authoritative resolution for the estimate/variance operations. Remaining draw-cost
semantics are a genuinely distinct covered-draw operation, not a retained legacy estimate endpoint.
Neither a proposal nor a drawn subtotal may be used as complete requirement evidence in the interim.
If the chosen Resource/schema change touches draw consumers, include them in C2a rather than shipping
an incompatible partial cohort. Completion is based on actual dependency edges, not nominal row names.

## C2b — Proposal/mix pricing, comparisons and control readers

**Owner:** Estimating allocation/controls + preview frontend, with Inventory query review.
Dependencies: C1a–c and the shared C2 resolution/schema; can be developed alongside C2a, integrated
before declaring C1/C2 correction complete.

- Migrate `AllocateInventory`, `InventoryAllocation/Line`, `PriceStockMix`, `StockMix/Line`,
  `JudgeStockMix` and `PreviewInventoryAvailability` response/projection. Scope and physical coverage
  remain explicit; price the actual selected/proposed draw through the Inventory port, with same
  resolution type and labelled amount basis. No draw subtotal becomes full requirement cost.
- Current mix input lacks individual serial identity. Query supports identified SPECIFIC evidence,
  but ambiguous current input returns UNKNOWN. A serial-aware Request/DTO/lookup/UI extension, if
  approved, is part of a complete C2b source-selection slice; do not claim it is already supported.
- Keep cost-ranking evidence consistent with cost displayed/compared. If query-derived evidence
  changes genuine-zero ranking or selection, bump `AllocateInventory::RULES_VERSION` and test the
  explained rule; do not silently rank on one basis and claim a different optimised cost. Any such
  selection-rule change requires C0 approval, not a new Inventory posting method.
- Preserve QA/location/stock bounds, chosen-stock authority, quantity increments and draw identity.
  Stock-mix zero-versus-zero no-increase and positive-above-zero undefined percentage stay distinct.
  Missing/partial compared-basis cost is not judged; quantity-only controls remain valid.
- Preserve material/stock approval subject refusal until actual plan adoption and existing reason/
  independent-approval policy. Do not fabricate adopted plans or switch off controls for direct Items.
- Migrate `inventory-proposal-panel`, `stock-mix-editor`, preview generated models and backend/browser
  assertions with their existing endpoint; remove any scalar alias with no remaining justified reader.

**Completion tests:** exact draw cost/status and requirement shortage separately; partial covered
cost; true zero ranking/comparison; deterministic selection/tie/criteria evidence; masked costs and
internal approval facts; held/foreign/duplicate stock refusal; unchanged no-posting/reservation;
capability/limited-scope guards; client generation/typechecks/build and stock-mix/preview browser states.

## Regression and fixture plan

| Existing suite / caller | Preserve valid semantics | Correct assertions/add coverage |
|---|---|---|
| `FormulationExplosionTest` | Nesting/wastage/UoM/cycle and fully known rates | Replace missing-item zero test; newly supported FIFO/scoped queries, uncertainty at every level and no duplicate layer use. |
| `AssemblyCostRollUpTest` | Hand-computed complete totals, markup/margin, time conversion, overhead precedence | Replace missing-resource zero test; true zero, partial drivers, nullable guidance, source/date context. |
| `FormulationApiTest` | Guarded resolved breakdown and recursive recipe | Replace uncosted recipe's zero payload fixture; typed cost and nullable recursive source assertions. |
| `MaterialPreviewApiTest` | Context, read-only counts, six-place inputs, no-cost/availability/recipe personas, fingerprints | Total versus known contribution; variance signed known impact; genuine zero and hidden-policy evaluation; updated proposal/mix basis. |
| `MaterialVarianceEvaluationTest`, `MaterialVarianceApprovalTest`, `StockMixControlTest` | Strongest bands, reasons/approvals, zero-base distinctions and fail-closed modes | Unknown does not pass; costed zero absolute amount; partial compared basis; matching cost-delta evidence and source-safe diagnostics. |
| `ApprovalsDemoSeederTest` direct variance call; demo tests | Authorised blind advice; valid catalogue/rate/policy examples | New signatures/DTOs and idempotent missing/zero demo cases, not extra cost-read grants or database reset. |
| Inventory posting/QA/package/conversion and access ratchets | All valid physical/valuation behaviours and source scope | Query does not write/lock; extraction parity, cross-company/scope, structured read authority and projections. |

Existing fixture locations and frontend/generated readers are in the [map](phase-4-cost-dependency-map.md).
New focused tests cover query/strategy, propagation, demand attribution and end-to-end API/UI semantics;
no test preserves known incorrect totals merely for compatibility.

## Validation, data and stopping gates

Run focused tests during code development and broader local checks at C2 integration milestones:
Pint/Larastan, relevant/full Estimating and Inventory regressions, permissions/company/scope/security
ratchets, OpenAPI/client drift, three typechecks/build, affected browser specs incl. mobile/light/dark.
No need to trigger remote CI; commits remain local until explicitly requested. Report actual checks.

DTO/API certainty needs no migration/backfill. C1d scope history is additive, with a real down and
isolated fresh/rollback/reapply checks only when applicable; no historical schema rewrites or developer
reset assumption. Demo reruns must be idempotent and retain valid estimating/stock data. Generated
client operations that clean the tree must wait for/coordinate with other agents' work.

Before code: owner approve revised 0110/C0, response and demand-attribution/coverage rules, temporal/
quantity limits, authority and any cost-ranking effect, plus named implementation cohorts and optional
C1d schema slice. C0 revision alone authorises none of them. R3 research can continue separately;
R3 decides source-neutral pricing/completeness gates and R5 adoption/staleness/approval invalidation.
C3 guidance wording remains independent; no Sales pricing or quotation implementation here.

## Related

- [Revised C0](phase-4-c0-cost-evidence-contract.md) · [Dependency map](phase-4-cost-dependency-map.md)
- [Proposed ADR 0110](../decisions/0110-version-cost-evidence-without-changing-legacy-estimates.md)
- [Compatibility handoff](phase-4-r2-compatibility-plan.md) · [Phase 4 plan](phase-4-sales-quotations-and-handover.md)
