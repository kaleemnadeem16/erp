[← Phase 5 planning](phase-5-job-execution.md)

# Task E — Jobs source integration and closeout coordination

2026-10-26 · #43 · PROPOSED DESIGN ONLY. No code, migrations, routes, duties, grants or activation.
ADRs 0040/0145–0152 and accepted Phase 4 contracts are unchanged. Technical interface candidates
are not delivered adapters. J1 completion-only is independent of optional J2 labour/cost reporting.

## Baseline and evidence

Branched from origin/develop `d4746d7d` after Task D submission. #41 authorises D→E→C.
Owner directed sequential planning after Task B resubmission; #34 remains under review and is
untouched. Helper dependency refusal and planning ownership exception are recorded on #43.
Reuse [Task A receiver](phase-5-r7-job-receiver-design.md), [0145 lifecycle](../domain/job-execution-lifecycle.md),
[0151 closeout](../domain/job-closeout-reconciliation.md), [0148 materials](../domain/job-material-attribution.md),
[0149 external sources](../domain/job-external-cost-attribution.md) and
[0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md).
Task B closeout is a reviewed design input pinned to
[981ce196](https://github.com/kaleemnadeem16/erp/blob/981ce19608ef83fd9381be35db360fc4866e35e0/docs/phases/phase-5-task-b-j1-closeout.md),
not integrated runtime proof. Existing research suffices; no business decisions are reopened.

Inspected Jobs README/SP0b, Purchasing README, PostInventoryMovement, ExecuteRecalculation and
CancelPurchaseOrder. SP0b provides disabled storage only; Purchasing currently owns goods receipts,
not reusable service acceptance. Inventory has real posting/recalculation workflows, but these
do not prove a Job-consumed correction participant, source readiness or common lock protocol.
ExecuteRecalculation currently locks company→request→impact version→locations→valuation pool.
CancelPurchaseOrder locks its PO header. These are evidence of orders to reconcile, not permission
to impose a guessed new order on their owning modules. No code changed during inspection.

## Ownership and required contract deliveries — all GATED

| Owner / path | Authoritative inputs/effects to deliver | What Jobs may consume; unsupported result |
|---|---|---|
| Sales R6/R7 | Real accepted SERVICE_ITEM/component, root allocation/stage/baseline, completion conditions, canonical verifier and source generations; release/reduction/hold/withdrawal/fulfilment/remainder receipts | Same-transaction verified source and immutable references. Draft quotation/document binding/SP0b fixture is not acceptance. Missing verifier refuses release/execution path |
| Jobs | Real receiver/activity/component integration; lifecycle evidence, safe readiness orchestration, original closeout and later resolution lineage | Own local facts only. Source obligations are not copied into mutable local shadows or marked done by checkbox |
| Inventory0148/0040 | Consumption/custody/demand identity, original issue allocation/value, applicability/writer inventory, correction-package Job participant and required receipts | Inventory-owned facts and safe obligation verdict. No Job stock balance, PO-as-consumption, scalar package fallback or local recalculation |
| Purchasing0149 | Reusable service acceptance/portion identities, accepted/disputed/rejected/remaining/terminated work, issued terms, hire/off-hire and cancellation/short-close/reallocation/correction participation | Technical Job evidence may feed owning acceptance; never a second supplier acceptance or fictitious goods receipt |
| Labour/external source owners | Known ordinary pending reports/recognition, disputed performance and supported corrections, or evidenced exclusion | Safe non-monetary obligations only for J1; absent optional entry is not a pending timesheet or zero cost |
| Security/coordinator | Each operation's safe read/duty/ALL-scope proof, named holders, source-writer inventory and joint lock/session review | No assignment/admin/cost-read inference. No vocabulary/grants introduced by this document |
| Future Finance/Tax | Applicable invoice/period/tax/FX/correction ownership and acknowledged supported follow-up where required | No fabricated AP/GL/settlement. Full Finance is not a universal J1 prerequisite; unknown mandatory impact blocks its affected path |

Each contract requires actual owner-reviewed implementation SHA, real keys/versions, all writer paths
and passing integration evidence. Design/issue approval, a registered callback or stub success is
insufficient. No current upstream file is modified to make a dependency appear available.

## Candidate readiness envelope and safe result

Each required source family returns company/Job/component, contract/policy version, applicability
basis and complete source inventory, source head/generation, checked lifecycle/baseline context,
assessment identity/time, result and owning evidence/disposition receipt. Private details stay in
owning storage; the close actor receives only reviewed non-monetary reason/next-action projection.
The full private vector is retained for integrity, never sent as a hash or identifier side channel.

| Result | Meaning and close behaviour |
|---|---|
| RESOLVED | Known applicable obligations and mandatory effects actually resolved at pinned generations |
| NOT_APPLICABLE_WITH_EVIDENCE | Source owner proves supported-path exclusion using enforced writer restrictions and authoritative inventory; not merely an empty query |
| BLOCKING | Known unresolved ordinary report, quantity dispute, work/remainder, custody/demand, supplier commitment or required correction; close refuses with safe next action |
| UNSUPPORTED_UNKNOWN | Missing adapter/inventory, denied necessary source check, incompatible contract or unknown impact; close refuses, no NONE/N/A conversion |
| SUPPORTED_VALUATION_FOLLOW_UP | Quantities/performance/ownership resolved; only permitted valuation remains, with actual supported correction path and eligible acknowledged owner; no mandatory local effect deferred |

Inventory comes from supported path policy plus every owning writer, not discovery of installed
callbacks. Closed-world family inventory is versioned; a newly applicable family invalidates the
prior assessment. Optional unconfigured labour is not invented as an applicable missing report.
Known out-of-path facts nevertheless block until resolved; UI absence is not exclusion proof.
An internal verifier's necessary service-level read is distinct from the human's broad source
visibility. If a reviewed safe projection is unavailable, refuse; do not give costs to close-only users.

Eligible J1 can complete with fixed-deliverable attestation and close with resolved/evidenced-N/A
families, without timesheets, money, coverage declaration or job.cost.view. This needs actual
source exclusion/readiness proof; the planning scope alone proves none. Valuation-only follow-up
is disabled until delivered, never a generic waiver. Owner departure requires governed successor
eligibility/acknowledgement; notes, dates or arbitrary assignee IDs do not satisfy that contract.

## Source-specific obligation handling

Sales release runs only through its coordinator; resolve true acceptance/root/stage/baseline chain
and canonical pinned conditions on the same connection. One target per stage/purpose and stable
business identity prevent fresh-key duplication. Release is not start or fulfilment. Completing
Jobs attests the pinned obligation, never implicitly changes agreement or cap. Cancel/stop/reassign
does not release a Sales claim. Remainder successor/reduction requires owning coordinated receipt;
failure preserves old responsibility/cap. Ordinary fixed deliverables do not acquire fake quantities.

Inventory custody is separate from consumption: transfer 12, consume 9, return 3 attributes 9 only;
remaining demand is resolved independently. Supported retention elsewhere requires actual custody/
responsibility disposition, not mandatory return to origin. Every consumed path requires0040 before
first activation, including ordinary quantity-only and grouped package paths. No scalar downgrade.
Value-only correction of 900.0000 by 90.0000 produces 990.0000 and no quantity change, linked to the
original consumed allocation and package/impact version. Physical corrections are distinct.
Preserve exact approved historical currency/base delta and allocation basis; no current-rate recost.

0040 Inventory coordinator owns impact/risk/approval/period validation and method-specific dependency
closure. Jobs participant validates original consumption/Job/source identity, finalised-state boundary,
current correction authority and exact approved allocated effect. Its immutable owning correction
receipt, effective attribution/version and required coverage invalidation commit with Inventory's
mandatory local effects. Sum economic allocation to the approved delta; GL/Tax/outbox representations
are not extra economic allocations. Unsupported required Job participant blocks consumed activation
and affected correction; never commit Inventory then mark a failed internal Job participant pending.
0040 explicitly outside-atomic obligations retain owner/basis/status/monitoring and linked resolution;
their existence never permits false FULLY_RECONCILED or waives a mandatory synchronous effect.

Purchasing acceptance records performed service/chargeable portion, not customer fulfilment. Preserve
60 accepted/10 disputed/30 remaining as distinct portions, not 70 accepted or 40 cancelled. Short-close,
cancel, transfer and correction must integrate affected service allocations/writers before activation.
Job stop alone cannot cancel a supplier commitment. Hire needs supported period/off-hire/custody facts;
presence and productive hours do not prove chargeable days. Pending disputed/unaccepted ordinary work
blocks close; missing valuation can use only a genuinely delivered valuation-follow-up contract.
Later invoice confirms/replaces/adjusts original components once; goods acquisition differences route
Inventory/0040, not direct external expense. No invoice/acceptance/attribution adapter delivered here.

## Transactions, lock compatibility and race proof

Assess is advisory. Final close uses one outer transaction and revalidates exact Job outcome,
holds/termination/baseline, authoritative family inventory/generations, dispositions and approvals
under fences that every conflicting writer participates in. Record original assessment/closeout,
event/head/operation receipt and all mandatory owning effects atomically. After-commit notifications
are ancillary; a queue message cannot stand in for a target/correction receipt.

Candidate partial order for joint review: existing company coordination root where actually shared
→ Sales accepted roots/allocations/stages → required Purchasing headers/lines → Inventory request/
impact/dependency locations/pools/layers/package anchors → Jobs (sorted IDs) → local source/effect/
receipt anchors. This is NOT an adopted universal order. Every branch/replay/receipt and absent-anchor
path must be audited against actual writers; Inventory dependency closure may force a different
reviewed placement. Never lock Job then call an owner that takes a source root before Job.
Source owner acquires its earlier locks and invokes Jobs, not an inverse Jobs→Sales lock callback.
No new global lock service is proposed. A Job-only lock or equal hash cannot protect source writers.

Before any affected enabled path, each owner supplies exact physical fence/key, acquisition order,
generation advanced, competing writers (HTTP/Action/import/worker/correction/cancel/short-close/
hold/release/approval/coverage) and absent-row protection. Reconcile in a jointly reviewed acyclic
order; if it cannot be proved, affected command stays GATED. Preflight outside protected roots
cannot authorise commit. Read isolation or generation equality alone cannot fence a later source write.

Runtime connection/backend session identity is asserted across all participants, including nested
savepoints. No separate connection, early commit, external mandatory call or after-commit posting.
Current company/duty/scope/access is checked before replay and under the reviewed authority fence.
Exact actor/key intent receipt and stable business-effect identities are independent. Existing exact
authorised replay returns original safe references before obsolete mutable prerequisites, with no
new effects; changed keyed/economic intent conflicts. Unique race/deadlock retries occur only after
whole outer rollback, with bounded re-authorisation/revalidation. Never query an aborted transaction.

Source correction/ordinary report/hold racing close must either serialise before the assessment and
be reconciled, or observe CLOSED and use the supported historical correction path. No ordinary new
work after close/termination. Original closeout remains immutable; late contradiction creates linked
resolution evidence/current discrepancy, not reopened execution. Historical occurrence and recording
times remain distinct. Truth capture during hold grants neither execution nor recognition authority.

## Required integration proof — future scenarios, not executed tests

| ID | Required evidence |
|---|---|
| ES01 | Real Sales release with actual keys/verifier, one target/default activity/baseline; fixture/draft/typed trusted flag refuses |
| ES02 | Throw after each owning insertion/receipt → full rollback on same runtime connection/session; nested savepoint cannot hide an early commit |
| ES03 | Two sessions release same stage with fresh keys/actors or race reduction/hold → one conserved target/claim or stale refusal |
| ES04 | Empty registry/missing/denied family → UNKNOWN; positive writer-inventory/path proof alone permits N/A |
| ES05 | Optional absent J2 data does not block supported J1; known ordinary report, dispute, custody/remainder or supplier obligation blocks |
| ES06 | Close races actual/import/hold/correction/commitment/late report → coherent complete vector or refusal, no hidden post-validation write |
| ES07 | Inventory0040 consumed participant absent/fails → no affected activation/partial commit; approved value adjustment once, quantity unchanged |
| ES08 | Failed mandatory Job correction cannot become pending; outside-atomic obligations remain visible and cannot falsely reconcile |
| ES09 | Purchasing partial acceptance/cancel/short-close/hire off-hire and invoice overlap conserve portions/effective value; goods receipt cannot accept service |
| ES10 | Receipt retry after revoke/limited scope/company change → safe denial/projection; no source IDs, hash, money or competing-record leak |
| ES11 | New family/writer after preflight invalidates close inventory; unsupported lock ordering blocks activation, not bypassed by mock concurrency |
| ES12 | Post-close/held historical evidence retains lineage and current discrepancy; unknown mandatory impact refuses applied correction without reopening |

Owners must attach actual dependency SHAs, writer inventories, independent-session barriers and real
rollbacks plus focused module/tenant/scope/permission/write/read ratchets, applicable migration/API/
browser checks. Mock ports prove caller handling only. No runtime tests or source modifications
performed here. Coordinator assigns separate implementation batches; owner authority is required.

## Review and handoff

Decided within this proposal: source ownership, complete family envelope, safe non-monetary verdict,
no NONE fallback, atomic mandatory local effects and source-first coordination. Technical selections
still GATED: actual interfaces/keys, writer fences/order, service acceptance/0040 participation,
canonical completion shape and holder/read policy. Route any actual incompatible upstream contract
through tasks.sh report for its owner; do not rewrite accepted contracts or other modules.
Do not require J2 to implement J1; do not claim source-complete closeout from disabled foundations.

## Related

- [Planning overview](phase-5-job-execution.md) · [Task A verification](phase-5-r7-job-receiver-verification.md)
- [Closeout](../domain/job-closeout-reconciliation.md) · [Stock correction](../domain/stock-recalculation.md)
- [External/Purchasing](../domain/job-external-cost-attribution.md) · [Domain integrity](../architecture/domain-integrity.md)
