[← Decisions](README.md)

# 0131 — Preserve operational baselines and handover identity

- **Status:** accepted
- **Date:** 2026-10-14
- **Affects:** Phase 4 R7, Sales, operational targets, R5/R6/R9 and Inventory/Phase 5/Finance boundaries
- **Authority:** owner's H1–H8 approval with clarifications after planning HEAD `7f2dc45`; architecture only, no code authorisation.

## Context

R1–R6 already govern commercial scope and immutable consent. The owner reviewed the final
R7 matrix, mixed journey and integrity research and approved the bounded architecture.
This adds downstream authority without changing historical accepted ADR bodies.

## Decision

H5–H6 are accepted. Preserve distinct original issued quotation, actual customer agreement,
initial operational baseline, authorised changes/successor baselines, and actual costs,
movements and fulfilment. Assembly paths retain pinned technical/material versions and controls,
with explicit fixed/setup-cost scope; Items/services need no fabricated Assembly. PARTIAL/UNKNOWN
cost stays explicit. Stock availability is not reservation. Replanning cannot silently change
agreed terms or historical cost evidence.

Use stable handover-business identity independent of request keys/employees, stage-specific
target identity, disjoint allocation caps and exact idempotent receipts. A repeated handover
under a fresh key cannot duplicate a target or accepted commitment. Genuine parallel branches
must identify separate allocations; stages inherit their allocation rather than consuming anew.

Prefer required local target creation through owning Actions in one transaction only where
same-connection participation and compatible lock order are actually supported. Guard Request
and Action, revalidate generations, coordinate all conflicting writers and enforce tenant-safe
constraints. Missing required target adapter refuses. Atomic groups roll back all required
effects; independent component releases report their individual outcomes truthfully.

This is an accepted contract, not verified atomicity. Real target Actions, migrations and
rollback/concurrency tests must exist before any delivery claim. Future delayed/external targets
require a separately approved durable handoff protocol with held capacity, receiver identity,
receipts and reconciliation. Ordinary queued notification is insufficient.

## Consequences

- **Positive:** commercial, operational and actual evidence remain attributable without duplicate sales.
- **Negative:** unsupported paths and stale/unknown impacts refuse release or correction.
- **Requires:** separately authorised delivery, approved R9 duties/scopes/grants and real adapter verification.
- **State:** ACCEPTED architecture; domain/slices SPECIFIED; R7 not IMPLEMENTED or VERIFIED.

## Alternatives considered

**Rebuild from current masters** — destroys historical commercial and cost provenance.
**Freeze all operations to the quotation** — prevents legitimate separately authorised replanning.
**Request-key uniqueness alone** — new keys can repeat the same business handover.
**Queue mandatory target creation** — dispatch is not an atomic or durable target receipt.

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
