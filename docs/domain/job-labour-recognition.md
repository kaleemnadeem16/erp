[← Domain index](README.md)

# Job labour recognition

**2026-10-16 · Business architecture ACCEPTED0146/Q51; bounded contract SPECIFIED.**
No labour/Job adapter IMPLEMENTED or VERIFIED; no implementation/grants authorised.
Exact coverage-declaration protocol is [PROPOSED Q52](../phases/phase-5-cost-coverage-review.md).

## Where it sits and supported-method boundary

R7 Job/baseline → optional method-specific evidence → applicable review → recognition → one
effective source attribution → source/category/Job completeness reporting.0145 governs work
start, holds, completion/termination and operational closeout; recognition never grants fulfilment.

| Method | Required evidence beyond company/Job/scope/recorder/source lineage | Initial boundary |
|---|---|---|
| Completion only | Supported outcome/checklist and authorised confirmation | No labour source or fake qty/cost; optional capture cannot block supported completion |
| Direct incurred amount | Base amount/currency, incurred date/period/basis, source or authorised declaration, covered economic work | Narrow internal labour path; no fake worker/rate/qty1; known source value not total labour coverage |
| Fixed units/days | Quantity, exact unit/basis, work/class context, date/period; aggregate basis where used | Incremental adapter, not hours inferred from worker-day |
| Hours | Supported duration/date/context; name if method/policy requires | Incremental adapter; no attendance/overlap claim without intervals/identity |
| Fixed external labour | Contractor/agreement/commitment and separate incurred evidence | Owning Purchasing/Finance integration, not direct internal shortcut |

SIMPLE first release direction is completion + optional direct base amounts. Daily/hourly need
their explicit adapter/precision contract, not automatic support from this table. A policy-required
method cannot be bypassed by choosing another UI form. Person-specific rates/detailed crews,
payroll, attendance/capacity and piecework output-quality rules remain later capabilities.

## Logical evidence and source responsibilities — no migration schema

- Source envelope: company, stable business/economic-component identity, Job/activity or supported
  Job-wide scope, method, cost category, original source reference/declaration, occurrence date/period
  at actual precision, recording timestamp/actor and immutable revisions.
- Usage evidence: quantity/UoM/basis and worker/team detail only where captured/required.
- Valuation evidence: direct incurred amount OR adopted rate/currency/UoM/date/source/basis/authority;
  copied source facts, FX/base evidence where supported, certainty and unresolved reasons.
- Review/recognition: exact source revision/fingerprint, applicable policy/decision, actor and receipt;
  recognition of usage and valuation are distinct facts.
- Attribution: each effective recognised economic contribution once, supported allocated scope and
  deterministic rounding; Job/activity projections are not additional monetary entries.
- Correction: original/effective chain, reason/authority, quantity/amount/attribution/date effects and
  dependent reconciliation. Coverage assessment is separate from expense evidence.

Names are stable company identities, optionally linked to existing person/login infrastructure;
no fake account, full HR or duplicate general directory. Worker, class, recorder, reviewer,
approver and responsible assignee remain distinct. Deactivation preserves historical facts.
Team aggregate records do not prove individual attendance or permission. Generic planning,
aggregate usage and detailed crew costing are separate support boundaries.

## Recognition rules and authority

1. Guard method/company/source/Job eligibility and current duty/scope in Request and owning Action.
2. Preserve actual occurrence granularity. Missing timestamps are not invented to pass a hold/rate
   check; ambiguity goes to governed review. Recorded evidence is not authorisation or cost recognition.
3. Bind applicable NONE/POLICY/REQUIRED shared approval to exact revision. Source edits invalidate
   relied-on review; no universal independent approver, and no method-switch bypass.
4. User holding record AND recognise eligibility can use one combined action. Otherwise report
   remains pending. Combined failure rolls back effects; no extra manual posting after eligible review.
5. Recognise supported quantity even if valuation UNKNOWN; amounts recognise only evidenced incurred
   cost, not advance/commitment/payment. Do not silently coerce report to recognised actual.
6. Commit applicable attribution and receipt with recognition. Pending/provisional and recognised
   views never sum; a known reported amount can be unrecognised while its value is known.

