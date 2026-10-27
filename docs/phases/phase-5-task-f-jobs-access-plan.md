[← Job lifecycle](../domain/job-execution-lifecycle.md)

# Task F — Jobs duties, holders and activation plan

2026-10-27 · #51 · PROPOSED DESIGN ONLY. No code, migrations, permission rows, grants, live
inventory audit or activation. Every holder option below needs explicit owner approval of actual
principals. ADRs 0145–0152 and the accepted Sales/R9 meanings remain unchanged.

## Baseline and pattern reuse

Branch starts at published develop `5f776203ee4cca3fd7e35aef92369a9d9099cf8d`. Reuse
[Task B](phase-5-task-b-j1-execution.md), [Task D](phase-5-task-d-j1-foundation.md),
[R9 rollout](phase-4-r9-permission-rollout.md) and [authorisation](../architecture/authorization.md).
Inspected [Sales manifest](../../backend/app/Modules/Sales/Support/Authority/SalesDutyManifest.php),
[duty status](../../backend/app/Modules/Sales/Support/Authority/DutyStatus.php),
[SalesAccess](../../backend/app/Modules/Sales/Support/SalesAccess.php),
[RecordScope](../../backend/app/Support/Scope/RecordScope.php),
[registry](../../backend/app/Support/PermissionRegistry.php) and
[mandates](../../backend/app/Support/FullAccessMandates.php). Existing research/accepted contracts
answer the business questions; this is a module-specific technical plan, not new policy authority.
JobsServiceProvider currently binds/registers nothing; SP0b tables are not a delivered access system.

Mirror Sales' explicit catalogue, manifest, per-duty scope guard and current-authorised replay.
Do not copy Sales' CRM read fallback or grant-growth assumptions: Jobs general worksheet visibility
does not follow from possessing any write duty. A close-only actor gets necessary safe own-operation
reads without job.view/job.cost.view or broad history. Sales and Jobs authorise independently.

## Exact proposed catalogue

The following names are the complete catalogue proposed by this task, not a registered vocabulary.
All are **SPECIFIED on this baseline**: no guarded Jobs consumers exist. STAGED is a later delivery
state, never a synonym for planned. No ACTIVE Jobs duty is proposed. J2 names below refine future
technical vocabulary only; they cannot widen J1 authority or assume Task C adapters are delivered.

| Exact duty | Operation / minimum projection | Candidate explicitly appointed holder option; additional gate |
|---|---|---|
| job.view | Company operational list/worksheet/general operational history; no costs/private source evidence | Named operational reader or explicitly reviewed role members; safe query/read adapter |
| job.receive | Internal Sales-coordinator-only receive of verified released scope; safe result references | Named release initiator also independently eligible under Sales; actual receiver/verifier and transaction proof; no receiver route |
| job.assign | Append supported responsibility/window change; only necessary eligible-person selector | Named dispatcher/operational allocator; assignee is not automatically a holder; user/team identity policy gate |
| job.start | Explicit supported execution start; necessary Job/baseline/hold context only | Named execution initiator; assignment and reviewed on-behalf context independently checked |
| job.hold | Place one independent hold with reason; safe affected-work context | Named operational controller; applicable Sales hold authority separate |
| job.hold.resolve | Resolve named hold after current prerequisites; other holds remain | Named authorised resolver; neither hold creator nor job.hold implies resolution |
| job.complete | Attest canonical fixed deliverable; no hours/cost prerequisite | Named accountable attester; actual pinned conditions, fulfilment and approval where configured |
| job.completion.withdraw | Governed unclosed completion withdrawal and dependent effects | Named sensitive-correction holder; original evidence and all dependent reconciliation |
| job.cancel_unused | Proven unused cancellation; safe remainder/impact context | Named operational disposition holder; actual proof/receipts; not Sales claim release |
| job.stop | Stop worked Job, retaining actuals and responsible remainder | Named operational disposition holder; source obligations are not cancelled implicitly |
| job.close | Safe readiness assessment and operational close; no cost values | Named closer; independent safe record/readiness profile, actual source inventory/dispositions; no job.view/job.cost.view prerequisite |
| job.history.record | Capture truthful late/held report with original context | Named reporter or reviewed on-behalf reporter; no recognition/fulfilment inference |
| job.history.review | Classify historical/held report with legitimate evidence access | Named historical reviewer; no automatic financial/source posting |
| job.history.correct | Apply supported operational historical correction lineage | Named sensitive-correction holder; independent source correction authority when effects require it |
| job.labour.record | Optional supported internal labour evidence, authorised input only | Named internal labour recorder; J2 method/context and own/on-behalf policy |
| job.labour.amount.declare | Accountable direct incurred internal labour declaration | Named accountable declarant; labour.record also required for recording, no external/procurement bypass |
| job.labour.recognise | Recognise exact supported labour revision and allocate once | Named recognition holder; method/review/evidence policy, not implied by record |
| job.labour.rate.adopt | Adopt and pin supported actual class-rate basis | Named valuation holder; later usage/rate adapter, no live-master recost |
| job.labour.correct | Governed original/effective labour quantity/value/scope correction | Named labour correction holder; old/new scope and mandatory dependent effects |
| job.cost.view | Current-authorised cost projection with certainty/coverage | Named cost reader; real source/report consistency and independent source fields |
| job.cost.coverage.declare | Scoped accountable completeness declaration | Named coverage declarant; supported inventory, no known omission/dispute; no automatic confirmation |
| job.cost.coverage.reconcile | Authoritative source-backed coverage reconciliation | Named reconciliation holder; actual complete writer/source inventory, not a checkbox |
| job.cost.export | Export exactly permitted cost projection | Named exporter plus job.cost.view; independent files/download/retention adapter if applicable |

