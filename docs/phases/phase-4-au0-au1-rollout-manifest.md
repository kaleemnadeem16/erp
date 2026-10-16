[← Phase index](README.md)

# Phase 4 — AU0 reconciliation and AU1 registration foundation: rollout manifest

**2026-10-15 · AU0 reconciliation delivered; AU1 foundation IMPLEMENTED locally (targeted tests, ratchets, Pint and Larastan only).**
Governing: [0138](../decisions/0138-authorise-sales-and-document-duties-with-explicit-scope-and-grants.md), the [duty matrix](../domain/sales-duty-matrix.md) and the [R9 rollout contract](phase-4-r9-permission-rollout.md).
No new permission is registered, no grant is made, no holder is assigned, no route or screen is added, and no migration exists. The code is `backend/app/Modules/Sales/Support/Authority/`.

## What is registered and what is not

| | Count | State |
|---|---|---|
| Existing CRM duties | 8 | **ACTIVE**, registered exactly as before: same strings, same holders, same read and scope meaning; `sales.opportunity.attribution.correct` stays excluded from automatic grants (ADR 0114) |
| Sales duties specified, no consumer yet | 44 | **SPECIFIED**: not registered, no constant, no permission row, refused by `SalesAccess` even if a row is made by hand |
| Sales duty staged (Action delivered, disabled) | 1 | **STAGED**: `sales.financial.configure` — its guarded Action exists (FR4/PX6 policy foundation) but the duty is not registered and `SalesAccess` refuses every caller until the owner-reviewed holder recipe activates it |
| Sales duties reserved by design | 5 | **DORMANT** (D-S): `sales.delivery.send`, `sales.delivery.reconcile`, `sales.pricing.exception.approve`, `sales.pricing.agreement.depart`, `sales.customer-decision.claim-release` — need their own approved and delivered contract before they may even become SPECIFIED |
| Other modules' duties in the 78-row matrix | 20 | Not Sales' to register (below); 6 are not registered anywhere yet (Tax 2, Documents 4) |

`sales.financial.configure` (FR1 policy, FR4/PX6 rounding and unit-price precision) is **STAGED, class S**: the FR4/PX6 policy Actions (`CreateRoundingPolicyDraft`, `ActivateRoundingPolicy`) now exist and are guarded by it, but registering it would make a grantable right that any full-access mandate holder would receive on the next sync, before a holder recipe is reviewed. So it stays unregistered and refused for everyone. The foundation is proven to carry it safely (tests register it as a hypothetical ACTIVE duty), and it becomes ACTIVE in the same change that delivers its owner Action, scope guard, read projection and reviewed holder recipe.

## How a duty becomes live (the gate every row below shares)

1. Its slice delivers the owning Action (guarding duty and scope **inside the Action**, not only the Request), the minimum read projection and the independent sensitive read where the matrix names one.
2. In the same change its status in `SalesDutyCatalogue` moves SPECIFIED (or STAGED) → ACTIVE and it gets a constant in `PermissionVocabulary`. Nothing else enables it.
3. From then on it is registered and **excluded from automatic grants** (all new duties, ordinary or sensitive) with the matrix class as the recorded reason.
4. An existing full-access mandate (ADR 0114) is an approved, machine-verified attestation for *all registered capabilities*; once a duty is ACTIVE a qualifying mandate holder receives it on the next sync. That is the accepted mechanism and is unchanged, which is why step 2 needs the holder review below first.
5. The owner reviews real holders (company, role or direct, effective scope) and approves a recipe; assignment is a separate, owner-authorised act with staging verification. No holder is inferred from a role name, a manager title, record ownership or CRM incumbency.
6. Only company-wide scope is supported. A person limited to "my units" for the duty is refused (403), never shown a subset.

## Existing CRM duties — preserved

