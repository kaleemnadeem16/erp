[← Phase 4 plan](phase-4-sales-quotations-and-handover.md)

# CRM A/B/C corrections — implementation specification

**2026-10-10 · BUSINESS ACCEPTED; NOT IMPLEMENTED.** ADRs [0111](../decisions/0111-disqualify-leads-independently-of-derived-opportunities.md),
[0112](../decisions/0112-correct-historical-opportunity-responsibility-with-append-only-evidence.md)
and [0113](../decisions/0113-preserve-original-lead-intake-and-govern-current-detail-corrections.md)
record owner approval. Planning specifies; designated implementation agent delivers after the owner
coordinates schedule through them. No application code/tests/migrations/generation executed here.
The [urgent security correction](phase-4-crm-security-correction.md) remains required independently.
B's new sensitive duty/grant choice is explicitly AWAITING APPROVAL; do not enable that write yet.

## Slice boundaries and examples

| Slice | Behaviour / dependency / exclusion |
|---|---|
| S — access correction | Fix relevant-duty scope and Party candidate authority first, per separate handoff. No new business lifecycle or CRM unit visibility. |
| A — independent disqualification | Duplicate intake can become DISQUALIFIED while its two genuine deals stay OPEN. Requires S and keyed decision receipt; no Opportunity mutation/merge. |
| C — intake/current corrections | Received phone 123 stays recorded, corrected phone 132 becomes current, later revival keeps both. Requires intake/delta evidence, additive rollout, Request/Action/UI integration. No Party master update, received-date rewrite or lifecycle transition. |
| B — attribution correction | Correct recorded pursuit ownership on a closed deal without reopening or editing its row. Requires S, approved sensitive duty/grant plan and append-only target/head evidence. Routine follow-up uses notes. No named admin owner, period editor or financial/performance recalculation. |

A/C reuse existing duties and can be scheduled separately from B's approval. Evidence/receipt schema
may be shared across these slices; deploy only complete compatible consumers. Cost C2, Stock,
pricing, acceptance and generated files owned by that implementation cohort are outside these edits.

## Historical-attribution authorisation gate

Inspected `Sales/Support/PermissionVocabulary.php`: opportunity manage is open-deal entry/edit/
stage/notes; assign changes who is currently responsible; close ends/revives pursuit. None accurately
expresses retrospective attribution correction, which can change historical accountability while
all current operational facts remain unchanged. Reusing manage is too broad; requiring assign+close
would grant unrelated powers to a correction specialist and still not identify the duty explicitly.

**Recommend `sales.opportunity.attribution.correct` as one narrow new capability, subject to owner
approval.** Requires independent legitimate pipeline-read eligibility AND effective company-wide
scope for this duty; unsupported restricted grants refuse. Owning the record, manager role, assign,
close or ordinary editing do not substitute. The proposed capability is not added to `mayRead`,
owner/candidate lookup duties or an unrestricted vocabulary-wide scope query.

Register only after approval in Sales vocabulary/registry and ratchets. Grant explicitly to approved
company principals/role assignments; no blanket salesperson/manager grant or inferred incumbent
attribution users, because this is a new operation. Owner must approve the initial grant audience,
including whether a named full-vocabulary administrator's normal re-sync should include it. Demo
correction-reviewer recipe has read + correction only and no active-edit/assign/close duty; recipe
is not authority to grant production roles. Rollout test proves ordinary existing roles do not gain
it, explicit eligible grants work, rerun is idempotent and companies remain isolated. If an approved
existing duty is preferred instead, record that deliberate sensitivity choice before enabling B.

Read original/corrected responsibility through existing authorised history projection; no separate
read permission is needed for the same responsibility fields. Historical inactive company members
may be referenced as attribution, not newly active owners. The corrected-member selector is a
narrow company-scoped projection for approved correction + read, including retained inactive
members; no email/financial/user-administration fields, external-company or deleted/unresolvable
member identity. This selector does not grant general directory or Party lookup rights.

## Actions, Requests and controllers

All paths below are relative to `/api/v1` and describe proposed contracts, not currently available
correction operations.
Existing route identities remain except the new named B operation; no generic status PATCH.

