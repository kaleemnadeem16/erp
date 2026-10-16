[← Phase index](README.md)

# Q52 and M1–M6 — refined coverage and material-path resolution proposal

**Historical research/proposal:** owner-approved2026-10-16 and reconciled in accepted0147/0148 and [coverage](../domain/job-cost-coverage.md)/[material](../domain/job-material-attribution.md) contracts. Review requests below record the prior stage, not current blockers. No implementation delivered.


**2026-10-16 · PROPOSED final resolution for owner review; no new ADR accepted.**
Owner supports the business direction with clarifications; this authorises refinement, not
implementation.0145/0146/R7/R9 and Inventory contracts remain governing accepted authority.
Q52/Q53 await final review/documentation authority; Q23/Q24/Q16 retain their own unresolved scope.

## 1. Q52 — recommended coverage/declaration rules

Keep three independent axes: individual-source valuation, labour expense coverage, whole-Job
cost completeness across applicable required categories. A COSTED15000.0000 source does not
prove all labour expenses captured. Confirmed labour coverage cannot establish complete material,
plant/subcontract/overhead cost or financial settlement. No-source is not known-zero.

| Coverage status | Recommended bounded meaning |
|---|---|
| Confirmed complete | Delivered authoritative source/writer inventory and reconciliation confirm the named scope/date basis; not an unlimited real-world audit claim |
| Declared complete | Eligible accountable person declares all known applicable expenses are represented for named scope/through-date/source version; retain human basis, never auto-confirm |
| Incomplete | Known omitted source, dispute, unresolved reconciliation or evidence contradicting completeness |
| Unassessed | No valid current assessment, including stale scope/version without a determined omission |

Recommend one optional SIMPLE action: 'All known applicable labour expenses for [scope] through
[date] are represented.' Retain actor, actual recording time, scope, through-date/known-as-of,
relevant source/version fingerprint, evidence basis and any explicit limits. No mandatory hours,
named-worker records, payroll or independent approval. Declaration neither creates expense nor
completes a Job. Reuse eligible cost-reconciliation authority only if exact duty/scope is proven;
record-only access is insufficient. Final vocabulary/grants remain gated, not introduced here.

At assertion, show scoped recognised sources, pending/disputed/omitted records and valuation gaps.
Known omissions or unresolved ordinary recognition/dispute cannot be overridden. A captured,
recognised but unpriced source is represented: coverage may be declared complete while valuation
remains incomplete, with the gap explicit. Do not equate missing RATE with missing SOURCE.
Restricted visibility cannot justify whole-Job completeness: refuse or explicitly narrow scope.

New contradictory/relevant source evidence invalidates current complete use immediately under
version checks: known missing/omitted work means INCOMPLETE; otherwise UNASSESSED pending review.
Material corrections trigger reassessment. A valuation-only correction can preserve coverage only
where dependencies prove covered source set/scope unchanged; valuation result/version still changes.
Never silently extend through-date or auto-promote declaration after closure, time or integration.
Preserve original declarations/closeout/report snapshots; current reports disclose current status.

Optional coverage cannot block supported completion or require fabricated optional records.0145
closeout still reconciles known required actuals/remainder and known valuation follow-up. Missing
optional data can remain honestly unassessed. Known omissions/unknown downstream impacts cannot be
hidden by a light workflow. No total is labelled final/fully reconciled merely because sums compute.

An empty source list may be declared as having no omitted known SOURCES only without manufacturing
a monetary zero. Explicit zero-cost assertion is a separate [research case](phase-5-zero-cost-assertion.md),
not part of ordinary coverage authority. Initial recommendation: leave zero assertions disabled
until its evidence/authority choice is approved; do not restrict ordinary completion/direct amounts.

## 2. M1 — exact prerequisite reconciliation and proposed release boundary

