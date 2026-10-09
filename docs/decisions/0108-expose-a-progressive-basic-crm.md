[← Decisions](README.md)

# 0108 — Expose a practical basic CRM without forcing enterprise workflow

- **Status:** accepted — owner-approved U6; final consistency review passed.
- **Date:** 2026-10-09
- **Affects:** initial Sales/CRM capability boundary, company configuration and UX exposure.
- **Gate:** accepted business scope, not implementation slices or a new activity/notification engine.

## Context

A small service firm needs a quotation and callback; a larger business needs accountable enquiries
and deals. Quotation-only CRM omits follow-up, while mandatory team/automation setup burdens
simple quoting. Existing architecture separates domain capability, configuration and UI exposure.

## Decision

Initial capabilities include optional Lead intake; qualification/disqualification with preserved
history; direct Opportunity creation and direct Quotation paths under ADR 0098; Party/contact
lookup and controlled linking; one opportunity owner/reassignment; basic lead/deal descriptions
and source; notes with author/time; next action/date for manual follow-up; optional expected close;
configurable stages; closure reasons/reopen history; simple authorised/scoped pipeline lists/views;
and accepted forecast methods/value distinctions in ADRs 0101/0102.

Unknown expected amounts remain unknown, not automatically zero. Small businesses can use
straightforward quotation-first workflow without unnecessary CRM fields or warnings. Lead intake
is never mandatory; one enquiry can yield several genuine deals with conversion provenance and
retry-safe intent under ADR 0105. Qualification does not consume commercial evidence or silently
create financial accounts. Disqualification and revived intake retain prior decisions/history.

Expose advanced controls only when relevant to company configuration and actor authority. Keep
company policy and future team dimensions separate; no selling-team model is assumed implemented.
Basic notes/follow-up do not promise activity scheduling, automatic reminders or auto-close.

Defer campaigns, predictive scoring, commissions, automatic territory routing/lead assignment,
email ingestion/synchronisation, complex activity/calendar engines, master merge, shared selling
teams/advanced team structures and portals. Notification delivery remains ERP-wide Q33; storing a
next-action date neither decides notification semantics nor implements delivery.

## Reconciliation and consequences

Compatible with progressive extensibility, ADR 0098 optional hybrid entry, ADR 0105 identity/
qualification, ADR 0106 responsibility/access, ADR 0107 stage/outcome history and ADRs 0101/0102
forecast evidence. Scope and financial fields are protected in notes/lookups/lists/history, not
merely buttons. Reuse Numbering, Approvals, audit and guarded Actions; no duplicate engines.
Exact basic forms/API/permissions/reporting projection are R9/delivery design after dependent
research. Phase 4 excludes Phase 5 execution and Phase 6 payment/ledger implementation.

- **Positive:** usable manual follow-up with small-company simplicity and larger-company extension.
- **Negative:** advanced automation is deliberately absent; expectations must be explicit.
- **Requires:** permission-aware progressive UX, scoped queries/history and retained human evidence;
  code, demo data, tests and integration work remain future delivery gates.

## Alternatives considered

**Quotation-only feature with no follow-up foundation** — rejected: loses responsibility/pursuit.
**Mandatory Lead then Opportunity for every quotation** — rejected: unnecessary CRM records.
**Enterprise automation/team setup initially** — deferred: unsupported complexity for simple firms.
**Automatic reminders implied by date storage** — rejected: Q33 is an independent decision.
**Unknown value defaults to zero** — rejected: misleading business/forecast interpretation.

## Research basis

Reuse [minimum CRM comparison](../phases/phase-4-r1-remaining-evidence.md#primary-source-comparison--bounded-additional-evidence)
and [original R1 evidence](../phases/phase-4-r1-leads-and-relationships.md#primary-source-comparison),
accessed 2026-10-08/09: Odoo activities/plans, ERPNext follow-up/loss reasons and Dynamics qualification.
Human follow-up and responsibility are useful without vendor automation. The owner selected the
bounded initial scope and deferrals; no product's activity engine or enterprise hierarchy is copied.

## Related

- [R1 closure](../phases/phase-4-r1-decision-matrix.md) · [Phase 4](../phases/phase-4-sales-quotations-and-handover.md)
- [Extensibility](../architecture/extensibility.md) · [Sales pipeline](../domain/sales-pipeline.md)
- [ADR 0098](0098-hybrid-sales-opportunity-requirement.md) · [ADR 0106](0106-separate-sales-ownership-and-access.md)
- [ADR 0107](0107-separate-opportunity-stages-and-outcomes.md) · [Open questions / Q33](../OPEN-QUESTIONS.md)
