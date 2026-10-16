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

Reuse accepted R7/R9 and Documents0137. Commercial allocations, baseline preservation and scope
correction rules remain settled. Completion-only and optional direct incurred labour are initial
SIMPLE direction; no universal timesheet/name/hourly rate/HR, generic service Work Order or Assembly.
Jobs are not mandatory for straightforward trading/delivery. Service pilot remains a candidate,
not permission to build before dependent handover/readiness and separate implementation authority.

## Q55 and genuinely open decisions

Owner supports U1–U5 business reporting principles, subject to [refined resolution](phase-5-unified-costing-resolution.md)
and [bounded contract candidate](../domain/job-unified-costing-candidate.md) review. Q55 remains
AWAITING_OWNER; no costing ADR accepted, physical schema fixed or application implementation authorised.
Initial proposed scope: current-restated known actuals, original/current cost basis where available,
corrections, separate commitments/exposure and independent completeness. No first-scope ETC/EAC.
Existing infrastructure inspected for reuse; no shared Job projection engine claimed delivered.

Q23 variable-content, Q24 ownership/exceptions and Q16 source-actor question remain open for affected
paths. They do not globally block truthful ordinary supported service/stock workflows. Unverified
adapter readiness is a delivery gate, not another owner business choice or implicit waiver.

## Remaining planning and research — in order

1. Owner reviews Q55 resolution/candidate. Resolve genuine new semantics before accepting ADR/physical design.
2. Reconcile detailed operational closeout and historical evidence adjustments beyond0145's accepted
   rules: known-source reconciliation, remainder disposition, correction follow-up and owning interfaces.
   Preserve optional capture and supported incomplete-cost closeout; do not reopen settled lifecycle.
3. Define needed operational document/report snapshots through0137 owning adapters and audience/scope
   rules. No independent renderer or assumption that shared Documents infrastructure exists.
4. Prepare consolidated Phase5 dependency/adapter inventory and bounded service-pilot slices, acceptance
   matrix, transaction/lock proof, real duty/holder rollout proposals and source/correction handoffs.
   Research any uncovered source-specific semantic gap before declaring its slice ready.
5. Owner selects a separately authorised implementation batch and owners; activate only verified paths.

Potential later exceptions/reservations/forecasting research is need-driven and separate from the
initial service pilot. Do not start all workstreams simultaneously or mark Phase5 planning complete
merely because core ADRs exist. Stop current turn at Q55/overview review; steps2–5 are not begun here.

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

A reviewed **bounded service-pilot planning package**: settled Q55 contract/decision; closeout and
required document boundaries reconciled; source/duty/dependency inventory; separately reviewable slices
with exact authority/transaction/correction ownership and acceptance/failure coverage. Clearly mark
adapters available versus missing and which must ship atomically. Initial candidate: supported R7
handover → Job/activity → assignment/start → optional direct labour → fulfilment → truthful cost view
→ supported operational closeout. Unsupported material/external/report paths remain gated.

This milestone makes scope reviewable for authorisation, not automatically implemented or safe to
activate. Actual implementation readiness still requires owner selection, named owners, dependency
delivery proof and applicable migration/API/security/concurrency/browser/demo checks at implementation.

## Current tasks

- [x] Accept/specify0145–0149; resolve Q51–Q54 and preserve research evidence.
- [x] Refine Q55 resolution/candidate, inspect infrastructure and update planning overview.
- [ ] Owner review Q55/overview, then reconcile ordered remaining planning areas.
- [ ] Consolidate service-pilot slices/readiness evidence for separate implementation authorisation.
- [ ] Implement/verify only separately authorised batches; no such work begun by these documents.

## Related

- [Q55 resolution](phase-5-unified-costing-resolution.md) · [Historical Job sketch](../domain/job-costing.md)
- [R7 slices](phase-4-r7-implementation-slices.md) · [R9 handoff](phase-4-implementation-handoff.md)
- [Documents](../domain/documents.md) · [STATUS](../STATUS.md) · [Questions](../OPEN-QUESTIONS.md)
