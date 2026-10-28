[← Receiver delivery handoff](phase-5-r7-job-receiver-verification.md)

# Task G — Sales R7 to Jobs dependency-ordered batch proposal

2026-10-27 · #52 · DESIGN/PREPARATION ONLY. Every implementation batch needs separate owner
authorisation and an assigned module owner. No code, migrations, routes, grants or activation.
ADRs 0125–0127, 0131–0132/0136 and 0145–0152 remain unchanged; no Phase 4 contract rewritten.

## Baseline and actual delivery boundary

Branch starts at origin/develop `5f776203ee4cca3fd7e35aef92369a9d9099cf8d`, after Task F submission.
Reuse [CA slices](phase-4-r6-implementation-slices.md), [HA slices](phase-4-r7-implementation-slices.md),
[coordinated handoff](phase-4-implementation-handoff.md), [Task A](phase-5-r7-job-receiver-design.md),
[Task E](phase-5-task-e-source-integration.md) and [Task B](phase-5-task-b-j1-execution.md).
Existing accepted research is sufficient; this supplies bounded delivery ownership/order.

| Inspected current artefact | What it proves / what it does not |
|---|---|
| Sales quotation foundation + draft Actions | Real family/revision/logical-scope keys, draft preparation/preview; revision CHECK allows DRAFT/CANCELLED_DRAFT, not issued authoritative business |
| #30 QL1b read adapters | Integrated guarded draft read/preview, duties STAGED; not issue, customer acceptance, released allocation or access activation |
| Sales document binding/snapshot schema | Integrated immutable binding machinery, internal binder, no actual issued snapshot writer; document binding is neither consent nor fulfilment |
| Jobs SP0b #28 | Four disabled tables, shape validator, append-only/tenant keys; input DTO has no acceptance/allocation/stage FKs and no receiver caller |
| Task A/B/D/E design documents | Required contracts and future verification, not implemented receiver/lifecycle/readiness/correction or permission rollout |
| Shared file foundation #47 design / Documents foundation | Neither establishes CA binary intake/access/download adapter; textual evidence may be independent, method-specific binary requirements stay gated |

Read actual migration, Sales Actions, Jobs ReleasedServiceInput and module READMEs. Their historical
README counts/status wording can lag code; the actual classes/constraints above are the inspected
boundary. No IssueQuotation, customer-acceptance or R7 release Action was found in inspected Sales
Actions. No draft row, fixture, observed PO, PDF/signature flag or trusted DTO can substitute.
Agent A queue #38 currently authorises QL1b-2 WRITE-adapter preparation only. This proposal neither
messages/reassigns Agent A nor adds a coding task to that queue. Coordinator must assign any batch.

## First supported path and authority boundary

Candidate first receiver: one FULL_ONLY independently acceptable fixed SERVICE_ITEM deliverable,
one acceptance component/root allocation/stage/purpose and one Job/default activity. Genuine source
acceptance still needs issued terms, verified unchanged consent, current claims and supported policy.
No MANUAL descriptor, stock, Assembly, fractional fixed deliverable, cross-acceptance consolidation,
technical split, mixed dependent release, goods Order or fake 1-job operational quantity.
Commercial offered quantity/UoM remains provenance; unmeasured operational measure/quantity are both
absent. Completion conditions must be canonical, immutable and versioned at source, not invented in
Jobs from Item text/current defaults. Missing authoritative condition shape blocks affected release.

Sales owns agreement/claims/allocations/stages and release coordination. Jobs owns receiver/origin/
baseline/activity/events and its independent guard. Release is not start/fulfilment, and receiver
activation is not J1 end-to-end sign-off. A bounded received RELEASED Job may precede later start/
complete/close implementation only under an explicitly reviewed supported-path activation plan;
no general executable Job or public pilot claim follows. Assignment/team absence at acceptance
does not invalidate agreement; release/execution prerequisites remain source/path specific.

## Ordered implementation candidates — none authorised here