Operation boundaries: own/on-behalf/aggregate recording, direct incurred declaration, recognition,
review, rate adoption, historical correction and cost read/export remain independently eligible.
Reuse R9/RecordScope, no assignment/role-name inference. Monetary entry reveals its authorised
input, not wider worker rates/Job margins. Final exact Job strings/holder rollout are delivery
specification gates; no vocabulary/grant is registered here. Protect nested/history/counts/sort/
export/receipts/error/worker data and refuse unsupported limited scope. Existing Sales handover
duties keep their accepted meanings; Job actuals are not a new broad Sales permission.

## Rates, units and money

ResourceRate is candidate class-rate evidence, not automatic actual-cost authority. Adopt and
copy rate6, compatible unit, base currency, effective date/validity, source, costing basis and
authority. Work occurrence date/period governs supported selection. Resolver's latest<=date has
no operational expiry policy; do not invent one or silently fall back to quotation/wages/zero.
Missing/conflicting/expired/unsupported adoption leaves recognised usage with incomplete valuation.
Later master edits/deletion cannot change pinned facts; historical corrections append adjustments.

Worker-day/crew-day/person-hour differ; no implied eight-hour/24-hour conversion. Fixed-unit evidence
retains its recorded quantity. Whole-second duration with exact hour arithmetic is the accepted
direction for duration methods, subject to bounded entry/adapter specification; no stock4 truncation
or rate6 precision borrowed for time. Exact compatible conversions only; no global UoM change.

Direct base amount needs no rate. Initial supported monetary rows store currency, FX1 and base
amount under existing decimal19,4 invariants. Unsupported foreign amounts stay unposted source
observations, not incomplete authoritative money rows violating dual-amount storage. Future FX
needs pinned date/rate/source/direction and explicit rounding; Sales FR1 is not Job policy authority.
Do not silently retranslate historical cost. No automatic standard-to-payroll finality is implied.

## Three completeness axes

| Axis | Meaning / rule |
|---|---|
| Individual source valuation |0110-style COSTED/PARTIAL/UNKNOWN with supported basis, known contribution and reasons; absence of complete valuation is not zero |
| Labour expense coverage | Have all applicable labour economic sources in a stated Job/scope/date basis been accounted for? Separate from pricing each known source |
| Overall Job cost completeness | Supported coverage plus valuation/reconciliation across ALL applicable required categories, not only labour |

Required reporting distinctions: confirmed complete coverage, declared complete coverage, incomplete,
unassessed, with scope/evidence basis. All-source valuation cannot infer coverage. One recognised
15000.0000 source can be COSTED while labour coverage is UNASSESSED and Job total incomplete.
A known15000 plus unpriced usage yields a known contribution, no complete total. An empty list
is not complete zero. Known zero requires explicit supported evidence, never a missing source.

The [Q52 proposal](../phases/phase-5-cost-coverage-review.md) defines recommended declaration/
confirmation/invalidation mechanics for owner review, not accepted runtime rules. Until delivered,
do not pretend these assessments exist. No formula adds unlike currencies or units or leaks hidden
source totals. Historical closeout/report snapshots remain while current completeness may change.

## Lifecycle and closeout reconciliation

No optional worker/timesheet/rate is made a mandatory execution or completion prerequisite. Valid
completion still requires0145 outcome evidence and actual execution context. Required/known pending
entries, disputed quantities, unknown downstream effects or concealed contractor obligations still
block appropriate operations. Unknown cost reporting is not a universal work-start blockade.

Known unpriced actuals require explicit missing sources/reasons, accountable owner and supported
follow-up for incomplete-valuation closeout. Optional uncaptured usage is not an invented pending
timesheet. Closure is neither confirmed complete coverage nor financial settlement. Later historical
evidence/cost adjustments preserve lineage without ordinary restart. Hold reports are captured/reviewed
under0145, never auto-recognised/fulfilled or authorised by a general bypass duty.

## Corrections and later sources

| Change | Required handling |
|---|---|
| Usage quantity | Append supported quantity correction and dependent valuation; no altered commercial allocation by hours |
| Direct amount | Append monetary delta/reversal-replacement; no fabricated usage |
| Rate/FX | Valuation-only correction; no catalogue-triggered recost/new physical consumption |
| Worker/source/Job/activity | Immutable correction lineage; guarded old/new attribution and atomic economic conservation |
| Occurrence date | Recheck historical context/rates/holds/approvals and dependent effects |
| Provisional revision | Invalidate prior relied-on review; stale recognition refused |
| Held/completed/closed | Governed0145 historical path; no ordinary restart |

