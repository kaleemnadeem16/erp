[← Phase 5 planning](phase-5-job-execution.md)

# Task C — J2 cost coverage, reporting and verification design

2026-10-26 · #44 · PROPOSED DESIGN ONLY, companion to
[labour/valuation/corrections](phase-5-task-c-j2-labour.md). No application implementation or runtime
verification. Reuse accepted [0147 coverage](../domain/job-cost-coverage.md),
[0150 costing](../domain/job-unified-costing.md), [0151 closeout](../domain/job-closeout-reconciliation.md)
and [cost research](phase-5-unified-costing-research.md); no accepted decision changed.

## Three independent axes

| Axis | Contract and example |
|---|---|
| Source valuation | COSTED/PARTIAL/UNKNOWN with known contribution and certainty/basis/reasons; fully supported versus provisional remains visible |
| Category expense coverage | Confirmed Complete / Declared Complete / Incomplete / Unassessed for exact scope/date/source inventory; one valued 15000.0000 source cannot establish coverage |
| Whole-Job completeness | All applicable required categories have supported coverage AND valuation/reconciliation; retain declared versus confirmed basis, never silently upgrade |

An unpriced recognised source can be represented in declared coverage while value remains incomplete.
A fully valued known source with unassessed labour coverage is not complete labour/Job cost.
No source list does not prove zero. Explicit zero-cost assertions remain deferred; legitimate
source-evidenced zero is distinct from an unsupported category-wide zero declaration. Closed is
neither fully costed nor invoiced/paid/revenue recognised/financially settled.

Coverage declaration is optional. It pins company/Job/activity/category, incurred-through date,
known-as-of time, supported source inventory/generations, basis/evidence, actor/time and predecessor.
Before accepting it, source owners enumerate known omissions/disputes/ordinary pending authoritative
sources and unresolved reconciliation; any such gap refuses declaration. A user's checkbox or empty
query cannot prove whole-source inventory. Confirmed requires actual authoritative reconciliation,
not confidence in a declaration. Missing trustworthy inventory leaves unassessed/unsupported.

Every source-recognition/correction/import/later Finance writer participates in version/invalidation.
Under compatible locks append assessment plus exact receipt and vector, or stale refusal. New
contradictory evidence creates known gap→Incomplete; otherwise stale complete use→Unassessed until
reassessment. Value-only correction may retain coverage only with proof source set/scope unchanged;
valuation version still advances. Never silently extend the through-date. Immutable historical
assessment/report/closeout references remain, while current view shows invalidation/reassessment.

## Read model and useful measures

Source owners recognise economic contributions; reporting/rebuild never posts expenses. Derive one
effective contribution per stable component/allocation, including corrections once and conserving
company remainder. Activity/Job/category summaries are projections, not additional monetary entries.
No generic event bus, separate stock balance or new financial ledger is implied.

| Exact display label | Eligibility and truthful limit |
|---|---|
| Original estimated cost | Original estimate evidence where actually present; not customer price |
| Original baseline cost | Immutable applicable R7 initial baseline/provenance, not current master recalculation |
| Current authorised plan cost | Explicit approved successor plan/version and scope; no silent replacement of original |
| Recognised known actual cost | Effective valued recognised contributions by category/source with fully supported/provisional basis separated; not necessarily complete total |
| Linked cost corrections | Bridge original→effective value, already included once in actual |
| Outstanding Purchasing commitment | Owning remaining contractual portion/terms; not incurred actual, reservation or PO-minus-invoices arithmetic |
| Reported/unrecognised exposure | Pending/disputed source evidence, separate from actual with overlap labels |
| Recognised unpriced/uncertain valuation | Preserve actual performance and known part/reasons; no missing monetary row hides work |
| Source valuation / category coverage / overall completeness | Independent axes above; unsupported/withheld/missing is never numeric zero |
| Spending gap to selected baseline/plan | Compatible full plan less actual-to-date, labelled spending gap; not savings, efficiency or final variance |

No additive grand total of commitments+exposure+actual: they can describe the same component.
Purchasing portions/acceptance and later invoice correction reconcile overlap; absent support
stays an explicit gap. MATERIAL cost comes only from Inventory consumed valuation/0040, not PO,
goods receipt, transfer or preview. SUBCONTRACT/PLANT hire/external labour preserve0149 ownership.
Known supported internal15000.0000 + disjoint external8000.0000 = known23000.0000, while uncovered
material scope still prevents complete Job cost. Without actual external adapter show its gap,
not an invented valued row. No baseline/hours required merely to show genuine known contributions.

## Comparisons, cutoffs and consistency

Compare only compatible meaning, baseline version, allocated scope, currency/FX/date basis and
supported quantities/units. Missing baseline→comparison unavailable. Partial baseline/actual→known
amounts and reasons, no trusted total variance/percentage. Zero denominator→percentage unavailable.
Never derive earned budget from manual progress%, efficiency from full-plan-minus-partial spend,
price-derived cost, revenue/margin or automatic EAC. ETC/EAC/earned-value remain later extensions
requiring disjoint remaining-work estimates, commitment inclusion and explicit overlap proof.