| Duty | Class | Operation | Enforced by | Grant behaviour |
|---|---|---|---|---|
| `sales.pipeline.view` | Existing | Pipeline list/detail/note/history | `SalesAccess::mayRead` (any of the five read-capable duties) | Unchanged: part of the automatic grant, as today |
| `sales.lead.manage` | Existing | Record/qualify/disqualify/link/convert Lead | Lead Requests and the lead Actions via `SalesAccess::require` | Unchanged: part of the automatic grant, as today |
| `sales.opportunity.manage` | Existing | Create/update/stage/note/next action | Opportunity Requests and Actions via `SalesAccess::require` | Unchanged: part of the automatic grant, as today |
| `sales.opportunity.assign` | Existing | Assign/reassign eligible active member | Assign/reassign Requests and Actions | Unchanged: part of the automatic grant, as today |
| `sales.opportunity.close` | Existing | Lost/abandoned/reopen; future accepted WON under0107 | Close/reopen Requests and Actions | Unchanged: part of the automatic grant, as today |
| `sales.stage.manage` | Existing | Configure open stages/history | Stage Requests and `MaintainSalesStages` | Unchanged: part of the automatic grant, as today |
| `sales.numbering.manage` | Existing | Existing Sales numbering formats | Sales numbering configuration | Unchanged: part of the automatic grant, as today |
| `sales.opportunity.attribution.correct` | Existing sensitive | Append historical attribution correction + independent CRM read | `SalesAccess::requireCorrectAttribution` (this duty AND an independent read, each by its own scope) | Excluded from automatic grants; explicit holder or verified mandate only (ADR 0114) |

Legitimate incumbents keep exactly what they hold: no migration touches a grant, a role or a scope limit. Only `SalesAccess::may` changed, and only to refuse a name the manifest does not list as ACTIVE — all eight are.

## The 50 duties not yet live (44 specified, 1 staged, 5 dormant)

All are Sales-owned, company-wide scope only, **no predecessor** (no CRM or editor continuity is inferred), **no approved holder recipe** (`NONE — owner review required`), and **excluded from automatic grants from the moment they go ACTIVE**. Until then they are neither registered nor grantable.

