[← Phase 4 plan](phase-4-sales-quotations-and-handover.md)

# R2 — Verified Phase 1–3 compatibility assessment

**Date:** 2026-10-09. **Baseline:** `4ca8b44`, rechecked after Items UI commit `baaa986`.
Owner approved U1–U6; [ADR 0109](../decisions/0109-shared-commercial-quotation-lines-and-optional-estimating.md)
records bounded direction. Audit inspected code, tests, API Resources, seeders and frontend sources;
no code changed or executable regression suite run. Other agents' uncommitted Party/backend and frontend edits are outside this baseline; recheck them
before implementation rather than treating them as delivered compatibility foundations.

**Historical assessment:** this record preserves the Phase 1–3 findings reviewed at `22a3603`.
The [current dependency map](phase-4-cost-dependency-map.md) and [revised C0](phase-4-c0-cost-evidence-contract.md)
replace subsequent compatibility-first API/test delivery assumptions; the old behaviour is not
a permanent architecture requirement.

## Business meaning and before/after

We are removing the need to maintain an Assembly for everything a company sells. This solves
unnecessary setup for plain goods and simple services while preserving advanced estimation.
Previously the design required a one-material Assembly to sell two cartons. Future Sales can
quote the Item directly, retaining carton/stock conversion evidence. Existing Assembly estimation
of 250 m² still resolves its own materials, resource costs and overhead; no estimator is replaced.

Risk sits at the adapters: a Sales caller must not mistake estimated selling guidance for an
approved customer price, missing cost for zero, or an optional source for permission to skip controls.
Removing mandatory Assembly does not mean removing Assembly controls when that source is used.

## Classification key

- **Compatible:** current behaviour needs no architectural change.
- **Documentation only:** current behaviour remains valid; descriptions need reconciliation.
- **Required shared-foundation change:** existing capability must be extended/corrected before its
  evidence can support the affected Sales/estimate pricing or control path.
- **Future Sales implementation:** quotation-specific work, not a defect in the current foundation.
- **Existing defect:** independently misleading cost certainty, deliberately distinguished from a new regression.
- **Needs further decision:** unsafe to settle detailed contracts in this R2 assessment.

## Impact matrix — actual repository evidence

Paths below are repository-relative. Classification describes the specific finding, not every
possible future change in a module. Test names are inspection evidence, not a claim tests ran.

