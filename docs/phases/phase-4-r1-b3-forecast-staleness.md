[← Phase 4 planning](phase-4-sales-quotations-and-handover.md)

# R1-B3-S — Forecast staleness and review

- **Status:** bounded B3 business research closed 2026-10-09; ADRs 0100–0102 accepted; detailed delivery gates remain open.
- **Access date:** 2026-10-08.
- **Question:** how should current forecasts respond when their selected evidence or maintained
  assumptions change without rewriting published history or silently changing sales intent?
- **Reviewed foundation:** [accepted ADR 0101](../decisions/0101-separate-accepted-business-and-remaining-forecast.md).


**Reading this record:** earlier alternatives/recommendations are retained research history.
Accepted ADRs 0100–0102 govern only their bounded contracts; detailed package recommendations
are not accepted by this closure. The [final review](phase-4-r1-b3-reconciliation.md) records the boundary.

## Owner review — 2026-10-08

[accepted ADR 0102](../decisions/0102-govern-forecast-trust-and-explicit-valuation.md) records the
reviewed rule: NEEDS_REVIEW stays provisional and excluded from trusted totals until authorised
review and a new publication, even if an expired offer is renewed. Preserve old values/evidence;
show eligible components informationally, without adding them to the provisional whole twice.
MAINTAINED stays unchanged; confirming the same amount still creates a new publication version.
No universal managerial approval is required; future configured approval uses the shared engine.

The owner-reviewed business meaning allows coexisting diagnostic reasons alongside persistent
review obligation. Physical representation remains undecided; no enums/tables are selected. Earlier comparison
and reporting options remain research history; reviewed direction takes precedence.

## Existing behaviour and evidence reused

No Sales forecast or acceptance model exists. The shared audit/immutable snapshot conventions,
explicit Actions and stale-evidence/idempotency patterns exist. Material Preview has freshness
inspection; it is not a persisted forecast. Proposed ADRs 0098–0100 preserve issue policy,
relationship/group history and distinct acceptance; they do not define expiry or partial acceptance.

## Primary-source comparison