No combined assign/start permission: both job.assign AND job.start independently pass. Combined
labour record/recognise requires each duty and job.labour.amount.declare for a direct declaration.
Holding recognition implies no recording, rate adoption or full Job cost visibility. Duties name
eligibility, not an approval decision. Existing shared Approvals and source-specific duties remain
independent; no new universal second approver or job.approve catch-all is selected.
job.history.correct never replaces job.labour.correct or Inventory/Purchasing correction authority.
No generic job.manage, job.*, unrestricted PATCH state, claim-release or hold-bypass permission.
Documents/attachments use their owning current access; operational job.view cannot disclose files.
No team/manager/delegation capability is added. Worker identity is distinct from responsible assignee.

Preserve Task B's exact job.cancel_unused spelling. Jobs catalogue validation accepts this explicit
name under the shared registry's underscore-compatible naming rules; do not blindly copy Sales'
narrower hyphen-only regex or silently alias names. Duplicate/unknown/wildcard/foreign-namespace
entries refuse. Exact catalogue matches every guard, test, later constant and reviewed holder row.

## Lifecycle and access algorithm

| Manifest state | Required evidence and runtime result |
|---|---|
| SPECIFIED | Named operation/projection/gate, no implemented guarded consumer. No registration, row, grant, constant needed by a consumer or reachable writer |
| STAGED | Real consumer, Request where HTTP applies, owning Action, safe projection and negative tests delivered; manifest refuses all callers, even hand-made permission rows/mandates. No live registry vocabulary or grantable row |
| ACTIVE | Same reviewed implementation plus all source/holder/registration/automatic-growth/staging gates satisfied and separately authorised release. Registered exact names only; actual grants explicit |

Jobs-owned JobDuty/JobDutyStatus/JobDutyCatalogue/JobDutyManifest mirror the structure, not the
Sales namespace or its Existing-CRM classes. JobAccess may/require/mayAll consult Jobs manifest;
unknown, non-ACTIVE, null actor or missing permission denies. For each required duty independently,
resolve RecordScope for THAT exact name and require ALL, never a list whose widest unrelated duty
wins. Empty mayAll denies. Current actor/company, active membership and target company are checked
by the owning operation; a user from a different company cannot reuse a cached team context.

Units/own/assigned/team-limited modes are unsupported and refuse, not partial query filtering or
client hiding. An actual explicit ALL grant of the same duty may yield ALL under existing additive
semantics; a limitation is not a deny overriding that grant. Record the effective scope and every
grant origin. An unrelated unrestricted duty never rescues a limited required duty. No RecordScope
schema/semantics extension is proposed. Do not inherit the unrelated Agent C QA1 work.

Guard in Request AND owning Action, including console/import/worker/direct invocation. For internal
receive there is no Request/route: the coordinator and owning Action each check their independent
capabilities and trusted source contract. A current actor passes, not an unscoped system bypass.
Recheck actor/company/scope/projection before receipt replay and before new effects under compatible
authority/source fences. Current revocation, membership change and context switch cannot reveal
cached privilege. Source-sensitive old/new records each need independent access. Operational reads,
counts/selectors/errors/history/exports/receipts/cache carry the same field boundary.

