[← Phase index](README.md)

# Phase 4 — Consolidated implementation dependency and readiness handoff

**2026-10-15 · Planning handoff SPECIFIED. R1–R9/Documents architecture ACCEPTED.**
No PR/FR/QL/CA/HA/DOC/DF/AU/RP coding, live registration/grants or push is authorised by planning
completion. Owner selects small named implementation batches, assigns code ownership, reviews
holder/rollout inventory and separately authorises execution. This file does not message another
agent or modify implementation-owned handoffs. Forward questions through owner under accepted workflow.

## Actual state and evidence boundary at c25d5cb

| Dependency/work | Architecture/specification | Implementation evidence inspected/reported | Verification / public exposure |
|---|---|---|---|
| Existing CRM foundation/corrections |0098–0114; existing code/vocabulary/access helpers | Actual current CRM Actions/8 permissions, SalesAccess and grants/mandate helpers inspected read-only; frontend WIP belongs to another agent | STATUS reports delivered/CI-verified prior CRM corrections; no fresh application checks here. Current frontend WIP not verified/committed by planning. Existing surfaces retain accepted behaviour. |
| Existing C1/C2 typed cost resolution |0110 accepted; migration to canonical read model | Git history includes1d77fc0 C2a and0ba5970 C2b; STATUS reports C1a–c/C2a–b implemented locally; cost query/consumers are existing dependencies | Historical local verification per STATUS, not rerun. Not evidence new Sales source/FX/technical adapter is implemented. C1d separately gated. |
| Shared Company/Numbering/Approvals/RecordScope/manual rates/Inventory | Existing accepted owner contracts | Real source helpers/registries exist; no need reimplement engine | Existing delivery per owning docs; new consuming integration/lock/scope/rate invalidation needs its own verification. No production configuration/holder audit run. |
| PR0–PR6 / FR0–FR6 | R3/R4 accepted/specifications | No new pricing/financial Sales delivery identified; source README remains CRM | NOT_STARTED for new delivery; no public activation claim |
| QL0–QL6 / CA0–CA6 / HA0–HA7 | R5/R6/R7 accepted/specifications (handover0136) | Current Sales Action inventory has no quote/acceptance/Order/Job handover implementation | No integration/atomicity/runtime evidence; all new paths remain disabled |
| DOC0–DOC7 / DF0–DF5 | R8/0137 accepted; one shared Documents archive specified | No formal backend Documents/quotation renderer/archive adapter delivered; existing PO Quick Print is different track | No verified formal artefacts/recovery/PDF runtime; no PO refactor or other adapter implied |
| AU0–AU5 / RP0–RP6 | R9 accepted0138–0140; authority/duty/rollout/report contracts specified | Existing CRM helpers useful, but new guard/limit/grant/report/forecast adapters absent | Not IMPLEMENTED/VERIFIED; no new permissions/grants/public feature safe to activate merely from approval |

ACCEPTED = governing business authority. SPECIFIED = reviewable delivery contracts. IMPLEMENTED =
actual owning code/migrations/client/UI present at named commit. VERIFIED = actual checks/runtime/
staging evidence at named commit/date/owner. PUBLIC_ENABLED additionally needs safe holder/grant and
supported-adapter rollout sign-off. These are independent axes, never a single “done” label.

Concurrent update during this planning turn: another agent committed Opportunities UI work as
0a62b0e, including its own frontend/test/shared-UI documentation paths. Latest handoff checks were
reconciled against that HEAD; no backend R9/quotation capability was added by that commit. This
planning agent did not run or attest its application checks and preserves its committed content.

## Build-once shared contracts

