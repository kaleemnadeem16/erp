[← Phase 4 plan](phase-4-sales-quotations-and-handover.md)

# C0 — Verified cost dependency and coordinated-change map

**Verified:** 2026-10-09, committed baseline `9dced38` plus read-only inspection of the current
checkout. Other agents' Sales/Party/generated/UI work is outside this change. No code or database
changed. Owner confirms no real production/business data; this is not permission to reset databases.

## Business meaning and conclusion

Known material 200.0000 plus missing labour must show an incomplete estimate, not complete cost
200.0000. A recorded zero labour rate is genuinely known. Correct the existing cost operations,
migrate their consumers together, and remove the zero fallback. Keeping duplicate old/new cost
endpoints would preserve a known ambiguity without a demonstrated external compatibility need.

The repository has real dependencies: nested backend calculations, direct test callers, generated
hooks, hard-coded non-null UI interfaces and approval/control consumers. Safe correction means
coordinating those changes, not simply changing nullability in a controller. No external/custom
consumer was found in this repository; external usage cannot be proved absent by code search alone.
The owner's active-development constraint permits this deliberate coordinated contract change.

## Inspection method and edges

Searched tracked/untracked backend `app`, `tests`, demo seeders and frontend source for the cost
resolver/Action/DTO class names, their numeric properties, hooks and response fields. Inspected
routes/Controllers/Requests, generated operations/models, the local OpenAPI artefact and Orval
configuration. Inspected source semantics and relevant tests; no application tests ran.

```text
IngredientCostResolver
  → ExplodeFormulationVersion → FormulationExplosion/FormulationLineBreakdown
      → FormulationVersionController.costBreakdown → generated hook → formulation cost panel
      → ExplodeAssemblyVersion → AssemblyCostBreakdown/AssemblyComponentBreakdown
          → AssemblyVersionController.costBreakdown → generated hook → Assembly cost panel
          → PreviewAssemblyMaterials.costBreakdown
  → PreviewAssemblyMaterials material costs
  → PreviewMaterialVariance → EvaluateMaterialVariance + approval routing advice

QueryStockAvailability → StockBalance
  → AllocateInventory → InventoryAllocation/InventoryAllocationLine
  → PriceStockMix → StockMix/StockMixLine
      → JudgeStockMix → verdict/approval routing advice
  → PreviewInventoryAvailability → MaterialPreviewController → generated preview hook
      → material-preview-screen / variance-what-if / inventory-proposal-panel / stock-mix-editor

ResolveResourceRate → ExplodeAssemblyVersion → preview non-material costs/overhead/guidance
```

## Backend dependency inventory

Paths in this table are relative to `backend/app/Modules/Estimating` unless explicitly prefixed.
“Change” is proposed, not an assertion current APIs already have certainty.

| Surface | Verified dependency/assumption | Coordinated change / preserved behaviour |
|---|---|---|
| `Support/IngredientCostResolver.php` | Four scalar callers; no pool becomes zero; non-AVCO/scoped cases throw | Replace scalar public result with 0077 material resolution or remove thin resolver in favour of one Inventory port. Change all callers; no zero-preserving adapter after migration. |
| `Actions/ExplodeFormulationVersion.php` | Recursive scalar line costs/output rate; nested partial cannot be represented | Propagate resolution status/known contribution; preserve depth/cycle/wastage/global-UoM logic and fully costed rounding. |
| `Support/{FormulationExplosion,FormulationLineBreakdown}.php` | BigDecimal-only totals/unit costs | Change existing DTOs to certainty-aware values; nullable complete amounts, explicit known values; constructors and test fixtures migrate with callers. |
| `Actions/ExplodeAssemblyVersion.php` | Material scalar/nested totals, missing resource → zero; rate overrides, overhead, margin | Consume resolutions; actual zero source stays valid; dependent overhead/guidance unknown when driver incomplete. Preserve supported arithmetic and technical component semantics. |
| `Support/{AssemblyCostBreakdown,AssemblyComponentBreakdown}.php` | Non-null material/resource/aggregate costs and selling guidance | Replace complete-cost assumptions throughout DTO consumers. Do not retain two arithmetic paths for old and new semantics. |
| `Actions/ResolveResourceRate.php` | Returns nullable eligible row by effective date; used by Assembly and `ResourceRateApiTest` | Its nullable lookup is valid; caller distinguishes absent from recorded zero. Preserve CRUD/rate precedence, add copied rate/time evidence. |
| `Actions/PreviewAssemblyMaterials.php` | Scalar leaf costs; separate context-resolved material and version roll-up; private response serialisation/fingerprint | Carry certainty in existing preview. Preserve customer/context/rate/formulation resolution, exact requirements, variance inputs and non-posting. No assume static version cost equals dimension-resolved preview. |
| `Actions/PreviewMaterialVariance.php` | Positive-only filter treats genuine zero as no evidence; known-only summary can look like total; internal policy facts evaluated when costs hidden | Use evidence including zero; incomplete complete-impact null, known impact labelled separately. Keep hidden calculations for authorised policy evaluation and withheld amounts. |
| `Actions/EvaluateMaterialVariance.php` | Nullable scalar cost for cost bands; absolute zero can be measured; zero percentage base is not evaluable | Supply evidenced standard/planned cost comparison for nonlinear sources; preserve equivalent constant-rate results, quantity bands/strongest effect and undefined-percent diagnostics. No missing evidence automatically passes a band. |
| `Actions/{PreviewInventoryAvailability,AllocateInventory,PriceStockMix,JudgeStockMix,ResolveStockMixControls}.php` | Cost-ranked proposals, selected-line costs, covered-quantity comparison and approval facts | Use same resolution vocabulary but explicit draw/requirement bases. Preserve quantities, eligibility, chosen-stock bounds and controls. Reprice actual selected basis through Inventory query; do not confuse covered-draw cost with whole requirement. |
| `Support/{InventoryAllocation,InventoryAllocationLine,StockMix,StockMixLine,StockMixVerdict}.php` | Nullable drawn-cost amounts but no common evidence/status/known contribution; signatures define draw identity | Extend/migrate DTOs coherently; retain signatures, scope/coverage/shortage and deterministic selection rules. Bump allocator rules version only if ranking semantics change. |
| `Support/{MaterialVarianceApprovalSubject,StockMixApprovalSubject}.php` | Persisted routing still refuses before adoption; evidence visibility protects internal costs | Keep refusal and capabilities. Preview approval facts receive correct completeness; shared `Approvals/Actions/ResolveApprovalPolicy` already handles nullable amount/facts. Preserve null, never fake zero; no persistence/engine replacement. |

