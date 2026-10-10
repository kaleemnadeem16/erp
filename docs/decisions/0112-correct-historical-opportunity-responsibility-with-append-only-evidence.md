[← Decisions](README.md)

# 0112 — Correct historical Opportunity responsibility with append-only evidence

- **Status:** accepted — owner-approved B, 2026-10-10
- **Date:** 2026-10-10
- **Affects:** Sales historical responsibility, closed-record corrections and authority

## Context

Active reassignment, historical attribution and routine closed-record administration have different
meanings. Reopening a lost deal solely to explain its wrong historical attribution falsely records
renewed pursuit. Freely changing its owner would erase accountability and conflate administration
with the sales history retained by ADRs 0106/0107.

## Decision

Allow explicitly authorised, reasoned, append-only correction of historical responsibility
attribution without reopening the Opportunity or rewriting original evidence. A correction
references the original fact and retains who asserted the correction, when and why. Any subsequent
correction also appends evidence; original and corrected interpretations remain distinguishable.

Keep active reassignment under ADR 0106 and reasoned renewed pursuit under ADR 0107. Routine
closed-record administrative follow-up uses existing authored notes. No separate named administrative
assignee, general closed-record owner editing, commercial amendment or automatic cascade is approved.
Current Opportunity ownership, outcome, closure, quotes/jobs and published evidence are unchanged.

Explicit correction authority is required at Request and Action with relevant record scope.
Ordinary Opportunity editing does not imply retrospective attribution rights. The narrowest
capability and its grant recipients require the owner-authorisation gate documented in the
[specification](../phases/phase-4-crm-correction-specification.md#historical-attribution-authorisation-gate).
Business approval here does not accept a new capability or grant it to existing editors/managers.

This supplements 0106/0107: responsibility and access remain separate, and reopening means actual
revived pursuit. An append-only correction can live outside the immutable closed Opportunity row;
its existing closed-row guard need not be relaxed.

## Consequences

- **Positive:** explain responsibility mistakes without fabricating reopened pursuit.
- **Negative:** readers/reports must distinguish recorded history from corrected attribution.
- **Requires:** tenant-safe target references, reason and actor/time, append-only correction chain,
  stale/refusal/retry tests and narrowly approved duty/grant rollout before enabling writes.

## Alternatives considered

**Reopen for every correction** — rejected: administrative correction is not renewed pursuit.
**Rewrite original owner events/closed owner** — rejected: destroys original evidence.
**Use active assignment authority by implication** — rejected: retrospective attribution has a
separate sensitivity; suitability must be assessed, not assumed.
**Named closed-record administrator now** — deferred by owner; existing notes suffice.

## Research basis

Reuse [CRM policy comparison](../phases/phase-4-crm-policy-review.md#bounded-primary-comparison--accessed-2026-10-10)
and R1 ownership/lifecycle evidence, accessed 2026-10-10/09. Dataverse distinguishes ownership,
privileges and cascades; Odoo preserves loss/restoration context. Neither establishes our specific
historical-correction contract. The owner's choice follows project append-only evidence and
pursuit semantics rather than copying vendor ownership or reopening behaviour.

## Related

- [Implementation specification](../phases/phase-4-crm-correction-specification.md)
- [CRM correction domain](../domain/crm-corrections.md) · [Policy review](../phases/phase-4-crm-policy-review.md)
- [0106](0106-separate-sales-ownership-and-access.md) · [0107](0107-separate-opportunity-stages-and-outcomes.md)
