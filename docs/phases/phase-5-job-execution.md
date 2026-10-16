[← Roadmap](README.md)

# Phase 5 — Job execution

- **Status:** research in progress; lifecycle0145, labour0146, coverage0147 and materials0148 ACCEPTED / bounded contracts SPECIFIED; no Job implementation or verification.
- **Authority:** D1–D6 approval2026-10-15 and L1–L6/Q52/Q53 approval2026-10-16; architecture documentation and Purchasing/external-work research only, no implementation.
- **Depends on:** accepted R7/R9 and actual supported CA/HA/owning adapters for delivery; existing shared foundations.
- **Blocked by:** delivery adapters and separate code authority; Q23/Q24/Q16 affect their own paths. Q51/Q52/Q53 are closed.

## Goal and scope

Execute applicable accepted work and compare estimate/authorised plan with actual usage/cost,
without duplicate commitments. Jobs are not mandatory for trading/straightforward delivery.
SIMPLE first; managed/advanced capabilities only where justified, not speculative settings.

Baseline: Jobs, appropriate work orders, material issue/consumption, timesheets/resource usage,
unified Job cost entries and estimate-versus-actual variance. Simple planning/progress/closeout
make these usable. Initial candidate: one labour-only fixed-scope service Job, acceptance →
supported handover/baseline → assignment/start → labour → completion evidence → costs → closeout.
No implementation authorised.

The revised [labour proposal](phase-5-labour-resource-research.md) investigates completion-only
and optional direct amounts, fixed units or time; it does not require every Job to maintain
named workers, hours or rates. Completion plus narrow direct incurred amounts is the accepted
initial business direction under0146; exact [coverage protocol](phase-5-cost-coverage-review.md) is accepted in0147, with the [coverage contract](../domain/job-cost-coverage.md) specified. Lifecycle0145
and its required-known-record/closeout guards are unchanged.

Specialised manufacturing/fabrication, complex capacity scheduling, advanced dispatch and equipment
management are separate later workstreams. No generic service Work Order. Finance owns invoicing,
payment/revenue/accounting settlement. Phase7 owns later mobile/integrations; operational responsive
UI remains each implementation slice's responsibility.

## Research sequence and dependencies

| Order | Deliverable | State / gate |
|---|---|---|
|1| Lifecycle/Job receiver | ACCEPTED0145; [bounded contract](../domain/job-execution-lifecycle.md) SPECIFIED, no adapter |
|2| Flexible labour evidence/usage/progress/recognition | ACCEPTED0146/Q51 closed; [bounded contract](../domain/job-labour-recognition.md) SPECIFIED; [coverage contract](../domain/job-cost-coverage.md) ACCEPTED0147/Q52 closed |
|3| Materials/custody/reservations/consumption/corrections | ACCEPTED0148/Q53 closed; [bounded contract](../domain/job-material-attribution.md) specified; Q24/Q23 retained |
|4| Purchasing/equipment/subcontract attribution | [Assessment](phase-5-purchasing-external-research.md) PROPOSED for owner review; no contract/implementation authority |
|5| Unified costing/source reconciliation | Queued; recognition/source identities,0040 correction linkage |
|6| Detailed closeout/documents/broader execution | Queued beyond accepted lifecycle;0137 and owning impacts |

Research can use accepted R7 contracts independently. Real release needs authoritative CA,
HA0–2/service HA3b, Job Actions, configured approvals and R9 guards/scope/holders. Registrations
or fixtures are not delivery. Missing impact is UNKNOWN, not NONE. Full Finance/production is
not universally required; no unverified Finance waiver for cancellation/reduction follows.

## Preserved requirements

Historical Job Costing is retained;0145 replaces only explicit historical assumptions. Unified
actuals/snapshot variance remain direction; exact source costing is queued.

0148 amends sequencing for the bounded ordinary company-owned, fixed-content QUANTITY_ONLY/
PACKAGE_BALANCE compatible AVCO/FIFO paths. It retains container identity, exact remnants,
variable-content, serial/SPECIFIC and advanced preview/variance obligations as path-specific gates.
Grouped v1 is not container tracking, reservation or a delivered Job adapter. Q24/Q23 remain open.
Every Job-consumed path requires0040 correction integration before activation.0137 Documents
and R7/R9 owning adapters remain delivery dependencies, not waived by architecture approval.

## Tasks and eventual acceptance

- [x] Record approved D1–D6 as0145, bounded contract and historical reconciliation.
- [x] Prepare labour bundle for review, not approval.
- [x] Resolve Q51 business direction as0146; specify bounded labour recognition/correction scenarios.
- [x] Resolve Q52/Q53 in0147/0148, specify bounded contracts and amend roadmap; defer zero assertions.
- [x] Prepare [Purchasing/external-work assessment](phase-5-purchasing-external-research.md) for owner review.
- [ ] Obtain owner direction for Purchasing/external-work research before detailed contracts.
- [ ] Research remaining streams in order, without simultaneous detailed design.
- [ ] Obtain separate implementation authorisation, named owners and actual adapter readiness.
- [ ] Deliver/verify one actual service workflow: tenant/scope guards, source lineage, exact retries,
      effective holds/termination, completion evidence and truthful incomplete-cost closeout.
- [ ] Complete applicable migration/client/build/browser/rollback/concurrency/demo checks.
- [ ] Deliver affected Inventory/correction/Documents integrations before enabling those paths.
- [ ] Update STATUS/domain/journey/ADRs at actual implementation milestones under normal rules.

Question origin is PLANNING RESEARCH. No implementation-agent question was forwarded for this
bundle. Existing implementation-owned questions/handoffs are untouched. No architecture record
establishes IMPLEMENTED, VERIFIED or PUBLIC_ENABLED.

## Related

- [Lifecycle](../domain/job-execution-lifecycle.md) · [0145](../decisions/0145-separate-job-lifecycle-execution-and-operational-closeout.md)
- [Labour research](phase-5-labour-resource-research.md) · [Open questions](../OPEN-QUESTIONS.md)
- [R7 slices](phase-4-r7-implementation-slices.md) · [R9 handoff](phase-4-implementation-handoff.md)
- [Historical Job Costing](../domain/job-costing.md) · [STATUS](../STATUS.md)
