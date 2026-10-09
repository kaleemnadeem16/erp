[← Phase 4 plan](phase-4-sales-quotations-and-handover.md)

# C1/C2 — Reviewable cost-evidence implementation slices

**Date:** 2026-10-09. **State:** implementation breakdown conditional on owner approval of
[C0](phase-4-c0-cost-evidence-contract.md) / [proposed ADR 0110](../decisions/0110-version-cost-evidence-without-changing-legacy-estimates.md).
No code/migrations authorised. Each row is an independently reviewed delivery, with scope, tests
and completion boundaries. Recheck shared checkout and current module state before implementation.

## Dependency order and proposed locations

C0 approval → C1a → C1b/C1c → C1d → C2a → C2b → C2c → C2d.
C1e scope configuration follows C1a/C1d independently of initial built-in COMPANY consumers.
C3 wording and R3 research need not wait for execution of these slices. R5 adoption remains separate.

New class names are proposed boundaries, not existing APIs. Inventory keeps its query/strategy DTOs;
Estimating owns source-aware estimate aggregation and scope-policy integration. Tests use isolated
databases; no stock writer or universal Sales pricing engine is part of these slices.

## C1 — Inventory-owned generic material cost query

| Slice / owner | Exact delivery and existing inputs | Required tests / acceptance |
|---|---|---|
| C1a Contract, access and read session — Inventory + Estimating | Inventory `Support/MaterialCost{Request,Resolution,Basis}`, strategy registry and `Actions/QueryMaterialCost`; guarded Inventory/Estimating adapters. Explicit stock-unit quantity, chosen lines, scope/context and current read session; status/null/known-cost shape per C0 | DTO/decimal/status invariants; invalid units/quantities/money bounds; same-company references; direct Action authority; separate cost/stock projection; unsupported method/time UNKNOWN; no write/reservation/lock calls. Unknown is never auto-converted to zero. |
| C1b AVCO — Inventory | Read existing `Models/AvcoPool.php`, active status, `base_avg_unit_cost`, real source quantities/values and configured pool scope. Support company-item and identified warehouse/batch pools; declared scope change recorded. No merging warehouse pools, no last-valid fallback from invalid/non-positive pool state | Active positive pool normal and evidenced zero value; absent/empty/frozen pool; warehouse/company mismatch; identified batch versus missing batch; required quantity exceeds available but valid estimate rate remains COSTED with availability separate; differing requested/used scope; cross-company and limited scope refusal. |
| C1c FIFO/SPECIFIC — Inventory | Read `StockLayer`, source eligibility/QA and scoped locations/batches/serials. Simulate oldest-first `(received_at,id)` for FIFO; SPECIFIC requires identified stock. Read shared pure eligibility facts; never call `LayerAllocator::lockIssueLayers`, `recordConsumption` or `PostInventoryMovement` | Multi-layer order/tie, partial coverage/shortage, zero-valued real layer, held/unusable/foreign stock, exact SPECIFIC selection and absence of selection UNKNOWN; batch/serial ambiguity refused; frozen/unusable evidence; decimal allocations; no stock/layer/consumption changes. |
| C1d Evidence assembly/integration — Inventory + Estimating | Query picks registered method and declared scope; copies currency/base values, selected basis, coverage and deterministic fingerprint. Scope adapter implements chosen → explicit → governed default → unknown; before configurable setting exists, only explicit and documented built-in COMPANY | Consistent read under concurrent receipts/consumption; unsupported historical/future requests cannot read current data as historical; known zero preserved despite availability's positive-only filter; fingerprint stable on unchanged basis and sensitive to real dependencies; incomplete chosen mix without invented remainder cost; no scope fallback after a failure. |
| C1e Governed default configuration — Estimating settings + Inventory review | Separately implement accepted ADR 0077 default-scope history; do not repurpose `MaterialSelectionPolicy.strategy`. Add company-scoped versioned history, reason/actor/effective scope and registration/grants for provisional `estimating.costing_scope.manage` after vocabulary review | Built-in COMPANY with no row; warehouse default, future-only change, same-company warehouse guard, permission at Request/Action, immutable history, effective resolution/concurrent policy change. Migration fresh/rollback/reapply and meaningful demo only when this slice is authorised. |

**C1 completion distinction:** C1a–d deliver the read-only query for explicit/built-in scopes;
they do not deliver governed configurable defaults or quotation snapshots. C1e is a progressively
exposed configuration delivery obligation under 0077, not an excuse to claim full ADR delivery early.
Configurable UI exposure remains progressive; no blanket company-setting/backfill operation.

