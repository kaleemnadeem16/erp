[← Decisions](README.md)

# 0123 — Issue numbered quotation revisions atomically

- **Status:** accepted
- **Date:** 2026-10-13
- **Affects:** Phase 4 R5 L3/L6, Sales quotations, shared governance and downstream boundaries.
- **Authority:** owner approval of L1–L6 after local `6d5bc0c`; architecture only, no code authorisation.

## Context

Accepted R1–R4 reserve quotation lifecycle seams. The reviewed L1–L6 research and bounded R6/R7
reconciliation distinguish quoted offers, customer decisions and operational commitments. These
remaining semantics must be explicit before implementation; existing CRM/C2 delivery stays separate.

## Decision

Use internal family/draft identities and one official centrally allocated quotation number for every
issued revision. Register Sales quotation numbering under 0079 with company-configurable series policy;
ALLOW_GAPS is the recommended initial default, not a mandatory company setting. Frozen number/date
variables use actual server issue instant and central company-local time, separately from pricing date.

Issue atomically revalidates eligibility/scope/current facts/required approvals, confirms the reviewed
result, adopts applicable evidence, freezes the complete immutable offer, allocates its number and
records operation receipt. A failure commits no half-issue. Completed exact authorised retry returns
original issue/number/instant without new allocation; changed payload conflicts. Current access and
sensitive projection still apply. Concurrent issue attempts cannot issue a revision twice. GAPLESS
allocation rolls back; ALLOW_GAPS may leave a sequence gap after failure; neither permits recycling
committed numbers. Preview does not allocate an official number.

Company issue policy for separate UNKNOWN withholding/payment estimate is BLOCK by default or
explicit ALLOW_WITH_ACKNOWLEDGEMENT. Only when all mandatory commercial/output-tax/FX/margin/floor/
other requirements pass may the latter path issue. Preserve acknowledgement bound to exact reviewed
facts, actor/time and policy version. Customer-safe projection must say UNKNOWN, never zero or guaranteed
receipt. Acknowledgement is not financial approval, exemption, customer consent or a mandatory bypass.

Issued scope/recipient/price/cost certainty/material/financial/FX/tax/allocation/policy/approval evidence
is immutable. Issuing is distinct from PDF generation, sending, delivery, customer acceptance,
operational commitment/handover, stock reservation and Finance posting. Artefact/delivery failure
cannot automatically undo a committed issue. R8 controls eventual customer documents/delivery.

## Consequences

- **Positive:** Explainable source-aware quotation lifecycle with immutable historical evidence.
- **Negative:** Explicit evidence/version checks can refuse stale or unsupported operations.
- **Requires:** Typed Sales adapters, company/record/sensitive-field guards, database integrity,
  idempotent transactions and verified authorised delivery. R6–R9 dependent functions stay gated.
- **Boundary:** Acceptance is architecture, not implementation or runtime verification.

## Alternatives considered

**Official numbers on draft/save or delivery** — confuses preparation, issue and communication.
**Handwritten revision suffixes instead of central allocation** — obscures official identity/history.
**Retry by calling allocator again** — consumes another number and can duplicate business facts.
**Unknown withholding as zero or ordinary issue bypass** — misstates cash and financial authority.

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
