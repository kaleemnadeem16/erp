[← Decisions](README.md)

# 0107 — Separate pipeline stages, accepted scope and governed opportunity outcomes

- **Status:** accepted — owner-approved U5; final consistency review passed.
- **Date:** 2026-10-09
- **Affects:** opportunity stages/outcomes, partial wins, closure/reopen and commercial boundaries.
- **Gate:** business meanings only; no state schema or detailed commercial corrections chosen.

## Context

A deal may win furniture while installation remains under negotiation. Closing the entire deal
on the first acceptance hides potential; leaving partially successful deals open forever distorts
pipeline. CRM closure must explain pursuit without changing customer commitments or jobs.

## Decision

Separate configurable OPEN stages, such as Qualification/Proposal/Negotiation, from stable
opportunity outcomes **OPEN, WON, LOST, ABANDONED**. These are business meanings, not prescribed
database enums. Previously used stages retain historical meaning when configuration changes;
authorised stage maintenance cannot erase/reinterpret recorded history.

Show partial win as a separate derived indication based on authoritative accepted business, not
as a terminal outcome blocking ongoing pursuit. Accepting one offer does not automatically mark
the entire opportunity WON while other independent scopes are still pursued.

Example: furniture `500000.0000` PKR accepted and installation `300000.0000` under negotiation
leaves the opportunity OPEN with partial win. If installation is rejected and no work remains
under pursuit, close WON with the accepted `500000.0000` and the unsuccessful remainder's
recorded disposition. A partial success need not remain OPEN indefinitely.

Authorised manual closure records reason, actor/time, outcome and disposition of remaining
potential. Initially WON requires authoritative recorded customer acceptance, not manually invented
commitment. With ADR 0100's initial quotation acceptance contract, free-entry off-system win amounts
cannot create accepted scope; any additional authoritative acceptance path needs R6 design.
LOST represents unsuccessful business; ABANDONED discontinued pursuit. Active accepted business
prevents classification of the entire opportunity as wholly LOST/ABANDONED; remaining-scope
loss/discontinuation is separate context, not erasure of the accepted part.

Authorised reopening of the same revived pursuit needs reason and preserved prior decisions,
closures and commitments. A genuinely new buying event normally creates a new opportunity.
Closure/reopen cannot silently cancel jobs, withdraw/revive quotes, change accepted scope or
groups, rewrite published forecast/valuation evidence or restore trust. Closed pursuit has no
active remaining-potential contribution; record that disposition separately rather than silently
zeroing a maintained estimate or changing a historical publication. R9 specifies report/review
history under ADRs 0101/0102. No requirement to close every quotation is implied.

## Reconciliation and consequences

ADRs 0100–0102 distinguish accepted scope, remaining potential and forecast trust; CRM outcome
is not acceptance or revenue. ADR 0104 prevents an outcome/acceptance from granting work/credit
or financial authority. ADR 0099 current association cannot change commitments/group/job ownership.
Tenant/record/field guards, shared audit, immutable evidence and atomic retries still apply.
Detailed partial acceptance, cancellation/correction and inactive commitments are R6; quotation
lifecycle interactions R5; job dispositions R7; exact reporting/approval/permission history R9.
No accepted contract is superseded and these gates do not block R1 research closure.

- **Positive:** honest partial-win visibility and explainable completion of pursuit.
- **Negative:** closure must explain remaining scope rather than relying on one stage toggle.
- **Requires:** authoritative acceptance references, reasoned closure/reopen history and independent
  commercial/forecast effects before delivery; used-stage history cannot be discarded.

## Alternatives considered

**First acceptance automatically means WON** — rejected: loses independent remaining pursuit.
**Terminal PARTIALLY_WON or always-open partial success** — rejected: separate indication and closure.
**Manual won money without acceptance evidence** — rejected: fabricates commitment.
**Outcome cascades to quotes/jobs/groups/trust** — rejected: independent authoritative facts.
**Reopen always creates a new deal or erases closure** — rejected: revived pursuit retains history.

## Research basis

Reuse [lifecycle comparison](../phases/phase-4-r1-remaining-evidence.md#primary-source-comparison--bounded-additional-evidence),
accessed 2026-10-09: Dynamics closure/reopen, Odoo lost reasons/restoration and ERPNext lost reason/
follow-up evidence. Explainable closure converges; vendor category cascades/all-quotes-closed
constraints do not fit our independent scopes. Partial-win/abandoned meanings and remaining-scope
disposition are owner-approved project choices, not universal ERP rules.

## Related

- [R1 closure](../phases/phase-4-r1-decision-matrix.md) · [Sales pipeline](../domain/sales-pipeline.md)
- [ADR 0100](0100-independent-quotation-scopes-and-alternative-groups.md) · [ADR 0101](0101-separate-accepted-business-and-remaining-forecast.md)
- [ADR 0102](0102-govern-forecast-trust-and-explicit-valuation.md) · [ADR 0104](0104-separate-customer-acceptance-and-readiness.md)
