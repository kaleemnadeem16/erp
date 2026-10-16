[← Phase index](README.md)

# Phase 5 — unified Job costing and estimate-versus-actual research

**2026-10-16 · PROPOSED research bundle / Q55 AWAITING_OWNER. No new costing ADR accepted.**
Question: how can unlike owning sources produce one trustworthy cost view and comparable variance
without duplicate contributions, fabricated completeness or a universal Finance/timesheet dependency?

## Business problems and examples first

**Small service visit:** optional labour15000.0000 plus evidenced specialist8000.0000 and Inventory
consumption2000.0000 gives known recognised valued contribution25000.0000. Labour coverage is
Declared Complete, material coverage Unassessed: this is not a final all-cost total. Completion is
still possible under0145. If no authorised cost baseline exists, say comparison unavailable; do not
manufacture an estimate from sales price or treat absent estimate as zero. A completion-only Job
with no costs shows no recorded cost/unassessed, not zero incurred cost or100% margin.

**Larger installation:** original comparable baseline100000.0000; authorised current plan110000.0000.
Recognised valued actual60000.0000 includes labour20000.0000, material25000.0000, hire5000.0000 and
accepted subcontract10000.0000. Unperformed supplier commitment20000.0000 is separate. Owner estimates
remaining cost45000.0000, explicitly INCLUDING that20000.0000 commitment: candidate forecast105000.0000,
not125000.0000. Against current plan the candidate forecast is5000.0000 lower; against original it is
5000.0000 higher. Neither comparison proves execution efficiency unless scope/quantities are comparable.
If ETC excludes commitment or overlap is unknown, refuse that forecast formula and expose uncertainty.

**Late correction:** Inventory adds1000.0000 valuation-only adjustment. Current actual becomes61000.0000
with unchanged physical quantity and an auditable bridge. Prior dated report stays60000.0000 with its
source/version snapshot. A later invoice confirming the subcontract10000.0000 contributes no second
10000.0000. A disputed supplier claim stays visible exposure, not automatic recognised actual.

## Distinguish the measures before choosing storage

| Measure | Recommended meaning / boundary |
|---|---|
| Estimate | Dated estimating/advisory cost evidence with quantity/scope/basis/certainty; not actual |
| Original baseline | Immutable accepted initial operational scope/cost evidence from supported R7 handover; may contain explicit gaps |
| Authorised plan | Versioned successor operational plan; preserves original and change reasons, never rewrites agreement |
| Commitment | Remaining authorised supplier obligation under owning Purchasing terms; not all remaining Job cost |
| Provisional exposure | Reported/unrecognised work, uncertain charges/claims or other evidenced potential cost, labelled basis/overlap |
| Recognised actual | Supported incurred economic contribution, including recognised unpriced performance; monetary known amount shown separately |
| Correction | Linked adjustment/reversal-successor to existing source, not another unrelated expense or physical quantity |
| Coverage/completeness |0147 source valuation, category coverage and overall required-category completeness, independently bounded |

Provisional valuation of recognised work is not the same as unrecognised exposure. Do not sum both
views of one component. Original quote, agreement, initial baseline and successor plan are distinct.
No automatic current-catalogue recost, inferred commercial scope or customer charge increase.

## Owning-source contribution map

| Source | Actual-cost authority / exclusion |
|---|---|
| Flexible labour |0146 recognised source/adopted valuation; optional quantity/name; payroll later reconciles same work |
| Inventory material |0148 posted actual consumption/allocation +0040 valuation adjustments; PO/receipt/transfer/preview never additional actual |
| Direct external |0149 evidenced incurred source/declaration under same attribution foundation; origin/evidence status retained |
| Purchased services | Purchasing authoritative accepted portions + supported recognition/valuation; invoice reconciles later |
| Equipment hire |0149 evidenced chargeable period/amount, not productive usage or equipment presence; owned-plant costing separate gate |
| Subcontract |0149 accepted defined portions, disputes/remainder separate; supplier/customer fulfilment independent |
| Overhead/other categories | Only delivered supported incurred allocation/source policy; estimate overhead policy alone is not operational actual authority |

Job/activity/category totals are projections of the same contributions. One source spread across
Jobs conserves quantity/amount and retains unallocated company remainder. Child totals are not added
again to Job total. Category mapping follows economic substance, with governed reclassification.
No quote/Assembly or labour-rate dependency for a direct source. Unsupported sources remain visible
as pending/unsupported completeness gaps, not silently omitted or represented as zero.