The [roadmap](README.md#phase-5--job-execution) currently requires CONTAINER_TRACKED,
package-aware previews and waste/residual bands before material-issue design. STATUS currently
defers container tracking until requested.0052 accepts progressive modes, not this sequencing waiver.
Both texts are preserved; grouped v1 does not fulfil individual-container requirements.

Recommended owner decision is a formal bounded first release, not global deletion of prerequisites:

> Permit initial Job material design for company-owned, fixed-content QUANTITY_ONLY and existing
> PACKAGE_BALANCE paths only where operation/valuation/physical evidence is supported. Preserve
> container identity, variable-content, exact remnant selection and their preview/variance obligations
> for the paths that require them. No downgrade, scalar bypass or relaxation of applicable controls.

If approved, add a scoped exception to the roadmap's sequencing sentence and retain its original
broader obligations as deferred path-specific gates. Do not alter accepted0052 or pretend container
functionality is delivered. Technical contract and code still need separate authority. Until this
decision, business refinement continues but no detailed material implementation contract is final.

Initial plan/draw can be manual where supported, revalidated against fresh physical evidence.
Existing standard/planned/selected/actual distinctions and applicable material variance controls
remain mandatory. No exact pack/container optimisation or unsupported waste/residual-band claim.
A source requiring those controls is refused until its real adapter exists, not silently treated
as an uncontrolled direct Item. This narrowing trades capability breadth for a correct first path.

## 3. Supported-path matrix — Inventory primitives versus future Job support

Inspection8810874,2026-10-16; source/code/contracts only, no new runtime tests. 'Candidate' means
business proposal for future integration, not an IMPLEMENTED/VERIFIED Job operation. All candidates
require owning Job/demand/stock-source/recognition/closeout and0040 correction adapters before enablement.

| Item mode / operation | Existing Inventory evidence | First Job-path recommendation / added boundary |
|---|---|---|
| QUANTITY_ONLY, AVCO/FIFO immediate actual-use issue | PostInventoryMovement::issuePosted; stocked Item, exact stock UoM, eligible location/QA/tracking/valuation rules | Candidate; guarded Job source + actual valuation attribution/corrections, current authorised replay |
| QUANTITY_ONLY, AVCO/FIFO custody transfer and back | transferPosted, source/destination layers/pools | Candidate; eligible company-stock site/vehicle locations, scope at both ends, Job custody reference not another balance |
| QUANTITY_ONLY serial/SPECIFIC | Core has tracking-aware posting and specific allocation | Not absent from Inventory, but defer first Job exposure until explicit serial/source/correction support verified |
| PACKAGE_BALANCE, supported AVCO/FIFO actual-use issue | IssuePackagedStock: SEALED/LOOSE; UNPACK/OPEN_AND_DRAW through recorded opening, actual allocator vector | Candidate; fresh reconciled predecessor, tracked levels, exact draw, package view/issue and opening authority where needed; atomic Job integration |
| PACKAGE_BALANCE custody transfer and back | TransferPackagedStock, two evidence legs; sealed stays sealed, opened/loose arrives loose | Candidate; fresh destination evidence or truly empty destination; no invented container identity |
| PACKAGE_BALANCE serial/SPECIFIC, shortage, stale/QA-dependent evidence or conflicting backdate | Grouped path refuses unsupported combinations/history | Refuse; no scalar fallback or downgrade; first Job scope adds no negative-stock override |
| CONTAINER_TRACKED / exact vessel/remnant selection | Mode accepted, individual tracking/operation adapters not delivered | Unsupported first path; preserve future identity/custody/return/preview/variance requirements |
| Variable-content/catch-weight | Fixed pack factor cannot express measured container contents | Q23 deferred, refuse physical claims needing its dual quantities |
| Receipt/QA/count/receipt reversal/rejected-stock exit | Existing specialised owner operations | Reuse their actual contracts only; receipt reversal or supplier scrap is not a general return of Job-consumed stock |
| Material Preview / stock availability | Advisory QUANTITY_ONLY proposal; no lock/reservation promise | Planning only; label NOT RESERVED; actual package draw/stock posting revalidates current facts |

Frozen/provisional AVCO, open shortages, unsupported historical contexts and other valuation exceptions
need explicit actual-cost certainty/correction support; being physically on hand alone is not sufficient
for first-path eligibility. Do not pretend Inventory's broader supported methods are globally disabled.

## 4. M2 — two small practical workflows

**Direct use:** repairer genuinely uses2 stocked fittings straight from the warehouse. Guarded Job
consumption posts2 through Inventory and attributes its authoritative posted valuation once. No site
warehouse/transfer, manufactured Assembly or production Work Order is required.

**Custody then use:** installation needs10, transfers12 to a supported site/vehicle location, uses9,
returns unused3 through Inventory. All12 remain company stock while in custody; only9 consumed units
enter Job actuals. Requirement1 remains to replan/dispose. Transfer value is stock custody, not another
Job expense. An existing supported stock-holding location is needed only for the custody path; no
mandatory site setup for direct-use SIMPLE and no parallel stock balances in Job tables.

Returning something already consumed is not this transfer-back workflow. It needs evidence of identity,
condition, returned quantity and owning stock/cost correction. Until a separate supported contract is
approved/delivered, refuse automatic unconsume/credit/re-receipt shortcuts and expose a resolution case.

## 5. M3 — advisory first, later reservations

Initial availability is explicitly NOT RESERVED, with live posting revalidation. Job demand neither
owns stock nor guarantees it. Two Jobs may see the same stock; later shortage is an honest outcome.

Explicit reservations remain Inventory-owned managed research, not a v1 feature or acceptance effect.
Their later contract must include eligible stock identity/ownership, concurrent writers, partial use,
expiry, release, reassignment and reconciliation with count/QA/transfer/replay. Holding/cancelling a
Job does not silently release stock or accepted scope. No new reservation policy, table or timeout
is selected here. Deferral permits the advisory workflow, not exclusive promises.

## 6. M4 — Q24/Q23 inclusion and deferral

| Exception | Initial boundary / what blocks |
|---|---|
| Ordinary company-owned stock | Included candidate only after source/package/valuation/correction gates |
| Customer-owned/free-issue | Deferred owning custody/value adapter; blocks posting THIS stock, not unrelated company stock; no zero-cost company receipt. Known third-party obligations cannot be hidden at closeout |
| Substitute Item | No generic substitution workflow. A different Item requiring technical/customer consent blocks until authorised mapping/change; ordinary correct-Item use unaffected |
| Recoverable scrap | Deferred condition/output/value treatment; cannot credit original consumed stock automatically |
| Grouped loose/open remainder | Existing aggregate composition retained where sufficient; not a new identified remnant asset or scrap recovery |
| Exact reusable remnant/container identity | Deferred container-dependent path; blocks work promising specific vessel identity/remnant tracking |
| Returnable container | Separate ownership/return obligation; no forced extension for ordinary stock without that requirement |
| Variable-content/catch-weight | Q23 gate; fixed-content paths unaffected, no invented factor |
| Previously consumed material return | Separate stock/condition/valuation correction gate, not ordinary unused-stock transfer |

These exceptions do not globally block a genuinely ordinary company-stock workflow. They do block
any route that needs them or would otherwise misstate ownership/quantity/value. Q24 is not closed by
excluding exceptions. If a first customer depends on one, prioritise its real adapter before claiming
their end-to-end workflow. No 'note' provides authoritative third-party stock control.

## 7. M5 — required actual valuation and0040 delivery gate

Use authoritative posted Inventory valuation/allocations, never quoted or advisory preview cost.
Link Job consumption to actual source/move, relevant layers/pool/valuation facts and posting basis.
Original evidence remains; correction affects the same economic source once. No duplicate issues.

Before enabling ANY supported Job-consumed stock path, deliver the necessary0040 Job correction
participant, economic ownership routing and reconciliation. Refusing unsafe affected replay remains
a safeguard, NOT an alternative that permits activation without required correction integration.
This tightens the previous research's either/or wording to match owner's explicit delivery gate.

Example:9 units cost900.0000; approved recalculation attributes90.0000 to that consumed Job scope.
Append90.0000 valuation adjustment → effective990.0000; physical consumption remains9. Preserve
package/version, original/counterfactual evidence, historical currency/FX, attribution and idempotent
receipt. Accounting represents the economic allocation, not another cost to sum. Implemented local
mandatory participants commit together; unsupported/deferred external owners follow accepted refusal/
pending/durable-obligation rules, never unsafe partial local posting followed by a note. No new period
reopen or Job restart. Distinguish physical consumption correction from valuation-only adjustment.

## 8. M6 — authority, transactions and closeout

Demand/planning/report/progress authority is independent from Inventory movement posting, warehouse
scope and valuation read. Reuse owning Actions/RecordScope and applicable review. No broad Job right,
new grants or HTTP-only guard. Q16 source-actor question remains separate; source records must supply
truthful actor/occurrence/recording evidence regardless of the future movement posted_by choice.

Future implementation must prove real same-connection transaction participation and compatible lock
order across Sales allocations, Job/source, physical locations/pools/package state and correction
owners. Recheck company, duties, scope, lifecycle including all holds/termination, source readiness,
stock evidence and generations under locks. Every conflicting writer participates; protect absent
anchors with stable identities/constraints. Key replay is currently authorised; changed keyed intent
conflicts; fresh keys cannot duplicate business consumption. The scalar issue primitive currently
deduplicates source_type/source_id before fresh payload checks; it is not itself an exact-intent,
actor/scope-aware Job receipt. The owning adapter must enforce these before calling/replaying it.
Existing package payload hashes include
actor and predecessor, so outer Job replay must resolve its original receipt without inventing new
downstream payload after progress. Internal posters/PackageWriteScope flags are not public bypasses.

0145 holds/termination block new ordinary consumption; historical reports/corrections remain governed.
Before closeout reconcile or explicitly dispose unconsumed custody stock, reservations if any,
remaining demand and required source corrections. Supported reallocation may retain company stock
elsewhere; no requirement to physically return everything to origin. A reason-only note is not safe
disposition where owning effects/obligations remain unknown. Closeout never erases actuals or proves
cost completeness.0146 three-axis reporting applies independently to material costs.

## 9. Testable business scenarios — proposed acceptance, not tests run

| ID | Required observable result |
|---|---|
| CM01 | COSTED15000 source + unassessed labour → no automatic complete Job total |
| CM02 | Declared labour complete + unpriced represented source → coverage/valuation separately labelled |
| CM03 | Known omitted/disputed/ordinary-pending source → cannot override with declaration |
| CM04 | Declaration races with new expense/correction → no stale current complete status; original retained |
| CM05 | Empty sources → no zero; ordinary declaration authority cannot invoke pending zero-assertion feature |
| CM06 | Direct use2 → one issue/cost, no compulsory site/transfer |
| CM07 | Transfer12/use9/return3 →9 consumption, no transfer double cost, requirement1 explicit |
| CM08 | Grouped partial draw → fresh atomic package evidence; actual allocator cost, no can identity claim |
| CM09 | Stale predecessor/held/short/unsupported package combination → refuse without scalar fallback |
| CM10 | Two Jobs see same stock → NOT RESERVED; posting race cannot overconsume |
| CM11 | Customer-owned/variable contents/consumed-return request → affected unsupported path refuses, ordinary stock unaffected |
| CM12 | Any required0040 participant unavailable → Job consumption activation/unsafe posting refused |
| CM13 | Valuation correction90 → amount990, quantity9, no duplicate issue/cost on retry |
| CM14 | Failure after stock/package posting or correction → required local effects/Job attribution/receipt roll back together |
| CM15 | Hold/start/use/cancel/close race → effective lifecycle and stock obligations conserved |
| CM16 | Close with custody/demand/reservation/correction outstanding → owning reconciliation/disposition required |
| CM17 | Planning-only or warehouse/cost-denied user → cannot post/disclose through API/history/receipt/print/export |
| CM18 | Repeated scalar source with changed intent → owning Job adapter refuses; primitive dedup alone cannot pass exact replay |

## 10. Remaining owner decisions and evidence

Confirm the exact bounded roadmap amendment in section2, including manual supported draws without
unsupported preview claims. Confirm deferred exception/serial/negative/historical boundaries and
required adapters before initial support. Coverage's scoped declaration/refusal/invalidation rules
are refined here for final review. Zero assertion authority/evidence is separately proposed, not
needed for ordinary first workflow. Later reservations and consumed-return contracts await their own
research; do not ask owner to choose arbitrary implementation mechanics now.

Reuse [prior material primary comparison](phase-5-material-flow-research.md#primary-comparison--accessed2026-10-16)
and [labour research](phase-5-labour-evidence.md). Existing research answers custody/ownership versus
valuation; no repeated ERP catalogue. [Zero-assertion research](phase-5-zero-cost-assertion.md) records
fresh bounded primary comparison. No accepted ADR conflict identified; no new ADR accepted.
No application code, grants, migrations, tests or implementation-owned delivery records changed.

## Related

- [Q52](../OPEN-QUESTIONS.md#q52--simple-labour-cost-coverage-declaration) · [Q53](../OPEN-QUESTIONS.md#q53--phase-5-material-flow-and-package-prerequisites)
- [Coverage basis](phase-5-cost-coverage-review.md) · [Material research](phase-5-material-flow-research.md)
- [Phase5](phase-5-job-execution.md) · [Package issue](../domain/package-issue-operations.md)
- [0040 corrections](../domain/stock-recalculation.md) · [Lifecycle](../domain/job-execution-lifecycle.md)
