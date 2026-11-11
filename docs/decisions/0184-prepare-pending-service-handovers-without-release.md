# 0184 — Prepare pending service handovers without release

- **Status:** accepted (implementation choices; owner defaults remain provisional)
- **Date:** 2026-11-11
- **Affects:** Sales handover slice 3 only

## Context

The owner authorises inactive preparation from real acceptance, not release or a Job receiver.
The current source has no canonical completion-condition evidence or service-cost provider;
Jobs has only a receiver input shape, not ReceiveReleasedService. Accepted R7 permits pending plans.

## Decision

- Use existing `sales.handover.prepare` as a STAGED ordinary duty; read plans under the existing independent acceptance/quotation view rules. Reject a second read-duty requirement here because the owner explicitly requests reuse.
- Register only FULL_ONLY SERVICE_ITEM → SERVICE_JOB preparation. Reject generic service/manual/stock fallback because a type alone does not deliver an adapter.
- Prepare immutable pending plans with one full root allocation, initial stage, baseline and readiness evidence. Keep overall readiness NOT_READY until every release dependency is delivered; expose preparation facts separately. Reject invented completion conditions, zero service cost or a pretend receiver to make READY achievable.
- Capture optional ON_SITE/REMOTE context, selected Party contact/site and instructions as operational inputs, never changed commercial terms. Missing facts report owning-domain next actions. Reject universal mandatory site/warehouse because remote, service-only work is valid.
- Bind encrypted actor/company-bound review to acceptance, exact terms, operational inputs and freshly read readiness facts; persist its token privately. Reject token-only trust because contacts/sites/Item status may change.
- Reuse CustomerDecisionOperation receipts and authority-first serialization from 0183. Exact receipt/business replay repeats current authority before mutable readiness; reject fresh UUID/employee/key as new allocation capacity.

## Consequences

Preparation records intent and pending facts, never consent, release, stock, Job, credit clearance or
completion. Real source/Party rows are read under shared locks after Sales authority/acceptance locks.
Canonical completion and service cost remain explicit missing dependencies owned by Sales;
Inventory/Purchasing applicability remains UNKNOWN, not inferred absent from the Item type.

## Research basis

Reuse the brief, accepted R7 domain contract and Task G receiver design; no new ERP business rule.

## Related

- [Brief](../phases/phase-4-customer-acceptance-and-handover-design-brief.md)
- [Operational handover](../domain/sales-operational-handover.md)
- [0183](0183-record-full-acceptance-under-the-sales-authority-fence.md)