## Primary comparison — accessed2026-10-16

| Primary source | Finding and project consequence |
|---|---|
| [Dynamics Actuals](https://learn.microsoft.com/en-us/dynamics365/project-operations/actuals/actuals-overview) | Approved operational/financial events create actuals; journal correction and no deletion preserve history. Reuse stable source/correction principles; its financial/schedule coupling does not override0145 |
| [ERPNext project costing](https://docs.frappe.io/erpnext/project-costing) | Service costing uses task/project timesheet cost. Useful source-to-project roll-up; not a reason to mandate timesheets for our direct/completion-only methods |
| [Odoo profitability](https://www.odoo.com/documentation/19.0/applications/services/project/project_management/project_profitability.html) | Official search-index evidence distinguishes expected/to-bill/billed measures; full-page fetch timed out. Corroboration for separate stages only, not proof of overlap-safe integration here |

Convergence: operational sources feed a project view with stage/history context. Divergence: vendors
couple costing to different recognition/accounting/time-entry models. Our accepted0145–0149 source
ownership/completeness rules take precedence. Reuse [0149 comparison](phase-5-purchasing-external-resolution.md)
for invoice replacement and [0110 cost evidence](../domain/material-cost-resolution.md) for certainty.
No repeated supplier/stock design or universal copy of vendor ledger schemas.

## U1 — unified view and economic identity options

**Live union of source tables:** little duplicate storage, but inconsistent semantics/cutoffs and
expensive permission-safe reconciliation as sources grow. **Central editable Job ledger:** simple
querying, but competes with source owners and permits unsupported money edits. **Source-owned evidence
plus shared immutable attribution/correction journal and rebuildable reporting view:** more adapter
discipline, one logical contribution identity and traceable aggregation. Recommend the third direction,
subject to contract review; no table/event bus or universal ledger schema selected in this research.

Identity includes company, owning economic source/component, revision/effective correction chain and
allocation portion. Source rows and monetary representation rows are not independent economic costs.
Later Finance confirms/replaces/deltas original components. Unmatched overlap refuses automatic second
contribution, not legitimate equal expenses. Journal rebuild/projection cannot replay source effects.
Source writer/receipt/attribution effects must reconcile atomically under accepted coordination.

## U2 — comparable estimate-versus-actual reporting

Recommend first show selected original/current baseline, recognised known contribution, coverage/
valuation warnings, commitments and exposure in separate columns. State comparison basis explicitly.
Full-budget minus partial-to-date spend is a spending gap, not efficiency, profit or savings.

Compare total actual with total baseline only on compatible scope/time basis. Later efficiency analysis
needs evidenced completed quantities or accepted work and a supported baseline quantity/cost mapping;
do not use manual progress%/time-spent to invent earned budget. Distinguish extra agreed scope,
execution overrun, rework, price/FX changes and estimate error only where evidence permits; otherwise
label unclassified difference. No automatic attribution of blame from a monetary gap.

Cost-only variance does not establish revenue/margin. R9 governs commercial reporting; price, invoice,
payment and recognised revenue differ. No new margin or accounting settlement metric selected here.
No baseline source → comparison unavailable. Partial baseline/actual → show known contributions and
gaps, not precise total variance/percentage. Baseline zero → percentage undefined, not division fallback.
Keep physical quantities/units distinct; aggregate money only in compatible supported base currency.

## U3 — cutoffs, precision and completeness

Recommend distinguish work incurred-through date from knowledge/recorded-as-of date and pinned source
generation. Late reported work and later value corrections can change current historical views; saved
reports retain original source/baseline/assessment references. Show whether looking at current-restated
or previously reported evidence, not an unlabelled historical recomputation. Full as-of replay needs
real source history; existing advisory QueryMaterialCost does not provide arbitrary historical cost.

Use existing money precision and deterministic allocation; never add source total plus allocations,
round each child independently then invent reconciliation, or sum unlike currencies. Presentation
precision cannot strengthen evidence. Initial company-base scope only; cross-company consolidation/
unsupported FX remain deferred. Exact dates/quantities stay at recorded granularity, no fake timestamps.

Retain0147 Confirmed Complete/Declared Complete/Incomplete/Unassessed per scope/through-date. A complete
valued source does not prove category coverage; one complete category does not prove whole Job.
Unsupported/unassessed categories must remain apparent to authorised readers. Restricted totals must
not leak hidden cost via variance/percentages/counts; show safely narrowed coverage rather than a
whole-Job claim from incomplete visibility. No-source is never automatic zero; explicit zero deferred.

## U4 — forecasts and remaining exposure options

Default recommendation: no automatic estimate-at-completion from bare cost records. Optional later
accountable ETC can support forecast = effective actual + disjoint remaining estimate, retaining
author/date/basis/inclusions/version. Show whether remaining commitment, uncertain accepted valuation,
disputed claims and planned internal effort are included, excluded or unknown. Do not sum commitment
and ETC when overlapping; do not add unpriced recognised work twice or silently exclude it.
If coverage/valuation is partial, any scenario is explicitly bounded/provisional, not a complete EAC.

Alternative spend-only view is smallest and safest; a universal automatic EAC is convenient but invents
remaining quantities/rates and false certainty. Recommend spend/commitment/exposure first, with bounded
manual ETC as separately approved follow-up. No earned-value management, automatic accrual, forecast
revenue, contingency or overhead actualisation in first scope.

## U5 — delivery/governance gates and proposed scope

Reuse0145–0149 and R7/R9: no new source lifecycle, scope increase, grants, Finance waiver or optional
capture completion gate. Owning sources remain authoritative; only delivered verified adapters feed
authoritative reports. Reconcile every writer, prior source and correction before source activation.
0040 material corrections and0149 later invoices retain mandatory delivery gates. Missing required
effects refuse safely; reports cannot conceal unresolved reconciliation by dropping a source.

Proposed initial useful scope: one Job/company-base category view with source drill-down, original/
current baseline selection, recognised known amount, corrections, coverage/valuation warnings and
separate commitment/exposure. Completion-only, missing estimate and amount-only sources must work.
Default all categories, safe scoped access, no compulsory complex screen. MANAGED can add activity/
source/reviewer analysis without another ledger. Optional Documents0137 export adapter has its own
security/snapshot gates; no new renderer work authorised.

Future contract must prove current authorised source/Job/cost reads, snapshot consistency across
source generations, safe cache/projection invalidation, allocation conservation and current-auth
idempotent source writes. Report rebuild is read/projection work, never a second posting. Historical
closed Jobs allow governed correction and report restatement, not ordinary reopening.

## Genuine owner decisions / recommendations

1. Approve U1's source-owned evidence/shared attribution direction versus live union or editable ledger.
2. Approve first report showing known actuals with independent certainty/coverage and separate
   commitments/exposure; suppress unsupported efficiency/total variance instead of filling gaps.
3. Choose spend/commitment view first (recommended), or include accountable bounded manual ETC in the
   first contract; either choice must disclose overlap and incomplete bases.
4. Confirm original/current-plan comparison and dated versus current-restated views, with deeper
   quantity/rate/earned-value decomposition deferred until supported evidence exists.

These are proposed business-reporting choices, not architecture acceptance or physical-schema approval.
No owner decision is requested on already settled allocation/commercial/source rules.

## Testable scenarios for later contract — not executed tests

| ID | Expected behaviour |
|---|---|
| UC01 | Small visit known25000.0000/unassessed materials: no complete total or fabricated margin |
| UC02 | No cost/estimate completion-only: unassessed cost and unavailable comparison, no fake zero |
| UC03 | Activity/source totals reconcile to Job once; shared source/company remainder conserved |
| UC04 | PO/receipt/issue/invoice/payment chain: one consumed economic contribution plus linked correction |
| UC05 | Larger example60000.0000+45000.0000 inclusive ETC:105000.0000, not125000.0000 |
| UC06 | Unknown ETC/commitment overlap or unpriced actual: refuse trusted complete EAC |
| UC07 | Later1000.0000 correction: current61000.0000, prior60000.0000 snapshot retained, qty unchanged |
| UC08 | New source contradicts coverage: invalidate/reassess, no stale complete report/cache |
| UC09 | Missing/partial/zero baseline: unavailable/partial comparison or undefined%, not false savings |
| UC10 | Full baseline versus partial work: spending gap label, no inferred efficiency |
| UC11 | Hidden cost/source scope: no leakage through totals/variance/export or false whole-Job completeness |
| UC12 | Projection rebuild/retry/concurrent correction: consistent view, no repeated source posting |

## Related

- [Phase5](phase-5-job-execution.md) · [External contract](../domain/job-external-cost-attribution.md)
- [Coverage](../domain/job-cost-coverage.md) · [Historical Job sketch](../domain/job-costing.md)
- [Labour](../domain/job-labour-recognition.md) · [Materials](../domain/job-material-attribution.md)