C1 proposes no stock schema/valuation algorithm change. Read the existing Inventory basis directly,
not `QueryStockAvailability`'s weighted-average/positive-only cost as a generic result. Share pure
eligibility calculations only if their extraction leaves posting behaviour exactly unchanged;
protect that extraction with existing posting tests before and after. C1 FIFO simulation is an
estimate, not a promise that posting will consume those layers later.

## C2 — Estimating certainty, APIs and consumer adoption

| Slice / owner | Exact delivery and affected locations | Required tests / acceptance |
|---|---|---|
| C2a Cost-aware formulation evidence — Estimating | Add `ResolveFormulationCostEvidence` / new result DTO over existing technical version/nesting resolution; replace scalar leaf use only in the evidence path with C1. Preserve `ExplodeFormulationVersion` legacy entry/DTO and its unsupported-method/zero behaviour | Fully costed nested/wastage/UoM arithmetic matches existing fixtures; unknown/partial leaf propagates at every level; source zero is COSTED; known subcost never becomes complete parent rate; cycle/depth/invalid units still fail; legacy zero/FIFO/scoped tests unchanged. |
| C2b Assembly/resource/overhead evidence — Estimating | Add `ResolveAssemblyCostEvidence`, resource evidence with explicit effective date and rate/override identity, and evidence path for context-resolved material requirements. Use existing `ResolveResourceRate`, application/formulation resolution and overhead calculator; narrowly share pure quantity logic where needed | Same five-part totals/markup/margin on complete input; missing resource versus configured/override zero; material unknown plus known resource produces PARTIAL; wholly unknown required direct cost remains UNKNOWN; dependent percentage overhead incomplete, independent overhead known; missing base cost yields null complete guidance; dates, context and versions retained. |
| C2c Cost APIs/preview control integration — Estimating API + access review | Implement the three proposed evidence routes/Resources from C0, guarded Actions/Requests, typed diagnostics and OpenAPI. Evidence counterparts of `PreviewAssemblyMaterials`, `PreviewMaterialVariance`, `PriceStockMix` use C1/C2 results for cost-dependent advice; preserve old entry paths | Legacy API payload/numeric/nullability and failures unchanged; new unknown is a successful typed result, malformed input/authority refusal follows conventions; zero baseline percentages not divided by zero; no hidden cost/recipe/stock/hash leakage; read-only preview counts unchanged; foreign IDs/scope refusal and direct-Action guards. |
| C2d Typed client and consumers — Estimating frontend | Regenerate affected clients; explicitly migrate Assembly/formulation cost panels and material-preview evidence consumer. Known partial cost is labelled incomplete, complete total/guidance absent; permission-aware technical preview remains available. Update inline manual interfaces to generated evidence types | Three typechecks/build/client drift; targeted estimating/browser/mobile/light-dark tests; no protected fetch for cost-less personas; no fallback to legacy totals on error; no subtotal presented as total; genuine zero renders as known; repeat preview and context changes remain safe. |

**No broad rewrite:** reuse technical quantity/version/rate resolvers and arithmetic; extract only
needed pure helpers with baseline regression parity. New nullable evidence DTOs must not replace
legacy BigDecimal-only DTOs in place. New Assembly evidence uses the declared resolved context;
legacy version-only costing and context-resolved preview are not assumed identical when their
inputs differ. Do not lose explicit formulation choices or customer-account override eligibility.

Four legacy scalar consumers remain inventoried:
`ExplodeFormulationVersion`, `ExplodeAssemblyVersion`, `PreviewAssemblyMaterials`,
`PreviewMaterialVariance`. C2 evidence counterparts eliminate scalar fallbacks for migrated
consumers; legacy APIs retain those calls until separate retirement. New server authority uses
only evidence, not a legacy DTO coerced into a nullable one. No route to legacy guidance may
be used as a cost-completeness check.

`PriceStockMix` is an additional integration surface: it uses availability cost today, which cannot
distinguish all genuine zero values. Preserve its quantity/QA/choose-stock checks; evidence pricing
must obtain actual identified source costs through C1. Absolute cost bands can judge a known zero;
percentage bands with zero denominator remain explicitly not evaluable, never auto-pass or divide
by zero. R5 decides adoption handling of not-evaluable controls; no current issue engine is created.

## Precise regression anchors and additions

