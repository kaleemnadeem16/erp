[← Phase 5](phase-5-job-execution.md)

# Phase 5 — revised implementation and activation plan

2026-11-12 · Planning task #108; refreshes integration issue #69. **FOR OWNER REVIEW.**
Owner permits parallel Phase 5 planning and prioritises the receiver, but requires approval of
finite implementation scope before feature code or permission activation. No runtime checks,
implementation assignments, grants or rollout are established by this document.

## Inspected baseline and completed work

Source inspection: develop `f467852cb0bc67036fa32e4efd6e2caa13c0602e` (fast-forwarded from
`84ac2c51` during review). Another agent's initially dirty demo/access files were left untouched;
their committed local-preview change is now included. This plan uses a private worktree. Older status prose
and #69's October inventory are historical; source presence is not fresh runtime verification.

| Existing on this baseline | Reuse; remaining boundary |
|---|---|
| Quotation issue and immutable `quotation_issued_lines`; `RecordCustomerAcceptance`, full acceptance storage/API | Real issued/accepted scope exists. Local preview customer-decision activation exists at this baseline; no implication of production grants or complete Phase 4 sign-off. |
| `PrepareHandoverPlan`, preview/read/readiness APIs; migration `2026_12_12_100000_create_service_handover_preparation` | Real `handover_plans`, `handover_allocations`, `handover_stages`, `handover_baselines`, `handover_readiness_evidence`; FULL_ONLY SERVICE_ITEM preparation only. Reuse these keys, never create duplicate claim/intake foundations. |
| SP0b + J1-F1, eight Jobs tables and models | Company/same-Job keys, append-only guards, head/event coherence, assignment/holds/receipts already integrated (#28/#46). No real receiver, source FKs, baseline components/default activity. |
| J-AU0 and four internal lifecycle Actions (#62/#63) | Reuse `JobAccess`, catalogue/manifest, assign/start/hold/resolve and `JobLifecycleOperation`. All shipped duties remain SPECIFIED; source verifier binds to unavailable. No real execution or public API is established. |
| Shared numbering, approvals, access/scope, Documents and Inventory/Purchasing | Reuse existing owners/contracts where applicable. Their existence does not supply Jobs adapters or certify an obligation absent. |

Critical schema facts: all five preparation tables reject UPDATE/DELETE/TRUNCATE; stages are
constrained to PREPARED, baselines to version 1 and null completion conditions, readiness to NOT_READY.
Do not rewrite them or weaken guards to simulate release. Add immutable release/condition evidence
linked to the original preparation; derive effective release status from that evidence. New Sales
composite parent keys may be needed before Jobs FKs; reserve additive migrations after **all** actual
prerequisites, including the December-named migrations. No timestamp is reserved here.

## Product and Phase 4 boundary

One Jobs engine: basic assign → explicit start → optional independent holds/resolution → attest
whole deliverable → operational close. One default activity is created internally. No mandatory
hours, named labourer, percentage, template, signature, formal PDF or cost figure. Responsible
company member and accountable command actor remain real identities; assignment grants no rights.

Phase 4 can close against its separately approved quotation, acceptance and prepared-handover
scope, once those deliverables pass their own UI/security/rollout acceptance. Pending preparation
must honestly show that release has not occurred. This plan does not declare Phase 4 complete or
waive its other obligations. Sales release + Jobs receipt is a **shared integration milestone**;
Phase 5 completion/closeout and advanced costing are not indefinite Phase 4 closure gates.

The first operational milestone includes release, safe Job list/detail and all basic user actions
through close. Batch 1's disabled backend integration is an intermediate checkpoint, not an
operational delivery or an activation candidate by itself.

## Exact missing interfaces and acceptance contracts

Names below are proposed interface boundaries, not delivered classes or permission registrations.
Freeze signatures and finite paths in the approved batch before code; no unrestricted module scope.

| Interface / owner | Required input, output and refusal boundary |
|---|---|
| `SalesCompletionBasis` — A | Resolve genuine acceptance → accepted line → issued line and original consent/terms evidence. Return immutable condition identity/version, exact customer-agreed text/conditions, evidence provenance and applicable attestation/approval policy. Missing or ambiguous agreement refuses. Instructions alone cannot invent agreement. |
| `ServiceReleaseReadiness` — A, Inventory/Purchasing owners | Given real prepared stage and current company policy, return supported source facts, applicability proofs, relied-on generations, blocking requirements and safe next actions; distinguish UNKNOWN from evidenced NOT_APPLICABLE. Cost status and whether cost is required are separate. |
| `VerifiedServiceRelease` + `HandoverTransactionContext` — A | Canonically resolve company, acceptance/line, allocation, stage/purpose, immutable release baseline/conditions, source generation and permitted operational fields under actual fences. Verify runtime connection/backend session and enclosing transaction; caller booleans/hashes do not prove authority or locks. |
| `ReceiveReleasedService` — B | Accept only the trusted verified release context; independently require `job.receive`; bind actual Sales lineage through composite parent-consistent FKs. Create exactly one numbered RELEASED Job, origin/result, immutable baseline/component, default activity and RELEASE event/head. Return safe stable references. No public create-Job endpoint. |
| `JobExecutionContextVerifier` — A supplies source evidence, B integrates existing port | Replace the unavailable binding only for the delivered Sales origin. Enter source fences before locking Jobs, validate current legitimate access/readiness and pin conditions/generations. Unsupported origin refuses. Every affected source writer shares the fence protocol. |
| `JobCompletionParticipant` — B with A | Validate exact pinned whole deliverable and applicable evidence policy; append attestation, completion event and source fulfilment receipt exactly once atomically. Fulfilment does not free accepted capacity or settle an invoice. Missing mandatory approval refuses. |
| `JobCloseoutAssessment` — B with source owners | Revalidate completed outcome, holds and full source-family inventory/generations; append closeout evidence/event/receipt atomically. Proven excluded optional sources need no invented records. Unknown effects, disputed quantities or known pending obligations block. No implicit financial settlement. |
| Safe Jobs queries/commands — B; frontend owner consumes | Current guarded list/detail/history, eligible assignee lookup, allowed actions and safe own-operation context; independent permissions/scopes per command. No commercial amounts, cost/hash/private source evidence or attachments by implication. |

### Cost and simple completion refinement

`ServiceHandoverAssessment` currently adds SERVICE_COST_CERTAINTY_UNKNOWN unconditionally.
Proposed replacement: evaluate the applicable commercial/release control policy first. Required
cost evidence missing → block that path. Cost not required → preserve UNKNOWN/UNASSESSED explicitly
without blocking completion-only release/execution. No fake zero, completeness claim or cost grant.
Unknown estimate/optional cost coverage differs from a known incurred but unresolved source.
Closeout still follows0151: supported unresolved valuation needs a real owning follow-up/correction
path; unknown operational effects or missing mandatory source adapters block. The first pilot
excludes such incurred obligations rather than pretending a follow-up engine exists.

Default completion basis is the pinned, authoritative agreed deliverable text plus accountable
whole-deliverable attestation, with occurrence and recording time. If existing issued/consent
text is sufficient, reference/copy it exactly with provenance; if not, obtain new authorised Sales
agreement evidence before release. Never backfill customer agreement from a local checkbox.
Templates, signatures, attachments and formal approval apply only when an applicable supported
policy requires them; a missing required adapter refuses. See proposed [0186](../decisions/0186-deliver-one-progressive-jobs-engine-through-a-bounded-service-pilot.md).

### Genuine no-material/no-subcontractor boundary

Inventory/Purchasing owners must inventory all ways obligations could be attached: linked issues,
transfers/custody, reservations/demand, POs/services/hire, external expense/allocation, imports,
workers and corrections. Supply authoritative absence/applicability evidence and enforce refusal
of unsupported writes against the pilot lineage. Check at release, start, completion and close
under compatible fences/generations. An empty query, company setting, human declaration or
SERVICE_ITEM discriminator alone is insufficient. If all writers cannot be fenced, pilot stays
inactive or waits for real affected adapters. Optional labour/cost writers are likewise excluded.

## Three coherent implementation batches — all await finite-scope approval

### 1. Paired release and receiver (first priority; disabled)

Depends on joint source/security/source-owner review and owner decisions below. A delivers simple
completion evidence, conditional cost-readiness policy, source applicability/protection and immutable
release evidence/coordinator. B delivers actual source FKs, baseline component/default activity,
numbering consumer and receiver; reuse existing Jobs foundation and access. A and B jointly prove
same-session atomicity, lock order and both idempotency identities. Shared security owner supplies
strict no-automatic-growth support before activation; ordinary verified-mandate exclusions currently
permit growth and do not satisfy the Jobs rule. Include that bounded shared change in this milestone's
reviewed scope rather than invent a Jobs bypass. Duties remain disabled until Batch 3.

Candidate storage: Sales-owned `service_completion_bases` and `handover_release_events` with unique
company/stage/purpose and real acceptance/allocation/baseline bindings; current decision receipts
reused where their actual operation contract fits. Jobs extends origins with Sales-specific lineage,
adds `job_baseline_components` and `job_activities`. These are candidates to approve, not reserved
physical schema. Preserve original pending evidence and decline unmappable historical fixtures.

Candidate owned files: A's `Actions/ReleaseHandoverComponent.php`, `Support/Handover/` completion,
readiness, canonical verifier/context types, relevant Sales models/provider, additive migration and
focused storage/release tests. B's `Actions/ReceiveReleasedService.php`, existing ReleasedServiceInput/
Validator, origin/baseline models, new activity/component/result/numbering types, Jobs provider,
additive migration and receiver/commit tests. Before coding attach the **enumerated full file list**,
FK columns, interface signatures, migration reservations and writer inventory to #69; directory
names here are scope descriptions, not authorisation to edit whole directories.

Exit: genuine issued → accepted → prepared → released source/Job transaction works internally;
failure after each write rolls everything back; same/fresh key or actor creates no duplicate target,
number or claim. New release consumes no second acceptance claim. Fixture-only/mocked verifier
success is insufficient. No isolated Sales-only release or Jobs-only receiver sign-off.

### 2. Usable basic workflow (depends on 1; still inactive)

B integrates existing assign/start/hold/resolve with the source verifier and implements whole-service
completion + closeout with their immutable evidence and additive lifecycle constraints. Existing DB
allowlist refuses COMPLETE/CLOSE today: extend it through a new migration, never bypass it.
A supplies source fulfilment/readiness participation without reimplementing Jobs lifecycle.
Frontend owner delivers the Sales handover/release review plus Jobs list/detail and basic actions;
B/A deliver matching guarded Requests/Actions/Resources/OpenAPI and regenerated client together.
No backend-only operational sign-off. Source-safe command context supports close-only actors without
forcing broad job.view or job.cost.view; broad listing remains independently guarded.

Candidate endpoints: GET /jobs, /jobs/{job}, /jobs/{job}/history and scoped eligible-assignee lookup;
POST /handover-stages/{stage}/release; POST /jobs/{job}/assignments, /start, /holds,
/holds/{hold}/resolve, /completion, /closeout-assessments, /close. Actor/time/readiness/state are
server facts; writes use idempotency keys, expected generations and review-bound intent.
No generic PATCH state or POST /jobs. Names and exact finite backend/frontend files freeze before
Batch 2 authorisation. No unreviewed public read surface while duty activation is incomplete.

Exit: guarded API and browser journey from real accepted service to CLOSED, including independent
holds, stale/refused/retry and least-privilege cases, succeeds in an isolated authorised review
configuration. Production defaults stay disabled. Cancellation, stop, withdrawal and historical
corrections remain refused/deferred; the initial company must accept that recovery boundary.

### 3. Independent acceptance and explicit pilot activation (depends on 1 + 2)

Coordinator collects independent review, exact-SHA green CI and end-to-end evidence. Human owner
approves pilot company, supported source, policy/version, actual holders/scopes and deployment SHA.
Activate only supported duties and source/capability profile; no automatic admin/role/mandate growth.
Provide an authorised create-only demo scenario/personas, policy/holder provenance, guarded setup
and history surfaces for delivered configurable policies, activation preflight and safe backout.
Backout disables new work/rollout and preserves all evidence and legitimate independent grants;
active obligations need a reviewed recovery path, never destructive rollback or disappearing UI.

Exit: the named company can complete the whole first workflow, and an unrelated company cannot.
This is the **smallest useful operational milestone**, not completion of every Phase 5 capability.

## Ownership and independent review

These are proposed assignments, not claims on agents currently doing other tasks. #77 remains
Agent B's current work; schedule/appoint Jobs ownership before implementation. Do not spawn or
reassign agents as a side effect of this planning task.

| Role | Responsibility / independent reviewer |
|---|---|
| Agent A — Sales | Accepted source, completion basis, release authority, source fences, allocation/claim conservation, fulfilment participant and Sales APIs. Reviewed by B for transaction/source interface and an independent Sales/security reviewer for own changes. |
| Agent B — Jobs | Receiver, target storage/numbering, execution/complete/close, Jobs API/read profiles. Reviewed by A for source/atomic contract and an independent Jobs/security reviewer for own changes. |
| Frontend owner — proposed Agent D, appointment pending | Own all new Jobs screens/hooks/navigation and handover release UI; coordinate existing quotation UI ownership. Consume generated contracts; no shared component redesign without review. Independent UI reviewer plus non-author operator runs journey/accessibility checks. |
| Shared security owner — appointment pending | Strict grant exclusion, per-duty ALL scope, current actor/replay/read fencing and holder rollout. Reviewer independent of shared implementation author; retain Sales/CRM mandate behaviour. |
| Inventory and Purchasing owners | Certify applicability writer inventory, enforce unsupported-path restrictions and review source races. Neither A nor B silently edits these modules. |
| Coordinator/integrator + human owner | Assign named reviewers/owners, reserve migration slots, reconcile all actual locks, integrate only approved exact commits, assess CI and activation. Authors cannot approve their own milestone. |

Cross-review A↔B is necessary but insufficient where both authored integration; an independent
security/transaction reviewer must assess the paired milestone. Actual reviewer identities remain
an owner appointment, never invented names or implied completed reviews.

## Company configuration and future origins

Basic workflow is one default activity with completion-only evidence. Future optional extensions:
labour recognition, material custody/consumption, supplier services/hire, cost/coverage/reporting,
multiple activities and advanced approval/evidence policies. Each enables independently of unrelated
extensions, with its real dependencies; not a single small/large-company tier. Prefer cohesive typed
module policies and optional records using existing configuration patterns, not a feature-flag engine.

For each delivered capability record safe default, who configures it, reason/history, effective
version, setup validation, existing-record behaviour and downgrade/refusal path. Configuration has
no effect until adapters are delivered; never offer switches that pretend unsupported features work.
Company capability does not grant access. View preferences cannot alter execution, hide relied-on
facts or erase historical evidence. Disable/downgrade refuses incompatible open obligations and
preserves permitted historical detail. Later enablement of costing cannot silently recost old Jobs.

Keep Sales FKs and consent/claim rules in the Sales-origin binding/adapter. Jobs uses canonical
operational identity, immutable baseline/conditions and source verification/fulfilment/closeout ports.
Future maintenance, warranty and internal-service adapters supply their own real company-scoped
source tables, authority, business uniqueness and verified readiness; additive origin-specific FK
bindings, not fake quotation rows, universal nullable IDs or unvalidated polymorphic strings.
Implement only Sales now; unsupported origins fail closed. No maintenance/warranty engine is in scope.

## UI coverage for Batch 2 and activation

| Contract | UI destination / scenario |
|---|---|
| Preparation/release | Existing acceptance context → handover review with source, agreed terms, location/contact/site, safe blockers, policy-required evidence and explicit release confirmation; target link on authorised replay. No fake READY. |
| List/search/filter/sort | Server-paginated Jobs list; code, customer-safe label, deliverable, effective state/holds, responsibility and relevant dates. Filters for delivered state/hold/assignee/search; sort fields match backend. No bulk operations in first scope. |
| Detail/history | Default RecordSheet with direct link, list context preserved, original terms/conditions and permitted source link, responsibility/start/holds/completion/closeout and safe chronological history. No cost/financial/PDF tabs in basic mode. |
| Assign/start | Searchable server-backed current member picker, optional supported window/instructions, separate guarded actions; no inferred authority from assignment. Unsupported team/on-behalf selection absent. |
| Hold/resolve | Reason entry, individual holds and named resolution; resolving one does not clear another or start work. |
| Complete/close | Show exact pinned basis and required attestation; separate close readiness/review. Unknown costs never labelled zero or settled; blockers explain permitted next action without sensitive source data. |
| All surfaces | Loading/empty/filtered-empty/error/denied/stale/held/completed/closed states; same-key retry, pending-submit protection, query refresh, company cache isolation, keyboard, light/dark, 320px without overflow. |

## Outstanding owner decisions before finite code authorisation

1. Select actual pilot company/source and accountable Inventory/Purchasing reviewers. Recommended:
   a real fixed service with evidenced and enforceable no-material/no-supplier obligations.
2. Approve proposed0186 and the simple completion basis: existing exact agreed text where sufficient;
   otherwise a new Sales agreement record before release. Select any mandatory evidence/approval.
3. Confirm applicable cost/control policy: missing optional cost does not block; mandatory commercial
   cost controls remain. Accept first-pilot exclusion of unpriced incurred obligations lacking follow-up.
4. Approve initial responsibility policy: active company-member assignment, with self-start by the
   assigned authorised actor; manager/on-behalf execution requires an explicit reviewed alternative.
   Approve refusing cancellation/stop/withdrawal/reopening until separate recovery slices exist.
5. Appoint A/B/frontend/shared-source owners and independent named reviewers; approve company-specific
   holder/scope recipe and the Phase 4 closure boundary. No holder or reviewer is assumed.

After these choices, A/B submit Batch 1's finite file/interface/schema/lock inventory for approval;
Batch 2 and activation each retain their own reviewed finite scope. No additional tiny foundation
issues are proposed; #69 coordinates the paired milestone and #108 carries this planning review.

## Engineering evidence required at delivery

Company-composite and same-parent raw-SQL attacks; immutable row/truncate evidence; safe migration
preflight and empty apply→rollback→reapply plus populated refusal; shared guard preservation.
Current actor/duty/ALL-scope in Request and Action, sensitive read projections, cross-company absence,
revocation/suspension before fresh and replay commands; strict no-growth including verified mandates.
Two real PostgreSQL sessions prove release/release, source mutation/release, hold/start, complete/hold,
close/source-write and access-change races. Inject failures after every source/target/evidence/receipt
step and at deferred COMMIT. Prove one runtime connection and agreed acyclic lock order including
access, Sales authority, source claims, source-owner fences, numbering and Jobs. No guessed lock order.

Use ERP_AGENT wrappers/private databases: focused Jobs/Sales/source regressions, applicable tenancy,
permission/read/write/scope/Action-boundary ratchets, touched-file Pint, whole-project PHPStan,
migration cycle, OpenAPI/client regeneration/drift, all frontend typechecks/build and relevant unit/
browser journeys via main-checkout wrapper. Exact-SHA hosted CI green plus independent reviews before
integration/activation; verified failures identify cause/owner. Docs, journeys, demo, coverage matrix,
status and handoff update with implementation. This planning task checks links/whitespace only.

## Related

- [Phase 5 overview](phase-5-job-execution.md) · [Task G ordering](phase-5-task-g-r7-batch-proposal.md)
- [Receiver design](phase-5-r7-job-receiver-design.md) · [Task E source integration](phase-5-task-e-source-integration.md)
- [Service preparation](../domain/sales-service-handover-preparation.md) · [Full acceptance](../domain/sales-full-acceptance.md)
- [Jobs module](../../backend/app/Modules/Jobs/README.md) · [Closeout](../domain/job-closeout-reconciliation.md)
- [Capability delivery](../architecture/capability-delivery-contract.md) · [Screen delivery](../architecture/screen-delivery-contract.md)
