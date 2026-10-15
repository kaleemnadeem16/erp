[← Roadmap](README.md)

# Phase 5 — Job execution

- **Status:** research in progress; lifecycle architecture ACCEPTED0145 / contract SPECIFIED; no Job implementation or verification.
- **Authority:** owner D1–D6 approval/clarifications2026-10-15; architecture documentation and labour research only.
- **Depends on:** accepted R7/R9 and actual supported CA/HA/owning adapters for delivery; existing shared foundations.
- **Blocked by:** proposed labour decisions Q51; materials Q24/Q23 and prerequisite reconciliation block only affected materials paths.

## Goal and scope

Execute applicable accepted work and compare estimate/authorised plan with actual usage/cost,
without duplicate commitments. Jobs are not mandatory for trading/straightforward delivery.
SIMPLE first; managed/advanced capabilities only where justified, not speculative settings.

Baseline: Jobs, appropriate work orders, material issue/consumption, timesheets/resource usage,
unified Job cost entries and estimate-versus-actual variance. Simple planning/progress/closeout
make these usable. Initial candidate: one labour-only fixed-scope service Job, acceptance →
supported handover/baseline → assignment/start → labour → completion evidence → costs → closeout.
No implementation authorised.

Specialised manufacturing/fabrication, complex capacity scheduling, advanced dispatch and equipment
management are separate later workstreams. No generic service Work Order. Finance owns invoicing,
payment/revenue/accounting settlement. Phase7 owns later mobile/integrations; operational responsive
UI remains each implementation slice's responsibility.

## Research sequence and dependencies

| Order | Deliverable | State / gate |
|---|---|---|
|1| Lifecycle/Job receiver | ACCEPTED0145; [bounded contract](../domain/job-execution-lifecycle.md) SPECIFIED, no adapter |
|2| Labour/resource usage/progress/recognition | [Research](phase-5-labour-resource-research.md), PROPOSED/Q51 awaiting owner |
|3| Materials/custody/reservations/consumption/corrections | Queued; Inventory, Q24/Q23, package prerequisites |
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
- [ ] Resolve Q51; specify supported labour semantics/scenarios.
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
