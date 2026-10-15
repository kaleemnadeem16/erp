[← Decisions](README.md)

# 0145 — Separate Job lifecycle, execution and operational closeout

- **Status:** accepted
- **Date:** 2026-10-15
- **Affects:** Phase 5 D1–D6, R7 Job receiver, R9 operational authority
- **Authority:** owner's approval of the refined D1–D6 contract, including final clarifications on incomplete valuation, hold evidence, completion withdrawal and effective status. Architecture documentation only; no implementation, permission registration, grants or migrations authorised.

## Context

R7 (0131–0132/0136) separates agreement, allocations, baselines and actuals. R9
(0138–0140) establishes authority without delivering adapters. The historical Job
Costing sketch is not a one-quotation/one-Job implementation contract. The owner
approved a bounded, service-first lifecycle after reviewing D1–D6.

## Decision

Job coordinates allocated operational work; lightweight activities organise actionable
portions. SIMPLE may create one default activity internally. No universal Job, Assembly,
CustomerAccount, site or generic service Work Order. Specialised production Work Orders
remain future work. Activities cannot multiply accepted capacity, fulfilment or cost.

Use four authoritative states: RELEASED, ACTIVE, COMPLETED, CLOSED. Preparation lives
in R7 before successful target creation. Planning/assignment are facts; independent holds
and termination constrain every writer/interface. Multiple holds remain effective until
individually resolved. ACTIVE + STOPPED is never ordinarily executable.

One authorised R7 release atomically establishes the Job and initial baseline; execution
start remains separate. Preserve baselines and authorised successors. Routine changes
stay lightweight within contractual, allocation and relied-on readiness limits. Overrun,
rework and additional agreed scope differ; no unrestricted repricing/scope increase.

Cancel only proven unused Jobs with safe downstream effects. Partially executed Jobs
retain actuals and accountable remainder until supported disposition. Job termination
cannot reverse acceptance or release Sales claims. Unused planned hours are not capacity.

Completion requires appropriate fulfilment evidence, not hours or manual percentages.
Allow controlled COMPLETED → ACTIVE withdrawal before closeout only with authority,
original evidence, current readiness and coordinated dependent effects. Unsafe/unknown
effects refuse and surface a resolution case. Ordinary closed/terminated restart is deferred.

Closeout reconciles fulfilment/remainder and required known operational quantities/records.
Acknowledged incomplete valuation is allowed only with missing sources/reasons, accountable
owner and supported follow-up/correction path. No disputed quantity, ordinary pending entry
or unknown downstream impact may be concealed. Closed is not fully costed, invoiced, paid,
revenue-recognised or financially settled. Later authorised adjustments preserve source
lineage and do not restart execution; incomplete cost is never zero or falsely final.

Capture and review work during holds truthfully, preserving occurrence/recording times,
historical assignment, hold evidence, actor/reviewer and classification/owning impacts.
Capture alone grants no retrospective authority, recognition or commercial fulfilment.
No initial general hold-bypass permission. Historical corrections use separate controlled
paths rather than ordinary restart.

Reuse R7 stable identity, receipts, coordinated locks/shared transactions and readiness;
R9/RecordScope guards apply at Request and Action, independently of assignment and sensitive
cost/file access. Future Job duty vocabulary/holders and labour recognition remain gates.

## Consequences

- **Positive:** simple service execution grows to installation/fabrication without a second Job system.
- **Negative:** unknown effects block affected changes; closed Jobs may retain visible valuation obligations.
- **Requires:** the bounded lifecycle contract and actual owning adapters/security/rollback/concurrency evidence.
- **State:** architecture ACCEPTED; bounded contract SPECIFIED; Job/receiver not IMPLEMENTED or VERIFIED. Labour research is PROPOSED.
- **Compatibility:** supplements R7/R9, including their Order-specific lifecycle. No accepted ADR is superseded. Only historical Job Costing assumptions are replaced.

## Alternatives considered

Mandatory generic Work Orders burden service work. One mutable status hides independent
facts. Release-as-start invents occurrence. Cancelling worked Jobs hides actuals. Waiting
for financial settlement conflates Operations and Finance. Hidden missing valuation,
hold bypass and silent historical edits fabricate completeness or authority.

## Research basis

Reuse [R7 evidence](../phases/phase-4-r7-evidence-and-integrity.md) and accepted R7/R9.
Lifecycle comparison accessed 2026-10-15: [ERPNext Projects](https://docs.frappe.io/erpnext/project),
[Work Orders](https://docs.frappe.io/erpnext/work-order), and
[Odoo task stages/statuses](https://www.odoo.com/documentation/19.0/applications/services/project/tasks/task_stages_statuses.html).
ERPNext separates project tasks and production; Odoo distinguishes stages/statuses.
Both support separating work breakdown and lifecycle presentation, but differ on completion
and reopening. Our exact lifecycle/hold/correction/closeout rules follow owner approval,
not vendor defaults; none overrides project commercial or cost-certainty authority.

## Related

- [Lifecycle](../domain/job-execution-lifecycle.md)
- [Phase 5](../phases/phase-5-job-execution.md)
- [Historical Job Costing](../domain/job-costing.md)
- [R7](../domain/sales-operational-handover.md) · [R9](../domain/sales-duty-matrix.md)
