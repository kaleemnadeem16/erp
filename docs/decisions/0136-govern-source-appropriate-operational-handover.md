[← Decisions](README.md)

# 0136 — Govern source-appropriate operational handover

- **Status:** accepted
- **Date:** 2026-10-14
- **Affects:** Phase 4 R7, Sales, operational targets, R5/R6/R9 and Inventory/Phase 5/Finance boundaries
- **Authority:** owner's H1–H8 approval with clarifications after planning HEAD `7f2dc45`; architecture only, no code authorisation.

## Context

R1–R6 already govern commercial scope and immutable consent. The owner reviewed the final
R7 matrix, mixed journey and integrity research and approved the bounded architecture.
This adds downstream authority without changing historical accepted ADR bodies.

## Decision

H1–H4 are accepted. Accepted customer business remains the sole authoritative commercial
commitment. Sales Order is an optional managed operational aggregate for goods fulfilment,
picking, delivery and backorders; Job applies to suitable service/project work. An enabled
Order must manage genuine operational state, not duplicate acceptance. No universal Order,
Job, CustomerAccount, Assembly or site prerequisite is introduced. Order creation means
neither delivery nor invoicing; direct paths cannot bypass applicable required-path controls.

Map supported sources to owning targets through explicit disjoint operational allocations.
One accepted quantity can branch into separate work; Order→Job→Delivery referencing the same
allocation consumes commercial capacity once. Preserve source measures and each line's lineage.
Initially defer cross-acceptance consolidation and unsupported technical/fixed-setup splits.

Schedules plan existing commitments. Preparation, review/approval where configured, release,
assignment, fulfilment and customer acceptance remain distinct. Internal schedule changes within
contractual limits are operational; changes to agreed destinations, scope or deadlines need
customer agreement and Sales authority. Delivery batches neither recalculate quantity-tier prices
nor create new sales. Readiness is versioned and source/path-specific; execution-only missing
facts cannot invalidate authentic acceptance. R9 must approve final duty vocabulary, scope and
grants before public enablement.

## Consequences

- **Positive:** commercial, operational and actual evidence remain attributable without duplicate sales.
- **Negative:** unsupported paths and stale/unknown impacts refuse release or correction.
- **Requires:** separately authorised delivery, approved R9 duties/scopes/grants and real adapter verification.
- **State:** ACCEPTED architecture; domain/slices SPECIFIED; R7 not IMPLEMENTED or VERIFIED.

## Alternatives considered

**Mandatory universal Order/Job** — adds redundant agreement entry and fabricates prerequisites.
**One target per acceptance** — cannot represent mixed goods/services or genuine disjoint branches.
**Unbounded targets or delivery-as-sale** — duplicates commercial quantities and changes pricing.
**One universal checklist** — confuses execution preparation with customer consent.

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