## Holder recipe and strict automatic-growth gate

For each proposed assignment the deployment reviewer must fill: exact company ID; user ID or role
ID plus explicitly reviewed membership; duty; direct/role origin; effective scope before/after;
minimum projection; activation contract/SHA; evidence of eligibility; approver/reference/date;
grant operation/provenance; rollback owner. Options are explicit named-person grants or explicit
named-duty grants to a reviewed existing role with reviewed members. Job assignment, role name,
admin/full-access label, Sales release duty, tenant ownership and current permission count infer
nothing. No live users are selected or queried by this planning task. Missing row/proof means no grant.

All 23 duties are new: predecessor continuity is NONE unless separately proven for the exact
operation/company/scope. No CRM incumbent copy, permission wildcard, mandate growth, demo/bootstrap
or register-all helper may grant them automatically. Existing explicit legitimate Jobs grants must
survive an unrelated re-sync; a strict exclusion must not become blanket revocation.

Important implementation gap: current PermissionRegistry::automaticGrants(target) returns all
registered capabilities for a covered FullAccessMandates user, ignoring ordinary exclusions.
Sales permits that reviewed path; Jobs Task B/this task do not authorise it. Calling only
excludeFromAutomaticGrants is insufficient for Jobs. No existing Sales/mandate policy is changed here.
Before ANY Jobs ACTIVE registration, a shared-owner-reviewed strict non-expanding Jobs exclusion
must cover every all-capabilities consumer, including verified mandates, with regression proof
that existing non-Jobs rights and explicit grants remain unchanged. Proposed new strict exclusion
mode is additive, Jobs-specific and separately scoped/authorised; exact shared API awaits review.
If that contract is unavailable, keep Jobs non-ACTIVE and unregistered. Do not paper over the gap
with a catalogue flag that shared sync ignores or a UI-only holder list.

## Activation order and rollback

1. Review exact catalogue/projections/holder templates and the disabled foundation proposal in
   companion. This planning approval is not code authority or a real holder selection.
2. Deliver separately authorised guarded slice with duties STAGED. Real receiver/verifier/source
   locks, supported identity/conditions, lifecycle and source effects must exist for that slice.
   No missing adapter becomes NONE; no F1 table/fixture proves runtime delivery.
3. Before ACTIVE registration, deliver/test strict no-growth support, inventory actual eligible
   company/principals/scopes under separate deployment authority, and approve exact holder rows.
4. Register ONLY slice-ready ACTIVE names; no empty Jobs owner group while all duties inactive.
   Registration creates vocabulary/team permission rows idempotently, grants nothing. Apply only
   explicitly authorised, provenance-tracked holder assignments; clear relevant permission caches.
5. Verify staging Request/Action/source/projection and negative/replay/multi-company cases with
   named authorised identities. Owner signs exact deployed SHA/config/holder inventory before public
   operation. No ACTIVE flip alone constitutes sign-off or source implementation evidence.

Enable receive only with genuine Sales coordinator + independent job.receive eligibility; enable
view and each J1 writer only after its actual guards/safe data; completion needs canonical conditions;
close needs complete source readiness/dispositions and close-only safe projection. Withdrawal/
termination/historical effects wait for their owning reconciliation. J2 follows separately and is
not a J1 prerequisite. Applicable Sales duties remain separate and may themselves still be STAGED.

Backout disables entry points/workers and moves affected consumers out of ACTIVE first. Preserve
business/audit/source/receipt evidence and permission rows still referenced. Revoke only proven
rollout-introduced grants unaffected by independent reassignment; ambiguous provenance needs review.
Never remove scope limits or existing unrelated rights. Retest cache/replay denial; reactivation
needs current source/holder proof. No destructive permission-row deletion under running checks.

## Related

- [Smallest batch, gates and verification](phase-5-task-f-jobs-access-batch.md)
- [Lifecycle](../domain/job-execution-lifecycle.md) · [Task B closeout](phase-5-task-b-j1-closeout.md)
- [R9 rollout](phase-4-r9-permission-rollout.md) · [Coordination](../architecture/agent-coordination.md)
