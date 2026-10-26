[← Roadmap](README.md)

# Phase 5 — Job execution planning overview

**2026-10-16 · Research/design in progress. Accepted architecture is not delivered functionality.**
No Job implementation, grants, migrations, production enablement or runtime verification authorised.

## Accepted architecture and closed questions

| Area | Governing authority / bounded specification | Delivery state |
|---|---|---|
| Job/activity lifecycle, R7 receiver, hold/termination/completion/closeout |0145; [lifecycle](../domain/job-execution-lifecycle.md) | SPECIFIED, no Job adapter |
| Flexible labour/source recognition |0146/Q51 closed; [labour](../domain/job-labour-recognition.md) | SPECIFIED, no source adapter |
| Scoped cost coverage/declarations |0147/Q52 closed; [coverage](../domain/job-cost-coverage.md) | SPECIFIED, no runtime assessments |
| Bounded materials/custody/consumption |0148/Q53 closed; [materials](../domain/job-material-attribution.md) | SPECIFIED, no Job integration; grouped Inventory v1 exists separately |
| External expenses, Purchasing service acceptance and allocations |0149/Q54 closed; [external](../domain/job-external-cost-attribution.md) | SPECIFIED, no acceptance/attribution adapter |
| Unified costing/reporting |0150/Q55 closed; [costing](../domain/job-unified-costing.md) | SPECIFIED, physical design/adapters/projections not delivered |
| Source closeout/historical reconciliation |0151/Q56 closed; [contract/matrix](../domain/job-closeout-reconciliation.md) | SPECIFIED, source readiness/correction adapters not delivered |
| Job live outputs/later formal documents |0152/Q57 closed; [contract](../domain/job-operational-documents.md) | SPECIFIED, no Job view/formal adapter |

Reuse accepted R7/R9 and Documents0137. Commercial allocations, baseline preservation and scope
correction rules remain settled. Completion-only and optional direct incurred labour are initial
SIMPLE direction; no universal timesheet/name/hourly rate/HR, generic service Work Order or Assembly.
Jobs are not mandatory for straightforward trading/delivery. Service pilot remains a candidate,
not permission to build before dependent handover/readiness and separate implementation authority.

## Current readiness and remaining implementation planning

Q55 costing0150, Q56 closeout0151 and Q57 Job outputs0152 are accepted/specified. No Job feature is
implemented/verified by those records. [Fresh-state consolidated readiness assessment](phase-5-implementation-readiness.md)
inventories newer Phase4 kernels/AU foundations and current uncommitted quotation WIP versus missing
R6/R7/Job/source adapters. It proposes J1 completion-only and J2 direct-cost extension, SP0–SP6 batches,
atomic groups, verification expectations and concurrent-owner/Git coordination for review.

1. Owner selects actual commercial source, J1/J2 pilot sign-off boundary and module/integration owners.
2. Review SP0a physical/interface/security design; separately authorise any disabled foundation/code.
3. Coordinate actual Phase4/R7 predecessor and Job atomic slices, preserving missing delivery proofs.
4. Implement only authorised batches; verify source/security/rollout before exposing supported paths.

Q51–Q57 closed; Q23/Q24/Q16 remain path-specific, not universal blockers.0137/DF0–DF5 and required
upstream source snapshots stay intact; no formal Job PDF is mandatory. No unsupported source is NONE.
This readiness package is prepared, not proof dependencies delivered. Stop at owner review; no code,
grants, migrations, push or final physical-schema authority follows from this assessment.

## Dependencies and possible independent foundations

| Boundary | Required delivery proof / what may proceed independently after separate authority |
|---|---|
| Phase4 R6/R7 | Supported CA/HA0–2 and service HA3b, exact acceptance/allocation/baseline/readiness, cancellation/reduction impacts; no fake released Job before authoritative handover |
| R9/security/shared approvals | Actual owning guards, RecordScope, version-bound review, real holder/configuration rollout and sensitive reads; registration/fixtures alone are not delivery |
| Inventory | Existing ordinary/package operations retained;0040 Job correction participant/routing/reconciliation before ANY consumed path. No scalar bypass or parallel balances |
| Purchasing | Reusable service acceptance plus affected PO cancel/short-close/portion writers and0149 overlap/correction integration; physical goods receipt is not service acceptance |
| Documents0137 | Shared framework and actual Job/report adapters, current access/snapshot/retention/download controls before enabled artefacts |
| Future Finance | Later invoice matching/AP/payment/tax/FX/period/GL ownership. Reconcile prior evidence before overlapping authoritative costs; full Finance is not universally needed for bounded operational recording |
| Potential independent foundations | Evidence/identity/recognition value contracts, precision/authority reuse assessment, read-projection and receipt conventions can be specified in isolation. Coding still needs separate authority; no skeleton grants or public surfaces implied |
| Integration-dependent slices | Actual Job release, recognised source postings, service acceptance, material consumption/correction, authoritative costing/closeout and durable reports need their owning adapters together; placeholders do not satisfy integration |