| Operation | Contract and implementation locations |
|---|---|
| `POST /leads/{lead}/disqualify` | `DisqualifyLeadRequest` + `DecideLead::disqualify`: existing reason plus required `request_key` (≤85), `expected_decision_event_id` (latest qualification/disqualification/revival event or null). Remove only open-deal coupling; retain current status semantics and record prior qualification context before clearing projection. |
| `POST /leads` | `StoreLeadRequest` + `RecordLead`: accepted input normalised as today, required `request_key`; create Lead, immutable ORIGINAL_INTAKE, original receipt event and receipt atomically. No baseline disguised as an original. |
| `PATCH /leads/{lead}` | `UpdateLeadRequest` + dedicated `CorrectLeadEnquiry` (or existing `UpdateLead` forwarding to that one authority): allowlisted six fields, required `reason`, `request_key`, `expected_enquiry_version`; all three lifecycle states allowed. Reject status/link/assignee/received_at/identity fields. No other field silently ignored as an editable correction. |
| `POST /opportunities/{opportunity}/attribution-corrections` | `StoreOpportunityAttributionCorrectionRequest` + `CorrectOpportunityAttribution`, thin controller + typed Resource: `target_event_id`, `target_field` (`OWNER_ID` or `TO_OWNER_ID`), `asserted_owner_id`, nullable `expected_predecessor_id`, `expected_closure_event_id`, required `reason`/`request_key`. Do not expose before sensitive duty approval. |
| `GET /opportunities/{opportunity}/attribution-members` | `IndexOpportunityAttributionMemberRequest` + `ReadOpportunityAttributionMembers` requiring correction+read and relevant scope; search active or retained inactive company members, capped/paginated, minimal id/name/status. Only for historical selection; no generic owner-lookup expansion. |

Correction reasons follow existing max 1000 and nonblank semantics; field bounds/normalisation
retain `UpdateLeadRequest` (name/organisation/email 200, phone 64, source 100, description 5000).
Keys, version markers and authority are also enforced in direct Actions, not only validation.
Whole payload canonicalisation includes subject/operation/expected marker/reason/changed fields,
explicit nulls, event/fact/member ids. Never hash floats or ignore changed intent.

Required-key additions migrate existing CRM forms, generated client, direct callers and demos in
the same completed cohort. They are explicit write-contract tightening, not an unnoticed nullable
field addition. Owner-coordinated implementation inventories all callers before enabling; no old
unguarded PATCH path survives and no parallel unreasoned edit is retained. Existing read payload
fields remain; new evidence fields are additive. No public API v2 or global contract change proposed.

Affected source paths: `backend/app/Modules/Sales/{Actions,Support,Http/Requests,Http/Controllers/Api/V1,Http/Resources,Models,routes.php}`,
`backend/database/migrations` (new files only), CRM demos and `frontend/packages/core-ui/src/features/sales`.
Extend `SalesEvent` with the two correction events and project typed domain evidence, not guessed
strings from generic audit. New intake field/type definitions and correction enums belong to Sales.

## Idempotency, locks and freshness

