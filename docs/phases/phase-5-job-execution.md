[← Roadmap](README.md)

# Phase 5 — Job execution

- **Status:** research in progress; lifecycle0145 and labour0146 ACCEPTED / bounded contracts SPECIFIED; no Job implementation or verification.
- **Authority:** D1–D6 approval2026-10-15 and L1–L6 approval2026-10-16; architecture documentation and coverage/materials research only, no implementation.
- **Depends on:** accepted R7/R9 and actual supported CA/HA/owning adapters for delivery; existing shared foundations.
- **Blocked by:** exact coverage protocol Q52; materials Q53/Q24/Q23 prerequisite/ownership choices affect their paths. Accepted labour direction is no longer blocked by Q51.

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
initial business direction under0146; exact [coverage protocol](phase-5-cost-coverage-review.md) awaits Q52 review. Lifecycle0145
and its required-known-record/closeout guards are unchanged.

Specialised manufacturing/fabrication, complex capacity scheduling, advanced dispatch and equipment
management are separate later workstreams. No generic service Work Order. Finance owns invoicing,
payment/revenue/accounting settlement. Phase7 owns later mobile/integrations; operational responsive
UI remains each implementation slice's responsibility.

## Research sequence and dependencies

| Order | Deliverable | State / gate |
|---|---|---|
|1| Lifecycle/Job receiver | ACCEPTED0145; [bounded contract](../domain/job-execution-lifecycle.md) SPECIFIED, no adapter |
|2| Flexible labour evidence/usage/progress/recognition | ACCEPTED0146/Q51 closed; [bounded contract](../domain/job-labour-recognition.md) SPECIFIED; [coverage protocol](phase-5-cost-coverage-review.md) PROPOSED/Q52 |
|3| Materials/custody/reservations/consumption/corrections | [Research bundle](phase-5-material-flow-research.md) PROPOSED/Q53; existing Q24/Q23 and package prerequisite owner choices retained |
|4| Purchasing/equipment/subcontract attribution | Queued; reuse owning systems; commitment versus actual |
|5| Unified costing/source reconciliation | Queued; recognition/source identities,0040 correction linkage |
|6| Detailed closeout/documents/broader execution | Queued beyond accepted lifecycle;0137 and owning impacts |

Research can use accepted R7 contracts independently. Real release needs authoritative CA,
HA0–2/service HA3b, Job Actions, configured approvals and R9 guards/scope/holders. Registrations
or fixtures are not delivery. Missing impact is UNKNOWN, not NONE. Full Finance/production is
not universally required; no unverified Finance waiver for cancellation/reduction follows.

## Preserved requirements

Historical Job Costing is retained;0145 replaces only explicit historical assumptions. Unified
actuals/snapshot variance remain direction; exact source costing is queued.

The roadmap requires CONTAINER_TRACKED, package-aware preview and waste/residual bands before
material issue design; current STATUS defers container tracking until requested. ADR0052's modes
do not silently repeal that prerequisite. Preserve the original requirement and reconcile with
owner before material design. Grouped PACKAGE_BALANCE v1 is not container tracking/reservation
or a verified Job adapter. Q24 separates company stock from customer/free-issue ownership,
substitutes, scrap/remnants/containers. ADR0040 requires consumed-Job correction attribution;
ADR0137 governs operational printed documents. None is designed or waived by the labour pilot.

## Tasks and eventual acceptance

- [x] Record approved D1–D6 as0145, bounded contract and historical reconciliation.
- [x] Prepare labour bundle for review, not approval.
- [x] Resolve Q51 business direction as0146; specify bounded labour recognition/correction scenarios.
- [ ] Final owner review of [refined Q52/M1–M6](phase-5-coverage-material-resolution.md), exact roadmap boundary and [separate zero assertion](phase-5-zero-cost-assertion.md); no new ADR or implementation authorised.
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
