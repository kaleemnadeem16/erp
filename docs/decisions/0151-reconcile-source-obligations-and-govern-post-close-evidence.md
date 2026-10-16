[← Decision index](README.md)

# 0151 — Reconcile source obligations and govern post-close evidence

- **Status:** accepted
- **Date:** 2026-10-16
- **Affects:** Phase5 C1–C4/Q56; owning-source closeout and historical reconciliation
- **Authority:** owner approval; architecture documentation only

## Context

0145 distinguishes operational completion and closeout. Closure must reconcile known operational
obligations without forcing optional timesheets or waiting for every final invoice. A free-text note
or assigned owner cannot replace owning-domain effects. Historical truth must survive later evidence.

## Decision

Use source-owned evidence-based applicability/readiness, not a universal manual checklist. Each
applicable owner reports resolved, supported valuation follow-up, blocking, unsupported/unknown or
evidenced not applicable. Reliable source inventory establishes applicability; missing adapter is
not no obligations. SIMPLE shows actionable outstanding issues, without new universal approvals or
optional labour/cost/coverage prerequisites.0145 lifecycle/states/transitions remain unchanged.

Permit supported incomplete valuation only after required operational obligations are resolved.
Follow-up retains source/component, missing valuation fact/reason/basis, accountable eligible owner,
supported owning correction operation, acknowledgement and review date/event. Govern reassignment;
no orphaned follow-up. A date or note is not a waiver or substitute for real resolution capability.

Unresolved quantities/performance, rejected work, hire periods, custody, ownership, allocation and
unknown impacts block. Only valuation-only differences with source-confirmed resolved performance/
scope/responsibility and supported later reconciliation may remain acknowledged. No general dispute
override. Stopping a Job never automatically cancels supplier/customer/Inventory obligations.

Classify post-close evidence as legitimate late reporting, existing-fact correction, new work,
unused-cancellation contradiction or unresolved evidence. Preserve occurrence/recording time,
original evidence, actor/authority and lineage. Capture never automatically authorises, fulfils or
recognises work. New work needs separate supported authority, not an adjustment. Valuation-only
corrections,0147 reassessment and0150 restatement preserve history without ordinary reopening.
Unsupported quantity/ownership/capacity corrections remain visible and blocked, never partially applied.

Source-specific Inventory/Purchasing/labour/external/operational allocation readiness contracts are
required. Revalidate relied-on generations under compatible transactions/locks or proven controls at
commit, including concurrent recognition/holds/corrections/custody. Missing mandatory adapter or unknown
impact refuses affected closeout. Independent source cost visibility remains; safe non-monetary owning
readiness verdicts may serve coordinators without exposing rates/prices/history. Preserve R7/R9/0145–0150.

## Consequences

- **Positive:** truthful SIMPLE closure with accountable supported valuation follow-up, no false settlement.
- **Negative:** each activated source needs real applicability/readiness/correction participation.
- **Requires:** [contract and dependency matrix](../domain/job-closeout-reconciliation.md), atomic
  mandatory effects, current-authorised exact retries and historical resolution evidence. No code authority.

## Alternatives considered

Manual checkbox/no-source defaults — cannot prove absence or discharge owning obligations.
Block all missing final prices — conflates operational closeout with Finance settlement.
General dispute override/ordinary reopen — hides unresolved facts or creates new work authority.
Silent historical edits — destroys original closure/report evidence and correction lineage.

## Research basis

Reuse [Q56 research/options/scenarios](../phases/phase-5-closeout-research.md), accessed2026-10-16.
Business Central couples completion/WIP/invoicing; SAP distinguishes technical/business completion
and outstanding commitments. Those financial/manufacturing states are not copied.0145 already settles
our lifecycle; comparison informs source-specific readiness and accountability only. Owner explicitly
approves conservative dispute gates, supported follow-up and independent safe readiness visibility.

## Related

- [Closeout contract](../domain/job-closeout-reconciliation.md)
- [0145](0145-separate-job-lifecycle-execution-and-operational-closeout.md)
- [0150](0150-unify-job-cost-reporting-through-source-owned-attribution.md)
