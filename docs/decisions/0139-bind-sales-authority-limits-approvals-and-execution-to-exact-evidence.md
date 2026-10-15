[← Decisions](README.md)

# 0139 — Bind Sales authority limits, approvals and execution to exact evidence

- **Status:** accepted
- **Date:** 2026-10-15
- **Affects:** Phase 4 R9 A5–A7; Sales, Documents/Company and owning source/approval/reporting adapters
- **Authority:** owner's A1–A10 approval with safeguards after local c25d5cb; architecture/documentation only, no application code/live permissions/grants authorised.

## Context

R1–R8 and Documents0137 have accepted business boundaries. The final R9 review reconciles duties,
scopes, limits and reporting without changing their historical accepted bodies. Actual delivery,
public enablement and real holder decisions remain distinct from architecture approval.

## Decision

Use versioned governed commercial references. Evaluate specific discount/override input separately
from aggregate final reduction, with independent percentage and absolute ceilings where configured.
Compare cross-currency absolute limits using pinned company-base rate/date/source evidence. Select
highest-priority applicable policy rule set; equal-priority ambiguity refuses. All independent
mandatory controls still pass. No actor/principal/delegate ceiling addition, line split/revision/
edited-base reference reset or unknown required reference/FX/cost interpreted as unlimited authority.
Actual thresholds are governed company data, not universal architectural constants.

Reuse shared approval engine for above-limit review only with eligible supported policy. Preserve
NONE/POLICY/REQUIRED and stored-actor maker/checker where applicable policy requires it. Issue,
ordinary approval, discount approval, operational release and sensitive correction remain distinct.
Approval grants neither transition nor unrestricted cost/limit/reason read. No general risk or
fixed-contract exception bypass is enabled.

Every HTTP/worker/import/console writer uses protected owning Actions. Preserve human initiator and
constrain actual service execution by company/subject/purpose/authorised intent; workers cannot
impersonate employees for business privileges. Exact completed retry returns currently authorised
projection; changed intent conflicts. Append-only authority/audit facts retain truthful occurrence
and recording times, including customer decisions and external provider results.

## Consequences

- **Positive:** explainable duty/authority/evidence and reporting with no duplicate business commitments.
- **Negative:** unsupported adapters, missing required evidence and unsafe rollout mappings refuse enablement.
- **Requires:** [duty matrix](../domain/sales-duty-matrix.md), [authority contract](../domain/sales-authority.md), [reporting contract](../domain/sales-reporting-and-forecast.md), [rollout specification](../phases/phase-4-r9-permission-rollout.md) and actual integrated verification.
- **State:** ACCEPTED architecture / SPECIFIED contracts; no new R9 feature IMPLEMENTED or VERIFIED by this record.

## Alternatives considered

Edited current base as authority denominator — allows authority reset.
Average discount only / add delegate ceilings — misses aggregate effects and independent authority.
Universal maker/checker — adds policy the owner did not require.
HTTP guard or privileged system actor only — bypasses non-HTTP Action/record/evidence authority.

## Research basis

Reuse [final R9 A1–A10 comparison and inspected contracts](../phases/phase-4-r9-authority-reporting-review.md),
primary ERPNext authorisation-rule and Dynamics forecast-permission evidence accessed2026-10-15,
plus accepted0101/0102/0106/0107/0114/0116/0070/0085/0087 and0137. Products separate conditional
authority and forecast access but differ on role/hierarchy rules; project capability/scope semantics
remain governing. Owner-approved refinements settle remaining choices, not vendor defaults.
Reused evidence answers these questions; no repetitive search or application test was needed to
record architecture approval. Exact repository vocabulary was inspected read-only before specification.

## Related

- [R9 slices](../phases/phase-4-r9-implementation-slices.md) · [Consolidated handoff](../phases/phase-4-implementation-handoff.md)
- [Decision index](README.md) · [Authorisation](../architecture/authorization.md)
