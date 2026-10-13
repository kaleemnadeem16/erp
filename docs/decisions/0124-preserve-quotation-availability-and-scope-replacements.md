[← Decisions](README.md)

# 0124 — Preserve quotation availability and scope replacements

- **Status:** accepted
- **Date:** 2026-10-13
- **Affects:** Phase 4 R5 L4/L5, Sales quotations, shared governance and downstream boundaries.
- **Authority:** owner approval of L1–L6 after local `6d5bc0c`; architecture only, no code authorisation.

## Context

Accepted R1–R4 reserve quotation lifecycle seams. The reviewed L1–L6 research and bounded R6/R7
reconciliation distinguish quoted offers, customer decisions and operational commitments. These
remaining semantics must be explicit before implementation; existing CRM/C2 delivery stays separate.

## Decision

Require explicit valid-through date with governed company default, frozen company-local expiry
boundary and immutable timezone evidence. No universal validity duration is imposed. Changing issued
validity/commercial terms creates a governed successor; historical issue is never edited.

Keep draft cancellation, issued expiry, seller withdrawal, customer rejection and accepted-business
cancellation distinct. Expiry/withdrawal ends eligible unaccepted availability only; accepted business
and its agreed evidence remain. Do not infer customer rejection, Opportunity loss or job cancellation.
Actual decision versus recording time and late-recorded acceptance remain R6 decisions.

Preparing revision2 leaves revision1 available. Issuing replacement explicitly identifies predecessor
and eligible logical scope, revalidates remaining availability and atomically ends only replaced
unaccepted scope. Within one family, overlapping logical scope cannot have contradictory independently
actionable revisions. Preserve0100's competing-family/exclusive-group architecture. Accepted scope
retains its original agreed terms; replacement cannot move/erase/recommit it or cancel an Order/Job.

Initial delivery MUST refuse partial replacement until required R6/R7 claims, allocation, consent and
commitment contracts are approved AND delivered. Initial whole-unaccepted replacement is separate
from those gated operations. New revision is not a new independent pursuit or new benefit entitlement.
Preserve source/choice/original allocation links for later decisions; no acceptance, commitment
amendment, risk bypass or multi-line header-discount authority follows from replacement.

## Consequences

- **Positive:** Explainable source-aware quotation lifecycle with immutable historical evidence.
- **Negative:** Explicit evidence/version checks can refuse stale or unsupported operations.
- **Requires:** Typed Sales adapters, company/record/sensitive-field guards, database integrity,
  idempotent transactions and verified authorised delivery. R6–R9 dependent functions stay gated.
- **Boundary:** Acceptance is architecture, not implementation or runtime verification.

## Alternatives considered

**Withdraw original on starting a draft** — removes a still-valid customer offer prematurely.
**Overlapping actionable revisions within one family** — enables contradictory acceptance.
**One closed flag / replacement cancelling accepted business** — erases partial truth and commitments.
**Treat competing families as forbidden revisions** — contradicts accepted 0100.

## Research basis

Reuse [R5 evidence](../phases/phase-4-r5-quotation-lifecycle.md) and
[owner-reviewed L1–L6](../phases/phase-4-r5-business-review.md), primary sources accessed 2026-10-12:
Dynamics quotation activation/revisions versus ERPNext submission/remaining mapping, and local
shared-engine/numbering inspection. Common pattern is frozen offer with distinct later decisions;
products differ on draft refresh, revision closure and order conversion. Preserve accepted deliberate
refresh/source neutrality; vendor behaviour is not law or approval for our pending R6/R7 policies.

## Related

- [Lifecycle contract](../domain/quotation-lifecycle.md) · [R5 slices](../phases/phase-4-r5-implementation-slices.md)
- [R6/R7 reconciliation](../phases/phase-4-r5-acceptance-and-handover-boundaries.md)
- [Phase 4](../phases/phase-4-sales-quotations-and-handover.md)
- [0070](0070-shared-configurable-approval-engine.md) · [0085](0085-request-specific-document-review-for-approvals.md)
- [0079](0079-document-numbering-system-templates-over-a-variable-registry.md) · [0109](0109-shared-commercial-quotation-lines-and-optional-estimating.md)
- [R3](../phases/phase-4-r3-business-review.md) · [R4](../phases/phase-4-r4-financial-review.md)
