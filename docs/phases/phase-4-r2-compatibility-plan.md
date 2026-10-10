[← Phase 4 plan](phase-4-sales-quotations-and-handover.md)

# R2 — Dependency-ordered compatibility plan

**Date:** 2026-10-09. **State:** compatibility assessment/plan owner-reviewed; bounded R2 research formally closed.
C0/C1/C2 delivery approach revised under no-real-business-data constraints; 0110 accepted and C1a–c/C2a–b implementation authorised on 2026-10-10. The earlier compatibility-first draft is historical.
Business direction is accepted in [ADR 0109](../decisions/0109-shared-commercial-quotation-lines-and-optional-estimating.md).
The [audit](phase-4-r2-compatibility-audit.md) distinguishes valid current behaviour, known defects,
required shared work and absent future Sales capabilities. No migration/backfill is proposed as
an immediate action; C1d alone needs separate scope-setting/migration authorisation.

## A — Documentation reconciliation (R2 milestone, retained history)

0109 replaces 0008/0055 as governing records, explicitly preserving their valid estimating/pricing
contracts. Only historical ADR status lines change; index links and current domain/invariant text
identify the new source-neutral direction. R2 research retains alternatives and dated initial
recommendations, with current approval/assessment overlays. Q40's answered business question is
removed; formal research closure is separate from implementation readiness.

Current detailed technical proposal (replaces the compatibility-first delivery assumptions): [C0 cost-evidence contract](phase-4-c0-cost-evidence-contract.md)
and [C1/C2 coherent correction slices](phase-4-c1-c2-cost-evidence-slices.md), supported by the
[verified dependency map](phase-4-cost-dependency-map.md).
Accepted ADR 0110 authorises C1a–c/C2a–b; C1d remains separately gated.

## B/C — Compatibility and future delivery matrix

Priority denotes dependency order, not permission to start. “Shared” means code supporting more
than quotation models; “Sales” means missing future functionality, not an existing defect.

| Area / slice | Existing assumption | Required change | Reason | Priority | Tests | Delivery owner |
|---|---|---|---|---|---|---|
| C0 Evidence contract review | Existing totals/zero fallbacks lack authoritative certainty; API input precisions differ | Correct existing cost APIs/DTOs and migrate every dependent consumer; one authoritative resolution, precise temporal/quantity semantics | Retire misleading zero while preserving valid behaviour; no permanent dual contracts | Before affected adapters; review only | Updated typed API/consumer fixtures, UNKNOWN/null versus true zero, coverage/demand attribution and bounds | Estimating + Inventory maintainers; owner approves corrected API/quantity/evaluation contract |
| C1 Shared material cost query | AVCO company-item-only IngredientCostResolver | Implement ADR 0077 read-only query with scope/as-of/method/status, copied sources and explicit refusals; verify direct stocked-Item consumer; do not inherit advisory availability's positive-cost-only filter | Existing accepted obligation needed by material and direct Item cost paths | Before trusted cost-derived prices/material-cost controls; depends C0 | New query tests for AVCO/FIFO/SPECIFIC, partial quantity, frozen/unsupported/no evidence, legitimate zero, same-company/method/scope, repeatable evidence; no stock writes | Inventory owns valuation query; Estimating consumes |
| C2 Shared estimate certainty | Nested materials/resources may turn missing evidence into zero aggregate guidance | Propagate C1 certainty and missing resource-rate diagnostics through nested material/Assembly/preview results; make authoritative completeness explicit | Correct known missing-cost ambiguity without changing fully costed arithmetic | After C1 for materials; resource slice independently testable after C0 | Existing hand-calculated roll-ups unchanged; add nested partial/unknown, missing versus configured zero resource rates and restricted projections | Estimating maintainer; shared with Inventory evidence |
| C3 Guidance labels/help | “Sell / unit” can read like the final Sales price | Clarify estimate guidance in Assembly screens/help, keep `sell_per_unit` field and markup/margin settings | Avoid using guidance as maintained price or applying markup twice | Small independent UI slice after approved wording; no engine dependency | Existing Assembly browser journeys plus updated label expectations, permissions and mobile layout | Estimating UI maintainer |
| S1 Commercial structure | No implemented Sales quote engine; historical sketch compulsory Assembly | Family/revision/logical scope; shared source-aware lines; sections/base/options/provenance; quantity and recipient/location adapters | Deliver approved R2 without fake Assembly/account | After owner authorises implementation and C0 resolves applicable quantity contract | Direct Item/service/manual/Assembly cases, R1 policy/family scope, totals and copy identity, decimal and cross-company tests | Sales Phase 4 R2 implementation |
| S2 Source-aware pricing | 0055 applicability described only Assemblies | Reconcile Item/manual applicability in R3; implement source adapters and applicable cost/price evidence | Same controls across entry paths; no guessed new hierarchy | R3 decision gate; C1/C2 for paths requiring cost | Maintained price stable on cost change; cost-derived methods require valid cost; manual price cannot bypass authority/tax/approval; no duplicate markup | Sales pricing R3; R4 tax/FX partner |
| S3 Controlled issue/adoption | Preview/advice exists, persisted plan and quote approval/number adapters do not | Adopt relevant plans, validate dependencies and issue source/quantity/recipient/price evidence atomically; source-aware readiness | Preserve estimate controls when applicable without mandatory irrelevant plans | R5 informed by R6/R7; depends applicable S1/S2/C1/C2 | Missing/stale/version refusal, relevant variance/mix approvals, direct Item without artificial plan, revocation/concurrency/failed retry no success evidence | Sales lifecycle R5 + Estimating/Approvals/Numbering adapters |
| S4 Acceptance/handover/documents | No authoritative quote selection or handover projection | Record option/partial selections, overlap/dependencies, accepted estimate baseline and customer-safe rendering | Commercial structure alone does not authorise work or finance | R6/R7/R8/R9 decision gates | Frozen issue evidence, accepted-work exclusion, independent/exclusive scopes, prerequisite refusal, no cost disclosure, idempotent handover | Sales acceptance R6; Jobs R7; documents R8; controls/reporting R9 |

