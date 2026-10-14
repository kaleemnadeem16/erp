[← Decisions](README.md)

# 0132 — Govern bounded accepted-business reductions and claim release

- **Status:** accepted
- **Date:** 2026-10-14
- **Affects:** Phase 4 R7, Sales, operational targets, R5/R6/R9 and Inventory/Phase 5/Finance boundaries
- **Authority:** owner's H1–H8 approval with clarifications after planning HEAD `7f2dc45`; architecture only, no code authorisation.

## Context

R1–R6 already govern commercial scope and immutable consent. The owner reviewed the final
R7 matrix, mixed journey and integrity research and approved the bounded architecture.
This adds downstream authority without changing historical accepted ADR bodies.

## Decision

H7–H8 are accepted. Original acceptance is immutable. Sales records governed linked
customer-agreed corrections with exact consent and authority; operational cancellation does
not alter accepted business, reopen a quotation or release an exclusive-family claim.

Initially permit only bounded remaining-scope reductions when Operations, Inventory, Finance
and Phase 5 effects can be authoritatively checked and safely applied. Accepted100, delivered30,
later agreed80 yields effective80, delivered30, remaining50 only when other constraints permit.
Reservations, invoicing, deposits, production, subcontracting and actual work can impose further
limits. Unsupported/unknown impact is not zero; an authoritative NONE needs a proven bounded
writer inventory. Preserve actual history and require new customer agreement/authority when
terms change. Local mandatory effects and commercial correction commit together under shared
locks; unsupported evidence may remain pending recognition, never falsely applied.

Operational Order/Job cancellation/replacement may release eligible unused operational allocation
only. Sales-owned commercial claim release is a separate sensitive duty requiring customer/owner
authority, validated downstream consequences and immutable release evidence. Reduction does not
automatically restore original offered capacity/benefits or free exclusive binding.

Initially defer automatic release, transfers, general returns, increases, repricing, full
cancellation and cross-family transfers. Each needs its own contracts and delivery authority.
No partial-family gate in 0124/0125 is relaxed until necessary R6/R7 adapters are delivered.

## Consequences

- **Positive:** commercial, operational and actual evidence remain attributable without duplicate sales.
- **Negative:** unsupported paths and stale/unknown impacts refuse release or correction.
- **Requires:** separately authorised delivery, approved R9 duties/scopes/grants and real adapter verification.
- **State:** ACCEPTED architecture; domain/slices SPECIFIED; R7 not IMPLEMENTED or VERIFIED.

## Alternatives considered

**Edit accepted quantity** — erases authentic agreement and correction history.
**Delivered quantity as the only floor** — ignores financial, stock and work commitments.
**Automatically release claims on target cancellation** — lets Operations cancel Sales obligations.
**General variation/returns engine now** — exceeds the approved bounded correction contract.

## Research basis

Reuse [H1–H8 review](../phases/phase-4-r7-handover-research.md),
[primary comparison and code inspection](../phases/phase-4-r7-evidence-and-integrity.md) and
[mixed journey](../phases/phase-4-r7-mixed-journey.md), accessed 2026-10-14.
ERPNext and Dynamics separate source provenance, fulfilment and later changes, but differ on
order-first packaging and editable targets. Our R6 commercial authority, optional targets and
five evidence layers follow owner approval, not vendor lifecycle defaults. No existing Action
inspection proves a future adapter's atomicity. R1–R6 decisions remain governing authority.

## Related

- [Operational domain](../domain/sales-operational-handover.md)
- [R7 slices and partial-family gates](../phases/phase-4-r7-implementation-slices.md)
- [0125](0125-govern-customer-acceptance-and-explicit-partial-consent.md)
- [0127](0127-record-customer-decision-evidence-and-atomic-business-claims.md)