Use module-owned operation receipts in [domain schema](../domain/crm-corrections.md#schema--implementation-contract-not-deployed-pseudo-ddl),
not another application-wide idempotency engine. New governed operations carry company/actor/key,
normalised hash and immutable versioned result references. Same key + exact intent replays original
result under current permission/scope; changed intent raises `REQUEST_KEY_REUSED`. Another operation,
subject or reason under that key is changed intent. Replay lookup precedes stale checks, so the
original successful request can replay after later changes, without replaying its mutation.

Lock/re-read subject before relevant source events/head/version; consistent subject → evidence
order. Concurrent identical first-time keys serialize through subject where one exists and the
company/actor unique receipt for creation; catch unique races outside the rolled-back transaction
(or with savepoint), then recheck intent/authority and return original. Never leave half a Lead or
poisoned transaction. No-op uses a receipt with unchanged version/status and creates no false
correction event. Failed writes produce neither success receipt nor domain evidence.

A: `CreateOpportunity` already locks the Lead and resolves completed conversion intent before
refusing disqualified intake. Preserve its company/Lead conversion identity and max100 key contract,
not a new opportunity-number allocator. Conversion winning first creates one genuine deal;
disqualification still proceeds with the same decision marker if conversion made no qualification
transition. If conversion newly qualified the Lead, stale decision marker requires refresh and
re-confirmation, after which disqualification is valid. Disqualification winning first refuses
new conversion (`LEAD_DISQUALIFIED`); completed same-intent replay remains allowed under current
authority. Existing retry intent/hash obligations apply; do not turn changed payload into a new deal.
Concurrent revival cannot be overwritten by a stale disqualification confirmation.

C: compare `expected_enquiry_version` under Lead lock. Insert correction header/deltas for actual
changed values, advance projection exactly once and record one `LEAD_ENQUIRY_CORRECTED` history reference in the same
transaction. Other decision/link/assign operations retain enquiry version and cannot erase intake.
Simultaneous enquiry corrections yield one success and one `STALE_EVIDENCE`; no silent merge.
Creation/rollout baseline is established before correction. Legacy baseline capture and normal writers
share Lead locking; read-original does not lazily create evidence. Mutation actor must belong to company.

B: under Opportunity lock require closed outcome and exact latest closure event id; validate target
owner fact belongs to that Opportunity and earlier responsibility evidence. Lock target/head and
compare `expected_predecessor_id`; append one successor with asserted member/reason/recording time and an
`OPPORTUNITY_ATTRIBUTION_CORRECTED` history reference in the same transaction.
Reopen winning first refuses with `ATTRIBUTION_REQUIRES_CLOSED`; correction winning first can be
followed by separately authorised reopen, preserving the correction. Reopen+reclose changes closure
marker, so a stale earlier confirmation refuses. Same-fact concurrent correction yields one head;
different facts remain independent after serialisation. Identical assertion is no-op. Later error
correction appends a successor; no DELETE/reset-to-original shortcut.

## Database, writer coverage and migrations

Use additive tables/columns/keys from the domain pseudo-DDL; do not edit the original CRM migration.
Evidence requires company-safe subject/actor/member/source/predecessor FKs, reason/delta/version
checks, unique intake/root/head and database mutation refusal. Closed Opportunity guard stays
unchanged: B inserts separate evidence and never updates the Opportunity, even `updated_at`.
Supplement generic activity logs; domain evidence is sufficient without generic audit retention.

C database guarantees: one labelled intake at commit for every Lead; immutable receipt values;
current enquiry changes require exact matching old/new deltas and one version increment; corrections
cannot exist without their projection/evidence; no-op cannot advance the version. Protect changed
fields at the database boundary, not a caller-supplied boolean or privileged application flag.
Migration must version new trigger functions and integrate all writers before enabling enforcement.

| Existing writer | Required treatment |
|---|---|
| `RecordLead` | Original intake + receipt + event in one transaction. |
| `UpdateLead` | Delegate to governed correction; no bypass through old NEW-only edit. |
| `DecideLead`, `AssignLead`, `LinkLeadParty`, conversion qualification in `CreateOpportunity` | Retain original and enquiry version, preserve lifecycle evidence; no enquiry replacement. |
| Demo seeders, factories, direct tests, console/import/integration writers | Use the supported Action or explicit labelled baseline fixture path; raw current-field mutations refused. No generic mode that disables guards. |
| `CloseOpportunity`, `ReassignOpportunity`, `ReopenOpportunity` | Existing responsibility/lifecycle evidence remains; B never patches these facts. No new current owner editor. |

Rollout: additive schema → per-company idempotent baseline capture under Lead locks → verify exactly
one evidence row per Lead → activate deferred consistency guard in a coordinated deployment with
all writers integrated. Keep capture timestamp/reference and recorded receipt date separate; do not
rewrite existing receipt/history events. Initial rollout captures CAPTURED_BASELINE for existing
rows; no speculative recovery or historical backdating. For large datasets use bounded batches and
explicit deployment gating; never a partially enforced live mode. Missing evidence blocks correction
until governed rollout, not a silent runtime backfill. Existing rows are not assumed absent.

Real `down()` removes only the new schema/trigger changes when safe; refuse with explanation when
accepted correction evidence would be lost or current corrected projection become unauditable.
Fresh apply → rollback → reapply and populated rollback refusal are implementation-owned checks.
Grant rollout for B is separately owner-gated; A/C need no new permission grant.

## API projection and screen coverage

Typed Resources/OpenAPI: Lead keeps current fields, adds `enquiry_version`, intake evidence kind/
capture context and opt-in typed original/correction relations; `history` carries typed correction
references rather than an unfiltered internal payload. B returns correction id, target/head,
recorded/asserted member, reason, actor/time and original fact time, with existing read authority.
An operation response separates immutable `operation_result` (replayed unchanged) from current
`data` projection; never label later current status as the original operation's status. Paginated
history uses stable time/id ordering and protected total counts. No new historical report exporter.

Server action availability reflects state AND effective duty/scope. New duty alone does not expose
a pipeline. Denied/include/filter/sort/search paths protect originals and corrections just like current
records; unsupported restricted surfaces remain 403, cross-company ids follow 404 conventions.
Diagnostics must not disclose hidden candidate identities. Stable reason codes are in the domain doc.

| Contract | UI destination / availability / acceptance |
|---|---|
| A reasoned decision | Existing Lead detail action for NEW/QUALIFIED under lead manage; sheet explains linked deals remain unchanged, retains reason after refusal, refreshes state/history on success. Disqualified uses existing revive. |
| C six fields + reason/version/key | Shared Lead form and named correction sheet, available in every state under lead manage; original read-only, current editable, field errors/pending protection/stale review, status unchanged after save. NEW edits also governed. |
| Original versus later baseline | Lead detail evidence section and history; explicit ORIGINAL_INTAKE/CAPTURED_BASELINE text, receipt/capture dates and provenance; list keeps current details, no historic authenticity claim. |
| B target fact + corrected member + reason/head/closure marker | Opportunity history action/sheet only when closed + approved correction duty/read; active/inactive narrow search; original owner/fact time beside correction actor/recorded time; ordinary owner remains unchanged. |
| Read correction chain | Existing detail/history projections; labelled recorded/corrected facts and chain; ordinary history readers can inspect, cannot mutate. No implicit performance totals. |
| Closed follow-up | Existing note entry, existing note duty; no assignee control and no reopen prompt merely to add note. |
| Lists/filter/sort/includes | Existing current-field meanings preserved; no new historic-attribute filters/sorts. Useful newly exposed evidence belongs in detail/history, not duplicated default columns. |

Apply shared shell/sheets/tokens, responsive 375px width, keyboard and light/dark checks only to
changed CRM surfaces. Define complete relevant coverage in domain/journey delivery; no global
retrofit. All enabled actions/fields and refusal states must be delivered together with their API.

## Regression acceptance and completion

- S's full mixed-grant/candidate/direct-Action matrix passes before exposing corrected workflows.
- A disqualifies with two open deals, preserves deal rows/conversion history/Party, appends reason
  once; NEW and QUALIFIED cases, missing reason/duty, wrong company and restricted scope refuse.
  Both conversion race orders, same completed replay, key mismatch and stale revival are covered.
- C captures all six accepted fields and receipt metadata; correction while qualified/disqualified
  and after revival preserves original, lifecycle, master and linked deals. Null clearing, bounds,
  no-op, two-edit race, stale token, exact replay, changed key and transaction rollback are covered.
- B cannot be used by editor/assigner/closer without approved duty+read; owner grants nothing.
  Valid closed target succeeds; unsupported actor/stage/closure-field target and wrong-company
  target/member/predecessor refuse. Inactive historic member works; no current owner change.
  Original event, Opportunity row and any published evidence remain unchanged. Head/reopen/reclose
  races, no-op, repeated correction, replay after revocation and rollback are covered.
- Raw SQL attacks fail: mutate/delete evidence, wrong-company FK, cross-fact predecessor, branch
  correction chain, enquiry update without deltas, correction without projection, absent intake.
- Baseline rerun adds nothing; existing originals are preserved; existing-row capture is labelled,
  actor/reference accurate and no old event rewritten. Populated migration rollback refuses safely.
- Update `CrmFoundationTest`, add focused evidence/permission/migration suites and meaningful new
  ratchet registrations; generate client after final backend, prove drift, all three typechecks/build,
  `sales-crm.spec.ts` plus shared-component affected specs, demo rerun and role recipes. Run Pint,
  Larastan and applicable backend/tenant/scope/write/read/controller ratchets per pre-push matrix.
- Implementation supplies passed checks or verified cause/owner, domain/module/journey/demo/status
  updates and explicit staged paths. Documentation acceptance here is not application completion.

R5/R6/R9 forecast/partial-win gates remain in the [policy record](phase-4-crm-policy-review.md#preserved-choices-and-future-integration-gates):
raw expectation is not trusted forecast; acceptance does not auto-close pursuit; incomplete/provisional
forecast never becomes trusted on view/revival/correction; active commitment prevents wholly lost
classification; remaining-business disposition is separately recorded when partial success closes.

## Related

- [CRM correction domain](../domain/crm-corrections.md) · [Accepted policy review](phase-4-crm-policy-review.md)
- [Security handoff](phase-4-crm-security-correction.md) · [R3 pricing review](phase-4-r3-sales-pricing.md)
- [Authorisation](../architecture/authorization.md) · [Database](../architecture/database.md)
- [Screen delivery](../architecture/screen-delivery-contract.md) · [Pre-push](../architecture/pre-push-checks.md)