C1 does not require replacing Inventory posting or inventing a Sales valuation method. C2 does
not require a universal Sales pricing engine. S1 can have a maintained commercial price without
an adopted material plan; whether any particular margin/cost rule refuses incomplete cost belongs
to S2/S3. No assertion that every Sales line needs every shared costing capability.

## Existing defects and resolution order

**D1 — Missing material cost reads as zero:** high cost-correctness priority, already acknowledged
by ADR 0077. Current `FormulationExplosionTest` deliberately asserts the legacy fallback. Resolve
through C0/C1/C2 before trusting affected price/margin/variance evidence. This is not evidence of
corrupt stock valuation or existing quotation postings.

**D2 — Missing resource rate produces zero aggregate cost:** high cost-correctness priority before
trusted estimate pricing. `AssemblyCostRollUpTest` explicitly preserves the fallback and its source
text. A configured rate of zero may be genuine; a null resolution is missing. C0/C2 must preserve
that distinction. No automatic blanket failure for all draft exploration is decided here.

**D3 — Guidance wording:** medium clarity priority, independently deliverable C3. “Sell / unit”
is not arithmetic failure and should not trigger deletion of the existing guidance API.

No defect above is fixed by this documentation commit. They remain tracked here with delivery
owners and tests rather than being mislabelled unfinished Sales functionality.

## Regression assessment

Inspected existing regression anchors (repository-relative):

- `backend/tests/Feature/Modules/Estimating/AssemblyCostRollUpTest.php`: exact five-part totals,
  markup versus margin, work/time quantities, application rates, overrides and overhead precedence.
- `FormulationExplosionTest.php` in the same folder: nested requirements, wastage, conversion,
  cycle rejection; replace legacy missing-cost zero assertions and obsolete supported-method refusal fixtures on approved migration; preserve valid fully costed maths.
- `AssemblyVersionApiTest.php`, `FormulationApiTest.php`, `ApplicationRateResolutionTest.php`,
  `ResourceRateApiTest.php`, `OverheadPolicyApiTest.php`: version rules, customer override,
  rate/dimension resolution and commercial-read guards.
- `MaterialPreviewApiTest.php`, `MaterialVarianceEvaluationTest.php`, `StockMixControlTest.php`:
  ephemeral preview, unknown planned cost, increments/precision, variance/mix controls, inventory
  capability and fingerprint protection, no reservation/posting.