| Foundation | One owner / reuse seam | Consumers / anti-duplication |
|---|---|---|
| Tenant/schema/money | Existing DB composite keys, append-only guards, decimal(19,4)/FX evidence, Company FinancialActivity | All monetary/source rows; don't rewrite deployed migrations or store float/unknown-zero |
| Authority/record scopes | Existing PermissionRegistry/FullAccessMandates/RecordScope + AU adapters | All public PR/FR/QL/CA/HA/DOC/DF/RP, no parallel scope/admin engine or generic download gate |
| Commercial identities/writer coordinator | Sales QL0 family/logical roots, CA0 decision/claims/entitlement identities, canonical lock/generation/receipts | Replacement/consent/handover/correction/forecast consume same source IDs/caps; don't build separate acceptance ledger per target |
| Financial arithmetic/source/FX | FR0–FR4 typed decimal/rounding/bridge/tax primitives and existing Currency/Estimating/Inventory query Actions | PR controls, issue/acceptance, AU limits and RP valuation; report FX policy separate from commercial snapshot, no duplicate rate engine |
| Approval | Existing0070/0085/0087 registries/requests/authority/review profiles | Quote ordinary/discount/operational exact subjects, no duplicate workflow/reason/ceiling tables |
| Documents | DF shared templates/pages/manifests/blobs/artefacts/receipts/secure access/recovery; Company brand | Sales customer snapshot/binding only; PO-shaped fixture, no extra Sales originals/archive or implicit invoice/Inventory implementation |
| Audit/invalidation | AU5 exact human/service evidence and Sales source generation/trust protocol | Imports/workers/receipts/RP, no cron-only freshness or logging confidential operands |

## Proposed small reviewable implementation batches — not authorisations

| Batch | Exact planned scope | Dependencies / safe exposure | Exit evidence to request before next batch |
|---|---|---|---|
| B0 — Readiness and vocabulary | AU0 code/surface/source/holder manifest and owner-assigned file boundaries; inspect current CRM/C2 versions without altering their WIP | Earliest independent docs/inventory task; no live registry/grant changes, no route | Current predecessor/registry reconciliation, package/source adapters and same-connection/lock inventory, owner-approved batch/holder plan |
| B1 — Decimal/source kernel | FR0 + PR0 internal typed input/output/supported-source contracts; AU2/AU5 internal actor/guard/receipt interfaces | Existing C2 read model/Company; no quote API, no public config. Share precision/certainty primitives rather than re-migrate C2 | Hand-derived arithmetic/source-neutral/zero-vs-unknown/scope proofs; no stock/Finance side effects |
| B2 — Financial config primitives | FR1 then FR2/FR3 supported subset then FR4; divide dates/FX, tax composition and rounding into separate commits | B1, actual AU0/1/2/5 for each exposed configuration duty; existing manual-rate/tax owners. No public route until grant/field support | Exact-date/prior/age/bridge, inclusivity/tax/component/rounding evidence and migration/denial tests; tax owner registration explicit |
| B3 — Pricing configuration/authority | PR1→PR2→PR3→PR4 with AU3 limits; maintained rates first, groups/agreements/plans in later sub-batches | B1+supported FR0/1/3/4; AU0–3/5, legitimate Party/terms guards; config complete UI only, no fake quotation workflow | Reference/input+aggregate/term conflict/fixed-zero/cost-blind tests; narrow pricing-persona config views; no risk bypass |
| B4 — Quote identities and preparation | QL0 plus CA0 coordination schema/claims ports, then QL1/QL2 source adoption/review; AU4 exact approval adapters | PR/FR supported subset, actual source MaterialPlan controls; no accept writer until CA2. Minimal claims anchors integrate from first issue writer | Tenant/FKs/FinancialActivity/immutable candidates/concurrent writer/generation and approval invalidation; safe draft UI |
| B5 — Shared Documents foundation | DF0/DF1 plus DOC0 Sales snapshot/binding/layout; PO-shaped fixture; Company brand | Can progress independently after B0/basic types; actual AU0/1/2/5 before public config. No live PO refactor; no real quote required for disabled fixture | Typed binding/no duplicate archive, A4/Letter fixture/quote A4, versioned defaults/assets and safe config grants |
| B6 — Coherent atomic quote issue | QL3 + PR5 + FR5 + DOC1/DF2 required manifest/intent binding and AU3/4 controls | B2–5 relevant delivered subsets, real same DB source/numbering/claims locks. Commercial issue may precede working PDF renderer; official send remains disabled | Actual rollback after shared bind/numbering, repeat issue one official number, source/policy stale refusal, frozen terms/cost/tax/FX/manifest, accepted0133 pending-render behaviour |
| B7 — Formal document/manual delivery pilot | DF3 + DOC2, then DF4/DOC3, then DF5/DOC4; split renderer, recovery/files and public pilot into reviewable commits | B6 for real issued original; DF can stage fixtures sooner. Recovery/security and AU access/holders before official send/download. No email or portal | Real worker races/storage crash/exact restore, physical PDF pages/text/images, no leak to quantity-only/internal-file denied users, manual delivery not receipt/consent |
| B8 — Full independent customer acceptance | CA1 textual shared intake then CA2 full unchanged eligible scope, CA6 basic measures; DOC5 only required file/method bridge | Real issued QL/PR/FR/CA0 claims + AU2/4/5, R8 binary only where method needs it. No universal Job/Order/account/PDF receipt prerequisite | Fresh-key business duplicate, option/family/entitlement caps/races; real manual PO consent vs acknowledgement, accepted-not-ready; no auto whole-WON |
| B9 — Ordinary lifecycle continuation | QL4, QL5 whole-unaccepted revision/replacement, QL6 supported capability transitions | B6+all conflicting CA/QL writers integrated; B8 may be staged after B9 only if acceptance absent/refused by proven writer inventory. No partial-family replacement | Current actual claims checked; new draft doesn't withdraw old; issue/expiry/withdraw/replace/accept races and safe disablement |
| B10 — Supported operational path | HA0/HA1 preparation then HA2+one explicitly delivered HA3a or HA3b owning target adapter; HA4 schedules then HA5 where impacts supported | B8 actual accepted source, AU operations and real Inventory/Phase5 target contracts/Actions. Target creation and fulfilment/Finance still separate; no fake Job fallback | Same-connection target-write rollback, fresh-key one target, disjoint caps vs stages, path readiness and real target locks, unknown impact refuses |
| B11 — Bounded advanced consent/correction | CA3 exact supported divisible retained-price adapters; HA6 reduction and CA5 late conflicts only when impacts delivered; CA4 partial-family remainder last for affected path | B8/B10 necessary claims/baseline/allocation/correction/Finance/Inventory/Phase5 adapters, not merely schemas. HA7 enablement for exact supported paths | Cumulative budget/fixed setup constraints, actual delivered floors+reservation/invoice/deposit/work/subcontract checks, supported NONE vs unknown, atomic correction and remaining-family conservation races |
| B12 — Reporting/forecast | RP0 measures, RP1 remaining basis, RP2 dated valuation, RP3 trust/invalidation, RP4 publish, RP5 history/export then RP6 rollout | AU rights/guard/limits as applicable, actual QL/CA/CRM/Currency source writer events; can develop alongside B10, no renderer dependency | No double count/hidden aggregates; known/effective times, exact bridge/missing valuation, persistent trust/rate correction/clock expiry and publish races, no polling-only freshness |

