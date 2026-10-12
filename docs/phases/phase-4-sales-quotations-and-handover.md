[← Roadmap](README.md)

# Phase 4 — Sales, quotations and job handover

- **Status:** R1/R2 bounded research complete; C0 accepted; C1a–c/C2a–b implemented locally per STATUS, R3 accepted/specifications prepared, R4 initial review, R5–R9 pending. CRM backend foundation
  exists separately; quotation implementation remains pending.
- **Started:** 2026-10-08, at the owner's request. Phase 3 enhancements remain with another agent.
- **Depends on:** Parties and Estimating; shared Numbering, Approvals, Company and access foundations.
- **Decision gate:** no new business model is accepted by this plan. Discuss each topic, then record
  agreed decisions in ADRs before defining implementation slices.

## Goal

Turn a sales enquiry or an existing customer request into an explainable, controlled quotation,
preserve what was offered across revisions, and hand accepted scope to a job with its estimate
baseline. Support different industries and company sizes through progressive exposure.

## Scope

### In — research scope, subject to decisions

Leads and opportunities; customer identity; quotation scope and line structure; Sales pricing;
tax and currency; lifecycle, approvals and revisions; acceptance; job handover; customer documents;
permissions, audit and reporting. Include carried Phase 4 obligations: material-plan adoption,
ADR 0077 cost resolution, stock-choice/variance enforcement and the Sales boundary of the
multi-owner recalculation correction package (ADR 0040).

### Out — established boundaries

Phase 5 execution, physical production and material reservation/issue; Phase 6 invoicing,
receivables and GL posting. Quotation inventory evidence is advisory, not a reservation.
Notifications remain an ERP-wide Q33 question. Advanced CRM is a scope discussion, not assumed
Phase 4 delivery. This exercise changes documentation only.

## Research topics and order

These are research categories, not implementation slices. R9 is cross-cutting from R1 onward;
its consolidated review comes last. R6 and R7 inform R5 before lifecycle decisions are accepted.

| Topic | Questions and dependencies | Existing basis to reuse | Research state |
|---|---|---|---|
| R1 Leads, opportunities and relationships | Enquiry vs deal; optional pipeline; identity/conversion; duplicates; owners, stages and closure | Parties; ADRs 0030/0034, 0084/0089 | Research complete, ADRs 0098–0108 accepted; Q39 resolved; delivery gates retained |
| R2 Quotation structure and scope | Assemblies, work units, sections, alternatives, optional/non-priced lines; identity of a quotation vs revision | Sales scope; ADR 0109; Estimating | Business research formally closed; 0109 accepted, Q40 resolved; implementation gates retained |
| R3 Sales pricing | SIMPLE first boundary, overrides, discounts, agreements and margin controls | ADR 0109 retains/generalises historical 0055; sales-pricing.md | P1–P8 accepted in 0115–0117; Q43 closed; slices specified, no code authorised |
| R4 Tax, currency and FX | Quotation tax estimate, rules/exemptions, rounding, rate date and evidence | ADRs 0012, 0025, 0056; currency APIs | Initial F1–F8 review (Q44), multi-tax/withholding/exemptions included; no new ADR/code |
| R5 Lifecycle, approvals, issue and revisions | Stale drafts, plan adoption, snapshot boundary, withdrawal/supersession, expiry and approval invalidation | ADRs 0054, 0058, 0070, 0072, 0076/0077, 0079, 0085 | Not researched; follows R2–R4, informed by R6/R7 |
| R6 Customer decisions | Whole/partial acceptance, alternatives, evidence, rejection and expired offers | Sales pipeline landmines; immutable snapshots | Not researched; follows R2; iterate with R5 |
| R7 Job creation and handover | Idempotent confirmation, scope/baseline, contacts/sites, job changes; Sales/COGS correction ownership | job-costing.md; ADR 0040; Phase 5 boundary | Not researched; follows R6; feeds R5 |
| R8 Documents and communications | Customer projection, PDF/template, delivery evidence, portal/signature vs manual recording | Existing PO print model; appearance-and-printing.md; Q33 | Not researched; follows R2/R5/R6 |
| R9 Access, audit, reporting and integration | Owner vs visibility, sensitive prices/costs, forecast metrics, history, imports and events | ADRs 0028, 0062, 0084/0089, 0096; shared audit/scope | Not researched; applied throughout and reconciled last |