| Area | Classification | Verified location / behaviour | Consequence |
|---|---|---|---|
| Assembly identity | Compatible | `backend/app/Modules/Estimating/Models/{Assembly,AssemblyVersion,AssemblyComponent}.php`; versions scoped through Assembly; components ordered by sequence, no quotation relation needed for calculation | Keep creation, versioning, grouping and no-output-Item model. Optional use in Sales does not require an Assembly migration. |
| Version transitions | Compatible | Estimating `Actions/{Create,Update,Activate}AssemblyVersion.php`; `AssemblyVersionApiTest` protects activation/supersession and refusal to edit/delete active versions | Preserve existing lifecycle. Issue-time selected-version eligibility and adopted reference protection are R5, not a new blanket preview restriction. |
| Work costing | Compatible | `Actions/ExplodeAssemblyVersion.php`; `AssemblyCostRollUpTest::test_the_full_five_part_breakdown_matches_hand_computed_figures` asserts material 10.5000, labour 30.0000, plant 25.0000, subcontract 15.0000, overhead 8.0500, total 88.5500 and guidance 106.2600 | Preserve fully costed calculations and overhead ordering; do not replace them with Sales pricing. |
| Selling guidance | Documentation only | `ExplodeAssemblyVersion.php`, `Support/AssemblyCostBreakdown.php`, `Http/Controllers/Api/V1/AssemblyVersionController.php::serializeBreakdown`; `sell_per_unit` remains in API; margin/markup tests distinguish effects | Clarify estimate-derived guidance, not maintained/approved customer price. Keep field name and existing arithmetic in this documentation change. |
| Formulations | Compatible | `Actions/{ResolveFormulation,ExplodeFormulationVersion}.php`; `FormulationExplosionTest` protects nested/wastage/UoM behaviour and cycle rejection | Keep recursion and active version resolution. Optional quotation sources do not change a formulation's output Item or composition. |
| Account override context | Compatible | `ResolveFormulation` tries the supplied `customer_id`, then null/company default; `FormulationApiTest::test_resolve_prefers_the_customers_own_formulation_over_the_default`; preview request validates `customer_accounts` company scope | Account-based override remains valid. Missing account already permits company default or loud no-match; no Party-wide override exists. |
| Party-to-estimate adapter | Future Sales implementation | Preview input is nullable CustomerAccount ID, not Party ID; `Parties/Models/{Party,CustomerAccount,PartyContact,PartySite,CustomerSite}.php` separate roles/contexts | Future caller maps only an applicable authorised same-Party account. No account means no customer-specific override, never another customer's or a fabricated account. Mapping permission/readiness detail remains R3/R5/R9. |
| Application rates | Compatible | Estimating resolution Actions and `ApplicationRateApiTest`; preview retains dimension IDs and application-rate/formulation evidence | Retain company-defined dimensions and consumption/work distinction. Plain Item lines need no artificial application-rate context. |
| Resource and overhead arithmetic | Compatible | `ResolveResourceRate`, `ResolveOverheadPolicy`, `Support/OverheadAmountCalculator`; cost-roll-up and `OverheadPolicyApiTest` cover time conversion, scope precedence and no-policy zero | Keep resource rate precedence and overhead drivers. No overhead policy means legitimate zero overhead; not the same as missing labour cost. |
| Missing material evidence | Existing defect | `Support/IngredientCostResolver.php` returns zero with no pool; `FormulationExplosionTest::test_an_item_never_received_costs_zero` explicitly asserts it; FIFO and warehouse-scope tests explicitly refuse unsupported costing | Known semantic ambiguity acknowledged by ADR 0077, not caused by direct selling. High cost-correctness priority before trusted cost/margin use; no current quotation financial posting is demonstrated. |
| Missing resource evidence | Existing defect | `ExplodeAssemblyVersion::explodeResource` converts a null rate to zero cost, with diagnostic string; `AssemblyCostRollUpTest::test_a_labour_item_with_no_resource_rate_configured_costs_zero_not_an_error` protects legacy behaviour | Cost source admits missing rate, but aggregate numeric totals cannot establish completeness. Track a separate certainty extension; do not turn null into a genuine rate of zero. |
| Generic material cost query | Required shared-foundation change | Current resolver is company-item AVCO only; ADR 0077 requires explicit scope/as-of/method, COSTED/PARTIAL/UNKNOWN and copied evidence; Inventory valuation/availability already has stock evidence | Implement the accepted read-only generic contract for direct stocked Items and material estimates before affected cost-derived/control paths. No new stock valuation algorithm or stock writer required by R2. Availability deliberately maps non-positive costs to null and is not a sufficient generic cost result. |
| Estimate certainty propagation | Required shared-foundation change | `AssemblyCostBreakdown` currently has decimal totals without a certainty enum; material preview planned-variance paths already distinguish absent evidence, protected by `MaterialPreviewApiTest::test_a_material_without_cost_evidence_has_no_planned_cost_rather_than_a_zero_one` | Carry uncertainty through nested materials/resources to evidence consumers; retain existing supported arithmetic. Existing API rollout needs explicit contract choice and regression coverage. |
| Estimate date/context adoption | Future Sales implementation | `ResolveResourceRate` accepts optional as-of, but `ExplodeAssemblyVersion` takes only a version and defaults resource reads to today; preview recomputes context-resolved material cost separately | Do not assume the existing cost API is a historical quotation valuation. Future adoption must align the actual calculation date/context and snapshot it under R5; no new quote-date rule selected here. |
| Stock valuation/integrity | Compatible | Inventory valuation records/pools, `Actions/Movements/IssuePoster.php` and `QueryStockAvailability`; AVCO/FIFO/SPECIFIC are existing Inventory-owned posting capabilities, not the legacy estimator's supported methods | Do not change posting, package balances, reservations or cost layers to add a quotation. Availability is not automatically a complete historical cost query. |
| Quantity foundations | Compatible | `backend/app/Support/UomConversionService.php` same-dimension scale 9 HALF_UP; Inventory `ItemQuantityUnit` factors/increments and `ItemPackaging` fixed stock content; `Purchasing/Actions/BuildPurchaseOrderLineAttributes.php` distinguishes stock/priced quantities | Reuse semantics, not purchasing document transitions. ADR 0050 forbids applying item/package conversions inside technical formulation/Assembly composition. |
| Quote quantity contract | Needs further decision | `Http/Requests/MaterialPreview/PreviewMaterialRequest.php` permits decimal 0–6; `MaterialPreviewApiTest::test_quantity_precision_is_enforced_not_rounded`; conversion factors use nine digits | Set quotation precision/bounds and conversion loss handling before adapter delivery. Do not widen existing preview inputs or silently truncate a nine-digit quotation. |
| Variance/stock-choice controls | Compatible | `EvaluateMaterialVariance`, stock-mix preview paths; `MaterialVarianceEvaluationTest`, `StockMixControlTest` and preview tests guard reasons, scope, approval advice and non-posting | Keep controls on actual adopted material plans. Optional source does not weaken chosen-stock or variance authority. |
| Persisted adoption/subject routing | Future Sales implementation | `Support/{MaterialVarianceApprovalSubject,StockMixApprovalSubject}.php::routing` explicitly refuses until a quotation plan exists | These adapters concern plans, not every quotation. No current universal Assembly gate found in shared Approvals; new quote readiness invokes estimating controls only when relevant. |
| Recipient/role foundation | Compatible | Party contacts/sites company-scope through Party; `PartyContactTest`, `PartySiteTest`, `PartyWriteActionAuthorizationTest`; CustomerSite attaches to account | Preserve existing identities and authorisation. Prospect-friendly quotation location/recipient snapshots are new Sales adapters, not a role-schema replacement. |
| Shared controls | Compatible | `Approvals/Support/ApprovalSubject.php`, Numbering registry/framework, authorisation and domain-integrity rules; approval/numbering engine and ratchet tests | New quotation subjects/adapters/grants/evidence probes are future Sales work. Do not describe absent Sales adapters as broken existing infrastructure. |
| APIs and projection | Documentation only | `AssemblyResource` protects margin fields; component Resources protect rates/recipe detail; cost-breakdown controller requires internal-cost capability; generated API models reflect current fields | Preserve current field names and guards. Any future added certainty fields require OpenAPI/client regeneration, no hand-edited generated output. |
| Estimating screens | Documentation only | `frontend/packages/core-ui/src/features/assemblies/assembly-form.tsx` says “A sellable unit of work”; `assembly-cost-breakdown.tsx` labels “Sell / unit”; material-preview screen remains explicitly Assembly-based and offers account selection | Wording is source-specific, not a global Sales-only selector. Clarify guidance in a small future UI slice; keep Assembly preview and optional account selector intact. |
| Demo data | Compatible | `backend/database/seeders/Demo/Estimating/{AssembliesDemoSeeder,ResourceRatesDemoSeeder,OverheadPoliciesDemoSeeder}.php` constructs genuine estimating examples; existing stock demos use `is_sellable=false` deliberately | No deletion, conversion or blanket flag flip. Future Sales needs its own direct Item/service/manual and Assembly demo records/personas. |
| Sales/tax/job sketches | Documentation only | `docs/domain/{sales-pipeline,sales-pricing,tax,job-costing}.md` contain planned scope; no Sales quotation models/routes found under `backend/app/Modules` | Reconcile source-neutral descriptions; leave physical tables, manual tax classification, acceptance and execution for their research owners. |

