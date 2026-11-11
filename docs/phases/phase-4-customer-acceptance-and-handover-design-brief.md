[← Phase index](README.md)

# Customer acceptance and handover — design brief

**2026-11-10 · DESIGN BRIEF. Plans the last Phase 4 feature; authorises no grant or activation.** Slice 1 was built on 2026-11-11 under the recommended defaults of section 12 (owner has not yet answered them): see [Sales customer decisions](../domain/sales-customer-decisions.md#delivered-record-what-the-customer-sent-acceptance-slice-1-2026-11-11). Backend slice 2 is implemented locally, inactive, without screens or acceptance demo grants; see [the backend contract](../domain/sales-full-acceptance.md). Slice 3 preparation backend is implemented locally and inactive ([contract](../domain/sales-service-handover-preparation.md)); slice 4 is not started.
It does not re-decide anything accepted. The business rules are already settled in
[ADR 0104](../decisions/0104-separate-customer-acceptance-and-readiness.md),
[0125–0127](../decisions/0125-govern-customer-acceptance-and-explicit-partial-consent.md),
[0131–0132 and 0136](../decisions/0136-govern-source-appropriate-operational-handover.md);
the full contracts are [sales-customer-decisions](../domain/sales-customer-decisions.md),
[sales-operational-handover](../domain/sales-operational-handover.md), the
[duty matrix](../domain/sales-duty-matrix.md), the [CA slices](phase-4-r6-implementation-slices.md),
[HA slices](phase-4-r7-implementation-slices.md) and the Phase 5
[Task G delivery matrix](phase-5-task-g-r7-delivery-matrix.md) (G-A2 acceptance, G-A3 handover
preparation, G-A4 release) and [Job receiver design](phase-5-r7-job-receiver-design.md). This brief
adds what those do not: a verified current-code baseline, a revised slice order in which every slice
is a finished feature, and the decisions the owner still has to make.

## 1. Built versus missing (verified in code, 2026-11-10)

| Piece | State |
|---|---|
| Issued quotation: `QuotationIssue`, `QuotationIssuedLine` (frozen quantity, UoM, price, net/tax/gross, `choice_group_id`, `is_baseline`, `logical_scope_id`), `valid_through_local_date`, `expires_at`, official number | Built, with API (`/quotations/{revision}/issued`) and UI |
| Availability (`QuotationAvailability`: AVAILABLE, EXPIRED, WITHDRAWN, REPLACED), `WithdrawQuotationOffer`, `IssueQuotationReplacement` | Built. Both refuse through the `QuotationAcceptanceState` seam, **bound to `NoAcceptanceYet` (always false)** |
| Intake foundation tables `customer_decision_cases/components/evidence` (append-only, tenant-safe), `customer_decision_receipts` | Built (migrations 2026-10-27, 2026-11-02, 2026-12-10) |
| `OpenCustomerDecisionCase`, `AppendCustomerDecisionEvidence`, `CustomerDecisionOperation` (per-actor request key, intent hash, replay, one retry on receipt-unique race), `CustomerDecisionRefusal` reason codes | Built, Action-level only |
| Duty `sales.customer-decision.evidence.record` | Declared STAGED; **no one holds it** (manifest refuses every caller) |
| HTTP routes, Requests, Resources, OpenAPI, generated client, UI, demo seeder for any customer decision | **Missing** |
| Other duties in the matrix (`view`, `accept`, `reconcile`, `correct`, `handover.*`) | **Missing** (not even constants) |
| Tables: `customer_acceptances`, `_lines`, `customer_scope_claim_heads`, `customer_claim_events`, `customer_evidence_policy_versions`, `customer_entitlement_consumptions` | **Missing** |
| `PreviewCustomerAcceptance`, `RecordCustomerAcceptance`, `AssessAcceptanceReadiness` | **Missing** |
| Handover tables (`handover_plans/allocations/stages/baselines/readiness_evidence/receipts`), `PreviewHandover`, `PrepareHandoverPlan`, `ReleaseHandoverComponent` | **Missing** |
| Receiving side: `ServiceJob`, `JobBaseline`, `JobHandoverOrigin` (receiver receipt shape), `ReleasedServiceInput/Validator` in Jobs | Foundation only. `ReceiveReleasedService` does **not** exist; origin has no FK to Sales acceptance yet |
| Sales Order / Order module | **Does not exist** (no goods handover target) |
| `DecisionActor` (shared, `App\Support\Scope`) | Exists and is used by Inventory/Purchasing/Approvals. **No Sales action uses it**; the CA1 Actions only call `ActiveActor` (a fresh active-member read) and `SalesAccess`, outside the company access fence |
| Opportunity win/outcome | `CloseOpportunity` is manual; there is no link from accepted business to outcome (correct per ADR 0101: no "mark won" bypass) |

Consequence: the whole feature is invisible to users today. CA1 has guarded Actions and nothing
exposes them, so slice 1 below is a real end-to-end feature, not a gap-filler.

## 2. The acceptance flow

1. Seller issues a revision (built). The customer sends a PO, email, signature or verbal
   confirmation. Delivery of the document is evidence of sending, never of consent (ADR 0135).
2. **Intake.** An authorised person opens a shared case for the customer Party (verified intake
   identity, never PO text alone) and appends textual observations (what was sent, source, how
   the sender's authority was checked, truthful occurrence precision). Observations consume nothing,
   however late or contradictory.
3. **Preview acceptance** (read-only): choose case component, issued revision, scopes and (for
   choose-one groups) the selected alternative. The server returns exact agreed terms, current
   eligibility (available, not expired/withdrawn/replaced, scope not already claimed, group not
   already chosen), the unchanged-terms statement and an opaque, short-lived, actor-bound token.
   No cost, margin or fingerprint is exposed.
4. **Record acceptance.** Same intent plus the token, expected generations and a request key.
   Under locks it re-checks everything, appends the immutable acceptance, its lines and claim
   events, and updates the claim heads in one transaction. Accepted business is a fact; it is not
   a mutable flag on the quotation, and not an Order, reservation, invoice or revenue.
5. **Readiness** (read-only `AssessAcceptanceReadiness`): shows what is still missing for the
   chosen operational path (site, contact, warehouse, cost certainty). Accepted-but-not-ready is a
   normal, visible state, never an error.
6. **Handover** (section 4) is a separate, later act by a different duty.

First delivery stays at ADR-approved bounds: **full, independently acceptable scope with unchanged
terms**. Smaller quantity is a request needing a reissued offer and fresh consent, not an
acceptance. No acceptance variation editor, no document-discount input.

## 3. State machine

Records are append-only facts; "state" is derived. Nothing is ever edited or reopened.

| Subject | States and transitions |
|---|---|
| Offer availability (built) | AVAILABLE → EXPIRED (clock) / WITHDRAWN / REPLACED. These stop only *unaccepted* scope. Withdraw/replace refuse once any decision stands on the revision |
| Observation | OBSERVED (the only value in CA1). Corrections add a successor; a chain never forks |
| Case component | PENDING → ACCEPTED (one immutable acceptance per component, DB-unique). Rejection is an observation, not a state change on accepted scope |
| Claim head per scope/group | `consumed` rises 0 → capacity (check constraint), never decreases in this feature; choose-one group records one selected identity. No release path (deferred, `sales.customer-decision.claim-release`) |
| Acceptance readiness | NOT_ASSESSED → READY / NOT_READY(missing facts) per path, versioned and recomputed, never stored as acceptance truth |
| Handover stage (slices 3-4) | NOT_PREPARED → PREPARED → RELEASED (target created in the same transaction). HELD, successor baseline and unused-allocation cancellation are later slices (HA4-HA5) and not designed here |

Illegal and refused: accepting an expired/withdrawn/replaced revision (`QUOTATION_NOT_AVAILABLE`),
second acceptance of a claimed scope or a second option in a chosen group, accepting from a stale
case/claim generation, accepting on an observation that is not the case's current head.

## 4. Handover and allocation-readiness prerequisites

Release needs all of: a genuine accepted component (root), a supported source path in a registry
(a source type alone is not support), a named, delivered target adapter, an immutable canonical
baseline (agreed scope, conditions with a version, commercial/source/contact/site facts), current
readiness for that path, and a root allocation cap that conserves the accepted quantity.
Goods have **no target** (no Order module), so a goods acceptance stays accepted-not-ready;
nothing falls back to a fake Job or Assembly. Release is not start, reservation, invoice or
completion. The Job receives permitted operational fields only, never the confidential commercial
or cost baseline.

First supported path (per Task G and the Job receiver design): one FULL_ONLY accepted
`SERVICE_ITEM` fixed deliverable, one root allocation, one initial Job stage, unmeasured
operational scope (null measure, explicit deliverable identity). Partial, split, Assembly and
manual-stock handover stay disabled.

## 5. Permissions and staged duties

Names are the accepted catalogue; no new ones are needed. Each ships its grant recipe with the
slice that first uses it (authorization R1-R11), and all stay STAGED until the owner reviews a
holder recipe. No production grant is made by this work.

| Duty | Slice | Sensitivity |
|---|---|---|
| `sales.customer-decision.view` (new constant) | 1 | normal; independent read of case/Party/quotation |
| `sales.customer-decision.evidence.record` (exists) | 1 | normal |
| `sales.customer-decision.accept` | 2 | sensitive; independent decision read also required |
| `sales.handover.view`, `.prepare` | 3 | normal |
| `sales.handover.release` | 4 | sensitive; plus the target-owner duty in Jobs |

Duties stay separate: recording evidence is not accepting; accepting is not issuing or
withdrawing; preparing is not releasing. No automatic grant to a role name. Record scope: the
limited-scope refusal of ADR 0106 applies (unsupported limited scope fails closed), cross-company
ids return not-found, hidden competing-family claims never appear in a refusal.

## 6. Idempotency, races and DecisionActor

* **Request receipt.** Reuse `CustomerDecisionOperation` unchanged: per `(actor, request_key)`,
  intent hash, same intent replays, different intent returns `REQUEST_KEY_REUSED`. Add the
  acceptance and handover operation names to its receipts; no second receipt mechanism.
* **Business identity, separate from the key.** `UNIQUE(company, decision_component_id)` on
  acceptances plus claim-head `consumed <= capacity` mean a fresh key from another employee cannot
  accept the same component twice: it returns the existing matching result, or conflicts if the
  intent differs. A retry never silently picks a newer offer.
* **DecisionActor.** Recording acceptance and releasing handover are irreversible decisions.
  Inside the transaction, first `DecisionActor::require($actor, <duty>, 'accept customer
  decision')` (takes the shared company access fence, rereads the actor as ACTIVE with fresh
  duties), then lock case, claim heads and revision, then the receipt look-up, then work. A
  suspended or stripped person is refused even when replaying. The Request and the Action still
  guard earlier for a fast refusal. **Retrofit:** `OpenCustomerDecisionCase` and
  `AppendCustomerDecisionEvidence` currently use `ActiveActor`/`SalesAccess` only; slice 1 moves
  them to `DecisionActor::require` so one rule governs the whole feature.
* **Lock order** (single, documented, no inverse acquisition): access fence, then case, then
  family/choice-group claim heads in ascending id, then issued revision/disposition, then
  acceptance and claim-event inserts. Withdraw/replace/expiry must take the same claim heads, so a
  race between "accept" and "withdraw" has exactly one winner: if acceptance commits first the
  seam reports `stands` and withdrawal refuses; if withdrawal first, acceptance refuses
  `QUOTATION_NOT_AVAILABLE`.
* **Expiry** is judged against the company calendar day (`Asia/Karachi`) and the stored
  `expires_at`, at the moment under the lock, never from the client clock.
* **No raw uniqueness 500s.** A unique violation is resolved by looking up the winner, checking
  current company and access, and matching intent (existing pattern).

## 7. API surface (all under `auth:sanctum`, Form Request plus Action guard, `reason_code`s)

Slice 1: `GET /customer-decision-cases` (scoped list), `POST /customer-decision-cases`,
`GET /customer-decision-cases/{case}` (safe detail, evidence chain, components),
`POST /customer-decision-cases/{case}/evidence`.
Slice 2: `GET /quotations/{revision}/acceptance-preview`,
`POST /quotations/{revision}/acceptances`, `GET /customer-acceptances/{id}`,
`GET /customer-acceptances/{id}/readiness`; the issued-quotation Resource gains per-scope
accepted/available/claimed facts, additively.
Slice 3: `GET /customer-acceptances/{id}/handover-preview`, `POST .../handover-plans`,
`GET /handover-plans/{id}`. Slice 4: `POST /handover-plans/{id}/release`.
Refusal codes added: `ACCEPTANCE_NOT_ELIGIBLE`, `SCOPE_ALREADY_CLAIMED`,
`CHOICE_ALREADY_SELECTED`, `PREVIEW_TOKEN_STALE`, `HANDOVER_PATH_UNSUPPORTED`,
`HANDOVER_NOT_READY` (existing `CASE_STALE`, `REQUEST_KEY_*`, `INTAKE_IDENTITY_EXISTS` reused).
OpenAPI, generated client and route-scope ratchets are committed with each slice.

## 8. Screens (sheets by default, light/dark, phone width, every state)

1. Customer decisions list + case sheet: intake form, evidence chain, "no consent recorded yet" banner.
2. On the issued quotation: an "Accept" panel (scope selection, choose-one selector, preview sheet
   with exact terms, readiness checklist, confirm with idempotent retry on network failure);
   per-scope badges accepted / available / claimed elsewhere; the disabled reason shown when
   the user lacks the duty or the offer is not available.
3. Acceptance detail: immutable terms, evidence link, claims, readiness panel.
4. Handover sheet: path support, missing-facts next actions, prepared plan, release confirmation
   showing exactly what the Job will receive and what it will not.
No "Won" wording from acceptance; win/loss on the opportunity stays a manual, separate step.

## 9. Tests (per slice, in addition to the applicability checklist)

Action-direct and HTTP denial per duty; cross-company; limited-scope; suspended/duty-stripped actor
refused inside the transaction (DecisionActor); same key replay, key reuse with a different
payload, fresh key + other actor returns one acceptance; real two-connection races
(accept vs accept, accept vs withdraw, accept vs expiry boundary, choose-A vs choose-B); stale
case generation; DB-level refusal of raw inserts (cap, cross-tenant, UPDATE/DELETE/TRUNCATE on
evidence, acceptance, claim events); rollback after a forced failure mid-transaction leaves no
receipt; `NoAcceptanceYet` replacement verified by withdrawal/replacement now refusing; migration
apply, rollback (refuses with evidence), re-apply; ratchets for routes, permissions, tenant
tables; UI browser spec with phone width; demo seeder idempotent.

## 10. Rollout slices (each a complete, usable feature, in order)

| # | Slice | Delivers end to end | Does not include |
|---|---|---|---|
| 1 | **Record what the customer sent** (CA1) | Routes, Requests, Resources, client, case list/detail/evidence UI, `view` duty, DecisionActor retrofit, grants recipe (STAGED), demo seeder | Any consent, claim or acceptance |
| 2 | **Record full acceptance** (CA0 remainder + CA2 + basic CA6) | Policy-version, claim heads/events, acceptance + lines tables, Preview/Record Actions, readiness read, `accept` duty, `QuotationAcceptanceState` rebound to the real reader (withdraw/replace now respect acceptance), accepted/available/claimed display, demo | Partial quantity, reduction, handover, auto-WON |
| 3 | **Prepare handover for a service** (G-A3: HA0 + HA1) | Handover tables, source registry (SERVICE_ITEM), Preview/Prepare Actions, readiness sheet, `handover.view/prepare` | Creating any Job or stock commitment |
| 4 | **Release a service to a Job** (G-A4 + Jobs `ReceiveReleasedService`, HA2) | Release Action, same-transaction Job + baseline creation, origin FKs, release sheet, `handover.release` | Goods/Order, partial, schedule, hold, correction |

Jobs-side receiver work in slice 4 is owned by the Phase 5 Jobs task; Sales may not write Jobs
tables. Deferred and gated (not in this feature): divisible partials (CA3), remainder reoffer (CA4),
late recognition and accepted-business correction (CA5, HA5-HA6), goods Order path, schedules,
holds, claim release, evidence file upload (needs the shared file foundation).

## 11. Risks

* **Withdraw/replace silently wrong until slice 2.** They currently assume no acceptance exists.
  Slice 1 must not enable `evidence.record` for anyone who could believe a recorded observation
  protects the offer; the UI states observations do not hold the offer.
* **Lock-order inversion** with quotation issue/replacement/approval writers: one documented order
  and a deterministic two-connection test per writer pair.
* **Accepting on live data.** Acceptance must use frozen issued terms and the Q/FX/tax evidence on
  the issue, not current prices or rates.
* **Fabricated history.** Revisions issued before slice 2 have no claim heads: backfill creates
  heads from issued lines with a labelled baseline and zero consumed; it invents no consent.
* **Duplicate acceptance via fresh key** is prevented by the business unique constraint, not the receipt.
* **Scope creep** into partials or goods handover: each is refused with a stable code, never half-built.
* **Sensitive grants** are an operational step for the owner; nothing here assigns them.

## 12. Open owner questions (each with a recommended default)

| # | Question | Recommended default |
|---|---|---|
| 1 | Who may hold `evidence.record` and `accept`? | Different people by default: administrative staff record evidence, a named sales approver accepts; no overlap forced, but both reviewed per recipe before activation |
| 2 | May the person who issued the quotation also record its acceptance? | Yes (small teams); show the fact in the audit view, no hard block |
| 3 | Is a verbal confirmation sufficient consent? | Allowed as a source kind only with a recorded authority-verification basis and a second observation reference; the company evidence policy decides, default off for verbal until the owner enables it |
| 4 | Must the quotation PDF have been sent before acceptance can be recorded? | No (ADR 0135); show a warning when no delivery is recorded |
| 5 | Does acceptance automatically move the opportunity to Won? | No; show "accepted business" and let the owner close the opportunity manually, as today |
| 6 | First handover target? | Service Job only (matches Task G); goods accepted-not-ready until a Sales Order module is scoped |
| 7 | Are expired offers acceptable late? | No, refuse; late acceptance is the deferred CA5 reconciliation with its own duty |
| 8 | Backfill of already issued quotations | Create zero-consumed heads lazily on first acceptance, labelled "baseline captured", never retroactive consent |
| 9 | Release approval: shared Approvals engine or single duty? | Single sensitive duty by default; add approval policy only if the owner asks (configured, not universal) |
| 10 | Slice 1 before slice 2 on the same release? | Ship slice 1 hidden behind the STAGED duty and enable it only together with slice 2, so no one records evidence the system cannot act on |

## Assumed defaults (slice 2, owner review pending)

1. Evidence recorders and acceptance approvers are separately reviewed holders; no holders or grants are added.
2. The issuer may also record acceptance; their identity remains in the immutable audit evidence.
3. Verbal is disabled by the initial evidence policy; enabling it later needs authority verification and a second observation reference.
4. Sending a PDF is not required for acceptance; absence of recorded delivery is a warning.
5. Acceptance does not automatically mark an Opportunity Won.
6. Service Job is the intended first target; goods remain accepted-not-ready; this slice delivers no target adapter.
7. Expired offers are refused; late reconciliation remains deferred.
8. Missing claim heads are created lazily with zero consumption and `BASELINE_CAPTURED`, never backdated consent.
9. Future release uses a single sensitive duty by default; no release or approval policy is implemented here.
10. Intake and acceptance stay STAGED pending combined owner rollout; the new accept duty is excluded from dev preview and receives no demo grants.

Slice 3 continues defaults 1–10 unchanged. In particular, **6** means SERVICE_ITEM → SERVICE_JOB
preparation only, goods remain pending with no Order target; **9** means a future single sensitive
release duty, without universal shared Approvals. No release, Job, grants or preview activation
are added. Plan reads reuse acceptance-view rules as the owner requested; only
`sales.handover.prepare` moves to STAGED. Remote/on-site operational context does not invent
commercial conditions, service cost or verified Inventory/Purchasing non-applicability; absent
source/receiver contracts remain explicit pending facts. These assumptions remain overridable.

## Related

[Phase 4 handoff](phase-4-implementation-handoff.md) · [R6 customer decisions](phase-4-r6-customer-decisions.md) ·
[R7 handover research](phase-4-r7-handover-research.md) · [Authorization](../architecture/authorization.md) ·
[Domain integrity](../architecture/domain-integrity.md) · [Feature completion checklist](../architecture/feature-completion-checklist.md)