R3–R4 can be investigated independently once R2's context is settled. Read-only source/code review
is evidence, not proof that a shared component fulfils the new workflow's requirements.

## Topic record contract

Each topic records scope/questions, verified existing capabilities and paths, dated primary
sources/comparison, alternatives/trade-offs, proposals, accepted decisions (ADR links only),
unresolved choices, dependencies/deferrals and research state. States are: not researched →
initial evidence → discussion → additional evidence if needed → decision recorded. An initial
proposal is never "researched", "accepted" or "complete" merely because it exists.

- [Existing-system review](phase-4-existing-system-review.md) — verified foundation and gaps.
- [R1 evidence and discussion](phase-4-r1-leads-and-relationships.md) — proposals and open choices.
- [R1-B3 offers and forecast](phase-4-r1-b3-offers-and-forecast.md) — retained evidence and reviewed scope/group direction.
- [Forecast-basis research](phase-4-r1-b3-forecast-basis.md) — selection sets, accepted/pending, FX/history; business direction accepted in ADRs 0101/0102; delivery details deferred.
- [Forecast staleness](phase-4-r1-b3-forecast-staleness.md) · [Forecast FX](phase-4-r1-b3-forecast-fx.md) — retained alternatives and accepted bounded direction; package details deferred.
- [R1-C1 prospect quotations](phase-4-r1-c1-prospect-quotations.md) — verified foundations and accepted bounded direction in ADR 0103.
- [R1-C2 financial readiness](phase-4-r1-c2-financial-readiness.md) — accepted bounded separation in ADR 0104; historical candidate gates remain deferred.
- [Consolidated R1 decision matrix](phase-4-r1-decision-matrix.md) · [additional evidence](phase-4-r1-remaining-evidence.md) — accepted matrix and closure; detailed implementation gates retained.
- [R2 consolidated recommendations](phase-4-r2-quotation-structure.md) · [actual capabilities](phase-4-r2-existing-capabilities.md) · [primary comparison](phase-4-r2-erp-comparison.md) — retained A–H alternatives; U1–U6 approved in accepted ADR 0109.
- [R2 verified impact matrix](phase-4-r2-compatibility-audit.md) · [compatibility plan](phase-4-r2-compatibility-plan.md) — source-specific coexistence, shared certainty obligations and future Sales slices; only C1a–c/C2a–b code authorised.
- [C0 contract proposal](phase-4-c0-cost-evidence-contract.md) · [C1/C2 slice plan](phase-4-c1-c2-cost-evidence-slices.md) — accepted 0110/Q41 resolved: correct existing operations and migrate consumers; delivery in progress.
- [Cost dependency map](phase-4-cost-dependency-map.md) — actual Actions/DTOs, routes/client/UI, controls/tests/demos and coordinated change boundaries.
- [CRM security correction](phase-4-crm-security-correction.md) — urgent handoff under existing authority; application correction not implemented by planning.
- [CRM three-policy review](phase-4-crm-policy-review.md) — A/B/C accepted in 0111–0113; [correction specification](phase-4-crm-correction-specification.md) and [evidence domain](../domain/crm-corrections.md) final, B authority accepted in 0114 (Q42 closed), safe rollout required; future acceptance/forecast gates retained.
- [R3 retained research](phase-4-r3-sales-pricing.md) · [approved P1–P8 matrix](phase-4-r3-business-review.md) · [expanded evidence](phase-4-r3-expanded-research.md) · [worked example](phase-4-r3-worked-example.md). Governing 0115–0117; Q43 closed; [implementation slices/gates](phase-4-r3-implementation-slices.md) and [resolution contract](../domain/sales-pricing-resolution.md) prepared, no code authorisation.
- [Accepted implementation-question coordination](../architecture/implementation-question-reconciliation.md) uses OPEN-QUESTIONS, original origin/context and coding-owned verification.

- [R4 initial F1–F8 financial review](phase-4-r4-financial-review.md) · [tax composition and examples](phase-4-r4-tax-composition.md) · [primary evidence/conflicts and R3 readiness](phase-4-r4-evidence-and-readiness.md). Q44 awaits owner approval; no R3/tax implementation authority.
- Later topic records are added when investigated; do not generate empty speculative documents.

## Tasks

