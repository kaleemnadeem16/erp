[← Decisions](README.md)

# 0146 — Recognise flexible labour evidence without fabricating usage

- **Status:** accepted
- **Date:** 2026-10-16
- **Affects:** Phase5 L1–L6/Q51, Job costing,0145, R7/R9 consuming boundaries
- **Authority:** owner's approval of revised Q51 business direction and five recommended decisions, with explicit cost valuation/coverage clarification. Architectural documentation only; no code, grants, migrations or Job development authorised.

## Context

Businesses legitimately record different evidence: completion alone, a direct labour expense,
worker-days, hours or detailed named time. A universal Employee/timesheet/hourly-rate dependency
would invent facts.0145 already separates lifecycle/closeout from actual-cost completeness.
The owner approved progressive evidence and requested explicit source/category/Job completeness.

## Decision

Accept revised L1–L6 business direction. One logical source-attribution framework accepts
method-specific evidence, not separate SIMPLE/MANAGED ledgers. Completion-only is supported
without mandatory quantity/cost; source absence is not zero expense. First useful delivery
direction is completion plus optional bounded direct incurred labour amounts in company-base
currency. Fixed-unit/daily and hourly methods follow incrementally; exact supported adapters
still require specification, delivery and separate implementation authority.

Named identity is conditional on method/policy, not universal. Distinguish worker, labour class,
recorder/reviewer/approver and responsible person/team. Optional login and stable company-scoped
worker identity need no HR/payroll. Aggregate records cannot claim individual attendance,
accountability or overlap detection. No manufactured units/hours/rates for amount-only sources.

Recorded usage, recognition, monetary valuation, progress and contractual fulfilment are separate.
SIMPLE can record/recognise in one action only when both duties hold and applicable review passes;
record duty does not grant recognition. Reuse shared approvals for MANAGED review, no universal
maker/checker or second manual posting. Recognised usage may remain unvalued. Recognition,
attribution and receipts are idempotent/transactionally consistent behind owning Actions.

Rate-based costing reuses ResourceRate evidence through authorised adoption; class first.
Pin unit/rate/currency/effective date/source/basis/authority. Work-date basis, compatibility,
precision and validity must be evidenced; no silent conversion of worker-days to hours.
Direct amounts do not require hourly rates. Company-base first; unsupported foreign observations
remain evidence, not fabricated converted amounts. Monetary posting obeys pinned dual-amount
invariants. No automatic catalogue recost or wages/quotation-price actual-cost authority.

Preserve append-only corrections for quantity, amount, valuation, identity/source and historical
date, with original lineage and both occurrence/recording context.0145 hold/closed correction
guards remain. Purchasing/Finance own external commitments/invoices/payroll/payment; later
sources confirm/replace/adjust existing economic attribution rather than adding it again.
Activity projections and provisional/recognised views never count twice.

Distinguish three independent completeness axes: valuation of a source; coverage of Job labour
expenses; overall cost completeness across applicable required source categories. A completely
valued15000.0000 source proves neither full labour coverage nor full Job cost. Reports distinguish
confirmed complete coverage, declared complete coverage, incomplete and unassessed, disclosing
scope and evidence basis. No automatic complete/zero total from no entries or all known rows valued.

The exact lightweight SIMPLE coverage declaration workflow is separately PROPOSED under Q52;
this decision accepts the distinction/reporting requirement, not unreviewed declaration authority,
zero-cost assertion, confirmation or invalidation mechanics. Completion and0145 operational
closeout cannot be made dependent on fabricated optional usage. Required known unresolved facts,
unknown impacts and known valuation follow-up conditions still apply.

## Consequences

- **Positive:** practical small-business execution and detailed organisational governance share one costing framework.
- **Negative:** coverage assertions and source uncertainty must remain visible; unsupported matches/conversions refuse affected posting.
- **Requires:** [bounded labour contract](../domain/job-labour-recognition.md), real source/guard/recognition/correction adapters, and separate implementation authority.
- **State:** Q51 business architecture ACCEPTED; bounded labour contract SPECIFIED; no Job labour IMPLEMENTED/VERIFIED. Q52 declaration protocol and materials research remain PROPOSED.
- **Compatibility:** supplements0145,0110,0131–0132/0136,0138–0140 and precision0141–0144; no accepted ADR conflict or supersession identified.

## Alternatives considered

Mandatory timesheets/names/hourly rates — fabricated or disproportionate evidence.
Separate ledgers by company size — fragmented corrections and double counting.
No labour entries means zero — false completeness. All known sources valued means complete
Job — ignores omitted sources/categories. Catalogue/payroll/invoice automatically adds cost —
duplicates economic expense or rewrites historical basis. Universal independent approval —
unnecessary SIMPLE overhead, unlike explicit operation authority and applicable review.

## Research basis

Reuse [revised L1–L6](../phases/phase-5-labour-resource-research.md) and
[primary comparison/inspection](../phases/phase-5-labour-evidence.md), accessed2026-10-15.
[Business Central supplies](https://learn.microsoft.com/en-us/dynamics365/business-central/projects-how-manage-project-supplies)
and [ERPNext Projects](https://docs.frappe.io/erpnext/project) distinguish resource usage,
expenses and purchase sources; retained Odoo timesheet evidence supports optional detailed
identity. Journal/employee-claim/recognition packaging differs. We retain owning-domain
boundaries and conservation, not vendor lifecycle/default grants. Completeness distinctions
follow explicit owner clarification2026-10-16; exact declaration protocol remains research.

## Related

- [Phase5](../phases/phase-5-job-execution.md) · [Labour contract](../domain/job-labour-recognition.md)
- [Coverage proposal](../phases/phase-5-cost-coverage-review.md) · [Lifecycle](../domain/job-execution-lifecycle.md)
- [Material research](../phases/phase-5-material-flow-research.md)
