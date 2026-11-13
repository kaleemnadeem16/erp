[← Decisions](README.md)

# 0190 — Adopt Material Plans on quotation Assembly lines

- **Status:** accepted (implementation; owner decision 2026-11-13)
- **Date:** 2026-11-13
- **Affects:** Phase 4 Sales quotation drafts and issue; Phase 3 Estimating material resolution, variance and stock-mix controls; the shared approval engine
- **Builds on:** [0054](0054-risk-based-material-plan-variance-controls.md), [0058](0058-phase-3-material-resolution-preview-contract.md), [0076](0076-company-control-over-hand-chosen-stock.md), [0077](0077-generic-material-cost-resolution-with-a-configurable-costing-scope.md), [0122](0122-govern-quotation-candidates-adoption-and-revalidation.md), [0189](0189-prove-assembly-quotation-lines-cost-blind-and-freeze-the-sold-version.md)

## Context

ADR 0058 made the Phase 3 material resolution read-only and left adoption to Phase 4: "Phase 4 will reuse the same resolver inside quotation-revision commands that adjust/adopt a plan,
evaluate ADR 0054 controls and persist immutable decision evidence." The approval subjects `MATERIAL_VARIANCE` and `STOCK_MIX` were registered with a `routing()` that refused until a document
could adopt a plan. ADR 0189 made Assembly work quotable. This ADR is the adoption.

## Decision

1. **One adoption fact per line, append-only.** `AdoptQuotationMaterialPlan` evaluates the plan of ONE Assembly line (its pinned version, work quantity, the seller's planned quantities and
   hand-chosen stock) by composing Phase 3's `PreviewAssemblyMaterials` and stores `quotation_material_plans` plus one `quotation_material_plan_items` row per leaf material. Standard and planned
   quantity are kept apart (0054 §1); each row keeps its effect, reason, policy identity and matched conditions, and the chosen stock with its judgement (0076). Adopting again is a new row that
   names the one it supersedes. Rows are written only while the revision is a DRAFT and are never updated; they can disappear only with their draft line (cascade, DRAFT only).
2. **Controls are Phase 3's, not re-implemented.** BLOCKED, a missing reason, and a stock mix that is blocked, unjudged, still lacking its reason or not covering the plan are refused and store
   nothing. `AUDIT_ONLY` and `REASON_REQUIRED` are recorded. `APPROVAL_REQUIRED` (a variance band, or a stock-mix control at that level) stores one approval subject per controlled item and kind in
   `quotation_material_plan_approvals`; an approval-required band is never read as "not required".
3. **Cost-blind adopter, real routing.** The adopter needs the quotation duty and their own preview rights, not `estimating.internal_cost.view`. The approval engine routes on the real internal-cost
   figure, so Estimating's previews fill a sealed `PlanRoutingCollector` that only `EvaluateMaterialPlan` reads (`PlanRoutingCollectorHasOneCallerTest`); the figures sit in protected columns and
   are never returned. What the person sees is what the preview shows them.
4. **Approval through the shared engine.** `RequestQuotationMaterialPlanApproval` submits each subject (`estimating.material_variance.request`); Estimating's two subjects delegate `routing()` to a
   Sales-bound `MaterialPlanApprovalFacts`, so the facts are frozen with the adoption. Deciding stays the engine's independent, separate act. With no applicable policy, or approval switched off,
   the request is refused and nothing counts as approved.
5. **Revalidation is deliberate and appended.** `RevalidateQuotationMaterialPlan` evaluates the stored request again and appends `CURRENT` (same routing fingerprint: policy, quantities, controls and
   routing figures hold, any approval stands) or `CHANGED` (the approvals are ended and the seller adopts again). The fingerprint is actor-independent and excludes free text, so an editorial note
   does not invalidate an approval (0054 §6). An approval is never rebound, revived or moved to a successor plan (0122). Changing the line's version, quantity or unit, or removing it, ends the
   approvals (`MaterialPlanStaleness`).
6. **Issue freezes the plan.** The issue assessor blocks an Assembly line whose plan is stale, whose approvals are not granted for exactly its facts, or that cannot be re-evaluated for the issuer
   (`MATERIAL_PLAN_STALE`, `_APPROVAL_REQUIRED`, `_APPROVAL_PENDING`, `_APPROVAL_REJECTED`, `_NOT_REVALIDATED`). `IssueQuotation` records `quotation_issued_lines.material_plan_id`, and the
   database refuses an issued line whose plan is not its draft line's latest. An issued revision's plan can no longer change or be deleted.
7. **A plan is optional.** A line may be priced and issued without an adopted plan. Whether a company must adopt one before issue is not decided here.

## Consequences

- No new permission. `sales.quotation.prepare` stays STAGED; the existing `estimating.material_plan.adjust`, `.package_plan.adopt`, `.stock_mix.choose` and `.material_variance.request` are checked.
- Re-evaluating at issue needs the issuer to hold `estimating.material_preview.run` (and availability for a mix plan); otherwise the line is `MATERIAL_PLAN_NOT_REVALIDATED` — fail closed.
- Physical allocation, reservation, stock movement and Job execution remain Phase 5; the plan records a proposal and a chosen mix as quantities only (`QUANTITY_ONLY`).
- Not delivered: HTTP routes and screens for adoption, approval request, revalidation and reading the evidence (the Actions are staged and called directly, like QL1a); a company rule that
  makes a plan mandatory; an evidence read projection for issued quotations; one request per item rather than one approval per item and kind.

## Alternatives rejected

- **Store the plan on the draft line** — mutable, and a repeated adoption would rewrite its own history.
- **Require `estimating.internal_cost.view` to adopt** — makes planning a duty of cost holders and contradicts the cost-blind decision of ADR 0189.
- **Re-evaluate silently at issue and auto-adopt** — an issue must confirm the exact reviewed result (0122); a changed plan blocks and goes back to the seller.
- **A new approval subject per document** — the two Estimating subjects already carry the facts and permissions (0070 §11); only their routing source was missing.

## Related

[Sales README](../../backend/app/Modules/Sales/README.md) · [Estimating README](../../backend/app/Modules/Estimating/README.md) · [Material resolution](../domain/material-resolution.md) · [Approval workflows](../architecture/approval-workflows.md)