## Inspection entry points

- [Assembly cost Action](../../backend/app/Modules/Estimating/Actions/ExplodeAssemblyVersion.php) · [protected cost API](../../backend/app/Modules/Estimating/Http/Controllers/Api/V1/AssemblyVersionController.php)
- [Material cost resolver](../../backend/app/Modules/Estimating/Support/IngredientCostResolver.php) · [formulation resolution](../../backend/app/Modules/Estimating/Actions/ResolveFormulation.php)
- [Material preview](../../backend/app/Modules/Estimating/Actions/PreviewAssemblyMaterials.php) · [item conversion](../../backend/app/Modules/Inventory/Actions/ItemUomConversionService.php) · [purchasing quantity/basis](../../backend/app/Modules/Purchasing/Actions/BuildPurchaseOrderLineAttributes.php)
- [Stock availability cost evidence](../../backend/app/Modules/Inventory/Actions/QueryStockAvailability.php) · [Inventory issue valuation](../../backend/app/Modules/Inventory/Actions/Movements/IssuePoster.php)
- [Assembly regression anchors](../../backend/tests/Feature/Modules/Estimating/AssemblyCostRollUpTest.php) · [formulation regressions](../../backend/tests/Feature/Modules/Estimating/FormulationExplosionTest.php) · [preview regressions](../../backend/tests/Feature/Modules/Estimating/MaterialPreviewApiTest.php)

