[← Phase index](README.md)

# R7 — Operational handover implementation slices

**2026-10-14 · H1–H8 ACCEPTED in0130–0132/Q47 closed; slices SPECIFIED.**
No R7 adapter is IMPLEMENTED or VERIFIED. All slices require separate owner code authorisation.
The [domain](../domain/sales-operational-handover.md) defines the Action/input/locking contracts.

## Delivery order and dependencies

R2 scope, PR/FR/QL frozen issue and CA acceptance/identity/claim contracts precede authoritative
release. R9 approved and delivered registry, duties, grants and effective reads/scope precede any
public surface. R8 governs binary evidence/output; textual internal readiness can be independent.
Preparation must not masquerade as reserving stock or committing a target. Source adapters are
enabled individually; source type alone is not support. HA3 branches can be delivered separately
after HA2; mixed dependent release needs every required branch delivered.

| Slice | Actions/Requests and API/UI deliverable | Dependencies and adapter boundary | Observable verification/demo |
|---|---|---|---|
| HA0 — Allocation/identity foundation | Tenant-safe plan/component/stage/baseline/readiness/receipt model, supported-path registry and coordinator ports; no public writer | R5/R6 root identity/generations; constraints/FinancialActivity where monetary; no target engines fabricated | Separate-company FKs, immutable evidence, direct invalid-write/cap refusal, missing-anchor races; disabled feature demo |
| HA1 — Preparation/readiness | PreviewHandover/PrepareHandoverPlan Requests/Actions; scoped list/detail, preparation sheet and missing-fact next actions | R9; versioned goods/service/Assembly/manual prerequisites and explicit atomic grouping; no target/stock/Finance mutation | Valid accepted-but-not-ready; warehouse/site appropriate to path; denied dependencies redacted; stale preparation cannot release |
| HA2 — One real local release | ReleaseHandoverComponent with exact baseline/policy/approval/generation tokens and receipt; owning target Action creates baseline+target in same transaction | HA0/1; CA authoritative source; one explicitly named delivered adapter; compatible real locks/connections proven, shared approvals where configured | Failure after real target insert rolls everything back; missing receiver refuses; same/fresh key across actors creates one target; changed intent conflicts; receipt after readiness change still resolves safely |
| HA3a — Goods managed Order path | Genuine preparing/released/held/partial/full/closed Order coordination, instructions and backorder detail; Inventory-owned pick/delivery integrations | HA2 plus delivered package/UoM/warehouse/stock-demand and actual fulfilment ports; reservation only with delivered reservation policy | Accept100, schedule40/60, actual30 leaves70 including10 first-batch backorder; no invoice or reprice on Order; package/QA/negative-stock refusals |
| HA3b — Service/full technical path | Assignment or Job owning Action, initial/successor baseline and schedule/completion view; descriptor adapter only if explicitly supported | HA2 plus Phase5 service/technical/control/actual ports; full Assembly first, manual stock requires real Inventory identity | Service needs no Assembly/stock; Assembly version/control drift refuses; setup60 not duplicated; partial technical split unsupported; accepted pending-team stays valid |
| HA4 — Disjoint branches/schedules | ReviseOperationalSchedule, controlled allocation split/grouping and stage linkage; mixed component readiness/outcomes | Real relevant HA3 adapters, contractual constraint evaluator and supported divisibility; within-acceptance only |20+20 against40 allowed;20+30 race refuses excess; Order40→Job40→Delivery40 counts40; required installation failure rolls back atomic group; internal slot versus commercial destination change |
| HA5 — Holds and unused allocation correction | HoldOperationalTarget/CancelUnusedOperationalAllocation, eligible reassignment and successor baseline with independent consent/actual views | Owning target, Inventory, Finance/Phase5 impact ports; all conflicting writers integrated; no claim-release side effect | Cancel eligible empty target releases assignment only; pick/work/invoice/unknown prevents unsafe reuse; cancel/release/fulfil race; held target refuses new release |
| HA6 — Bounded commercial reduction | PreviewAcceptedReduction/ApplyAcceptedReduction; exact agreed terms/consent and impact generations; confirmation with original/effective/actual/remaining evidence | Dedicated R9 correction duty; HA5 plus all relevant authoritative Operations/Inventory/Phase5/Finance ports; local effects atomic |100→80 after30 yields remaining50 only when impacts permit; reservations/deposits/subcontract/production lower bounds; unsupported NONE refuses; rollback, fresh-key duplicate correction, consent delta and stale impact races |
| HA7 — Integrated enablement and projections | Supported-path activation/disablement policy, all-writer reconciliation and safe accepted/assigned/scheduled/fulfilled reports; no general release engine | Necessary HA/CA/QL adapters delivered, R8/R9 boundaries, verified imports/legacy writer inventory; capability delivery contract | Mixed goods/installation journey and denied personas; disabling cannot abandon active targets; totals counted once; partial-family gates open only for exact supported contract |

## Cross-slice implementation obligations

