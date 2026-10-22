[← Phase index](README.md)

# Task A — R7-to-Job receiver integration design

2026-10-22 · Task #32 · **PROPOSED integration contract for coordinator/Sales/owner review.**
ADRs 0125–0127, 0131–0132/0136 and 0145–0152 remain ACCEPTED. SP0a is integrated as
3b2d4d1 (source 7f74b10), with CI success recorded on #19. This design adds SPECIFIED candidates,
not implemented/verified adapters or PUBLICLY ENABLED service delivery. No implementation authority.

## Scope, current evidence and reuse

Baseline inspected: develop f0e2871, in the isolated planning worktree on codex/phase5-task-a-receiver.
Reuse the accepted [R6](../domain/sales-customer-decisions.md), [R7](../domain/sales-operational-handover.md),
[HA slices](phase-4-r7-implementation-slices.md) and [SP0a](phase-5-sp0a-design.md), including its refined
four-table SP0b. No new ERP comparison or accepted business decision is needed. Scope ends at real
release into a Job/default activity and immutable initial baseline; execution/closeout are Task B,
labour/costing Task C/J2. Inventory ADR 0040/Purchasing acceptance are separately gated affected paths.

| Boundary | Inspected evidence / precise limit |
|---|---|
| QL0 and internal Sales foundations | Committed quotation identity/draft models and PR/FR/FX/control/AU support; no inspected authoritative CA/HA Actions or tables |
| Sales access/receipts | SalesDutyManifest default-deny unless ACTIVE, SalesAccess ALL-only per duty, CrmOperation actor/key hashing/replay; CRM helper is not the release coordinator |
| Documents DF0 | Real BindDocumentSubject and owner access/type contracts on baseline; #11 integrated. No renderer/source acceptance authority inferred; #24 Sales binding and #22 DF1a remain separate deliveries |
| Numbering | NumberingService.allocate uses caller transaction; GAPLESS locks counter, ALLOW_GAPS may leave gaps; real Jobs type/series binding still required at activation |
| Jobs | No Jobs module on inspected baseline; #28 authorises Agent A's disabled four-table foundation, queued, not delivered by this task |
| R6/R7 | Business/contracts ACCEPTED/SPECIFIED; actual source identities, generations, allocation counters, fences and receiver unavailable here |