- [x] Establish this research structure and inspect the existing foundations.
- [x] Capture initial R1 evidence from three mature products.
- [x] Record R1-A owner-reviewed direction in [accepted ADR 0098](../decisions/0098-hybrid-sales-opportunity-requirement.md); final U1 review completed.
- [x] Record R1-B1/B2 owner-reviewed direction in accepted ADR 0099 and capture initial B3 evidence.
- [x] Record owner-reviewed independent scopes/exclusive groups in accepted ADR 0100; compare forecast bases.
- [x] Record owner-reviewed MAINTAINED/SELECTED_OFFERS methods in accepted ADR 0101; investigate staleness/FX separately.
- [x] Record owner-reviewed persistent provisional trust and explicit-date SPOT in accepted ADR 0102.
- [x] Final owner-authorised acceptance review passed for ADRs 0100–0102; bounded B3 research closed with R2/R4/R5/R6/R7/R9 delivery gates retained.
- [x] Record initial R1-C1 prospect-quotation evidence and recommendation.
- [x] Record owner-approved bounded R1-C1 direction as accepted ADR 0103 after consistency review.
- [x] Compare R1-C2 financial-readiness gates and record initial recommendations.
- [x] Accept owner-approved bounded C2 direction in ADR 0104; universal handover account gate withheld.
- [x] Consolidate remaining R1 evidence, 0098/0099 blocker resolutions and six owner-review bundles.
- [x] Final owner review U1–U6 passed; accept 0098/0099 and 0105–0108; close R1 and resolve Q39.
- [x] Begin R2 at owner request; document A–H initial evidence, actual capability review and primary comparison.
- [x] Record approved R2 U1–U6 in accepted ADR 0109, supersede 0008/0055 explicitly and document actual compatibility/required slices; resolve Q40.
- [x] Owner review of compatibility assessment/plan and formal bounded R2 business research closure; final consistency review passed.
- [x] Prepare C0 and C1/C2 slices; revise compatibility-first proposal after owner constraints with verified dependency map and coherent in-place consumer migration.
- [x] Owner approved C0/0110 and authorised C1a–c/C2a–b code; C1d migration/settings remain gated.
- [x] Record owner-approved CRM A/B/C in accepted 0111–0113 and prepare correction Action/evidence/API/UI/migration acceptance handoff; no implementation by planning; B authority now accepted in 0114/Q42 closed, safe rollout required.
- [x] Close R3 bounded business research, record owner-approved P1–P8 in 0115–0117, resolve Q43 and prepare implementation contracts/slices with R4–R9 gates; no code authorised.
- [x] Begin R4 and prepare initial F1–F8 currency/FX/tax/rounding review, including multiple taxes, exemptions and withholding; Q44 awaiting owner approval.
- [ ] Investigate R4–R9 in dependency order, R6/R7 informing R5 and R9 applied throughout; no operational pricing/quotation authority inferred.
- [x] Reconcile R1 business foundations with the historical Sales proposal; physical schema reconciliation remains R2–R9.
- [ ] Define implementation slices, demos and observable acceptance criteria only after decisions.
- [ ] Before each implementation, apply the feature-completion checklist and authorisation rules.

## Acceptance criteria — planning gate

- [ ] Each topic has sufficient evidence, realistic alternatives and an explicit decision/deferment.
- [ ] Accepted rules are traceable to ADRs; unresolved choices remain visible and block dependent design.
- [ ] Phase 4/5/6 ownership and the carried obligations have agreed boundaries.
- [ ] The owner has reviewed the resulting implementation plan. Feature acceptance criteria are
  deliberately not finalised during this first research task.

## Docs to update on completion

STATUS phase row and STATUS-LOG; this plan; Sales/related domain docs alongside accepted ADRs;
OPEN-QUESTIONS when a recorded question is resolved; journeys alongside eventual workflows.

## Related

- [R1-B3 bounded reconciliation and acceptance readiness](phase-4-r1-b3-reconciliation.md)

- [Research rules](../architecture/research-and-design.md) · [Feature checklist](../architecture/feature-completion-checklist.md)
- [Sales pipeline](../domain/sales-pipeline.md) · [Sales pricing](../domain/sales-pricing.md)
- [Parties](../domain/parties.md) · [Job costing](../domain/job-costing.md)
- [Existing review](phase-4-existing-system-review.md) · [R1](phase-4-r1-leads-and-relationships.md)