Unsafe impacts retain visible discrepancy and refuse applied posting. Finance controls later period
treatment, not a Job reopen flag. Signed adjustments need an explicit safe representation; non-negative
Sales FinancialResult cannot blindly encode correction deltas.

Later payroll/invoice/Purchasing evidence must declare confirmation, replacement or difference against
covered economic sources. Preserve originals and one effective allocation; partial matches conserve
scope/amounts, genuine FX effects stay explicit. Activity totals/GL/payment are not extra expenses.
Equal date/amount is not identity. Unknown overlap refuses automatic second attribution; unrelated
legitimate equal expenses remain possible. A future source adapter must inventory every writer and
reconcile prior direct/aggregate declarations. No automatic financial matching engine delivered here.

## Idempotency and transactions

Use company-scoped stable source/component/revision/recognition identities plus exact request receipts.
Check current authority before replay; fresh key for same business intent cannot duplicate cost.
Changed keyed intent conflicts. Source quantity/recognition/attribution/correction ledger effects and
receipt share outer transaction/verified runtime connection with consistent R7→Job→source/downstream
locks, unique identities and tenant constraints. Revalidate lifecycle including holds/termination,
generation/review, coverage-relevant source facts and dependent impacts under locks. Competing
recognise/correct/close/import/worker paths participate. Missing adapter is UNSUPPORTED, not zero/NONE.
Exact reason_code vocabulary/physical schema/client/UI remain implementation-owned after code authority.

## Acceptance scenarios — required evidence, not executed tests

| ID | Expected behaviour |
|---|---|
| LR01 | Completion-only: valid outcome completes; no synthetic source/worker/qty/rate or complete zero cost |
| LR02 | Direct base amount without hours/names: eligible record+recognise commits one contribution |
| LR03 | Record-only actor cannot recognise; combined action missing either eligibility commits no combined effects |
| LR04 | Required review/stale revision: no auto-recognition or method-switch bypass |
| LR05 | Recognise usage with absent rate: quantity retained, incomplete valuation, no zero total |
| LR06 | Six worker-days: no invented hours; method requiring named worker refuses anonymous input |
| LR07 | Adopted-rate catalogue changes/deletion: original actual unchanged; correction explicit |
| LR08 | Unsupported foreign source: preserve observation, no invented base amount/posting |
| LR09 | Same/fresh-key retry or concurrent recognition: one source cost; changed intent conflicts |
| LR10 | Exception after recognition insert: attribution and receipt roll back consistently |
| LR11 | Known15000 valued source: labour coverage can remain unassessed and whole Job incomplete |
| LR12 | No sources: never automatic zero/confirmed complete; declared assessment cannot masquerade as confirmed |
| LR13 | Labour complete but material category unresolved: overall Job incomplete |
| LR14 | Amount/rate correction: no new hours; worker/date correction safely reconciles dependent effects |
| LR15 | Invoice/payroll matches existing expense: confirm/replace/delta once, no additive duplicate |
| LR16 | Hold/closed report: preserve original/history, no auto-recognition, fulfilment or restart |
| LR17 | Scope/cost denied: no leak via worker, history, receipt, aggregate or export |
| LR18 | Recognise/close race: consistent reconciled closeout or refusal, no hidden pending entry |

Coverage declaration-specific scenarios remain proposed under Q52; detailed materials under Q53/Q24.
Architecture ACCEPTED/SPECIFIED does not prove any adapter or test has run. No Inventory correction
package, payroll/Finance posting, generic crew system or independent renderer is implemented here.

## Related

- [0146](../decisions/0146-recognise-flexible-labour-evidence-without-fabricating-usage.md)
- [Lifecycle](job-execution-lifecycle.md) · [Phase5](../phases/phase-5-job-execution.md)
- [Coverage proposal](../phases/phase-5-cost-coverage-review.md) · [Materials](../phases/phase-5-material-flow-research.md)
- [Cost certainty](material-cost-resolution.md) · [R7](sales-operational-handover.md) · [R9](sales-duty-matrix.md)