| Duty | Class | Status | Operation | Sensitive projection | Activation dependency |
|---|---|---|---|---|---|
| `sales.quotation.view` | New ordinary | SPECIFIED | Quotation list/detail safe metadata | Quote-min | QL1 |
| `sales.quotation.commercial.view` | Sensitive | SPECIFIED | General chosen-commercial figures | Commercial | QL1/FR5 |
| `sales.quotation.prepare` | New ordinary | SPECIFIED | Draft/revise/cancel-draft/preview/ordinary submit | Commercial-workflow | QL1/2 + PR/FR |
| `sales.quotation.issue` | New ordinary | SPECIFIED | Official issue | Commercial-workflow | QL3 |
| `sales.quotation.withdraw` | Sensitive | SPECIFIED | Eligible unaccepted withdrawal/replacement disposition | Commercial-workflow | QL4/5; partial remains gated |
| `sales.quotation.history.view` | New ordinary | SPECIFIED | Quote lifecycle history | Quote-min + independent fields | QL1–6 |
| `sales.quotation.approve` | Sensitive | SPECIFIED | Exact commercial review subject | Review-commercial | QL2 + shared Approvals |
| `sales.quotation.review-operational` | Sensitive | SPECIFIED | Exact operational review profile | Review-operational | QL2 + review adapter |
| `sales.quotation.discount` | New ordinary | SPECIFIED | Routine specific discretionary input | Commercial-workflow | PR4/5 + AU3 limits |
| `sales.quotation.price.override` | Sensitive | SPECIFIED | Reasoned final net override | Commercial-workflow | PR4/5 + AU3; no risk bypass |
| `sales.quotation.discount.approve` | Sensitive | SPECIFIED | Exact above-limit discount subject | Review-commercial | AU4 + supported policy |
| `sales.quotation.associate` | Sensitive | SPECIFIED | Current CRM association, original0099 preserved | CRM + quote association | QL association Action + both source/destination eligibility |
| `sales.quotation.policy.manage` | Sensitive | SPECIFIED | Validity/unknown-payment configuration | Config | QL0/4/6 |
| `sales.pricing.configure` | Sensitive | SPECIFIED | Version supported base/rule/plan/control policy | Config writable terms | PR1–4/6 |
| `sales.pricing.eligibility.manage` | Sensitive | SPECIFIED | Dated Party pricing memberships | Party eligibility | PR2 |
| `sales.pricing.agreement.manage` | Sensitive | SPECIFIED | Contractual pricing versions | Confidential terms | PR2/3; cannot depart accepted terms |
| `sales.pricing.terms.view` | Sensitive | SPECIFIED | Read otherwise protected term choices | Confidential terms | PR2/3 |
| `sales.financial.configure` | Sensitive | STAGED | Sales FX/rounding policy | Config | FR1/4 |
| `sales.customer-decision.view` | New ordinary | SPECIFIED | Decision/case safe detail/history | Decision-min | CA1 |
| `sales.customer-decision.evidence.record` | New ordinary | SPECIFIED | Shared intake/append observations | Evidence-write | CA1; file requires secure DF |
| `sales.customer-decision.accept` | Sensitive | SPECIFIED | Authoritative ordinary consent/claims | Exact consent | CA2/3 |
| `sales.customer-decision.reconcile` | Sensitive | SPECIFIED | Late recognition | Historical consent/impact | CA5 + affected HA |
| `sales.customer-decision.correct` | Sensitive | SPECIFIED | Governed accepted reduction | Exact consent/impact | HA6/CA5 |
| `sales.customer-decision.evidence.view` | Sensitive | SPECIFIED | Confidential evidence/files | Evidence-read | CA1 + file adapter |
| `sales.handover.view` | New ordinary | SPECIFIED | Safe plan/readiness/target links | Operational | HA1 |
| `sales.handover.prepare` | New ordinary | SPECIFIED | Prepare plan, no target/stock | Operational | HA1 |
| `sales.handover.release` | Sensitive | SPECIFIED | Release exact supported component | Operational + baseline | HA2 + real target |
| `sales.handover.hold` | Sensitive | SPECIFIED | Authorised operational hold | Operational | HA5 |
| `sales.handover.amend` | Sensitive | SPECIFIED | Schedule/unused cancellation/reassignment | Operational/impact | HA4/5 |
| `sales.document.view` | New ordinary | SPECIFIED | Safe generation/artefact history/status | Document-min | DF2/DOC1 |
| `sales.document.generate` | New ordinary | SPECIFIED | Request pinned generation/retry | Customer render profile | DF3/DOC2 |
| `sales.document.repair` | Sensitive | SPECIFIED | Authorise labelled semantic rendition | Customer render profile | DF4/DOC3 |
| `sales.document.download` | New ordinary | SPECIFIED | Original/permitted rendition/print invocation | Exact whole payload | DF4/DOC4 |
| `sales.document.attachment.manage` | Sensitive | SPECIFIED | Validate/link allowed evidence attachment | Association write | DF4 + CA/DOC |
| `sales.document.attachment.view` | Sensitive | SPECIFIED | Independent confidential file access | Exact association | DF4 + owner adapter |
| `sales.delivery.view` | New ordinary | SPECIFIED | Safe recipient/attempt history | Recipient/attempt; confidential proof independently | DOC4 |
| `sales.delivery.record` | New ordinary | SPECIFIED | Manual actual-channel evidence | Recipient/attempt; confidential proof independently | DOC4 |
| `sales.delivery.send` | Dormant sensitive | DORMANT | External current/historical dispatch | Recipient/attempt; confidential proof independently | DOC6 supported provider only |
| `sales.delivery.reconcile` | Dormant sensitive | DORMANT | Provider unknown-result disposition | Recipient/attempt; confidential proof independently | DOC6 durable protocol |
| `sales.forecast.view` | New ordinary | SPECIFIED | Forecast basis/trust/source view | Forecast commercial; no hidden sources/FX-admin | RP1 safe basis; RP4 for published forecast; CA/QL sources |
| `sales.forecast.maintain` | New ordinary | SPECIFIED | Maintain explicit remaining assumption | Forecast commercial; no hidden sources/FX-admin | RP1 + actual Opportunity; acceptance effects need CA/QL integration |
| `sales.forecast.select` | New ordinary | SPECIFIED | Choose eligible noncompeting issued set | Forecast commercial; no hidden sources/FX-admin | RP1 + actual eligible QL/CA remaining-value adapters |
| `sales.forecast.review` | Sensitive | SPECIFIED | Explicit commercial trust review | Forecast commercial; no hidden sources/FX-admin | RP3 + exact basis/source review |
| `sales.forecast.publish` | Sensitive | SPECIFIED | Publish reviewed immutable version | Forecast commercial; no hidden sources/FX-admin | RP4 + all source writer invalidation |
| `sales.forecast.value` | Sensitive | SPECIFIED | Explicit dated management valuation | Forecast commercial; no hidden sources/FX-admin | RP2 + actual FR/Currency primitives |
| `sales.report.view` | New ordinary | SPECIFIED | Scoped measures/known/effective-as-of reports | Report-safe; no hidden totals | RP0 for implemented accepted measures; RP5 for history/forecast |
| `sales.report.export` | New ordinary | SPECIFIED | Same permitted report projection exported | Report-safe; no hidden totals | Same delivered report as read + AU export guard; RP5 history/forecast |
| `sales.pricing.exception.approve` | Dormant sensitive | DORMANT | Future control-specific risk exception | No runtime projection | Separate approved/delivered contract first |
| `sales.pricing.agreement.depart` | Dormant sensitive | DORMANT | Future accepted-contract departure | No runtime projection | Separate approved/delivered contract first |
| `sales.customer-decision.claim-release` | Dormant sensitive | DORMANT | Future commercial exclusive-claim release | No runtime projection | Separate approved/delivered contract first |