| Order / candidate | Scope and owner | Dependencies / completion gate |
|---|---|---|
| G-A0 / CA0a disabled intake foundation | Agent A, Sales-only case/component/textual evidence storage + scoped models and integrity tests; no Actions or entry points | Existing Parties/quotation identity FKs only. No issued source, acceptance or Job assumed. Exact files in companion; smallest candidate Agent A could implement first after authority |
| G-A1 / remaining CA0 + QL2/QL3 prerequisites | Sales issue/claim/source-policy/baseline contracts and exact supported frozen issue, FR/PR/control evidence, numbering and required document manifest binding | Real supported SERVICE_ITEM financial/tax/control source, company policy and R9 review. No Assembly-only #29 shortcut or blanket dependency if path does not use it; missing mandatory supported control still refuses |
| G-A2 / CA1 + CA2 full unchanged consent | Sales textual intake/observations, ordinary full authoritative acceptance and lines/claims/entitlement evidence/receipt; no target | Actual ISSUED source/terms/selection and consent-policy proof; source/claim/family/option locks and all conflicting QL writers. Binary bridge only for methods requiring it |
| G-A3 / HA0 + HA1 disabled/preparation | Sales plan/root allocation/stage/immutable baseline/readiness/receipt structures; safe Preview/Prepare with no target or stock commitment | Real accepted component/claims, exact canonical fixed-deliverable condition contract, source/path registry and generation fences; prepare duties STAGED until separate rollout |
| G-A4 / source verifier + HA2 coordinator | Sales-owned typed canonical source verifier and ReleaseHandoverComponent; caller receipt/business replay, source authority/fences, staged readiness | G-A2/3 real data and jointly implemented G-J1 receiver. Missing verifier or real target refuses. No independent successful release without target |
| G-J1 / HA3b first service receiver | Jobs-owned same-transaction internal ReceiveReleasedService, origin/source FKs, baseline components/default activity and generation/event/result | Actual Sales keys/verifier/held fences, source conditions, Numbering and job.receive/JobAccess; paired with G-A4 transaction/race proof |
| G-R1 / integrated disabled proof then rollout | Sales + Jobs implementers, shared access/security and integrator | Real fixture-independent issue→accept→prepare→release→Job tests; source-specific restriction proof; explicit holder/configuration approval. Duties first STAGED, ACTIVE only after own gates |
| Later bounded operations | Owning HA4–6/Jobs lifecycle/source participants | Disjoint/measured branches, schedule changes, holds/dispositions/reductions, withdrawal and historical corrections each need affected actual writers/ports; no implicit claim release |

G-A0 can be independent. G-A1's issue/remaining CA0 can be split by Sales owner into smaller reviewed
sub-batches, but actual issue and acceptance cannot bypass each other's needed identity/claim/control
contracts. G-A4 and G-J1 may land disabled schema/contracts in dependency order; no live half is
activated between them. Existing QL1b-2/QA1–QA3 access work is separately owned: reconcile exact
read/write scope before new public Sales surfaces, not by assuming company-wide access is permanent.
Do not copy draft adapter status/error behaviour blindly into acceptance/release contracts.

## Mandatory Sales deliverables before receiver activation

1. Real immutable issued revision/scope/terms, applicable acceptance entitlement/policy and consent
   evidence/verification; stable component identity, Party/family lineage and current claim anchors.
   Full acceptance of independently acceptable scope creates agreement once, no target automatically.
2. Acceptance lines/claims/benefit-budget consumption with current effective caps and generation;
   supported entire fixed component, no invented proportional technical claim. Competing option/
   family/replacement/withdrawal/late-correction writers share roots and fail closed where unsupported.
3. Plan/allocation/stage/source baseline with real composite keys, parent relations, source contract
   version, path policy, canonical completion conditions and complete supported-path readiness.
   Source generation advances for every relied-on change, including imports/background writers.
4. Sales-owned verifier resolves actual source chain on coordinator's exact runtime connection under
   already-held source fences. It returns canonical positive data or non-positive refusal, never
   accepts client authorised=true, status/token/lock assertion or unverified input as proof.
5. Sales coordinator locks/revalidates authority, roots/caps/stage/approval/readiness, invokes real
   Jobs receiver and atomically persists all source/target/baseline/receipt/result effects. Canonical
   business identity is separate from caller key; fresh actors/keys do not multiply capacity.