No application tests rerun. Issue states/branch commits are coordination evidence, never passing runtime
proof. The contract names below map to R6/R7 logical entities; actual SQL names require Sales review.
Do not consume unintegrated worktree changes or infer functioning handover from QL1a (#10).

## Supported source and completion basis

Candidate first path: one authoritative FULL_ONLY accepted fixed-service deliverable, one root
allocation and one initial Job stage, no technical split or cross-acceptance consolidation. Service
Item or explicitly supported descriptor source must already be eligible under its actual Sales
adapter; never fall back from arbitrary manual/stock/Assembly lines to a generic Job.
Fixed-deliverable completion is the SIMPLE default already directed by #32: pin the exact agreed
scope identity, conditions and evidence requirements to the accepted Sales baseline. Release is not
completion or start. Customer signature, PDF, hours or worker name are not universal prerequisites.
Concrete customer-agreed conditions must be supplied by the authoritative source, not chosen by Jobs.

Distinguish commercial offer/pricing quantity from operational completion measure. Sales may legitimately
preserve a commercial quantity/unit without making the deliverable's execution measurable. Jobs copies
that commercial provenance through typed source references, not as a fabricated operational quantity.
Unmeasured operational component has measure_id=NULL and quantity=NULL, explicit deliverable identity,
scope text and versioned completion conditions. No synthetic 1 job, 0 quantity or default sqm.
Genuinely measured scope uses both values, quantity > 0 and the source's exact increment/conversion.
No arbitrary fractional fixed deliverables: Sales conserves whole original component claim identity.
No arithmetic on null measures; remaining obligation is a named outstanding deliverable, not a count
invented by this adapter. Measured partial/disjoint paths remain disabled until exact source support.

## Identity and immutable source binding

Receiver identity K = (company_id, stage_id, target_purpose, business_identity). Business identity is
Sales-owned and stable for this stage/allocation purpose; it cannot be client-selected to bypass
capacity. Stage's one-target contract also prevents another business_identity creating a second target
for the same stage/purpose. Genuine disjoint branches get explicit new Sales allocations/stages; a
stage chain shares its root claim. Fresh UUID, actor or request key is not new business capacity.

Resolve and verify the complete chain under Sales-owned locks:
issued revision/scope → authoritative acceptance/consent component → effective accepted component →
R7 plan/root allocation → stage/purpose → pinned source baseline → Jobs origin/initial baseline.
Party/company/source contract, root anchor, stage parent and baseline must agree. Missing consent,
unverified observation, quotation draft, or mere document binding is not an accepted source.
Prepare/readiness can stay pending without Job. No writable public POST /jobs, import shortcut,
console shortcut, generic accepted flag or unauthenticated callback creates this chain.

Proposed Jobs-owned persistence additions AFTER actual CA/HA tables exist:

| Change | Tenant/source integrity and purpose |
|---|---|
| job_handover_origins | Add real acceptance_id, accepted_component_id, allocation_id, stage_id, source_baseline_id and source generation/version binding; job_id composite FK still points to service_jobs; no unchecked source-ID strings |
| Origin uniqueness | Replace SP0b UNIQUE(company_id,target_purpose,business_identity) with UNIQUE(company_id,stage_id,target_purpose,business_identity); keep UNIQUE(company_id,job_id). SP0b omitted stage because no actual Sales stage table existed |
| Cross-reference consistency | Composite source keys include parent where needed: component/acceptance, allocation/component, stage/allocation and source baseline/stage; enforce via source composite unique keys/FKs or jointly reviewed DB constraint checks, not unrelated same-company FKs alone |
| Stage one-target protection | Sales owns stage target binding and locked release invariant; Jobs unique company/stage/purpose prevents incompatible identities producing two Jobs; never weaken R7 target identity to arbitrary UUID |
| job_baselines | Add real source baseline composite FK and pinned source generation; keep scope_description, completion_rule_version, fingerprint, immutable version/predecessor; no circular current-head FKs |
| job_baseline_components | Same-Job/baseline composite FKs, source accepted-component/original anchor FKs, immutable scope/completion evidence; optional paired measure_id/quantity CHECK, quantity positive only when both present; no application-only pair check |
| job_activities | Arrives in THIS receiver integration slice, one default per Job via unique partial index and commit-time existence guard; no new commercial claim |

All new local evidence is append-only. Jobs migration owns FKs into Sales AFTER actual tables and
required composite target keys are integrated; Sales never adds FKs into Jobs. Sales stores an opaque
result reference to origin ID via the agreed adapter result, not a mutable shadow target ledger.
If required Sales composite keys are absent, raise a contract issue for Sales rather than editing
its schema. Physical table names and width/precision are reconciled to actual source implementation.

SP0b test fixtures cannot be promoted to authoritative origins. Before attaching non-null real-source
FKs, inventory all existing foundation evidence: normally none outside tests. Any real-looking legacy
row blocks rollout pending separately authorised reconciliation; do not delete immutable history,
backfill fake acceptance or leave production source FKs nullable as a bypass. Forward migration
replaces the old unique constraint atomically; down refuses when evidence exists and cannot silently
revert a broader stage-inclusive identity to conflicting old uniqueness.

## Jobs receiver input/result candidates

No general shared handover engine. A later Jobs-owned ReceiveReleasedService Action takes a typed
ReleasedServiceInput-compatible value, after joint review with #28's validator. That foundation has
no entry point; Task A neither changes #28 nor introduces a production Action.

| Value group | Required facts, ownership and checking |
|---|---|
| Context | Current actor, company and supported source-contract version; receiving capability and scope independently checked in Jobs |
| Source identity | Actual acceptance/component/allocation/stage/purpose/business keys and pinned baseline; not a DTO authorised=true field |
| Exact payload | Immutable scope/completion-condition version and digest, component identities, optional supported measure pair/conversions; actor cannot supply source status or computed capacity |
| Readiness | Expected root/allocation/stage/source generations, path policy and relied-on approval/readiness fingerprints; source-owned verified result, not client trust |
| Transaction | Exact runtime connection identity and outer transaction context established by Sales; Jobs validates connection/transaction level and trusted source-contract participation |
| Result | company, service_job_id, origin_id (the receiver receipt), initial_job_baseline_id/digest, default_activity_id, initial_generation and versioned safe replay indicator |

The typed input is data, not proof. Proposed narrow Sales-owned source-read verifier resolves the
real rows and relationship chain on the SAME connection under coordinator-held locks, returning
canonical facts or refusal without acquiring inverse locks. Jobs independently uses it to verify
input binding/contract applicability. Interface name/implementation belongs to the later Sales task;
no such verifier exists now. It neither mutates Sales nor treats arbitrary input as trusted evidence.
A caller-supplied transaction token or asserted lock flag is insufficient: actual coordinator/verifier
participation and held-source-fence discipline require independent-session proof. Missing verifier or
transaction participation fails closed. Jobs never acquires Sales locks or writes
Sales records; only the Sales release coordinator authorises/appends allocation/stage effects.

Baseline initial version copies supported operational scope/conditions and pins original Sales
baseline/acceptance. Never regenerate from live Item text, current quotation, price/FX or completion
policy. No commercial amounts or cost fields copied into J1 Jobs; source price/baseline certainty
remains independently accessible through owning read guards. A future baseline successor is explicit,
linked and separately authorised; replay cannot rewrite the original, add another Job or silently
adopt newer scope. Task B consumes the pinned rule/evidence basis, not a current default.

## Atomic release and compatible locking proposal

Sales ReleaseHandoverComponent owns the outer transaction. Proposed order, subject to the real
R5/R6 writer audit: Sales company fence → sorted claim/accepted roots and required policy/approval
barriers → root allocations → stages → existing ServiceJob rows (sorted IDs) → compatible downstream
locks → Jobs origin/receipt and Sales receipt writes. Jobs only locks its own existing target rows;
Sales source-read verifier is lock-free within the already fenced source context. Never Jobs→Sales
lock inversion. Lock absent target via stage/root protection plus uniqueness; do not SELECT FOR UPDATE
only an absent origin and assume protection. All source acceptance/replacement/reduction/release/
fulfilment/cancel/import/worker writers must participate before activation.

For a genuinely new target, reserve row ID/code through the central Numbering contract in the same
transaction; insert ServiceJob before origin FK write, then baseline/components/default activity/
release event/origin. Proposed Numbering lock position: after Sales source locks and before Jobs
origin insertion, consistent across all Job creators. Review actual numbering counter/advisory locks
and rollback against all participants; no MAX(code)+1 or globally held number reservation. GAPLESS
counter rolls back; ALLOW_GAPS may consume a sequence value, not a durable orphan Job/receipt.
No enabled Jobs type/series or reviewed holder configuration exists here; missing configuration refuses.

Sales checks current duty/read/scope first, obtains canonical locks, then handles exact request receipt
or existing business identity before mutable new-release readiness. If new: recheck acceptance/cap,
no conflicting stage result, generations, support, approval and prerequisites. Jobs validates own
current authority/source binding/connection, creates RELEASED target+baseline+default activity+
release event+origin receipt. Sales records allocation/stage result and its request receipt, linking
the origin. Commit ALL together; exception at any point rolls back local mandatory effects. No inner
connection/commit, fire-and-forget queue or notification-as-receipt. Nested helper transaction must
be a savepoint on the same connection. After-commit notices cannot determine release success.

No assignment/start/fulfilment/cost/stock/AP posting as an implicit release side effect. Required
atomic groups need every actual adapter in the same compatible transaction; otherwise refuse before
creating any target. First path excludes unsupported mixed required groups rather than partially
releasing them. Source lock compatibility, including Inventory when affected, remains unproven;
unknown is not NONE. This proof blocks activation, not this planning or the disabled #28 foundation.

## Idempotency and recovery

Two identities: Sales request receipt follows its own actor/key/action convention; Jobs origin is
business K, independent of actor/key. Jobs never calls Sales OperationReceiptPort and adds no
job_operation_receipts for receiving. Later client-command receipts belong to Task B.
Canonical intent pins source/stage/purpose/baseline/contract/completion basis and exact optional
measures; stable sorting/decimal strings/versioned serialization, never presentation or timestamps.
Expected freshness tokens are checked for new effects; distinguish them from the semantic identity
so refreshing readiness alone cannot create another target. Sales keyed intent hash still detects
changed expected tokens under an already used key according to its accepted receipt contract.

| Retry case | Required outcome |
|---|---|
| Same actor/key, same exact request | Current authority first, original safe references replayed; no new readiness/number/target |
| Same key, changed intent | Stable request-key conflict, no effect |
| Fresh key or different authorised actor, same K/semantic intent | Existing origin/target returned; Sales may record its request receipt atomically; no extra Job or claim |
| Same K, changed baseline/scope/contract/measure | Business-intent conflict; no receiver overwrite; explicit supported successor needed |
| Different business identity, same stage/purpose | Conflict under stage one-target rule, no capacity bypass |
| Existing receipt, now revoked/limited/other company | Refusal or safe scoped absence per API policy; no cached historical privilege |
| Original target later ACTIVE/held/closed | Replay permitted safe original identity with current state; never re-release/start/reset or demand obsolete readiness |
| Network loss after commit | Retry finds origin/Sales receipt; atomic commit prevents a durable half-target |

Unique/deadlock failure rolls back entire outer operation before bounded retry; no querying a failed
PostgreSQL transaction or retrying only receiver writes. Reacquire locks/recheck authority. Lost-race
constraint maps to authorised replay/conflict, not HTTP500/private IDs. A target/origin mismatch is
an invariant violation: refuse, report for owning reconciliation, never reconstruct acceptance or
manufacture a missing receipt. Raw recovery is not a new release command.

## Security and ownership review

Sales guards its R9 handover release/source reads; Jobs owns future JobDutyManifest/JobAccess and
job.receive. Both sides must be ACTIVE under reviewed recipes before enabled receiving; STAGED or
hand-made permission rows confer nothing. ALL-only RecordScope per required duty, checked separately;
limited grants refuse across direct Actions, Requests, workers, replay and result lookup. No reuse of
SalesAccess for Jobs and no generic service-account bypass. Actor/company context stays explicit.
Source read eligibility and independent target receiving authority cannot be inferred from assignment,
customer identity or role. Review safe receiving-result access without requiring job.cost.view.

Result exposes safe target/origin/baseline/activity identities only under current source/target access.
Never expose private control hashes, cost/rates/margin, consent attachments or hidden competing IDs.
J1 has no cost fields, labour tables or compulsory job.view/job.cost.view for later close authority.
Formal evidence requirements stay source/path-specific under Documents ADR 0137; #11 alone does not prove
Sales source issue/consent or secure binary delivery. No universal PDF gate or renderer in this task.

## Review gates and genuine remaining choices

Recommend one narrow fixed-service, whole-deliverable adapter first. Owner already chose R7 authority,
fixed-deliverable default and J1/J2 separation: do not reopen these. Still review: Service Item versus
supported descriptor adapter for the first real source; concrete accepted-condition evidence shape;
final CA/HA physical/composite-key mapping; receiving holder recipe; Numbering type/series/defaults;
source verifier and actual lock/connection proof. These are bounded delivery selections/technical seams,
not new business architecture. Coordinator assigns actual Sales/R7, Jobs and security/integration owners.
Lifecycle/closeout support gates public J1 after receiver delivery; receiver success alone is not pilot
sign-off. J2 labour/costing, Inventory ADR 0040 and Purchasing remain separately authorised.

## Related

- [Receiver verification and dependencies](phase-5-r7-job-receiver-verification.md)
- [SP0a](phase-5-sp0a-design.md) · [SP0a transaction contract](phase-5-sp0a-delivery-contract.md)
- [Phase 5 overview](phase-5-job-execution.md) · [R7](../domain/sales-operational-handover.md)
- [R6](../domain/sales-customer-decisions.md) · [Numbering](../architecture/document-numbering.md)
