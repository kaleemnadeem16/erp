[← Decisions](README.md)

# 0186 — Deliver one progressive Jobs engine through a bounded service pilot

- **Status:** proposed (owner direction recorded; finite implementation scope not authorised)
- **Date:** 2026-11-12
- **Affects:** Phase 4 closure boundary; Phase 5 basic service pilot; Sales release/readiness; future Job origins

## Context

The owner reviewed the Phase 5 recommendation and requested changes before implementation authority.
Sales now has genuine full acceptance and immutable pending handover preparation. Jobs already has
storage, access predicates and internal assign/start/hold Actions, all disabled. Repeating those
foundations would delay the missing release/receiver integration and usable execution workflow.

Current preparation always reports unknown service cost as a release blocker, stores null completion
conditions and cannot become a released record through UPDATE. Accepted0145–0152 distinguish
operational fulfilment, optional labour, cost certainty and financial settlement.0184 records the
existing pending preparation, not authority to fabricate missing facts or overwrite its evidence.

## Decision

Propose one Jobs engine with a basic company-capability profile: one complete fixed-service
deliverable/default activity, assignment, explicit start, independent holds, accountable completion
and operational close. Sales release and Jobs receipt commit in one transaction with independent
authority and stable source/effect identity. The first operational milestone includes guarded APIs,
safe list/detail and basic user actions, not only internal Actions.

Completion pins authoritative customer-agreed text/conditions and provenance. Default whole-deliverable
attestation needs no universal template, signature, hours or percentage. Applicable evidence/approval
policy can require more; missing required support refuses. Local instructions cannot create agreement.

Cost readiness is conditional on applicable commercial/control rules. Missing mandatory evidence blocks;
optional unknown cost stays explicitly UNKNOWN/UNASSESSED without obstructing completion-only work.
This proposes refining the current unconditional blocker, not treating an unknown amount as zero.
Closeout retains0151: unresolved operational obligations block, and incomplete incurred valuation needs
a real supported accountable follow-up. The initial pilot excludes such obligations if that path is absent.

Choose a genuine no-material/no-subcontractor pilot only with owning source inventory/proof and enforced
writer restrictions. SERVICE_ITEM, absent rows or an operator checkbox alone cannot certify absence.
Preserve prepared evidence with additive immutable release/condition records. Never weaken its guards.

Labour, material/supplier integration, detailed costing, multiple activities and advanced approvals are
optional gated extensions with their own dependencies. Company enablement, permissions and presentation
stay separate. Configuration never bypasses integrity or changes historical facts; unsafe downgrade
refuses. Only delivered capabilities may be enabled. No global small/large-company split engine.

Keep Sales source lineage in an origin-specific adapter/binding. Later maintenance, warranty and internal
sources may supply their own authoritative tables, permissions and verification through the same Jobs
ports, with additive tenant-safe origin bindings. No fake Sales consent or speculative alternate adapter
is implemented now. Missing origin support fails closed.

Phase 4 closes against its own approved quotation, acceptance and prepared-handover deliverables after
their acceptance checks. Sales-to-Jobs release is a coordinated integration milestone. Phase 5 execution,
closeout and advanced costing do not indefinitely expand Phase 4 closure requirements.

## Consequences

- **Positive:** small-company workflow is usable without compulsory time/cost administration; richer
  capabilities extend the same engine and preserve genuine source ownership.
- **Negative:** first-pilot eligibility is deliberately narrow; absence proof and restricted writer
  coverage require real source-owner work even when no material or supplier record is expected.
- **Requires:** finite scope approval per coherent batch; actual policy, identity and holder choices;
  independent source/security/UI reviews; exact-SHA CI and atomic/concurrent end-to-end evidence.
- **Authority:** this proposal changes no accepted ADR, runtime behaviour, registry or grant. If
  accepted, explicitly reconcile any conflicting current readiness requirements in domain/docs and
  implementation together. Existing prepared baselines/readiness remain historical evidence.

## Alternatives considered

**Build Jobs foundations again** — existing storage/access/lifecycle Actions should be integrated.
**Backend-only first operational delivery** — fails the requirement for operators to finish real work.
**Always require cost/timesheets** — confuses optional valuation/labour with required fulfilment controls.
**Always ignore cost** — bypasses applicable mandatory commercial rules and hides known obligations.
**Treat all services as no-material** — cannot prove source effects absent or fence competing writers.
**Separate basic and advanced engines** — duplicates lifecycle/security and makes company evolution costly.
**Generic string origin IDs now** — trades real FK integrity for speculative extensibility; add real
origin bindings when their authorised adapters are delivered.

## Research basis

Question: how can lightweight service execution accommodate optional time/cost detail and multiple
origins without requiring a heavyweight project structure for every service? Reuse accepted0145–0152
and their research; supplementary primary-source comparison checked 2026-11-12:

- [Odoo19 field service task creation](https://www.odoo.com/documentation/19.0/applications/services/field_service/creating_tasks.html):
  official indexed documentation identifies manual, sales-order and helpdesk origins. Direct page
  retrieval timed out during this review; use only that indexed origin finding, not unverified settings.
- [ERPNext Tasks](https://docs.frappe.io/erpnext/tasks): independent tasks and project-linked tasks;
  optional planning/hierarchy coexist with timesheet-derived actual dates and costing. We preserve
  explicit start/attestation rather than adopt timesheet-derived execution dates.
- [Business Central project creation](https://learn.microsoft.com/en-us/dynamics365/business-central/projects-how-create-jobs):
  at least one task under a project, detailed planning lines, conditional timesheet setup and usage-link
  capabilities. Reuse the one-default-activity seam, not its full project/posting hierarchy.

Convergence: operational work units can carry source links and richer detail. Differences: project
hierarchy and derivation of actual dates/costs vary; these products do not establish our consent,
permission, absence-proof or transactional rules. The bounded profile is a project-specific synthesis
of owner direction and accepted architecture. Optional advanced activation/downgrade retains the
existing [capability contract](../architecture/capability-delivery-contract.md); vendor patterns do
not authorise a new configuration engine or override source ownership.

## Related

- [Revised implementation plan](../phases/phase-5-revised-implementation-plan.md)
- [0145 lifecycle](0145-separate-job-lifecycle-execution-and-operational-closeout.md)
- [0184 pending preparation](0184-prepare-pending-service-handovers-without-release.md)
- [Job closeout](../domain/job-closeout-reconciliation.md)
- [Extensibility](../architecture/extensibility.md)