Batch labels describe dependency groups, not one large commit each. Code owner should keep one coherent
API/Action/model/UI/delivery contract per sub-batch with disabled future writers. B5 and disabled RP
schemas may progress independently; B2 tax methods/PR2 managed pricing need not all ship for one
supported SIMPLE quote. No circular requirement that every CA/HA/DF/RP capability must exist before
ordinary quote issue. Conversely disabled interfaces do not satisfy real adapter/field/security gates.

## Per-slice coverage and extensions

PR0–PR5 core map B1/B3/B6; PR6 ADVANCED formula/mode separate future approved batch after PR5 and
complete formula/source/mode-transition controls. FR0–FR5 map B1/B2/B6; FR6 additional tax execution
separate supported-method batch, not implied in initial issue. QL0–6 map B4/B6/B9. CA0–6 map
B4/B8/B11 plus RP-linked measures. HA0–7 map B10/B11; HA3a and HA3b can ship independently per real
adapter but mixed required atomic group needs every required path. DOC0–5 map B5–8; DF0–5 map
B5–7 once, not duplicate code; DOC6 later supported provider batch, DOC7 future governed capabilities.
AU0–5 deliver incrementally before their first consumer; RP0–6 map B12. No slice is declared delivered
merely by a batch entry. Existing C1d, wider access Q26, statutory Finance/Phase5 and later public
portal/signature/retention duties remain independently gated, not silently pulled into this plan.

