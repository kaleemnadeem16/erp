[← Phase 4 planning](phase-4-sales-quotations-and-handover.md)

# R1-B3 — Forecast basis with independent and competing offers

- **Status:** MAINTAINED/SELECTED_OFFERS owner-reviewed in proposed ADR 0101; trust/dated FX direction reviewed in proposed ADR 0102; bounded scope ready for acceptance review, not accepted.
- **Access date:** 2026-10-08.
- **Question:** what should initially forecast a deal when several scopes can be bought but
  alternatives and revisions cannot be counted as additional business?
- **Owner-reviewed scope direction:** [proposed ADR 0100](../decisions/0100-independent-quotation-scopes-and-alternative-groups.md).
- **Existing basis reused:** [R1-B3 comparison](phase-4-r1-b3-offers-and-forecast.md), especially
  Dynamics manual/calculated revenue and duplicate quote-join warning; ERPNext ordered scope.
- **Runtime:** currency/FX, company audit and immutable evidence foundations exist. No Sales
  forecast, acceptance ledger, quote selection or historical forecast calculation is implemented.

## Owner review update — 2026-10-08

[Proposed ADR 0101](../decisions/0101-separate-accepted-business-and-remaining-forecast.md) records
the owner's MAINTAINED default and SELECTED_OFFERS direction, accepted-business separation,
acceptance safeguards, selected-reference history and separate forecast FX evidence. Earlier
recommendations below are retained as research history; the method choice is owner-reviewed but
not finalised. Partial acceptance/overlap and the remaining lifecycle dependencies stay open.

Next bounded topics: [staleness and review](phase-4-r1-b3-forecast-staleness.md), then
[FX valuation](phase-4-r1-b3-forecast-fx.md). Persistent provisional review and explicit SPOT/date publication now have owner-reviewed direction
in [proposed ADR 0102](../decisions/0102-govern-forecast-trust-and-explicit-valuation.md).
Remaining triggers, fallback/age and accepted management valuation are open.

## Fresh primary evidence and comparison