## API and client inventory

| Existing surface | Observed dependency | Migration required |
|---|---|---|
| GET `/api/v1/formulation-versions/{formulation_version}/cost-breakdown` | `FormulationVersionController::serializeExplosion/serializeLine`; generic Request, controller cost guard | Correct this route in place; typed Resource and guarded read Action/Request; migrate recursive line schema and cost-panel hook together. |
| GET `/api/v1/assembly-versions/{assembly_version}/cost-breakdown` | `AssemblyVersionController::serializeBreakdown/serializeComponent`; string totals and `sell_per_unit` | Correct this route in place; typed nullable/evidence response with derived guidance, no alternate `/cost-evidence` route. |
| POST `/api/v1/assemblies/{assembly}/material-preview` | `MaterialPreviewController`, `PreviewMaterialRequest`; policy, quantity, inventory and mix permission-dependent input | Extend same operation with authoritative certainty semantics for its costs; technical preview remains advisory and cost-optional. Preserve six-place work input and context checks. |
| Existing Resources | Assembly/component/Formulation Resources protect margins, overrides and recipe fields; cost endpoints mostly use inline arrays | Preserve field guards, add explicit cost-result Resources so schema generation can describe recursive/null data. Do not mistake model show resources for cost endpoints. |
| `backend/storage/api-docs/openapi.json` | Local generated, gitignored snapshot; three inspected operations have non-null strings and poorly inferred recursive arrays | Export from approved backend change; never hand-edit or commit stale schema output. Local artefact may include other agents' changes, so it is inspection evidence, not a release contract. |
| `frontend/packages/core-api/orval.config.ts` | Scramble JSON → tags-split React Query, `clean:true`, barrel hook | Regenerate only after shared generated tree is safe; include all explained output with corresponding backend change. No hand edits. |
| Generated operations/models | `assembly-version`, `formulation-version`, `material-preview`; `AssemblyVersionsCostBreakdown200Data`, `FormulationVersionsCostBreakdown200Data`, preview cost/variance/mix models | Replace affected response types and nullable assumptions with a common typed cost resolution. Verify exports and client drift; preserve unrelated generated work. |

Frontend locations are under `frontend/packages/core-ui/src/features`:

| Consumer | Exact assumption | Change |
|---|---|---|
| `assemblies/assembly-cost-breakdown.tsx` | Hook plus hand-written DTO cast; non-null cost/unit, component rates and selling guidance | Generated cost types, status/known contribution display, no complete guidance when incomplete. |
| `formulations/formulation-cost-breakdown.tsx` | Hook plus recursive hand-written DTO; renders nested unit costs directly | Typed recursive resolutions, null guards and visible unknown/partial state at parent and child. |
| `material-preview/material-preview-screen.tsx` | Hook plus large local shape; renders `cost_breakdown.total_cost` and breakdown amounts | Adopt typed results alongside backend preview change; preserve technical/no-cost personas and stale-request handling. |
| `material-preview/variance-what-if.tsx` | Generated variance types; signed `summary.cost_impact` shown despite missing-count field | Incomplete impact cannot be labelled total; known signed contribution separate, hidden costs remain hidden. |
| `material-preview/inventory-proposal-panel.tsx` | Nullable line rates and `estimated_cost` for proposed draw | Explicit covered-draw basis/coverage; no confusion with full required cost; genuine zero valid. |
| `material-preview/stock-mix-editor.tsx` | Mix `estimated_cost`, proposal cost and cost/percent difference fields | Only compare evidenced compatible bases; preserve zero/zero equality branch; unknown/undefined diagnostics visible where permitted. |
| Assembly/formulation edit screens | Mount panels under existing permissions; normal CRUD forms contain margin and recipe controls | Retain access, version selection and form behaviour; changes to cost-panel shape do not require catalogue redesign. |