No unsupported downstream effect is NONE. Transactions must share proven connection/compatible locks,
exact retries and source generations; unknown required impacts refuse. Independent specification or
internal tests cannot establish IMPLEMENTED/VERIFIED/PUBLIC_ENABLED service workflow.

## Explicit deferrals and preserved obligations

- Containers/exact remnants, variable contents, serial/SPECIFIC and unsupported stock/valuation paths;
  advanced package previews/variance requirements stay path-specific gates under0148. Initial compatible
  fixed-content company-owned QUANTITY_ONLY/PACKAGE_BALANCE AVCO/FIFO paths retain all Inventory controls.
- Q24 customer/free-issue/substitution/scrap/returnable-container cases and already-consumed returns;
  refuse affected unsupported routes, never zero-cost ownership fiction.
- Inventory-owned managed reservations; initial availability is NOT RESERVED with live revalidation.
- Explicit zero-cost assertions; arbitrary historical reconstruction; ETC/EAC/earned-value automation,
  automatic accrual, unsupported foreign-cost posting and unsupported incurred overhead policy.
- Advanced fleet/capacity/dispatch/payroll, manufacturing/subcontract hierarchies/retention certification;
  mobile/integrations later Phase7, Finance financial settlement Phase6.

## Next implementation-ready planning milestone

A reviewed **bounded service-pilot planning package**: accepted0150 costing and0151 closeout contracts with delivery gates; required document
boundaries reconciled; source/duty/dependency inventory; separately reviewable slices
with exact authority/transaction/correction ownership and acceptance/failure coverage. Clearly mark
adapters available versus missing and which must ship atomically. Initial candidate: supported R7
handover → Job/activity → assignment/start → optional direct labour → fulfilment → truthful cost view
→ supported operational closeout. Unsupported material/external/report paths remain gated.

This milestone makes scope reviewable for authorisation, not automatically implemented or safe to
activate. Actual implementation readiness still requires owner selection, named owners, dependency
delivery proof and applicable migration/API/security/concurrency/browser/demo checks at implementation.

## SP0a design review — 2026-10-21

Task #19 adds a proposed [physical/interface/security package](phase-5-sp0a-design.md) and
[transaction/API/verification companion](phase-5-sp0a-delivery-contract.md). The owner accepted the
21 October provisional readiness baseline and confirmed R7 accepted-service direction, independently
signable J1 completion-only and optional J2 labour. Technical candidates await review; SP0b remains
separately gated. No Job implementation, verification or public enablement follows from this package.

## Task A receiver design — 2026-10-22

SP0a is integrated as `3b2d4d1` with CI success recorded on #19. Task #32 proposes the
[R7-to-Job receiver contract](phase-5-r7-job-receiver-design.md) and
[dependency/verification handoff](phase-5-r7-job-receiver-verification.md): fixed-deliverable source
binding, immutable conditions, origin identity, same-transaction release and current-authorised replay.
Actual CA/HA source keys/verifier/locks and Jobs adapters remain missing delivery dependencies at
baseline f0e2871. SP0b #28 is a separately authorised disabled foundation, not runtime delivery;
this design does not implement it, Task B lifecycle/closeout or Task C/J2. Technical seams need joint
Sales/security/owner review; J1 sign-off is not established by receiver design or release alone.

## Current tasks

- [x] Accept/specify0145–0152; resolve Q51–Q57 and preserve research evidence.
- [x] Refine Q55 resolution/candidate, inspect infrastructure and update planning overview.
- [x] Reconcile Q55 approval, current costing contract and provisional/final valuation distinction.
- [x] Prepare Q56 source-specific closeout/historical-evidence research for owner review.
- [x] Record C1–C4 approval in0151 and reconcile closeout dependency matrix; Q56 closed.
- [x] Prepare Q57 operational documents/reports/snapshots research under0137.
- [x] Record Q57 in0152 and bounded Job output contract, preserving0137/DF sequence.
- [x] Inspect latest checkout and prepare consolidated readiness/service-pilot assessment.
- [ ] Owner selects source/owners/batch and reviews physical/interface/security design.
- [x] Consolidate proposed service-pilot slices/dependencies; actual runtime/delivery evidence still missing.
- [ ] Implement/verify only separately authorised batches; no such work begun by these documents.

## Related

- [Task D lifecycle foundation design](phase-5-task-d-j1-foundation.md) and [J1-F1 batch proposal](phase-5-task-d-j1-f1-proposal.md) — technical candidates for separate review/implementation authority.
- [Q55 resolution](phase-5-unified-costing-resolution.md) · [Historical Job sketch](../domain/job-costing.md)
- [R7 slices](phase-4-r7-implementation-slices.md) · [R9 handoff](phase-4-implementation-handoff.md)
- [Documents](../domain/documents.md) · [STATUS](../STATUS.md) · [Questions](../OPEN-QUESTIONS.md)
