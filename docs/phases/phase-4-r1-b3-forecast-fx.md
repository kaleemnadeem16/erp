[← Phase 4 planning](phase-4-sales-quotations-and-handover.md)

# R1-B3-FX — Forecast conversion and historical valuation

- **Status:** initial evidence/alternatives for discussion; no new FX rule accepted.
- **Access date:** 2026-10-08.
- **Question:** how should foreign-currency remaining estimates/selected offers be valued without
  changing issued prices or historical forecast amounts when rates are corrected or updated?

## Reuse existing decisions and actual runtime

[ADR 0012](../decisions/0012-multi-currency-dual-amounts.md) and invariant 5 require source currency,
amount, pinned FX and base amount; display preference does not change stored evidence.
[ADR 0031](../decisions/0031-manual-exchange-rate-entry.md) establishes manual rates, not scheduled
provider fetching. [Multi-currency](../domain/multi-currency.md) documents SPOT/AVERAGE/CLOSING,
most-recent-prior fallback and recording the actual rate date. Forecast selection policy is not
implemented merely because those rules are documented.

Code: `backend/app/Models/ExchangeRate.php` stores a company-scoped directed pair, rate date/type,
source and decimal:9 rate. `backend/app/Actions/ManageExchangeRate.php` permits authorised create,
correction and deletion under currency.exchange-rate.manage, stamping manual source. A source-row
id alone cannot reproduce history after correction/deletion. Currency references/rate APIs exist;
there is no Sales forecast rate resolver or forecast history. Company base currency is governed
and locked by financial activity; future money-carrying Sales records must register its probe.

## Primary comparison