6. Independent Sales release/Jobs receive duties and reviewed safe fields/holders, actual transaction/
   numbering/lock/race evidence and deployable supported-path restrictions. No mandate or admin-name
   inference gives Jobs rights. Task F's strict no-growth requirement is a separate access gate.

Jobs independently resolves company/receive capability/ALL scope and validates canonical chain.
Sales has no direct Jobs writes or Jobs FK; Jobs never locks Sales roots or calls a late inverse
Sales lock callback. Sales verifier owns read resolution without inverted lock acquisition.

## Shared transaction, identity and readiness contract

Candidate order retains Task A's Sales company coordination fence→sorted accepted/claim roots→
allocation→stage→Job before child origins/receipts, with Numbering positioned under joint review.
Actual company/approval/source/downstream fence names and order remain GATED; Task E documents real
Inventory/Purchasing orders that must be reconciled, not overwritten. All writers including absent
anchor creators, receipt lookup/replay and source corrections participate. Snapshot/hash equality
without writer fencing is insufficient. No Numbering-before-source-lock creator/import shortcut.

Use one outer transaction/runtime connection; nested helpers are savepoints, no early commit,
queue-only receiver or external mandatory effect. Throw after real Job insertion rolls source roots,
stage result, baseline/activity/origin/event and all receipts back. Current authority precedes replay;
identical original authorised effect returns safe original references before obsolete freshness
checks, no new code/numbering/baseline. Changed keyed/semantic intent conflicts; unique/deadlock
recovery occurs after whole rollback and renewed checks, bounded, never on an aborted transaction.

Receiver identity is (company,stage,purpose,business_identity), plus independent UNIQUE(company,
stage,purpose) preventing a second target with a new business identity. Sales keeps typed opaque
result reference to Jobs origin; Jobs owns real source FKs and parent consistency. No circular
service_jobs head pointers or independent mutable source/target receipt ledger is added.

Reconcile Task E: no missing/denied module/callback/empty query becomes N/A/NONE. Service-only release
may exclude material/supplier paths only with actual owning writer inventory and enforced supported
path restrictions. Known obligations/unknown mandatory effects block affected operations. Inventory
consumed paths require0040 BEFORE first activation; supplier services require0149 acceptance/portion/
correction integration. These are affected-path gates, not blanket requirements for every fixed
service Job. Closeout/later fulfilment source gates remain separate from initial receiver receipt;
no stub declares future closeout resolved. Optional J2 absence never blocks valid completion-only J1.

## Reviews and exclusions

Sales/Agent A reviews acceptance/source/claims/fences and exact physical names; Jobs implementation
owner reviews actual receiver/schema/numbering/F1 compatibility; shared access owner reviews Sales
scope work and Jobs strict no-growth/holder rollout. Inventory/Purchasing owners review affected
writer/obligation/correction contracts. Documents/shared file owner reviews only actual required
binary/issued binding path; no universal PDF/portal requirement. Integrator confirms reservations,
dependency SHAs, paired activation and independent-session tests; human owner separately authorises
each code batch and exact-commit rollout. No requested coding assignment or auto-start is made here.

Excluded from first source/receiver: partial/divisible acceptance, mixed atomic groups, goods Order,
stock consumption/reservations, Assembly/technical splits, MANUAL fallback, claim release, commercial
repricing/increases, ordinary closed restart, J2 payroll/costing/Finance, file engine/renderer and
general manager/team/delegation rights. No migration/route/UI/grant/activation in this planning task.
Four protected status/overview documents remain untouched; E/C branches/designs unchanged.

## Related

- [Exact proposed files/tables, migration order and tests](phase-5-task-g-r7-delivery-matrix.md)
- [Receiver contract](phase-5-r7-job-receiver-design.md) · [Task E](phase-5-task-e-source-integration.md)
- [R6 slices](phase-4-r6-implementation-slices.md) · [R7 slices](phase-4-r7-implementation-slices.md)
- [Receiver verification](phase-5-r7-job-receiver-verification.md) · [R9 rollout](phase-4-r9-permission-rollout.md)
