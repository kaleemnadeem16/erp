[← Domain index](README.md)

# Unified Job costing

**2026-10-16 · ACCEPTED0150/Q55 resolved; bounded contract SPECIFIED, not implemented/verified.**
Physical schema, exact locking and projection implementation remain later design/verification.
0145–0149 and R7/R9 retain governing source/lifecycle responsibilities.

## Where it sits

Authoritative source evidence → recognised economic allocation/correction → rebuildable authorised
Job view. Source owner posts economic effects; reporting never posts or re-posts them.

## Initial measures and exact labels

Every amount carries currency, scope, cutoff, basis and certainty. Missing/withheld is not numeric zero.

| Label | Eligible evidence / meaning |
|---|---|
| Original baseline cost | Immutable applicable R7 initial baseline with original estimate provenance; unavailable if absent |
| Original estimated cost | Separate original estimate evidence where available; do not equate quote, agreement and baseline |
| Current authorised plan cost | Selected authorised successor plan/version, never current catalogue recalculation |
| Recognised known actual cost | Effective known valued contribution of recognised sources, by category/source; not automatically complete total |
| Linked cost corrections | Auditable bridge from original contribution to current effective value; included once in actual, not extra total |
| Outstanding Purchasing commitment | Owning Purchasing remaining obligation on stated terms/basis; not incurred actual or reservation |
| Reported/unrecognised exposure | Evidenced pending work/claims, with recognition/dispute and overlap labels |
| Recognised unpriced/uncertain valuation | Recognised performance with missing/partial/uncertain value; disclose known valued part and unresolved basis |
| Source valuation status | COSTED/PARTIAL/UNKNOWN with amount basis/reasons; provisional valuation basis separately identified |
| Category coverage | Confirmed Complete / Declared Complete / Incomplete / Unassessed, bounded per0147 |
| Overall Job cost completeness | Required categories' coverage plus valuation/reconciliation; retains declared versus confirmed basis |
| Spending gap to selected baseline/plan | Full compatible planned cost less actual to date, explicitly NOT efficiency savings/final variance |

Separate columns/sections, not an additive grand total across these measures. Known actual distinguishes fully supported valued contributions from
provisionally valued contributions/subtotals, retaining reliable basis and certainty. Provisional is
not financially final; even fully supported operational valuation implies no accounting settlement.
Known actual includes supported provisional valuation only with explicit basis/certainty; do not count it again as exposure.
Unpriced work cannot disappear because no monetary row exists. Source, category and Job statuses are
independent; complete15000.0000 source does not prove all labour/Job costs captured. Explicit zero
assertions remain deferred; legitimate supported zero source evidence does not prove category coverage.

## Contribution identity and logical evidence — no physical schema

Retain company, owning source/economic component, immutable revision/correction chain, recognition
receipt, allocation portion and Job/activity/category scope; occurrence date/period, recording time,
supported quantity/UoM, copied monetary/currency/FX basis, certainty, approval/source generation and
coverage references. Report manifest retains baseline/plan selection, measures/version, cutoffs,
source-generation vector, reconciliation status, coverage and audience/scope projection basis.

One effective economic contribution per source/allocation. Alternative documents confirm/replace/
adjust the same component; physical row identity or equal amount/date is insufficient. Shared source
portions plus company remainder conserve each compatible quantity and currency amount. Job/activity/
category summaries are projections, not additional costs. Signed correction semantics use owning
contracts, not a blind reuse of non-negative Sales FinancialResult.

| Source | Contribution boundary |
|---|---|
| Labour0146 | Recognised direct/adopted-rate source; optional quantity/name; later payroll reconciles |
| Material0148 | Authoritative Inventory consumed allocation plus0040 corrections; not receipt/transfer/PO/preview |
| External0149 | Direct declaration/evidence OR accepted supplier-service component through shared attribution; no duplicate mechanisms |
| Hire/subcontract0149 | Chargeable hire/accepted deliverable portions; productive usage/customer fulfilment separate |
| Other/overhead | Delivered supported incurred source/allocation policy required; estimating overhead alone cannot create actual |

Unsupported adapter/category remains an explicit authorised reporting gap, not zero or silently complete.

## Comparison eligibility and refusals

Compare only where source meaning, selected baseline version, allocated scope, work quantities/units,
currency/FX basis and dates align. Monetary spending gap does not prove quantity/rate efficiency.
For partially completed work, full baseline versus to-date actual may show a labelled spending gap
when those money/scope bases are compatible; earned-cost/efficiency needs evidenced comparable work.

Missing baseline → 'Comparison unavailable — no supported baseline'. Partial baseline/actual → known
amounts and reasons, no trusted total variance/percentage. Zero denominator → percentage unavailable.
Different scope/UoM/currency/date basis → explain incompatibility. No arbitrary quantity conversion,
manual progress%-earned budget, price-derived estimate, false final profitability or margin%. R9
commercial reporting remains separate; customer price/payment is not recognised revenue.
Only evidence-supported overrun/rework/scope/price/FX/estimate-error distinctions; otherwise unclassified.

## Commitments and exposure