- [Dynamics forecast currency](https://learn.microsoft.com/en-us/dynamics365/sales/view-forecasts#select-a-currency)
  converts live forecast display at latest organisation rates. This is useful for current viewing,
  but copying it into stored historical base amounts would conflict with our accepted invariant.
- [ERPNext Currency Exchange](https://docs.frappe.io/erpnext/currency-exchange) distinguishes dated
  stored rates, optional stale-rate allowance and provider fallback. [Frozen rate guidance](https://docs.frappe.io/erpnext/exchange-rate-field-frozen)
  explains how state/permissions/stale policy affect editable rates. We reuse dated/manual rate
  semantics, not automatic fetching, and do not import its editable-document rules into issued
  quotations. Rate age is a policy question separate from prior-date fallback.

Agreement: source currencies/rates are explicit and date/state matter. Divergence: current display
conversion, provider sourcing and stale-rate policy. Neither product selects our forecast-date
or accepted-plus-pending accounting semantics; recommendations below are management valuation,
not legal/accounting advice or GL postings.

## Alternatives

| Option | Benefit | Limitation |
|---|---|---|
| Each quote's issue FX | Matches its frozen commercial baseline | Mixed dates; pending current exposure uses potentially old rates; manual estimates have no quote rate |
| SPOT at a declared forecast valuation date | Consistent date across selected/manual pending scope; repeatable published versions | Needs explicit refresh and missing/old-rate handling |
| CLOSING or AVERAGE for a reporting period | Fits specific comparative reporting purposes | Poor default for an operational forecast; period policy must be explicit |
| Latest rates on every read | Convenient live view | Changes figures without a published update; unsuitable as historical source of truth |
| Forecast/horizon/scenario rates | Supports enterprise budget scenarios | Extra governance and rate-purpose model; defer until justified |

**Recommend:** SPOT at an explicit forecast valuation business date for remaining potential,
consolidated initially into company base currency. Retain source amounts/currencies. Follow
most-recent-prior fallback for the same company/directed pair/type, recording requested and actual
rate dates. No future-dated rate, implicit inverse or triangulation, provider call or fallback to
quotation issue rate without its own agreed rule. Base-currency-to-itself uses exact one.
If no eligible prior rate exists, the forecast is incomplete; it must not become zero or use one.

This forecast valuation date is separate from expected sales close date. Recommend explicit
refresh advances valuation date; viewing/history does not. Decide allowed historical dates and
rate correction freshness in R4/R9. Rate age must be visible. A hard age limit or override needs
owner-approved settings/duties; do not invent a seven/thirty-day limit or silently change the
existing prior-date policy. Initial candidate: visible age and review warning, with missing-rate
block; an optional governed maximum age could follow a demonstrated requirement.

## Practical example

Pending USD offer `1000.0000` plus PKR offer `300000.0000`:

- Forecast date 8 October, manual USD→PKR SPOT `280.000000000`: published remaining base
  `580000.0000` PKR, preserving both source amounts and that rate/date.
- Later rate `285.000000000`: the recorded forecast stays `580000.0000`. Explicit valuation
  refresh publishes a new version of `585000.0000`, even though quoted source prices are unchanged.
- No usable USD→PKR rate: PKR contribution `300000.0000` can be shown, with USD `1000.0000`
  unconverted and the total clearly incomplete. Do not report the PKR subtotal as full potential.

Separate source-business change from FX-only movement in history so salespeople can explain the
`5000.0000` difference. These are examples of a proposed rule, not current functionality.

## Accepted commitments and display currency

Accepted business retains authoritative source amounts and pinned commercial/acceptance basis.
The exact acceptance-date vs inherited issue basis is an R4/R6 decision; acceptance must not
silently recompute issue evidence. Recommend keeping accepted baseline and pending forecast-date
valuation separately labelled. Combining them produces a mixed-basis management outlook, not a
new all-at-current-rate figure. A comparable-date revaluation of accepted amounts, if required,
would be an additional labelled management view preserving original evidence and without GL posting.

Initial display: source currencies per contribution and company-base consolidated result. Defer
interactive other reporting currencies if unnecessary. If later added, label display-only rate/date
and convert from the declared source/base view without persisting changes to issued/history amounts.
A reader switching display currency has no rate-management or forecast-refresh authority.

## Historical evidence and integrity

Each published forecast stores company/actor/recorded-at and valuation-date; method/selection
references; source amounts/currencies; target currency; requested/actual rate dates and type;
source identifier plus copied rate value; direction/calculation/rounding version and base result.
Retain source provenance even if a reference rate row is later corrected/deleted. Currency rates
remain decimal(19,9); money decimal(19,4), API strings. Exact rounding boundaries across converted
contributions and totals need R4 validation; do not repeatedly round intermediary calculations.

Historical reports use frozen valuation versions, never current rates. A later correction can
publish a new valuation/correction referring to the old one; it cannot mutate it. Backdated rate
corrections need recorded-at vs valuation-date evidence and a rate-freshness policy. Published
snapshots already remain reproducible even before those operational choices are settled.

Rate visibility is not rate-management authority. Refresh requires forecast authority, rechecks
scope/eligibility and reads rates consistently before atomic publication. Concurrent rate changes
or selection/acceptance changes need a deterministic snapshot/lock/version protocol; a rate id
alone is not a freshness token. Missing/old rates and withheld records appear consistently in
UI, totals and exports. No scheduler is authorised by this research.

## Small next decisions

1. Use SPOT at an explicit forecast date for pending scope, preserve source/actual-rate evidence,
   and publish a new version on explicit refresh (recommended)?
2. Start with prior-rate fallback plus visible age, or enforce an owner-defined maximum age?
3. Keep accepted baseline and pending forecast-date values separate, with a labelled mixed-basis
   total, or require a separately revalued accepted-business view immediately?

R4 owns rate/date/rounding and currency rules; R6 owns commitment basis; R9 owns report/as-of,
corrections and access. These remain proposals; no accepted ADR is altered.

## Related

- [Phase 4](phase-4-sales-quotations-and-handover.md) · [Forecast basis](phase-4-r1-b3-forecast-basis.md)
- [Staleness](phase-4-r1-b3-forecast-staleness.md) · [Proposed ADR 0101](../decisions/0101-separate-accepted-business-and-remaining-forecast.md)
- [Multi-currency](../domain/multi-currency.md) · [ADR 0012](../decisions/0012-multi-currency-dual-amounts.md)
- [ADR 0031](../decisions/0031-manual-exchange-rate-entry.md) · [Q39](../OPEN-QUESTIONS.md#q39--phase-4-r1-enquiry-deal-and-customer-onboarding-boundaries)
