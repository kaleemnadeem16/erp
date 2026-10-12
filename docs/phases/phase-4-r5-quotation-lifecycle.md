[← Phase index](README.md)

# R5 — Initial quotation lifecycle research and owner review priorities

**2026-10-12 · INITIAL RESEARCH / PROPOSED, Q45 · Origin: PLANNING RESEARCH.**
No R5 ADR or operational lifecycle is accepted by this record. Reuse R1–R4 first; research is
bounded to draft/adoption/approval/issue/revision transitions. R6 acceptance and R7 commitment/
handover must inform the final R5 review; R9 access applies throughout. This is not coding authority.

## Settled authority — do not reopen

- [0098](../decisions/0098-hybrid-sales-opportunity-requirement.md) fixes one workflow with
  FLEXIBLE/MANAGED/STRICT opportunity-link policy; an issue exception is narrow, not general bypass.
- [0099](../decisions/0099-separate-quotation-issue-and-crm-association.md) distinguishes current
  family association from immutable issue-time relationship and permits governed history.
- [0100](../decisions/0100-independent-quotation-scopes-and-alternative-groups.md) fixes independent
  scopes/exclusive competing offers; revision is not a new parallel pursuit. R6/R7 still govern claims.
- [0109](../decisions/0109-shared-commercial-quotation-lines-and-optional-estimating.md) fixes family,
  revision and logical-line scope, immutable issued offers, optional estimating, source-specific
  measures and baseline option selection. Direct Items/manual lines need no material plan/Assembly.
- [R3](phase-4-r3-business-review.md) fixes explicit eligibility/benefits, bounded discretion, canonical
  net and controlled mode transition. [R4](phase-4-r4-financial-review.md) fixes financial dates/FX/
  components/rounding/evidence. No live-open repricing or tax/gross/withholding margin shortcut.
- [0110](../decisions/0110-version-cost-evidence-without-changing-legacy-estimates.md) preserves cost
  certainty and current evidence limits. Future-date commercial pricing does not invent future cost.
- Existing material preview is ephemeral; 0054/0058/0072/0076 require source-specific explicit adoption,
  variance/stock-choice controls and evidence, not reservation. Adopted quotation plan is future R5.
- Shared approval0070/0085 and numbering0079 must be adapted, not replaced. Relevant fact changes
  invalidate review; approval authority and read projections are separate. Maker/checker follows
  explicit policy; do not invent a universal compulsory two-person rule.
- 0101/0102 preserve accepted business vs remaining forecast and persistent provisional trust.
  New issue, delivery or expiry extension cannot itself republish trusted expected value.

## Primary comparison — accessed 2026-10-12