Initial report is current-restated authoritative effective evidence with explicit incurred cutoff,
observed-at time, selected plan/baseline, source vector, coverage basis and reconciliation version.
Distinguish incurred-through from recorded/known-as-of and current restatement of historical work.
A saved dated report retains immutable result/manifest where source snapshot support exists;
arbitrary prior-date reconstruction is unavailable until every participating source proves it.
Never label today's data as historical as-of. Later correction changes current effective value,
not original report, physical quantity or original closeout. Formal saved/exported artefacts need
the separate Documents0137 source/access/retention adapter; no independent renderer.

Use one compatible database snapshot or verified generation-vector protocol spanning all relevant
source/recognition/correction/allocation/coverage/baseline reads. Separate per-source transactions
do not prove consistency. Mixed generations retry/refuse. Rebuild derives projections only;
interrupted/stale watermarks are disclosed or authoritative output refused. No rebuild reposts
source effects or acts as a correction back door. Reconcile source effective values to allocated
portions + company remainder and category/Job projections; unexplained residual refuses trusted totals.

## Safe visibility and J1 independence

Cost-read, source/warehouse/worker detail, coverage declaration, export/save/download and operational
close each need their own reviewed authority/scope. Protect fields as well as lists, counts, sort,
history, nested data, receipts, errors, cache and saved snapshots. Current rights apply on replay/
download, even after original recording approval. Company/access change invalidates cached results.
Restricted projection says Authorised scope only, never whole-Job completeness. Never expose hidden
totals via subtraction, percentages, fingerprints or blocker IDs. If safely narrowing is unsupported,
refuse. Cost-entry authority reveals permitted input, not broad rates/margins or historical snapshots.

J1 completion/close uses a reviewed non-monetary owning readiness verdict, not this cost report or
job.cost.view. Optional absent labour/coverage does not block supported work. Known ordinary pending
reports/disputed quantities/commitments still block appropriate closeout; valuation-only follow-up
requires actual eligible acknowledged owner and supported correction path under0151. Unknown
mandatory effect is never a follow-up waiver. All source/readiness and real holder delivery is GATED.

## Required implementation scenarios — not executed tests

| ID | Scenario / required result |
|---|---|
| JC01 | Supported J1 without J2 → completes/closes under its own gates; no worker/hour/cost/coverage prerequisite or zero cost claim |
| JC02 | Direct incurred base amount without usage → one authorised recognised contribution; external/service/material cannot bypass own adapter/policy |
| JC03 | Record-only versus combined record/recognise, missing duty/stale review → pending or atomic refusal, no unauthorised posting |
| JC04 | Later recognised usage missing rate → unpriced actual retained; no invented hours/unit/zero/FX; pinned rates survive master changes |
| JC05 | Same/fresh-key/cross-actor concurrent recognition → one component/allocation; changed intent conflicts after current access check |
| JC06 | Exception after recognition/allocation/coverage/receipt step → whole rollback on actual same connection |
| JC07 | Fully valued15000.0000 plus unassessed coverage or missing category → known subtotal only, no whole-Job completeness |
| JC08 | Recognised unpriced source represented → declaration may be coverage-complete while valuation incomplete; pending omission/dispute refuses declaration |
| JC09 | New source/correction/import races coverage/report/close → coherent vector or stale refusal/invalidation, original snapshots retained |
| JC10 | Amount/value-only correction → effective value once, no physical/usage change; Job/category/date correction conserves old/new allocations and scopes |
| JC11 | Later invoice/payroll partial confirmation/replacement/delta → no duplicated cost or lost unmatched remainder; ambiguous overlap refuses automatic posting |
| JC12 | Missing0040/0149 mandatory participant → affected path disabled/atomic refusal, never local pending after partial internal commit |
| JC13 | Missing/partial/incompatible/zero baseline → correct unavailable/partial comparison and no efficiency/margin/EAC claim |
| JC14 | Report/rebuild interruption/mixed source snapshots → stale disclosed or refusal; no source repost, deterministic conserved projection |
| JC15 | Revoked/limited/foreign-company reader, saved export/receipt/cache → deny/safe projection without hidden totals/rates/IDs/hash leakage |
| JC16 | Held/closed/terminated historical report/correction → truthful lineage/context and governed effect only; no new execution or ordinary restart |

Future implementation runs focused module tests and applicable company/scope/permission/write/read/
Action-boundary ratchets, Pint/PHPStan, isolated migration cycle, codegen/client/frontend/browser
checks for actual touched surfaces. Real source adapters and independent-session race tests are
required; fixtures/mocks prove only local handling. CI/coordinator owns full regression and integrated
browser evidence. No code tests run by this docs-only task; link/ownership/whitespace checks only.

## Review handoff

Review first supported method, shared source/revision/correction/coverage contracts, monetary
FinancialActivity requirement, safe projections and bounded slices in companion. Exact schema,
duty literals/holders, rate/time adapters, source inventory/lock protocol and reporting snapshot
implementation remain separately gated technical deliveries. No new business decision or code
authority implied. Sales source/receiver, Inventory0040, Purchasing0149 and later Finance remain
explicit dependencies, never assumed delivered because their architecture is accepted.

## Related

- [Labour/valuation design](phase-5-task-c-j2-labour.md) · [Planning overview](phase-5-job-execution.md)
- [Coverage](../domain/job-cost-coverage.md) · [Unified costing](../domain/job-unified-costing.md)
- [Closeout](../domain/job-closeout-reconciliation.md) · [Pre-push checks](../architecture/pre-push-checks.md)