| Product | Evidence and relevant behaviour | Translation to this ERP |
|---|---|---|
| **Dynamics 365 Sales** | [Opportunity FAQ](https://learn.microsoft.com/en-us/dynamics365/sales/faq-opportunity) distinguishes user-provided/system-calculated revenue and warns about quote joins duplicating aggregates. [Forecast management](https://learn.microsoft.com/en-us/dynamics365/sales/view-forecasts) rolls up opportunities by default; configured forecasts refresh separately. Display currency conversion uses latest rates. [Deprecations](https://learn.microsoft.com/en-us/dynamics365/sales/deprecations-sales#snapshots-in-sales-forecasting) says native snapshots/history charts were removed/deprecated from June 2024 and suggests Forecast API sync. | Deal rollup and explicit refresh are credible patterns. Live display FX must not be confused with historical quotation or forecast evidence. Do not repeat old claims that current native Dynamics snapshots provide the required historical evidence. |
| **Odoo 19** | The [official forecast documentation source](https://raw.githubusercontent.com/odoo/documentation/19.0/content/applications/sales/crm/performance/forecast_report.rst) groups opportunities by expected close month and describes probability-prorated value as expected revenue × probability. Reuse the earlier expected-revenue comparison; English rendered page timed out, so the official source was read. | Amount, expected date and probability are separate inputs. Weighted view is useful but should not replace gross potential or accepted commitment. Automated scoring is not necessary for arithmetic weighting. |
| **ERPNext** | Reuse [opportunities](https://docs.frappe.io/erpnext/opportunity), [quotations](https://docs.frappe.io/erpnext/quotation) and the prior official-source review: opportunity amount/probability, revision/item-alternative and ordered-item provenance. | Supports maintaining deal assumptions separately from actual order scope. It does not establish our exact selected-combination forecast, accepted-plus-pending rule or historical FX contract. |

Supporting fourth-product check: [SAP Sales Cloud primary-quote guidance](https://help.sap.com/docs/help/23ec04ee830846958605622f27ff5c3d/a66e8ce049604268a81bc817d0d9638d.html)
and [create quotes](https://help.sap.com/docs/PRODUCT_ID/ea5ff8b9460a43cb8765a3c07d3421fe/6af3061140894ba2911defe5e98861f6.html)
indexed official summaries describe explicit primary designation and inheritance by a version.
The Help Portal returned no readable article body, so no stronger forecast calculation claim is
based on it. This is supporting evidence for a selected offer, not proof of combined-scope support.

**Synthesis:** vendors distinguish deal value, quotation lifecycle and forecast presentation;
there is no evidenced universal rule to sum related offers. Combining selected non-competing
scopes is our recommendation derived from this project's reviewed multiple-acceptance requirement.
Historical forecast guarantees and exact eligibility remain project decisions, not vendor defaults.

## Alternatives

| Basis | Benefit | Limitation |
|---|---|---|
| Maintained opportunity estimate | Lowest friction; works before offers; can cover a whole deal | Needs reviewed meaning after some scope is accepted; can be stale |
| One explicitly selected issued revision | Traceable, simple for one scope | Cannot represent furniture plus independent installation without an artificial combined quote |
| Explicit set of eligible issued revisions | Covers several independent scopes; one member per exclusive group; single-offer is a set of one | Needs validation, overlap awareness, invalid-basis handling and accepted-scope coordination |
| Automatically sum linked/current quotes | Low user effort | Counts alternatives, overlapping scope or copies; family deduplication alone is insufficient |
| Probability-weighted view | Distinguishes gross potential from risk-adjusted pipeline | False precision if input probability is poor; must not weight accepted commitments |

## Initial recommendation — historical research before the owner refinement

Support **two basis modes** through one forecast service:

1. **Maintained estimate**, default: explicitly entered remaining uncommitted business value,
   currency and expected close date. Early in the deal, everything is uncommitted. Record when
   the estimate was reviewed; unknown amount is unknown, never zero. The remaining-value meaning
   is a proposed clarification, not something the owner has already approved.
2. **Selected issued offers**: an explicitly chosen set of eligible revision identities. One offer
   is the simple case; several independent offers can be combined. Never auto-select on linking,
   new revision, issue or acceptance. A basis change is its own authorised/audited operation.

Keep accepted commitment a separate amount/evidence stream. Show **accepted business**, **remaining
forecast**, and optionally their combined **total expected deal value** with an explicit label.
A manually entered remainder must not be mixed with a second implicit "whole deal" estimate.
Acceptance should invalidate or require review of affected pending selections/remainder rather
than silently subtracting an entire quote or assigning probability 100% to the whole deal. Final
update rules await R6/R9; no recalculation algorithm is accepted here.

Recommend a validated selection set in initial delivery because multiple independent offers
are already required. This is broader than the previous single-offer suggestion, not a reason
to construct a new quotation containing every scope. Simple users see one selector and may add
another scope; advanced grouping appears only when needed.

## Selection invariants to review

- Same company and consistent deal/Party identity; eligible current CRM association, independent
  of immutable issue association. Direct-quote linking does not add it to a basis automatically.
- At most one selected revision per family and one selected family per exclusive group. Membership
  must be checked against the effective group evidence; later relinking/regrouping cannot evade it.
- Selected pending scope cannot compete with already accepted scope in that group. Accepted
  quantity/value cannot also appear in pending forecast. Partial acceptance needs R6 provenance;
  until that rule exists, do not pretend whole-revision selection handles partially accepted lines.
- Independent-by-default is permission to accept separate scopes, not automatic proof they are
  additive. Deliberately copied or overlapping offers require confirmation/warnings and possibly
  scope identity; R2/R6 decide how to prove this without a premature general dependency engine.
- Selection identifies an eligible **issued revision**, not a moving "latest" pointer. A new
  draft does not replace it. Expiry/withdrawal/supersession/rejection or identity changes make the
  selection invalid/stale and visible; no automatic substitute or hidden zero.
- Before publishing refreshed basis evidence, recheck permissions, association, group and revision
  versions atomically. Race with issue, acceptance, group edit, cancellation or relinking is refused
  or serialized. Current projection and history update together; retries do not duplicate evidence.

A two-member invalid combination cannot be "justified" by a forecast reason that bypasses an
exclusive group. If a customer really wants both furniture solutions, the reviewed commercial
scope/group must accurately represent that new buying event; the forecast cannot redefine it.

## Accepted business plus pending offers — examples

All values below are net PKR, excluding tax, at a common valuation date:

| Situation | Accepted | Remaining selected potential | Total expected deal value |
|---|---|---|---|
| Standard furniture + installation selected, nothing accepted | `0.0000` | `800000.0000` | `800000.0000` |
| Premium furniture + installation selected instead | `0.0000` | `1050000.0000` | `1050000.0000` |
| Standard furniture accepted; installation still pending | `500000.0000` | `300000.0000` | `800000.0000` |
| Standard and installation accepted, premium unselected | `800000.0000` | `0.0000` for those scopes | `800000.0000` for those scopes |

These examples illustrate the intended reviewed scope model plus proposed forecast semantics;
other unquoted future scope can still be unknown. Empty known remainder and unknown remainder are
different. Acceptance is not an opportunity closure or job-count decision.

At 50% probability for pending installation, a separate weighted view gives `150000.0000`
pending plus `500000.0000` accepted = `650000.0000` accepted-plus-weighted outlook. Accepted
business is not probability-weighted and is not accounting revenue.

## Eligibility/recalculation — initial recommendation, refined by proposed ADR 0102

Recommend recording explicit basis versions and frozen valuation results. Trigger a visible
review-needed state when selected evidence becomes ineligible. Display the last recorded forecast
with its as-of time and stale warning, but **do not silently include it in a trusted active total**:
report valid total and excluded/review-needed opportunity count/value separately. Exclusion must
not imply the deal's value is zero. Retain the last value for audit and require explicit review to
publish a new valid basis. The owner now reviewed provisional exclusion until explicit review/republication in ADR 0102;
partial-valid component calculation remains unresolved.

Manual estimates also require review when acceptance materially changes remaining scope; a
review-needed flag is not permission to change the amount. Refresh calculation, selection changes
and report viewing are distinct operations. Nothing schedules recalculation or snapshots now.

## Currency, historical forecasts and scalability

Quotation price/issue FX is immutable commercial evidence. Forecast FX is separately identified
valuation evidence: source amounts/currencies, destination reporting currency, chosen valuation
date, rate identity/value, conversion/rounding rule and calculated base values. Do not add foreign
currency numbers or reuse issue FX without declaring that choice. Missing rate means an incomplete
forecast, not zero. Recommend company base currency for initial consolidation while retaining all
source currencies. R4/R9 decide whether new forecast versions use issue rates or a forecast-date
rate; recommendation is explicit forecast-date valuation for pending scope, never changing quote
history. Accepted amounts retain acceptance/issue basis; show any separately revalued management
view as such, not overwrite commitment evidence.

Preserve actor, basis kind, selected revision/group/association evidence, source amounts,
accepted/pending scope interpretation, date/probability inputs and FX/calculation version with
each published forecast. Historical reports use recorded versions/as-of membership, not today's
links or FX. Backdated corrections need both recorded-at and effective-at treatment (R9).
A current view can flag new eligibility issues while the historical version stays immutable.
Periodic snapshots, trend dashboards and quota/team rollups may be deferred; the source evidence
needed to reconstruct published forecasts cannot. Forecast rules use decimal quantities/money,
explicit rounding and tenant-scoped identities, with no schema prescribed here.

## Initial versus safely deferred

| Initial recommendation | Safe deferral if evidence contracts exist now |
|---|---|
| Manual remaining estimate + explicit selection set; accepted/pending separation | Predictive scoring, quotas, territories, manager adjustment workflows |
| Non-competing family/group validation; no automatic all-quote sum | General dependency engine; advanced overlapping/conditional scope modelling |
| Explicit refresh/stale state, governed history and source/FX evidence | Scheduled snapshots/recalculation and trend dashboards |
| Currency/date and preserved as-of valuation | Multiple interactive reporting currencies and scenario comparisons |

Basic probability-weighted reporting can be deferred without losing gross potential or accepted
business. If deferred, preserve the capacity to record probability/date with future forecast
versions; do not fabricate historical probabilities later. If included, use an optional labelled
measure with explicit opportunity-level probability initially; per-scope probabilities and
predictive assignment need separate design. No weighting choice is accepted in this document.

## Dependencies and next decision

R2: family/group/scope shape and dependency scenarios; R4: FX/net/tax/rounding; R5: eligible issued
states, supersession and refresh; R6: acceptance/partial acceptance/corrections; R7: jobs and
handover; R1-F: partial win/closure; R9: duties, visibility, reporting dates/history.
Keep all unresolved: post-issue regrouping, cancelling/changing accepted scope, dependencies,
partially won outcomes, one/multiple jobs, closure interactions and forecast eligibility/recalculation.

**Next discussion:** R1-C customer/prospect onboarding. Methods, persistent provisional trust and
ordinary later-date FX movement now have sufficient owner-reviewed direction in proposed ADRs
0101/0102. The [scope reconciliation](phase-4-r1-b3-reconciliation.md) assigns remaining delivery
questions to R2/R4/R5/R6/R7/R9. Formal acceptance is still required; whole R1/Q39 remain open.

## Related

- [R1-B3 original research](phase-4-r1-b3-offers-and-forecast.md) · [R1](phase-4-r1-leads-and-relationships.md)
- [Proposed ADR 0100](../decisions/0100-independent-quotation-scopes-and-alternative-groups.md) · [Q39](../OPEN-QUESTIONS.md#q39--phase-4-r1-enquiry-deal-and-customer-onboarding-boundaries)
- [Phase 4 plan](phase-4-sales-quotations-and-handover.md) · [Sales pipeline](../domain/sales-pipeline.md)
- [Invariants](../architecture/invariants.md) · [Domain integrity](../architecture/domain-integrity.md)
