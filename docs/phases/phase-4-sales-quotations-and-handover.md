[← Roadmap](README.md)

# Phase 4 — Sales, quotations and job handover

- **Status:** research and discussion in progress; implementation not started.
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
| R1 Leads, opportunities and relationships | Enquiry vs deal; optional pipeline; identity/conversion; duplicates; owners, stages and closure | Parties; ADRs 0030/0034, 0084/0089 | B3 bounded business research closed; ADRs 0100–0102 accepted; 0098/0099 Proposed; R1-C1 evidence for discussion |
| R2 Quotation structure and scope | Assemblies, work units, sections, alternatives, optional/non-priced lines; identity of a quotation vs revision | Sales pipeline draft; ADR 0008; Estimating | Not researched; follows R1 |
| R3 Sales pricing | SIMPLE first boundary, overrides, discounts, agreements and margin controls | Accepted ADR 0055; sales-pricing.md | Existing design to assess; follows R2 |
| R4 Tax, currency and FX | Quotation tax estimate, rules/exemptions, rounding, rate date and evidence | ADRs 0012, 0025, 0056; currency APIs | Existing design to assess; follows R2/R3 |
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
- [R1-C1 prospect quotations](phase-4-r1-c1-prospect-quotations.md) — verified foundations, three ERP comparisons and unapproved recommendation.
- Later topic records are added when investigated; do not generate empty speculative documents.

## Tasks

- [x] Establish this research structure and inspect the existing foundations.
- [x] Capture initial R1 evidence from three mature products.
- [x] Record R1-A owner-reviewed direction in [proposed ADR 0098](../decisions/0098-hybrid-sales-opportunity-requirement.md); architecture and edge-case review remains pending.
- [x] Record R1-B1/B2 owner-reviewed direction in proposed ADR 0099 and capture initial B3 evidence.
- [x] Record owner-reviewed independent scopes/exclusive groups in accepted ADR 0100; compare forecast bases.
- [x] Record owner-reviewed MAINTAINED/SELECTED_OFFERS methods in accepted ADR 0101; investigate staleness/FX separately.
- [x] Record owner-reviewed persistent provisional trust and explicit-date SPOT in accepted ADR 0102.
- [x] Final owner-authorised acceptance review passed for ADRs 0100–0102; bounded B3 research closed with R2/R4/R5/R6/R7/R9 delivery gates retained.
- [x] Record initial R1-C1 prospect-quotation evidence and recommendation.
- [ ] Discuss R1-C1 before accepting recipient rules or beginning R1-C2.
- [ ] Investigate R2–R9 in the dependency order above, reusing sufficient accepted research.
- [ ] Reconcile the historical Sales domain proposal against accepted decisions.
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