## Tests and direct callers

All backend module tests below are under `backend/tests/Feature/Modules/Estimating`.

- `FormulationExplosionTest` directly constructs resolver/Actions and reads line DTOs. Replace
  `test_an_item_never_received_costs_zero` with missing-evidence assertions; update old FIFO/scoped
  refusal tests to the newly supported scope contract. Preserve fully costed nesting/precision/cycles.
- `AssemblyCostRollUpTest` directly constructs the costing graph. Replace missing-rate zero test;
  migrate DTO/API assertions while preserving complete 88.5500 cost / 106.2600 guidance fixture.
- `FormulationApiTest::test_cost_breakdown_returns_the_resolved_total` currently expects
  zero for an uncosted recipe; update its semantic fixture, not
  the response to match an outdated zero assumption.
- `MaterialPreviewApiTest` protects no-cost/inventory/recipe guards, non-posting, precision, fingerprints,
  variance/mix advice and unknown planned cost; migrate all cost assertions with current endpoint.
- `MaterialVarianceEvaluationTest`, `MaterialVarianceApprovalTest`, `StockMixControlTest`,
  `ResourceRateApiTest` cover direct lookup/evaluation and valid control boundaries.
- `backend/tests/Feature/Support/ApprovalsDemoSeederTest` directly invokes `PreviewMaterialVariance`
  with hidden amounts; keep policy evaluation working without granting cost-read visibility.
- Demo tests: `MaterialVarianceDemoSeederTest`, `StockMixControlDemoSeederTest`,
  `RecipeAccessDemoSeederTest`; browser `material-preview.spec.ts`, `estimating-list-filters.spec.ts`,
  `material-selection-settings.spec.ts`. Update changed assertions and add partial/unknown/real-zero cases.
- `backend/tests/Unit/Modules/Estimating/AllocateInventoryTest.php`, Inventory
  `QueryStockAvailabilityTest` and `StructuredLocationsDemoSeederTest` protect ranking/availability
  readers outside the three cost panels; include them in any advisory-source adaptation.
- Inventory posting, stock/QA, conversions and company/scope/permission ratchets protect extraction
  and new query authority. Add no-write/current-read/FIFO/SPECIFIC/zero-source regression suites.

## Inventory, demos and database boundaries

Inventory `QueryStockAvailability` exposes availability and advisory weighted-average costs;
`StockBalance` feeds the Estimating allocation/mix graph. Its positive-cost filter cannot prove real
zero cost. Preserve its physical availability semantics; any adaptation of advisory cost fields
must migrate all readers deliberately. New material valuation evidence lives in Inventory and
uses pools/layers/identified stock; it must not modify posting or call the locking allocator.
Current preview stock-mix input and `StockBalance` identify location/batch, not individual serials.
SPECIFIC query support therefore does not prove every existing UI draw can supply sufficient
identity. Ambiguous selection remains UNKNOWN; serial-aware input/DTO/UI support is a separately
identified scope extension if the owner wants that operation exposed now.

Estimating demo seeders `FormulationsDemoSeeder` (company AVCO fixtures), `AssemblyVersionsDemoSeeder`,
`ResourceRatesDemoSeeder`, `OverheadPoliciesDemoSeeder`, `MaterialVarianceDemoSeeder` and
`MaterialSelectionDemoSeeder` create valid catalogue/policy examples. Keep those records; add genuine
zero and unavailable-cost cases without resetting databases or fabricating receipts/accounts.
`DemoDatabaseSeeder` is concurrently modified by another agent; coordinate any eventual registration.

Existing formulation/Assembly/component/resource migrations need no change for DTO/API certainty.
Inventory pools/layers already store decimal values; no backfill is needed to distinguish a absent
source from a real source. Governed default-scope history requires an additive migration only when
its concrete slice is authorised; keep historical migration conventions, isolated rollback/reapply
and idempotent seed verification. “No real data” does not imply developers can reset their databases.

## Governing documents and decisions

0077 owns query/scope/certainty; 0109 preserves optional estimating and commercial boundaries;
0050 technical/item unit separation, 0049 overhead, 0054/0057/0058 and 0072/0076 controls remain.
0110 was accepted on 2026-10-10; its old compatibility-first draft remains in Git at
`9dced38`, never treated as accepted architecture. C0/slices/compatibility handoff, module README,
Assembly/formulation/material-resolution/pricing domain references, phase indexes, Q41 and STATUS
must identify the corrected proposal. Accepted ADRs and invariants require no semantic relaxation.

## Related

- [Revised C0](phase-4-c0-cost-evidence-contract.md) · [Revised slices](phase-4-c1-c2-cost-evidence-slices.md)
- [Proposed ADR 0110](../decisions/0110-version-cost-evidence-without-changing-legacy-estimates.md)
- [R2 compatibility audit](phase-4-r2-compatibility-audit.md) · [Phase 4 plan](phase-4-sales-quotations-and-handover.md)