- [Dynamics recalculation](https://learn.microsoft.com/en-us/dynamics365/sales/forecast-recalculation-methods)
  describes forecast refresh after record/adjustment/hierarchy changes; [manual refresh](https://learn.microsoft.com/en-us/dynamics365/sales/keep-forecast-data-up-to-date)
  supports requesting immediate recalculation. Reuse prior quote lifecycle evidence: a revision
  creates a new draft; published old/new offers are separate. This distinguishes live source
  change from refreshed aggregate, but does not specify our invalid-selection rules.
- [Odoo quotation validity](https://www.odoo.com/documentation/19.0/applications/sales/crm/acquire_leads/send_quotes.html)
  has explicit expiry/default validity configuration. Reuse official Odoo forecast source: deals
  have their own expected dates/probability. Offer expiry is not proof that the whole deal is lost.
- Reuse [ERPNext quotation lifecycle](https://docs.frappe.io/erpnext/quotation) and the previous
  official-source review of revision/ordered-item provenance. Ordered scope and offer eligibility
  need tracking beyond document totals; exact partial-acceptance rules differ from our undecided R6.

Convergence: lifecycle/current assumptions and refreshed reporting are distinct concerns.
No source dictates whether stale remaining forecasts must be excluded, retained or partly counted.
The options and triggers below are project-specific recommendations, not vendor consensus.

## Candidate triggers — recommendations only

| Event | SELECTED_OFFERS | MAINTAINED |
|---|---|---|
| Selected quote expires, is withdrawn/rejected or otherwise ineligible | Flag affected selection ineligible; preserve its reference/value in history | Flag only if it was recorded supporting evidence or materially affects assumptions; unrelated quote events need not invalidate the estimate |
| New revision drafted | Notify/indicate newer draft; do not select it or assume old issued quote superseded | Optional review indication if relevant; no numeric change |
| Selected revision actually superseded | Ineligible/stale under R5's eventual supersession rule | Review if underpinning assumptions changed |
| Whole selected independent scope accepted | Acceptance evidence updates accepted measure; exclude accepted work from live pending projection, mark basis for reconciliation | Keep entered amount unchanged; review required if potentially overlapping with accepted scope |
| Partial acceptance or scope correction/cancellation | Mark unresolved affected contribution for review until R2/R6 identity rules can determine the remainder | Keep amount; review required for potentially affected assumptions |
| Group, CRM association, recipient identity or visibility changes | Revalidate eligibility/non-competition/scope; no automatic substitute | Review material assumption changes; no automatic re-assignment |
| Time since last review | Show reviewed-at age; configurable reminder threshold possible | Show reviewed-at age; suggest review, not automatic numeric reduction |
| Ordinary later-date FX movement | Remains trusted at its published date if otherwise valid; no automatic review or revaluation | Same; source-currency estimate unchanged |
| Correction/invalidation of a used rate | Separate R4/R9 impact assessment; preserve original evidence | Same; no universal consequence assumed |

Expiry is a date-based fact even if no background task has changed a status. R5 decides company
business-date/time-zone boundary (end of valid-until day is a candidate); current reads must check
that rule, not depend on scheduled mutation. No scheduled job/reminder is authorised here.

## Reporting alternatives — historical comparison before owner review

| Option | Advantage | Cost |
|---|---|---|
| Keep last total in active forecast with warning | Continuity | Readers/exporters may treat invalid scope as current potential |
| Exclude the entire invalid selection set from trusted active totals | Honest completeness boundary; preserves intended combination | One expired member can withhold otherwise valid independent value |
| Show valid contributions plus withheld/unknown remainder | Useful granular view | Can misrepresent a chosen package if the scopes depend on each other |
| Automatically reselect/latest/subtract | Low operator effort | Violates reviewed intent/history; manual subtraction explicitly rejected |

**Recommend for initial delivery:** preserve last published value/as-of basis for history, flag
invalid selections, and treat the affected published combination as review-needed/incomplete.
Show a separately labelled valid contribution breakdown if determinable, but do not silently
publish that subtotal as a replacement whole-deal forecast. Trusted active aggregate shows valid
forecasts plus counts of excluded/unknown/review-needed deals; excluded is not zero potential.
A manual estimate remains the stored salesperson value but carries a review-needed flag after
material acceptance/assumption changes. The owner now requires separate provisional presentation outside trusted totals, with no
automatic recovery when a technical condition becomes valid again.

## Example and reconciliation

Furniture `500000.0000` PKR + installation `300000.0000` PKR selected = `800000.0000` remaining.
Furniture expires: retain the `800000.0000` historical forecast with its date, mark current basis
incomplete and show installation `300000.0000` as a valid component, not an automatically
confirmed replacement total. Selecting an eligible new furniture revision is an explicit action.

Furniture accepted instead: accepted becomes `500000.0000`; pending must not still count it.
The live pending contribution can show only the unaccepted installation `300000.0000`, with
basis review status until reconciled; the old published `800000.0000` is historical. That is
acceptance-aware projection, not rewriting stored forecast history or auto-selecting a new basis.
If the manual remainder was `800000.0000`, leave it unchanged and flag it: the salesperson must
review whether remaining business is `300000.0000` or includes other unquoted scope.

## Review operations and integrity

Recommend explicit **review maintained estimate** (confirm/update with evidence), **revise selected
basis** (choose eligible references) and **refresh valuation** (same intent, new valuation date).
Checking freshness is read-only; an audit event recording "reviewed unchanged" is not a fake
monetary change. Reason requirements and exact duties remain R9, not role names.

Fingerprint selection, acceptance/group/association and FX evidence. Refresh locks/re-reads
relevant facts and refuses concurrent changes; two reviewers cannot overwrite newer review
without a stale-version response. Publishing stores source versions/calculation timestamp,
company/actor and authoritative evidence atomically. Explicit confirmation of unchanged assumptions creates a new reviewed publication; ordinary
no-op saves, failed publication and repeated retries cannot create false/duplicate versions; immutable history remains unchanged. Read/export access and
aggregate visibility must be consistent, including hidden group conflicts.

## Remaining triggers and ownership

Reviewed trust/reporting direction is accepted ADR 0102, not a completed forecasting design.
Recommend mandatory review for basis eligibility loss or material relevant acceptance/identity/
group/CRM changes. A newer draft, unrelated quotation or age alone should initially be an
informational indication unless governance explicitly requires review. Exact materiality, expiry
boundary, hidden-record handling and partial acceptance need their owning topics; no threshold
or universal trigger list is finalised.

A durable invalidation must survive temporary recovery, including expiry/renewal between reads.
Current-state fingerprints alone can miss that history. R5/R9 must define event/version/time
checks without scheduling anything implicitly. Distinguish a viewer's limited visibility from
a company-wide invalid commercial assumption.

Ordinary later-date FX movement is settled as non-invalidating in accepted ADR 0102. No further
blocking R1-B3 business choice is identified; see the [reconciliation](phase-4-r1-b3-reconciliation.md). R4 owns
rate age/fallback; R5 owns lifecycle/expiry; R2/R6 owns overlap/acceptance; R9 owns trigger
classification, trust plus diagnostic representation, review/approval duties and as-of reports.
Also open: whether stale-basis FX refresh is candidate-only or blocked until combined review;
it cannot republish trusted evidence merely because calculation succeeds.

## Related

- [Phase 4](phase-4-sales-quotations-and-handover.md) · [Forecast basis](phase-4-r1-b3-forecast-basis.md)
- [Forecast FX](phase-4-r1-b3-forecast-fx.md) · [accepted ADR 0101](../decisions/0101-separate-accepted-business-and-remaining-forecast.md)
- [Q39](../OPEN-QUESTIONS.md#q39--phase-4-r1-enquiry-deal-and-customer-onboarding-boundaries)
- [Domain integrity](../architecture/domain-integrity.md) · [Sales pipeline](../domain/sales-pipeline.md)