## Public activation and must-remain-disabled matrix

| Feature | Can be exposed only after actual evidence | Disabled until then / permanently outside initial scope |
|---|---|---|
| PR/FR configuration previews | Supported actual calculations + AU0/1/2/5 registration/authorised holders/scope/projections and UI | Internal kernel/registry alone is not public readiness |
| Draft/issue | Real PR/FR/QL + AU controls and0137 required snapshot/manifest binding, approval/numbering/claims/races | No blind issue with stale source/unknown mandatory controls; no general exception |
| Official customer PDF/download/manual delivery | Verified DF original/recovery/access + correct Sales adapter/holder eligibility, DOC4 | Rendering-pending issue stays issued; sending disabled; no public URLs or email by download |
| Full ordinary acceptance | CA2 exact consent/claims/entitlement source and AU duties; method-specific DOC5/file support where needed | Observation/signature alone cannot accept; no account/Job requirement invented |
| Partial-family replacement/withdrawal/reoffer | Required CA allocation/claims+HA baseline/correction/impact adapters delivered and verified for that exact path |0124/0125 gate remains despite all architecture accepted |
| Divisible Assembly/fixed setup/tax partial | Explicit offered technical/commercial entitlement/rounding/actual supported path | Generic fractional split/automatic benefit reset forbidden |
| Operational target/reduction | Real owning Actions/locks/current impact ports, verified local atomicity, AU and downstream approval/holder evidence | Missing Finance/Inventory/Phase5 port is UNKNOWN, never zero or no exposure |
| Current trusted forecast/export | RP complete source generation/invalidation protocol, rates/eligible review/publication, scoped totals and current export access | Cron-only staleness, auto-trust after recovery, live-rate historical rewrite forbidden |
| Future exceptions/release/transfers/returns/repricing/full cancellation/email/portal | Own approved contract, actual adapters/security and separate owner batch authorisation | Reserved names or architecture approval do not enable them |

## Coordination, verification and genuine owner choices

Preserve frontend CRM WIP, existing C2 implementation and all generated/migration/in-flight handoff
ownership. Each authorised batch names exact paths/module owner and shared hotspots. No two agents
change shared registry/schema/client generation concurrently without owner coordination. Explicit
path/hunk commits, no stash/reset/blind restore or push. Original coding questions pass through owner,
retain Origin: IMPLEMENTATION AGENT and original context; planning cannot mark code owner's checks
verified. Existing accepted answers get citations, not redundant Q/ADR or wholesale redesign.

Before public rollout require named implementation commit; actual applicable tests/ratchets/migration
cycle/client drift/build/browser/PDF/worker races; supported adapter inventory; safe grant manifest,
owner-reviewed real holder/limit/configuration inventory; staging and recovery runbook sign-off.
Report cause+owner for unavailable evidence, keep affected path disabled. No production tests/seeding.
Planning only checks documentation links/numbers/whitespace; no fresh runtime guarantee here.

No further R1–R9 business approval blocks specification. Genuine next owner decisions: which named
small batch to authorise, code owners/checkout coordination, actual company limits/policies/holders
and staged rollout timing. Future deferred capability requests may need their own business contract.
This handoff supplies a concrete reviewable next step (B0 then B1/B5 foundations); it authorises none.

## Related

- [R9 slices](phase-4-r9-implementation-slices.md) · [Rollout](phase-4-r9-permission-rollout.md)
- [PR](phase-4-r3-implementation-slices.md) · [FR](phase-4-r4-implementation-slices.md) · [QL](phase-4-r5-implementation-slices.md)
- [CA](phase-4-r6-implementation-slices.md) · [HA](phase-4-r7-implementation-slices.md) · [DOC](phase-4-r8-implementation-slices.md) · [DF](document-print-framework-slices.md)
- [Question workflow](../architecture/implementation-question-reconciliation.md) · [Pre-push checks](../architecture/pre-push-checks.md)