- `backend/tests/Unit/Support/UomConversionServiceTest.php`, Inventory `ItemQuantityUnitApiTest.php`,
  Purchasing `PackagedPurchasingTest.php`: global/item conversions, package/basis/increments.
- Parties `PartyContactTest.php`, `PartySiteTest.php` and unit `PartyWriteActionAuthorizationTest.php`;
  Approvals engine/information-flow and Numbering engine/concurrency tests protect shared contracts.
- `frontend/apps/e2e/tests/material-preview.spec.ts` and `estimating-list-filters.spec.ts` protect retained
  Assembly entry/preview; C3 must find and update dependent label assertions.

For future source integration add direct Item/service/Assembly/manual cases side by side. Prove
two cartons derive the expected stock measure, while 250 m²/two-coat work retains existing selling
measure and consumption. Reject incompatible conversions, invalid increments, unrepresentable
quantities and foreign company/source/contact/site IDs without silent rounding or leakage.
Test an accountless Party using company/default formulation context; an applicable same-Party
account retains its authorised override; a wrong account/context is refused. Explicit formulation
references also need recipient/context eligibility review in R5, not merely matching numeric IDs.

Every applicable code slice must run the repository's [pre-push matrix](../architecture/pre-push-checks.md):
Pint/Larastan and relevant/full milestone suites, guards/scope ratchets for new surfaces, OpenAPI
and generated-client checks for changed contracts, three frontend typechecks/build and affected
browser specs for UI. These are future validation requirements, not claimed results today.

## Migration, demo and API assessment

- No existing quotation families/lines/adopted plans exist to backfill or convert. Existing
  Assemblies, versions, formulations, rates, Items, packages, stock and Party records remain valid.
- C1 can initially be a read-only contract over existing valuation records; a migration is not
  automatically necessary. C2 may use result/evidence DTOs without schema changes. Concrete
  persistence/caching requirements must establish any actual migration need.
- Future quotation/plan/snapshot persistence is additive Phase 4 work after physical design;
  migrated constraints, real rollback and isolated fresh/rollback/reapply checks apply then.
- Retain existing operation routes, correct DTO/response semantics deliberately and migrate all
  affected consumers in coordinated cohorts. Preserve fully costed arithmetic, not incorrect zero
  totals or unnecessary scalar aliases. No parallel compatibility endpoints. Current guidance is
  not historical valuation; preserve explicit date/context and reject unsupported historical reads.
  Keep valid preview quantity limits. Regenerate clients and verify nullable/recursive schemas.
- Purchasing stores derived stock quantity at four places in `BuildPurchaseOrderLineAttributes`;
  nine-place factors do not imply nine-place stock documents. Reuse conversion semantics only,
  explicitly reconcile destination precision and loss handling for Sales.
- Keep existing estimating demos and deliberately non-sellable stock personas. Add meaningful Sales
  demos/permissions only with S1–S4; no blanket flag flip, artificial Assemblies or account backfill.

## Decisions, closure and stopping point

The owner-approved U1–U6 business direction is reconciled in 0109; the Assembly-only conflict is
resolved by explicit supersession. Remaining quantity/API rollout and Item/manual price/tax
applicability choices are genuine delivery/research gates, not permission to expand R2 into R3.

**Closed:** owner approved formal bounded R2 business research closure after reviewing `22a3603`;
final consistency checks retain estimating/pricing governance and explicit implementation gates.
No compatibility implementation is authorised. C0 is now a concrete proposal in unaccepted 0110;
C1/C2 code waits for contract review and explicit authority.

R3–R9 own pricing, tax/FX, lifecycle, acceptance, job multiplicity, customer documents and access/
analytics details as assigned in the R2 record. R3 research can proceed independently of cost-code
delivery; this task does not start it. Next action is owner review of revised C0, dependency map and coherent C1/C2 delivery slices.
Local commits only; do not push unless explicitly requested. No real business data does not
authorise historical migration edits or development database resets.

## Related

- [Verified impact matrix](phase-4-r2-compatibility-audit.md) · [R2 record](phase-4-r2-quotation-structure.md)
- [ADR 0109](../decisions/0109-shared-commercial-quotation-lines-and-optional-estimating.md)
- [Phase 4 plan](phase-4-sales-quotations-and-handover.md) · [Research rules](../architecture/research-and-design.md)
- [Feature completion checklist](../architecture/feature-completion-checklist.md)
