[← Decisions](README.md)

# 0101 — Separate accepted business from evidence-based remaining forecasts

- **Status:** accepted — bounded business direction; owner-authorised final review passed 2026-10-09.
- **Date:** 2026-10-08
- **Affects:** Phase 4 R1-B3, forecasting, acceptance evidence, reporting and currency valuation.
- **Gate:** bounded B3 business research closed; whole R1/Q39 and deferred delivery gates remain open; no implementation authorised.

## Context

ADR 0100 permits independently acceptable scopes and exclusive alternative families.
One selected quotation cannot represent every multi-scope deal, while summing every associated
quotation double-counts alternatives, revisions and potentially overlapping work. The owner
reviewed [forecast-basis research](../phases/phase-4-r1-b3-forecast-basis.md) with the refinements below.

## Decision — accepted bounded business direction

Distinguish three business facts:

1. **Accepted business:** confirmed commercial commitments, derived from authoritative
   acceptance evidence, not opportunity status, forecast inputs or invoice revenue.
2. **Remaining potential:** additional uncommitted business that may still be won.
3. **Forecast basis:** the evidence or assumptions supporting that remaining potential.

Support two initial forecast methods through one shared domain contract:

| Method | Business meaning |
|---|---|
| MAINTAINED (default) | An authorised salesperson maintains the expected remaining-business estimate |
| SELECTED_OFFERS | Explicit selection of one or more eligible, non-competing issued quotation revisions establishes remaining potential |

A single selected offer is a selection set of one. Linking quotations does not automatically
select them. Revisions, competing alternatives and overlapping scopes must not be counted twice.
Do not create synthetic opportunities or combined quotes to work around forecast limitations.

### Acceptance and eligibility safeguards

Acceptance updates the accepted-business measure from its authoritative evidence. It never
silently changes a MAINTAINED value: flag potentially stale assumptions for review.
SELECTED_OFFERS calculations exclude accepted work under defined rules, retain selected revision
references and flag ineligible selections rather than silently replacing them. Whole-acceptance
handling, partial acceptance and detailed overlap rules are not finalised here: R2/R6 must define
the commitment/scope identity and reconciliation before the calculations can be implemented.

A new revision does not automatically become the selected revision. A CRM link, group change or
acceptance does not automatically decide an opportunity outcome, job count or forecast refresh.
Post-issue grouping, accepted-scope corrections, dependencies and partially won deals remain open.

### Evidence, currency and authority

Preserve forecast history and selected source revisions. Updates do not change issued quotation,
issue linkage, approval, price, tax, FX or estimating evidence. Forecast currency/FX valuation is
separate evidence, preserving source currencies, the applied rate/value/date and base amounts.
Reuse ADR 0012's historical dual-amount invariant and ADR 0031's manual-rate foundation; forecast
SPOT/explicit valuation date and persistent provisional review now have owner-reviewed direction
in [ADR 0102](0102-govern-forecast-trust-and-explicit-valuation.md); exact fallback-age,
accepted management conversion and used-rate correction assessment remain R4/R9 questions.
Ordinary later-date FX movement is non-invalidating under ADR 0102.

Guard forecast operations at request and Action boundaries, with current actor authority,
company isolation and appropriate record/field scope. Forecast maintenance is not permission to
change quotes, accept work, manage exchange rates or waive opportunity policy. Exact capabilities,
rollout, selection scope and historical reporting access are R9 design work. Preserve auditable
actor/time/before-after/basis evidence and apply atomic retry/stale/concurrency conventions.

Probability-weighted forecasting can be deferred. The contract leaves probability as a distinct
future input/measure, never reconstructing an unrecorded historical probability or weighting
accepted commitment. No probability defaults or scoring engine are decided here.

## Reconciliation

- Proposed ADRs 0098/0099 remain in force as design proposals: effective opportunity policy and
  immutable issue vs current CRM association are independent from forecast selection.
- ADR 0100 supplies independent scopes and exclusive groups; selection may not bypass
  group exclusivity or count acceptance twice. Partial acceptance remains R6.
- ADRs 0012/0031 and invariants require pinned values/manual FX; live-rate rendering that changes
  stored historical forecast amounts would conflict. No automated FX fetching is proposed.
- ADRs 0070/0085 preserve original issue approval evidence; forecast updates are separate facts,
  not commercial repricing or approval changes.
- No new table/schema is chosen. The shared Party model and capability/record-scope distinctions
  are retained; team ownership/visibility is not assumed implemented.

## Consequences

- **Positive:** multi-scope opportunities have explainable remaining forecasts without inflating
  commitments; simple companies retain a maintained estimate.
- **Negative:** acceptance can make assumptions stale; selected sets need eligibility/overlap
  checks and published valuation history, not just a total field.
- **Requires:** authoritative acceptance reconciliation; snapshot/tenant/authority/race/retry
  safeguards and explicit calculation, stale-selection and FX rules before implementation.

## Alternatives considered

**Always sum related offers** — rejected: alternatives/revisions/overlap inflate business.
**Single selected quote only** — rejected as the sole offer basis: misses independently won scopes.
**Automatically subtract acceptances from manual estimates** — rejected by owner: silently
rewrites salesperson assumptions and mishandles overlap/corrections.
**Automatically pick a newer eligible offer** — rejected: replaces business intent without review.
**Treat accepted amount as remaining forecast or accounting revenue** — rejected: distinct facts.
**Require weighting now** — deferred: adds probability semantics without being necessary to
record gross remaining potential; the evidence contract must allow later extension.

## Research basis

Reuse [forecast comparison](../phases/phase-4-r1-b3-forecast-basis.md), accessed 2026-10-08:
Dynamics deal value and refreshed rollups, Odoo probability weighting, ERPNext ordered provenance.
The two methods and acceptance safeguards are owner-reviewed project design, not a vendor
standard. Further [staleness](../phases/phase-4-r1-b3-forecast-staleness.md) and
[FX valuation](../phases/phase-4-r1-b3-forecast-fx.md) research preserves alternatives and the bounded accepted direction in ADR 0102. Existing currency/audit decisions are reused rather than reopened.

## Still open

Persistent review/exclusion and explicit SPOT/date publication are accepted in
ADR 0102, including non-invalidating ordinary later-date FX movement and coexisting diagnostics.
Exact triggers/expiry, physical trust representation and review duties; fallback/age, historical rate
correction assessment and accepted conversion; partial/overlap/corrections, regrouping/dependencies,
closure/jobs and as-of reporting remain delegated delivery gates. See the
[bounded acceptance assessment](../phases/phase-4-r1-b3-reconciliation.md); bounded business acceptance only, not whole R1 completion or delivery approval.

## Related

- [Forecast basis](../phases/phase-4-r1-b3-forecast-basis.md) · [Staleness](../phases/phase-4-r1-b3-forecast-staleness.md) · [Forecast FX](../phases/phase-4-r1-b3-forecast-fx.md)
- [ADR 0100](0100-independent-quotation-scopes-and-alternative-groups.md) · [ADR 0098](0098-hybrid-sales-opportunity-requirement.md) · [ADR 0099](0099-separate-quotation-issue-and-crm-association.md)
- [ADR 0012](0012-multi-currency-dual-amounts.md) · [ADR 0031](0031-manual-exchange-rate-entry.md)
- [Q39](../OPEN-QUESTIONS.md#q39--phase-4-r1-enquiry-deal-and-customer-onboarding-boundaries) · [Domain integrity](../architecture/domain-integrity.md)