| Primary source | Observed lifecycle distinction | Project implication / rejected automatic import |
|---|---|---|
| [Dynamics Sales create/edit quotes](https://learn.microsoft.com/en-us/dynamics365/sales/create-edit-quote-sales) | Draft precedes activation; active amounts lock. Revised/cancelled/lost closure differs from customer acceptance and order creation. Draft prices may refresh on open/edit; active prices stay fixed. PDF sharing is a separate activity. | Freeze issue evidence; separate operational events and delivery. **Do not import live-open draft refresh**, which conflicts with accepted deliberate review. Order creation does not decide our partial acceptance/job model. |
| [ERPNext Quotation](https://docs.frappe.io/erpnext/quotation) | Draft/submitted, validity/expiry, lost reason, cancellation/amendment and later Sales Order conversion are distinct. Supports customer/lead quotation and line alternatives. | Expiry and formal revision need evidence; quoting precedes financial/order setup. Do not copy Lead-as-recipient instead of accepted Party identity, or a whole-quote ordered flag as sufficient partial-scope truth. |

Common pattern: editable preparation, frozen presented offer, explicit later disposition/revision
and downstream commitment. Meaningful differences: activation versus submission, auto draft pricing,
amend/cancel semantics and order-centric status. Two primary products plus accepted project research
are sufficient for initial lifecycle framing; no broad R3/R4 re-research or statutory research needed.
Vendor status vocabulary is evidence, not an approved project state machine.

## Business choices for the next consolidated review — recommendations only

| Bundle | New decision, practical impact and options | Initial recommendation / trade-off | Architecture now / later integration |
|---|---|---|---|
| L1 Draft and adopted evidence | Seller changes qty10→20 after calculating a plan. Keep every edit auto-adopted; have a distinct adoption checkpoint; or issue-only adoption? | Separate editable inputs from calculated preview and adopted evidence. Preview alone cannot satisfy issue; material/variance approval applies only to relevant source facts. Consider adoption plus issue revalidation in one transaction for routine no-approval cases; reduce SIMPLE steps without bypass. Exact checkpoints require owner review. | Dependency vector and evidence boundary now; no unnecessary material adoption for direct/manual lines. R9 duties, R6 selected scope. |
| L2 Approval and stale dependencies | Supplier cost/profile/agreement changes after review but total stays equal. Blind retain approval, always discard all approvals, or relevant-fingerprint invalidation with no-change revalidation? | Relevant fingerprint includes reviewed scope, source/cost certainty, controls, relationship/routing, financial policies/FX/dates and selected baseline. Equal totals alone insufficient. Preserve valid no-change evidence only with explicit revalidation. Use shared engine; no generic risk bypass. | Exact subjects/checkpoints and maker/checker policy with R9; risk/contract exceptions stay unsupported until separately approved. |
| L3 Issue and numbering | Two sellers issue same draft while rate/date eligibility changes. Number at creation, issue, or delivery? Must failed issue consume official number? | Atomic issue after current scope/policy/approval/freshness checks, capture immutable evidence and allocate official number using0079; internal draft identity need not be official number. Retry same receipt does not issue twice. Separate delivery from issue. | R5 aggregate lock/fingerprint and numbering policy; R8 artefact/delivery. Confirm revision number display and initial quote series policy rather than guess. |
| L4 Expiry, withdrawal and loss | Customer responds after validity ends; seller withdraws early. Edit original validity, reopen history, or record new events/new revision? | Preserve original expiry. Expiry is date eligibility with explainable as-of state; governed withdrawal/loss adds reasoned events. Renewal/change to issued terms creates linked revision; acceptance of expired offer is R6's explicit choice, never ordinary draft edit. | Distinguish immutable offer and current availability/status projections; R6 late acceptance and accepted-scope restrictions, R9 forecast provisional updates. |
| L5 Revision and supersession | Seller starts revision2 while customer still holds revision1. Does starting/issuing revision2 instantly withdraw1, require explicit replacement, or allow both? | Drafting revision2 alone must not silently withdraw1. Recommend explicit replacement choice at issue with clear customer impact and scope/acceptance checks; whether simultaneous live revisions are ever allowed remains owner choice informed by R6/exclusive claims. Copy inputs with original links; deliberately re-resolve affected current facts. | Original history and draft basis now; R6 partially accepted originals/competing revisions and R7 commitment amendments before final acceptance. No rewrite original component allocations. |
| L6 Unknown estimates and operational simplicity | Output-tax context unknown versus withholding estimate unknown with otherwise complete totals; SIMPLE seller has no estimating line. Block everything, permit every unknown, or distinguish dependencies? | Mandatory financial control/output-tax prerequisites must fail closed. Consider allowing separately labelled UNKNOWN withholding/payment estimate where company issue policy permits and customer projection is honest; it cannot hide unknown tax or mandatory margin. Keep SIMPLE routine prepare/issue concise; advanced review shown when relevant. | Owner must settle quote issue completeness/mandatory warnings/acknowledgements; no missing cost-to-zero or generated certificates. R8 projection and R9 sensitive duty policy. |

These are genuinely new lifecycle decisions, not revisions of F1–F8. Exact issue dates, original
validity/renewal, supported simultaneous revisions and unknown-estimate issue policy require owner
selection. Actual jurisdiction rates/max FX age remain governed setup, not new universal choices.

## Acceptance/commitment scenarios to research before final R5 acceptance

1. Baseline contains base goods plus one selected alternative and an optional service. R6 decides
   acceptance selection/partial quantity/consent/tier repricing and fixed-component allocation.
   R5 issue freezes offered baseline and provenance without guessing the accepted outcome.
2. Customer accepts4 of10 units, then seller issues revised remaining6. Original accepted allocation
   stays linked and consumed once; R6/R7 define remaining caps, exclusive claim and amendment.
   Don't calculate a second full fixed tax/benefit merely from fresh master lookup.
3. A current associated Opportunity closes while an issued offer remains. Apply accepted relationship
   direction without automatic quotation cancellation/win or creating duplicate pursuit. R6/R9 settle
   acceptance/remaining-forecast effects; CRM Lead disqualification does not cascade.
4. Accepted scope creates a job/order twice through retries. R7 requires idempotent target baseline
   creation with original commercial/estimate/contact/site provenance; issue is not handover.
5. Draft revalidation changes cost/FX but final net remains equal. R5 approval subject may still change;
   original issue snapshot and0102 provisional forecast publication remain distinct.

## Next research sequence and question handling

First settle L1–L3 draft/adoption/approval/issue transaction scenarios using existing Actions/engine
interfaces and bounded source inspection. Then investigate R6 late/partial acceptance and R7 target
baseline/amendment enough to resolve L4–L5; consolidate L6 with R8/R9 evidence/access. Present one
business-first lifecycle matrix before proposing ADRs. No unconditional state diagram or schema is
accepted prematurely. Further browsing only for a demonstrated unanswered design conflict.

Q45 is planning-originated and blocks new R5 lifecycle acceptance, not CRM/C2 or drafting R4 specs.
No implementation question was forwarded this turn. Future owner-forwarded questions retain
**Origin: IMPLEMENTATION AGENT**, original wording/example/commit/files and checked authority under
[question reconciliation](../architecture/implementation-question-reconciliation.md). Routine choices
within accepted authority need clarification, not invented business ADRs. Documentation alone never
marks a coding question VERIFIED; the coding owner supplies implementation/check evidence.

## Related

- [Phase 4](phase-4-sales-quotations-and-handover.md) · [R4 slices](phase-4-r4-implementation-slices.md)
- [Q45](../OPEN-QUESTIONS.md#q45--r5-quotation-lifecycle-checkpoints-and-issued-offer-treatment)
- [R2 structure](phase-4-r2-quotation-structure.md) · [R3 slices](phase-4-r3-implementation-slices.md)
- [Financial contract](../domain/quotation-financial-calculation.md) · [Approvals](../architecture/approval-workflows.md)
