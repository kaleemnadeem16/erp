# 0183 — Record full acceptance under the Sales authority fence

- **Status:** accepted (local implementation choices; owner defaults remain provisional)
- **Date:** 2026-11-11
- **Affects:** Sales acceptance slice 2 only

## Context

The owner authorised backend slice 2 using the design brief's recommended defaults, without
answering its holder/rollout questions. Existing withdrawal and replacement take the company
access fence, then the Sales authority head, before quotation records (0164).

## Decision

Use that same exclusive authority head for acceptance before case, ordered claim heads and
revision locks. It serialises lazy baseline creation and every withdrawal/replacement, including
missing heads; no lifecycle writer needs to acquire a claim lock after a revision lock.
One case component accepts one full independent scope (one selected option for a choice group).
Store immutable references to frozen issued lines rather than duplicate their monetary/FX facts.
Claim capacity is one whole-scope entitlement, not a quantity in a stock or offered UoM.
The initial immutable evidence policy permits written sources with named representatives and
DOCUMENT_ON_FILE or CONFIRMED_BY_SELLER verification; verbal consent stays disabled.
No acceptance duty is added to the development preview, registered or granted.

## Consequences

- **Positive:** lifecycle races share an existing coordinator; original issued terms remain authoritative.
- **Negative:** acceptance serialises with other company Sales authority operations.
- **Requires:** DecisionActor before authority or record locks; append-only evidence and atomic claim guards.

## Alternatives considered

**Lock only existing claim rows before revisions** — rejected: lazy heads leave an absence race and existing lifecycle writers would invert the order.
**Copy/recalculate monetary amounts** — rejected: FULL_ONLY can preserve exact immutable source evidence without a second rounding or FX calculation.
**Enable the sensitive duty in dev preview** — rejected: owner explicitly withheld activation.

## Research basis

Reuses accepted R6 decisions 0125–0127 and the brief; no new ERP behaviour or external research.

## Related

- [Design brief](../phases/phase-4-customer-acceptance-and-handover-design-brief.md)
- [Customer decisions](../domain/sales-customer-decisions.md)
- [0164 lock order](0164-order-discount-authority-locks-before-quotation-and-approval-records.md)
