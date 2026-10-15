[← Domain index](README.md)

# Sales reporting, valuation and persistent forecast trust

**2026-10-15 · ACCEPTED0140 plus0101/0102/0107; SPECIFIED, not IMPLEMENTED/VERIFIED.**
Reports measure authoritative commercial facts once. Forecast is remaining potential with explicit
assumptions/selected sources, not revenue, delivery or another accepted commitment.

## Measures and source adapters

| Measure | Authoritative source | Exclusion/boundary |
|---|---|---|
| Original accepted business | CA immutable agreed components/quantities/amounts | No sum of quotation versions/Order/Job/delivery |
| Current effective accepted obligation | Original plus actually applied authorised HA6 correction | Pending observation/reduction request changes no accepted amount; original benefit consumption remains separate |
| Available offered remainder | Current actionable QL scope less actual CA claims/caps under supported adapter | Exclude accepted, expired/withdrawn/replaced/claim-blocked; no unsupported partial Assembly/fixed-tax scaling |
| Claim-blocked/withdrawn/rejected dispositions | Exact QL/CA claims/disposition evidence | Blocked is not rejected; reopening pursuit does not reopen offer |
| Unoffered potential | Explicit maintained assumption or recorded remaining intent | Not fictional selectable quote lines; not automatically priced leftover |
| Assigned/scheduled/fulfilled | Owning HA/Inventory/Phase5 actual adapters, distinct completion basis | Shared root counted once across stages; actual quantity is not new accepted sales/value |
| Partial-win/outcome |0107 accepted scope + pursuit disposition/history | Derived indicator, not new terminal status; accepted part prevents wholly LOST/ABANDONED classification |

Accepted40 of100 can leave pursued60 OPEN/partial-win. If60 unsuccessful/discontinued and no further
pursuit remains, explicit authorised closure can be WON for accepted40 with remaining disposition.
No invented accepted100, automatic offer withdrawal, claim release, Job cancel or forecast trust reset.
Closed pursuit has no active remaining contribution but original maintained estimate/publication remains.
Ordinary WON implementation waits actual authoritative CA integration; current CRM close Action does
not become authorised by a planning report. Unknown expected amounts stay unknown.

## Typed evidence shape — pseudo-DDL

```
sales_forecast_bases / versions
  company_id, id, opportunity_id, method MAINTAINED|SELECTED_OFFERS, generation
  assumptions/currency/maintained_amount nullable, selected_revision/scope references
sales_forecast_valuations
  company_id, id, basis_version, reporting_currency, explicit_date, policy_version
  component_source_currencies/amounts, bridge_legs/value/date/source/age/route, base_amounts
  settled_components/residual_policy, completeness, recorded_at
sales_forecast_publications
  company_id, id, basis_version, valuation_id, source_generation_set, review_evidence
  author/published_at, exact_receipt/business_identity; immutable
sales_forecast_trust_events
  company_id, publication_id, sequence, event_kind, coexisting_diagnostics
  affected_source/generation, occurred_at/recorded_at, actor/service/review_reference
sales_report_versions / receipts
  company_id, permitted_scope/profile, measure/basis/as_of/valuation/version references
```

Composite tenant-safe source keys, decimal(19,4)/currency/pinned base amounts/FinancialActivity,
immutable publication/valuation/trust history. Current trust head/generation may be mutable derived
projection, never overwrite original publication/evidence. Do not create unrecorded historical facts.

## Remaining basis, FX and combined management view

MAINTAINED default is explicit expected remaining business. Acceptance flags potentially stale
assumptions, never subtracts silently. SELECTED_OFFERS uses explicitly selected eligible disjoint
noncompeting issued scope and supported remaining-value adapter; preserve source revision references,
never auto-swap to successor. Cannot add maintained600.0000 plus selected400.0000 for same intent.
Selection must obey choice/exclusive/claim/entitlement/technical/quantity rules, not generic proration.

ValueForecast takes explicit reporting date/currency and company-base bridge: source→base and
reporting→base manual SPOT legs at same requested date, ratio for conversion, exact identity legs.
Default exact date (age0); explicit governed policy may use bounded latest-on-or-before per leg.
No future/arbitrary inverse/provider/cheapest route or missing→one/zero. Pin requested+actual dates,
rates/source/route/policy/age/amounts. Missing/unsupported evidence makes incomplete valuation; show
permitted known currency contributions, never subtotal as complete. Use delivered FR decimal/rounding
primitives and conserve settled components; no frontend recomputation.