## Proposed holder recipe for `sales.financial.configure` (for owner review — not applied)

This is a proposal only. No migration, seeder or command assigns it, and no real person or role is named here. The duty stays STAGED until the owner approves a recipe and the manifest is changed to ACTIVE.

| Who | Should hold it? | Why |
|---|---|---|
| **Financial configuration administrators** — the one or two named people accountable for how the company rounds and prices (for example the finance or commercial-policy owner) | **Yes, explicitly, as a direct grant to each named person**, company-wide | Changing rounding or unit-price precision changes what every new quotation settles to. It is sensitive (class S): explicit, auditable, few holders. |
| **Routine Sales operators** — people who prepare, price and issue quotations | **No** | They never need to *change* the policy. Their calculations read the policy in force automatically, and a read-only display of the version in force (ADR 0144 §6) needs no duty. |
| **Managers, administrators, "admin"-named roles** | **No by name or title** | Nothing is inferred from a role name. An administrator receives it only as a named holder, or through an existing verified full-access mandate (ADR 0114) — which, once the duty is ACTIVE, would include it on the next sync; that is why activation waits for this review. |
| **Roles** | Prefer none | A role would spread a sensitive duty to everyone later given the role. If the owner wants a role, it should be a dedicated, single-purpose one with an approved membership list. |

Before activation the owner should also confirm: the holder list and their effective scope (company-wide only is supported; a "my units" limit is refused); that the activation change is staged and verified with an authorised identity and a negative control (an ordinary seller must be refused on create and activate); and the backout (flip the status back — the Actions and history remain).

## Duties the matrix lists that other modules own

Not registered by Sales, not in the Sales manifest, and a test asserts the manifest contains none of them. Existing ones are reused by their exact string with their own contracts; no automatic Sales transfer.

| Duty | Owner | Registered today | Note |
|---|---|---|---|

The six unregistered ones (`tax.*`, `documents.*`) are registered by the Tax (FR2) and Documents (DF0–4) providers in their own slices.

## Entry-point inventory (AU0)

- **Existing surfaces:** the Lead, Opportunity, stage, note, owner-lookup and attribution-correction routes and Actions in `Modules/Sales`, each guarded by one of the eight duties as listed above; the Party candidate search allows lead/opportunity managers through `SalesAccess::may`. No console, import or worker consumes a Sales duty.
- **Internal Phase 4 code** (B1 `2ab3552`, PX1 `def0c48`, FR1 `f09b2a9`): `ResolveSalesPrice`, `ResolveQuotationFx` and the FR0 arithmetic have **no route, permission or UI**; they check no duty and a guarded Action must. `AssemblyWorkSourceAdapter` requires the existing `estimating.internal_cost.view` of its actor; no Sales duty substitutes for it.
- **New entry points added by AU1:** none. A test asserts no route names a non-active Sales duty and none exists for `/sales-fx-policies`, quotation rounding or quotations.
- **Application code naming a non-active duty:** none; a test scans `app/` and fails on any, so a consumer cannot appear before its slice flips the status.

## Idempotency, migration and backout

Registration is declarative and idempotent: it replaces the module's vocabulary and exclusions on each registry resolution, and `registerForTeam` only `findOrCreate`s. There is **no migration** and nothing to roll back; backing out is reverting this commit, which restores the eight-duty vocabulary byte for byte (the attribution exclusion text is unchanged). No permission row is created or removed by the code on deploy; rows still arrive through the existing deployment step.

## Verification evidence and what is not verified

Targeted: `SalesDutyManifestTest` (pure), `SalesDutyRegistrationTest` (registry, grants, mandates, scope, routes) plus the existing attribution-rollout, permission-registration and CRM suites and the architecture ratchets. Not verified: staging, real holders, the full backend suite, any public activation. See the delivery report for counts.