| Existing test/location | Keep | Add for evidence path |
|---|---|---|
| `Estimating/FormulationExplosionTest.php` | `test_an_item_never_received_costs_zero`, FIFO/scoped-AVCO refusal, nested/wastage/UoM/cycle tests | Separate evidence tests UNKNOWN/null for missing pool; true zero; partial leaf/parent; fully costed numeric parity. Never change old assertions to claim the defect is corrected globally. |
| `Estimating/AssemblyCostRollUpTest.php` | `test_a_labour_item_with_no_resource_rate_configured_costs_zero_not_an_error`, hand-computed 88.5500 cost / 106.2600 guidance, markup/margin and overhead tests | New missing-resource diagnostics, zero rate/override, mixed/all-missing components, dependent overhead and null guidance, correct as-of rate. |
| `Estimating/MaterialPreviewApiTest.php` | Read-only counts, no-cost/inventory projection, fingerprint isolation, unknown planned cost, six-place request precision, stock-mix/variance checks | New evidence route/typed totals; genuine zero cost-band evidence; invalid percentage base diagnosis; full/partial/unknown plan/mix costs; hidden evidence does not change caller-visible token. |
| `ApplicationRateResolutionTest`, `FormulationApiTest`, `AssemblyVersionApiTest` | Exact/default rate, customer override, version/recipe guards | Declared context reproducibility, pinned references, same-Party/account-scoped inputs and foreign version refusal. No new Party override invented. |
| Inventory movement and `ItemQuantityUnitApiTest`; `Unit/Support/UomConversionServiceTest`; Purchasing `PackagedPurchasingTest` | Posting/valuation, technical/item conversion and package semantics | Read-query does not touch balances/layers/consumption, deterministic FIFO/SPECIFIC evidence, factors/coverage retain precision, no package conversion changes technical composition. |
| API/access ratchets + browser `material-preview.spec.ts`, `estimating-list-filters.spec.ts` | Existing guards/scopes/lookups/personas and phone layout | New evidence routes registered in guard/read/scope matrices; typed client/permission states; explicit unknown/partial/zero display and no legacy-error fallback. |

Test files above are under `backend/tests/Feature/Modules` unless stated; exact inspected evidence
links are in the [audit](phase-4-r2-compatibility-audit.md). Proposed new focused suites:
`MaterialCostQueryTest`, `MaterialCostStrategyTest`, `FormulationCostEvidenceTest`,
`AssemblyCostEvidenceTest`, `CostEvidenceApiTest`, and evidence-specific browser cases. These are
names for review, not existing tests. Assert semantics and source evidence, not implementation structure.

For each slice run applicable [pre-push checks](../architecture/pre-push-checks.md): Pint/Larastan,
focused backend tests then milestone regression suites; direct authority/company and concurrency
coverage; ratchets for new API/read/scope/permission surfaces. For API/client/UI delivery regenerate
OpenAPI/client, run all three typechecks/build and relevant browser specs. All databases isolated.
No application test run is claimed by the documentation assessment.

## API, migration and authority boundaries

- C1a–d/C2a–d need no new table, backfill or conversion of existing estimates. C1e's governed
  scope history requires an additive migration only with that slice's explicit authorisation;
  R5 adoption snapshots are later persistence, not C2 scope.
- C1 query surface is internal; Inventory callers use valuation access, Estimating callers use
  internal-cost access through guarded adapters. Cost visibility does not grant stock/recipe detail.
  Both projection and scope must protect diagnostics, source IDs and fingerprints. Refuse unsupported
  limited scopes rather than leaking company/global evidence. No new Sales grant is invented.
- C2 preview preserves technical preview permission and allows no-cost operation. New cost-only
  routes require internal-cost capability twice. Recipe/stock details and chosen-stock inputs retain
  their own capability checks; API Resources own projection, not UI CSS.
- Seed genuine zero/missing/fully costed examples only with relevant delivered tests/demos; keep old
  estimating fixtures. Do not overwrite other agents' generated clients or unrelated seeds.

## Approval and readiness gates

Owner review must approve C0 endpoint/null/known-cost contract, initial time/coverage/precision
support and scope/projection staging. Explicit code authority then names the C1/C2 slices and any
C1e migration. Do not implement all slices merely because this breakdown exists.

R3 research is independently ready after bounded R2 closure; it can use these pending technical
contracts as identified constraints while deciding source-neutral pricing and where complete cost
is required. C1/C2 implementation readiness remains conditional on C0 approval; R3 must not treat
legacy totals or an unaccepted 0110 proposal as delivered trusted cost. R5 owns adopted authority,
staleness and approval invalidation. C3 guidance wording is independent. No R3 research starts here.

## Related

- [C0 proposal](phase-4-c0-cost-evidence-contract.md) · [Proposed ADR 0110](../decisions/0110-version-cost-evidence-without-changing-legacy-estimates.md)
- [Compatibility plan](phase-4-r2-compatibility-plan.md) · [R2 research closure](phase-4-r2-quotation-structure.md)
- [0077](../decisions/0077-generic-material-cost-resolution-with-a-configurable-costing-scope.md) · [Phase 4 plan](phase-4-sales-quotations-and-handover.md)
