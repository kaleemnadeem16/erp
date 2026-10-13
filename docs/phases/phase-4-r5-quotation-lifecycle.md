[← Phase index](README.md)

# R5 — Quotation lifecycle evidence and authority reconciliation

**2026-10-13 · R5 ACCEPTED0122–0124 / Q45 CLOSED; source inspection historical2026-10-12.**
Origin: PLANNING RESEARCH. [Lifecycle contract](../domain/quotation-lifecycle.md) and
[R5 slices](phase-4-r5-implementation-slices.md) are specified, no implementation/verification.
[Consolidated L1–L6 review](phase-4-r5-business-review.md) is the authoritative current recommendation
matrix now approved; [partial acceptance/handover journey](phase-4-r5-acceptance-and-handover-boundaries.md)
provides the R6/R7 reconciliation. The original initial agenda at 1d63de5 is historical.
This evidence record supports accepted R5; it grants no code authority. Reuse R1–R4 first; research is
bounded to draft/adoption/approval/issue/revision transitions. R6 acceptance and R7 commitment/
handover inform R5 boundaries; exact R6/R7 decisions remain pending and R9 applies throughout.

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

## Bounded downstream comparison — additional primary evidence, 2026-10-12

| Source | Observed behaviour | Why it informs, rather than dictates, this ERP |
|---|---|---|
| [Dynamics Sales transaction stages](https://learn.microsoft.com/en-us/dynamics365/sales/sales-transactions) | Activating freezes a quote; revise closes it and creates a draft with incremented revision identity. | Our proposed L5 deliberately keeps the original available while drafting its successor; vendor auto-close-on-revise is not imported. One versus many actionable scopes must be explicit. |
| [Dynamics create order](https://learn.microsoft.com/en-us/dynamics365/sales/create-edit-order-sales) | Accepted quote cannot be revised; creating an order copies products/price-list/currency. | Our accepted-vs-remaining split needs finer scope history. No blanket whole-quote freeze blocking a legitimate remaining-scope proposal, and no issue-to-order implication. |
| [ERPNext version-15 official quotation source](https://raw.githubusercontent.com/frappe/erpnext/version-15/erpnext/selling/doctype/quotation/quotation.py) | `get_ordered_status` distinguishes partial/full; mapper subtracts already ordered stock quantity, filters alternatives, calculates target totals and checks expired quotations unless company setting allows them. | Confirms remaining quantity and selected choices need provenance. Do not import target recalculation as authority to change original net/benefits/taxes, automatic financial-customer creation or generic expired-offer bypass. Our R6/R7 must define consent, allocation and claim semantics. |

The ERPNext Sales Order documentation timed out twice; no detailed claim rests on the unavailable
page. Its official version-15 source supplies the bounded partial mapping evidence above; it is not
asserted identical to every current vendor edition. Earlier quotation documentation remains reused.
No source settles our tier entitlement, fixed-tax legal allocation, parallel revisions or consent.
Those are explicit owner/project/jurisdiction decisions. This closes the research gap for R5 review,
not all future R6/R7 design. No broader currency/tax/pricing investigation was repeated.

## Current repository facts — read-only inspection, no application checks

| Surface inspected | Finding | Required Sales adaptation / limit |
|---|---|---|
| Sales module README and file inventory | CRM foundation and LEAD/OPPORTUNITY numbering exist; no quotation issue/approval adapter found in inspected module. | Draft/issue/revision/acceptance are future operations. Existing CRM duties/creation numbering are not quote authority. README history is not independent verification of delivery. |
| Numbering `NumberingService::allocate`, `LockedCounterStrategy`, `SequenceCounterStrategy` | Caller transaction captures allocation; locked counter rolls back; sequence nextval may leave gap. Allocator is not an issue retry receipt lookup. | Quote-specific atomic receipt/family-revision lock prevents another allocator call on completed retry. Scope/date/routing/number uniqueness remain 0079. No new framework or global gap policy. |
| Purchasing `IssuePurchaseOrder` | Demonstrates document lock, shared approval guard and number allocation at official issue. | Reuse pattern, not PO semantics: source-aware quote snapshots and exact retry need own adapter; no Purchasing changes authorised. |
| Approvals `ApprovalSubject`, `ApprovalRouting`, `RequireApproval` | Routing hash covers typed facts/base amount; current guard also checks registered review provider fingerprint. General modes NONE/POLICY/REQUIRED already exist. | New Sales routing/review provider must cover all reviewed commercial/technical/financial/Party facts; total-only routing is insufficient. Subject guard doesn't issue a quote. |
| `ApprovalReviewRegistry` and `SubmitForApproval` | Review capture/fingerprint is consumer-owned; unchanged pending request can be reused; changed review is not approved just because amount equal. | No-change revalidation must preserve both hashes and original reviewed evidence, not alter old request. Separate current dependency receipt. |
| `ExpireOverdueApprovals` | Implements open-step deadlines and overdue/request-expiry effects. | Do not claim generic post-approval age expiry exists. Quote expiry and any proposed subject approval valid-until are different governed facts. |
|0109/0110, R3/R4 and job-costing domain | Sources/cost certainty/financial evidence accepted; job schema is conceptual and accepted scope need not require a job/Assembly/customer account. | R6 decides accepted terms/remaining claims; R7 decides applicable target baseline and corrections. No duplication, reservation or Finance work from issue. |

Code pointers:
[NumberingService](../../backend/app/Modules/Numbering/Services/NumberingService.php),
[counter strategies](../../backend/app/Modules/Numbering/Services/SequenceCounterStrategy.php),
[RequireApproval](../../backend/app/Modules/Approvals/Actions/RequireApproval.php),
[routing](../../backend/app/Modules/Approvals/Support/ApprovalRouting.php),
[review registry](../../backend/app/Modules/Approvals/Support/Review/ApprovalReviewRegistry.php),
[step expiry](../../backend/app/Modules/Approvals/Actions/ExpireOverdueApprovals.php),
[PO issue pattern](../../backend/app/Modules/Purchasing/Actions/IssuePurchaseOrder.php).
Read-only inspection supports design compatibility, not runtime/concurrency verification.

## Synthesis and owner boundary

No demonstrated contradiction requires altering accepted R1–R4. The engine contracts support
explicit candidates, relevant fingerprint invalidation, named issue and per-series numbering;
new Sales adapters and downstream scope/claim design are still required. Recommendations choose
routine explicit atomic adoption/issue, one actionable overlapping scope, reasoned availability
changes and a narrowly governed unknown-withholding disclosure path, not automatic acceptance.

The [single owner matrix](phase-4-r5-business-review.md) records all new L1–L6 choices/options/defaults;
the [reconciliation](phase-4-r5-acceptance-and-handover-boundaries.md) identifies exactly what R5 can
settle now and what R6/R7 must later approve. The owner review is approved in0122–0124; contracts/slices now specify it.
No R6 decision or code authority follows from R5 acceptance.

Q45 planning research is closed. [R6 review](phase-4-r6-customer-decisions.md) opens Q46;
only affected downstream decisions/delivery are gated. No forwarded implementation question was supplied. Future owner-forwarded questions
retain **Origin: IMPLEMENTATION AGENT**, original wording/example/commit/files and checked authority
under [question reconciliation](../architecture/implementation-question-reconciliation.md).
Routine choices within authority need clarification rather than duplicate policy ADRs. Documentation
alone never marks a coding question VERIFIED; coding owner supplies actual implementation evidence.

## Related

- [Consolidated business review](phase-4-r5-business-review.md) · [R6/R7 journey](phase-4-r5-acceptance-and-handover-boundaries.md)

- [Phase 4](phase-4-sales-quotations-and-handover.md) · [R4 slices](phase-4-r4-implementation-slices.md)
- [Q45](../OPEN-QUESTIONS.md#q45--r5-quotation-lifecycle-checkpoints-and-issued-offer-treatment)
- [R2 structure](phase-4-r2-quotation-structure.md) · [R3 slices](phase-4-r3-implementation-slices.md)
- [Financial contract](../domain/quotation-financial-calculation.md) · [Approvals](../architecture/approval-workflows.md)