Original accepted commercial dual amounts remain their own immutable facts. Combined accepted+
remaining management view uses one disclosed comparable date/currency/policy, including effective
accepted components once and compatible remaining basis. Preserve separate management valuation
version rather than rewriting quotation/acceptance FX. No implicit sum of historical accepted-base
amount plus today's remaining valuation. No probability weighting, GL/revenue/realised-FX implication.

## Trust and event integration

| Event | Current trusted view / history consequence |
|---|---|
| Acceptance/authorised effective reduction or scope/choice/exclusive change affects basis | Invalidate affected publication/current contribution, append review-needed diagnostics; maintained amount unchanged |
| Selected expiry/withdrawal/replacement/dependency ineligible | Exclude affected contribution from trusted totals, keep selected original references; whole basis incomplete/provisional as applicable |
| Missing required rate/control/remaining allocation | Incomplete/invalid, never zero; show only permitted known components explicitly |
| Technical recovery | Validation may recover; persistent review-needed remains until successful explicit eligible review/publication |
| Ordinary later-date FX movement | No invalidation of earlier dated valuation; optional indicative value separately labelled |
| Relied-on rate value/date/source-validity correction | Review required regardless of materiality initially, preserve used original values/provenance even if source later restores |
| Metadata-only change with no relied evidence change | Warning only; cannot conceal changed source validity as metadata |
| New draft/revision not issued, download/signature/Order/Job/invoice | No automatic supersession/acceptance/win/value/trust transition |
| Closure/reassociation/owner change | Preserve history; remove closed pursuit's active remaining contribution, re-evaluate genuinely affected scope/basis, never rewrite commercial evidence |

ReviewForecast and PublishForecast are distinct eligible operations (same authorised person may hold
both where policy permits). Technical FX success cannot clear commercial review. Configured approval
uses0070/0085, never universal manager sign-off. Atomic publish freezes basis/valuation/review/source
generations plus receipt, appends trust fact; changed versions refuse instead of obsolete TRUSTED.

All authoritative writers join source-generation/invalidation protocol: CA acceptance/HA correction,
QL expiry/withdrawal/replace (including company-local boundary time), choices/CRM association/outcome,
relied manual-rate correction/delete and policy changes. Lock Sales coordination/source roots before
publication heads; actual FX writer locking/order reconciled before integration. Trusted response
checks current source generations/eligibility, so clock expiry/rate correction cannot wait for cron.
Durable invalidation events/heads plus read revalidation are authoritative; notification/polling only
accelerates refresh. Missing writer/adapter invalidation means forecast feature stays unexposed.

## History, scope, API/UI and verification

Default historical question is known-as-of server recording time; effective business time is separate.
A PO occurring Monday but recorded Wednesday was not known Monday. Supported restatement explicitly
labels new knowledge/version and preserves original publication. Don't reconstruct an unrecorded past
forecast from current quote/FX masters. Show historical dated publication, current trusted, provisional/
incomplete and optional indicative values separately, with coexisting diagnostics and permitted sources.

Report read/export duty plus current effective source/profile scope applies before aggregate, count,
filter/sort and download. Visible-scope totals labelled as such; no hidden total subtraction, confidential
competitor existence, redacted zeros or privileged cached export replay. Unknown is explicit; do not
manufacture zero exposures from absent Finance/Phase5. Cached report pins scope/profile/generations
but every response checks current eligibility. No global “report viewer” bypass of source restrictions.

Specified Actions: MaintainForecastBasis, SelectForecastOffers, ValueForecast, ReviewForecast,
PublishForecast, InvalidateForecastBasis, ReadSalesMeasures/Report, ExportSalesReport, RecordPursuitOutcome
through owning CRM Action. Requests bind exact basis/source/valuation/review generations and key.
Resources omit protected evidence; UI explains partial/remainder/trust/FX date/incomplete/history, no
silent source substitution or live revaluation. Schema/client/demo delivery accompanies actual API.

Future verification: accepted-vs-revisions/stages conservation, mixed currency bridge and missing rate,
fresh-key publication receipt, review versus source/rate/clock-expiry races, persistent invalidation
after recovery, known/effective times, scope-before-total/export/cache, direct Action denials and no
automatic WON. Implementation owner supplies actual results; no tests were run by this specification.

## Related

- [0140](../decisions/0140-publish-sales-measures-and-dated-forecasts-with-persistent-trust.md)
- [Duty matrix](sales-duty-matrix.md) · [Authority](sales-authority.md)
- [R9 slices](../phases/phase-4-r9-implementation-slices.md) · [Handoff](../phases/phase-4-implementation-handoff.md)