## ADR dependency assessment

| Record | Exact impact / disposition |
|---|---|
| 0008 | Superseded by 0109: universal Assembly selling, forced simple product/service Assembly and rejection of direct product selling change. Versioned industry-neutral estimation, optional components and evidence remain valid. |
| 0055 | Superseded by 0109: universal Assembly scope becomes source-aware. Its progressive modes/effects, formula controls, evidence, overrides and price/cost/tax separation are expressly retained. New Item/manual precedence is R3, not inferred. |
| 0049 | Compatible: an Assembly still has no output Item; flat Assembly Group overhead scope remains appropriate. No Item category replaces it. |
| 0050/0080 | Compatible with source-specific adapters: commercial Item/package conversions do not alter technical composition; Purchasing price-basis evidence is a reusable pattern. |
| 0051/0053/0054/0057/0058/0071/0072/0076 | Compatible within estimating/material-plan scope. Their Assembly preview and adoption wording does not make every commercial line an Assembly. |
| 0077 | Compatible, unimplemented shared contract; direct stocked Items increase its consumer set, not permission to bypass it. |
| 0056/0025 | Already describe Item/Assembly and goods/services tax context. Manual classification and option-tax allocation stay R4; no newly accepted tax model here. |
| 0098–0108 | Unchanged. Shared sources retain policy, family/revision/competition, forecast, Party/readiness and ownership/access contracts. |
| Invariants 1/11 | Clarify snapshots for applicable source evidence and explicit uncertainty; preserve consumption versus sold quantity for work, add Item stock conversion meaning. No relaxation of immutability or costing integrity. |

## Findings and limits

No verified Phase 1–3 writer or screen requires **all future Sales lines** to use an Assembly.
Existing mandatory Assembly IDs belong to Assembly-specific endpoints and tests; that is valid
scope, not a global quotation constraint. No universal estimating-plan gate was found in shared
Approvals. A future quotation engine must select the relevant controls, not change those endpoints.

Missing-cost certainty is the necessary shared work; unsupported legacy FIFO/scoped estimation is
an acknowledged limitation, not a reason to rewrite Inventory valuation. Prospect adaptation,
quotation quantities, plan persistence and commercial scope are future Sales integration. Resource
certainty needs a deliberate implementation contract; ADR 0077 concerns materials, so it must not
be misrepresented as an existing resource-cost query API.

This is static implementation/test inspection. Existing tests encode both valid arithmetic and
known zero fallbacks; only the latter expectations may need a deliberately approved extension.
The [dependency plan](phase-4-r2-compatibility-plan.md) specifies actual regression execution before
code delivery. No claim of runtime test success, exhaustive security audit or production migration
validation is made by this documentation-only assessment.

## Related

- [Compatibility implementation plan](phase-4-r2-compatibility-plan.md) · [R2 approved direction](phase-4-r2-quotation-structure.md)
- [ADR 0109](../decisions/0109-shared-commercial-quotation-lines-and-optional-estimating.md)
- [Existing capability review](phase-4-r2-existing-capabilities.md) · [Primary comparison](phase-4-r2-erp-comparison.md)
- [Phase 4 plan](phase-4-sales-quotations-and-handover.md) · [Invariants](../architecture/invariants.md)