Use Purchasing's remaining accepted contractual obligation/basis, conserving allocated portions.
Do not derive remainder by subtracting invoices from PO total or Job actual from ordered value.
Partial service acceptance/receipt/short-close/return needs its own owning effects; receipt is not
material consumption. Commitments/accepted-unpriced work/disputed claims may overlap: identify relation
and covered portions, never add blindly. Unknown overlap stays visible and blocks trusted complete
combined measures. Supplier cancellation does not release customer obligations or erase actuals.

## Reporting dates, snapshots and corrections

Distinguish incurred-through date, known/recorded-as-of date and current-restated historical work.
Initial priority: current-restated view from authoritative current effective evidence, with explicit
incurred cutoff and observed generations. Late reports/value corrections can change current historical
amounts; preserve original sources and any immutable saved dated report. Value-only correction never
changes physical quantity. New evidence reassesses0147; closure does not freeze false completeness.

Saved dated evidence is a pinned result/manifest where delivered source/snapshot support exists, not
proof that any arbitrary prior date can be reconstructed. No unsupported backdated query silently
substitutes current facts. Show 'Historical reconstruction unavailable' where source history/as-of
semantics are unverified. Current corrections do not overwrite historical report contents or grants.
Save/report/export requires its own authorised projection and retention path, with0137 where applicable.

## Forecast extension — explicitly outside first delivery requirement

No automatic EAC from spend, open POs, progress% or remaining hours. Future accountable ETC must name
remaining work/cost basis, author/date/source generation, commitment inclusions/exclusions, accepted
unpriced work, uncertain/disputed claims, valuation and coverage. EAC may combine effective actual
with disjoint remaining estimate only when overlap treatment is established. Unknown overlap refuses
a trusted complete forecast; bounded scenarios remain labelled incomplete. No advanced forecast,
earned-value, automatic accrual or overhead actualisation obligation is imposed by this contract.

## Reuse inspection and physical architecture boundary

See [Q55 resolution inspection](../phases/phase-5-unified-costing-resolution.md) for source paths.
Reuse conventions: owning synchronous Actions, current-authority guard, exact intent receipt,
append-only predecessor corrections, deterministic decimal allocation and consistent read context.
Inspected Sales receipts/history are CRM-specific; receipt port is an interface, not generic storage.
Inventory valuation entries are stock-specific. No verified shared Job attribution/projection engine
was found in inspected shared/module paths. Do not repurpose those tables or treat audit events as
economic source identity. Generalise infrastructure only after proving compatibility in a later
implementation design; no new bus/event-sourced architecture or fixed table layout chosen here.

## Authority, consistency and integrity

Job/source/warehouse/sensitive-cost scope remain independent. Evaluate current authority for list,
drill-down, saved snapshots, download/export and replay. Restricted reader gets only safely authorised
projection or refusal; never all-company totals minus visible subtotal, hidden-source diagnostics,
hashes, counts or percentages that reveal withheld values. Narrow reports say 'Authorised scope only',
not whole-Job completeness. Snapshot possession cannot grant permanent access after revocation.

Authoritative view must use one compatible database snapshot or a verified generation-vector protocol
covering source recognition/corrections, baseline, allocation and coverage. A mixed-generation read
retries/refuses, never labels itself authoritative. Exact connection/isolation/lock order is later
delivery proof; independent read transactions alone do not establish a common snapshot. Projection
watermarks/caches must prove freshness or explicitly disclose staleness; no trusted mixed summary.

Reconciliation checks source effective amounts/quantities against allocations, category/Job projections
and unallocated remainder. No double summing original+replacement+delta. Owning source writes require
atomic mandatory effects/receipts, current-authorised exact retries and stable tenant-safe identities.
0040 and0149 gates apply; missing mandatory participant/unknown impact refuses safely. Projection
rebuild is deterministic read/derivation only, never source posting or a recovery side door.

## Acceptance scenarios — required, not executed tests

- No baseline/hours: known costs shown, no invented estimate/quantity/complete cost.
- Installation partly performed: spending gap label, no efficiency or automatic EAC.
- Late correction: current value changes once, physical quantity and saved report unchanged.
- Recognised unpriced plus valued sources: known subtotal and gap; no trusted complete total.
- Invoice/payroll/PO/receipt/transfer/payment: reconcile source components, never extra duplicate actual.
- Shared-source split and correction: conserved quantities/amounts and company remainder.
- Correction races report/coverage/baseline change: consistent generation or retry/refusal.
- Missing participant/unsupported historical date: refuse affected operation, no partial authority.
- Rebuild/retry: no source repost, deterministic effective projection, stale watermark visible.
- Revoked/restricted reader: no leak via totals, differences, evidence, exports or saved snapshots.
- Missing/partial/incompatible/zero baseline: explanations/undefined percentage, no false margin.

## Related

- [0150](../decisions/0150-unify-job-cost-reporting-through-source-owned-attribution.md)

- [Q55 refined resolution](../phases/phase-5-unified-costing-resolution.md)
- [Phase5](../phases/phase-5-job-execution.md) · [Coverage](job-cost-coverage.md)
- [Labour](job-labour-recognition.md) · [Materials](job-material-attribution.md)
- [External](job-external-cost-attribution.md) · [Lifecycle](job-execution-lifecycle.md)