- **Source ports:** preserve company/Party/source scope/measure, original quote/agreement provenance,
  supported transforms, controls/cost certainty, completion rule and initial/successor baseline links.
  Owning Actions enforce their own duties; Sales cannot call internal Inventory posters or mutate
  downstream tables. Unsupported source/path never falls back to a fabricated Job/Assembly.
- **API/UI:** pair each mutation with a guarded Request and Action and Resource/OpenAPI contract;
  generate client in implementation delivery. Register scoped reads, counts/history/receipt lookup,
  ratchets and independently protected costs/files/dependencies. Standard sheets/loading/empty/error/
  stale/denied/unsupported states, light/dark and phone width. UI server decimal quantities/totals,
  exact baseline approval and explicit independently released versus pending outcomes.
- **Permissions:** final names/grants await R9. Scope matrix covers source, target, warehouse/site,
  commercial correction and sensitive evidence independently. Review is configured, not universal.
  No automatic sensitive role-name grant; authorised holder assignment remains separate.
- **Migrations:** additive pseudo-DDL translated to real tables/composite FKs/indexes, append-only
  guards, unique identities and cross-row cap enforcement. Document real down path, evidence-safe
  rollback limitations and isolated apply→rollback→reapply. Legacy/import/external writers need
  truthful baseline reconciliation; no invented consent, empty liabilities or stock reservation.
- **Concurrency:** real separate-transaction tests for missing anchors, allocation overrun,
  stage-target uniqueness, fresh-key business replay, cancellation/release/fulfil and reduction
  races. Canonical Sales→root/allocation→stage/target→downstream lock order must reconcile actual
  owning Actions. Required group failure and exception after target write must roll back receipts,
  baseline and capacity. Same database runtime connection must be demonstrated, not assumed.
- **Demos:** idempotent fixtures/personas for goods, service, full Assembly, unsupported manual,
  mixed dependent and independent scopes, accepted-not-ready, duplicates, backorders, denied scopes,
  eligible unused cancellation and bounded reduction with supported versus unknown exposure.
  No real sensitive assignee or general returns/full cancellation demonstration implied.
- **Completion:** implementation owner supplies domain/API/client/demo/journey/status and applicable
  pre-push checks, Action/HTTP/tenant/scope/immutability/capacity tests, migration cycle and actual UI
  browser checks. This planning commit runs only documentation checks, no application tests.

## R5/R6 blocked operations — approval is no longer the missing R7 gate

| Operation | Can proceed architecturally | Still required before enablement |
|---|---|---|
| QL whole-unaccepted replacement; CA ordinary full independently acceptable scope | Existing0124/0125 paths; no mandatory Job/Order | Their actual PR/FR/QL/CA implementation and R9; R7 target unnecessary |
| QL partial-family replacement/withdrawal; CA4 remaining reoffer after accepted scope | R7 authority accepted; original accepted scope remains pinned | Integrated CA claims/entitlements and HA allocation/baseline/correction ownership, conflicting writer conservation and races; exact affected adapters delivered/verified, not all HA slices by name |
| CA3 divisible retained-price partial | Explicitly offered supported source only | Quantity/benefit/tax/technical adapters; affected HA baselines/corrections and capacity support; arbitrary Assembly/fixed splits remain disabled |
| CA5 late recognition with operational conflicts | Pending evidence can be retained | Dedicated R9, historical proof, current claims and delivered affected HA5/6 impact/disposition ports; no free backdating |
| Accepted quantity reduction | Bounded H7 contract accepted | HA6 exact consent plus authoritative downstream checks/effects; actual30 alone proves no other floor |
| Automatic claim release, transfer, increases/repricing/full cancellation/returns | Deferred, not enabled by H7/H8 approval | Separate contracts/approval and owning downstream delivery; target cancellation cannot lift family binding |

## Remaining boundaries

R8: issued customer artefacts, transmission/recipient evidence, signature context and secure files.
R9: final prepare/review/release/hold/amend/correct/release duties, independent reads/scopes/grants,
reports/outcomes/forecast trust. Inventory/Phase5: reservations, picking, production, subcontract,
actual service/work/returns and technical change. Finance: account/credit at appropriate step,
invoicing/credits/deposits/payment/statutory tax/FX/COGS and0040 correction ownership.
No missing domain is a verified zero-impact port. External/delayed creation requires separately
approved durable handoff; ordinary queued notices cannot satisfy mandatory effects.

## Related

- [Operational domain](../domain/sales-operational-handover.md) · [Accepted review](phase-4-r7-handover-research.md)
- [Mixed journey](phase-4-r7-mixed-journey.md) · [Integrity](phase-4-r7-evidence-and-integrity.md)
- [R5 slices](phase-4-r5-implementation-slices.md) · [R6 slices](phase-4-r6-implementation-slices.md)
- [R8 matrix](phase-4-r8-documents-review.md) · [Question workflow](../architecture/implementation-question-reconciliation.md)
- [Pre-push checks](../architecture/pre-push-checks.md)
