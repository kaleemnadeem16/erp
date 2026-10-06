[← Domain](README.md)

# Approvals

The shared approval engine decided in [ADR 0070](../decisions/0070-shared-configurable-approval-engine.md). It answers one
question for any consuming module — *do the required approvals exist for this document as it is now?* — and owns nothing
else: the document, its permissions and its state transitions stay with the module. This page is the implemented truth; the
design baseline and per-workflow checklist are in
[approval-workflows.md](../architecture/approval-workflows.md).

## Concepts

| Concept | Meaning |
|---|---|
| **Subject type** | A kind of document a module registers (`PURCHASE_ORDER`, `MATERIAL_VARIANCE`, `STOCK_MIX`): its label, its approve capability and the *facts* a policy may route on, each typed (decimal, string, id). |
| **Approval mode** | Per company and subject type: `NONE` (never asked — the default), `POLICY` (asked only when an active policy matches) or `REQUIRED` (a matching policy must exist; with none, the document cannot proceed). A missing policy is never read as "approved". |
| **Policy** | A named rule set. Each row is one *version* (`DRAFT` → `ACTIVE` → `SUPERSEDED`); an active version is never edited — a new version is created and activated. Among active policies whose conditions all match, the highest priority wins. |
| **Condition** | `fact operator value`, with bounded operators (`>=`, `>`, `<=`, `<`, `=`, `<>`, `in`) over the subject's declared facts. No expressions or code. |
| **Stage / step** | Steps in the same stage run in parallel; a stage opens when every step of the one before is approved. A step needs `required_approvals` decisions from *distinct* people (1, 2, 3 …). |
| **Eligibility** | A stable capability (the step's `permission`, else the subject's approve capability) plus, when the step *uses authority limits*, an amount ceiling held as data. |
| **Authority** | A person's ceiling per subject type in the company base currency, or unlimited, optionally dated. Never modelled as a permission such as `approve-under-10000`. |
| **Delegation** | Dated cover: the delegate acts *as* the delegator, within the delegator's authority and never beyond it; the delegate's own authority is not added and chained delegation is not honoured. |
| **Request** | One routed approval of one document. It freezes the routing facts, a hash of them, the pinned currency evidence and the policy version. |
| **Decision** | An append-only fact (immutable at the database) with the reason and an authority snapshot. `principal_id` is whose authority was used. |

## Rules the engine enforces

- **Active identities.** Both the decision actor and any delegated principal must be `ACTIVE` and
  have completed their temporary-password change. Retained permissions or amount authority cannot
  keep delegated approval access alive during suspension/invitation/credential restriction. This
  eligibility check applies to inbox/amount visibility and direct `DecideApproval` callers; existing
  decisions remain immutable. Reactivation restores eligibility only while the delegation and all
  other authority checks still hold (`ApprovalInformationFlowTest`, 2026-10-06).
- **Distinct people.** One person can never fill two slots of a step: not twice personally, not personally and as a delegate,
  not as two different delegators' principal. The database has unique keys on `(step, decided_by)` and `(step, principal_id)`.
- **Maker/checker per step.** The submitter cannot satisfy a step unless that step sets `allow_submitter`. Off by default; a
  small company may turn it on for low-value steps deliberately.
- **Changes void approvals.** The request stores a hash of the routing facts. Every decision and every later use
  (`RequireApproval`) re-reads the document; a mismatch cancels the request (`INVALIDATED`) and the document must be submitted
  again. An approval given at 80,000 cannot be used to issue an 800,000 order.
- **Currency evidence.** Amounts are routed in base currency at the document's own pinned rate. The request keeps the original
  amount and currency, the rate, its date and source, and the converted amount, so an approval never moves when a rate does.
- **Who sees the money.** For registered document-review subjects, commercial reviewers receive the
  saved document's prices and currency evidence; technical reviewers receive operational sections only.
  Ordinary financial readers still need the subject's existing field capability and request access.
  History permission alone reveals no document snapshot. A PO's full-commercial step is the default;
  an operational step uses `approvals.document.technical-review` without spending-authority limits.
  Past deciders retain the profile recorded with their decision, never later expanded evidence.
  Other subjects keep ADR 0070's amount-only evidence rules until they register a review provider.
  Free-text decision/closing reasons and ceiling readership remain unchanged pending Q35.
- **Expiry never decides.** A step may have `expires_after_hours`. On expiry it is marked *overdue* (still decidable; an optional
  `escalation_permission` starts to count) or, if configured, the request ends as `EXPIRED` and must be resubmitted.
  `ExpireOverdueApprovals` applies this; nothing is ever auto-approved or auto-rejected.
- **Rejection is terminal** for that request and needs a reason; the submitter revises and submits a new request. A pending
  request can be **withdrawn** by its submitter (or an approvals manager) with a reason.
- **Approval never issues or posts.** Approving the last step approves the request only; the module's own action then proceeds.

## Consumers

| Subject | Facts | What it does today |
|---|---|---|
| `PURCHASE_ORDER` | order total in base currency, supplier, currency | Optional submit → approve → issue. `POST /purchase-orders/{id}/submit`; issuing calls `RequireApproval` (migrated cutover orders excepted); the order is frozen while a request is pending; editing or cancelling voids an earlier approval. The order response carries an `approval` block. |
| `MATERIAL_VARIANCE` | cost impact in base currency, material, material category | Makes the `APPROVAL_REQUIRED` variance effect selectable (only while the mode is not `NONE`). The Material Preview names the approval a plan *would* need (policy and steps) and marks it not adoptable; with no matching policy it is never treated as "not required". A stored approval band fails closed to `BLOCKED` if approvals are later switched off. A plan is only hypothetical until Phase 4 adopts it, so nothing is submitted yet. |
| `STOCK_MIX` | extra cost over the proposal in base currency, the percentage above it, material, material category | Makes the `APPROVAL_REQUIRED` level of the company's stock-choice control ([ADR 0076](../decisions/0076-company-control-over-hand-chosen-stock.md)) selectable (only while the mode is not `NONE`). The Material Preview names the approval a hand-chosen mix *would* need; a stored approval level fails closed to `BLOCKED` if approvals are later switched off. Nothing is submitted until plan adoption (Phase 4). Approve capability: `estimating.stock_mix.approve`. |

Recalculation, negative-stock and cutover approvals keep their own module-specific rules and migrate later, one at a time.

## Capabilities

`approvals.settings.manage` (modes), `approvals.policy.manage` (policies; also withdraw any request), `approvals.authority.manage`
(ceilings), `approvals.authority.view` (read everyone's ceilings, nothing more), `approvals.reason.view` (read person-written reasons on
every request), `approvals.delegation.manage` (delegate on someone's behalf and see everyone's delegations — anyone may delegate their
own), `approvals.history.view` (see every request — its outcome, who decided and when; not the limits or the written reasons, see below). Per-subject capabilities live with the module:
`purchasing.purchase-order.submit`, `purchasing.purchase-order.approve`, `estimating.material_variance.approve`.
Delegation list responses contain only records involving the caller unless they hold
`approvals.delegation.manage`; this is the existing ADR 0070 boundary, not a Q35 policy decision.
Response-body tests cover delegator, delegate, unrelated user, manager and foreign-company records.

## API

`GET approval-subject-types` and `PUT approval-subject-types/{type}/mode`; `approval-policies` (create → draft, `PATCH` a draft,
`POST …/activate`, `DELETE` a draft); `approval-authorities` (+ `mine`); `approval-delegations`; `approval-requests` (audit list,
`inbox`, `show`, `POST …/decide`, `POST …/withdraw`). A request is visible to its submitter, to auditors, and to anyone who can
act on it or has acted on it; what money it shows them follows the rule above.

## Audit boundary (2026-10-06)

Policy update/delete/activate and authority/delegation revocation re-query their target under the
active company before reading or mutating it, including non-HTTP calls with hydrated foreign models. Internal stage-opening/invalidation Actions
also re-query the request before changing it.
A decision checks actor eligibility, maker/checker separation and previous slots before inspecting
changed-document evidence or settling expiry; those preflight checks can cancel a request and must
not be triggered by an unrelated caller. Eligibility is checked again inside the decision transaction.
`ApprovalInformationFlowTest` verifies foreign list/detail/Action paths and that an ineligible
response neither discloses document-change state nor cancels a stale request. Existing engine tests
retain eligible stale/expired handling. Q35 document review follows ADR 0085; limit and reason readership follow
[ADR 0087](../decisions/0087-approval-limits-and-reasons-are-separate-auditor-capabilities.md) (next section).

## Screens

`/settings/approvals` — what needs approval (per subject type), policies (list, editor, versions), approval limits.
`/approvals` — the inbox (including what a person may decide as someone's delegate), an audit tab for those who may, and
"Cover while you are away" (delegations). The purchase-order detail screen shows the approval state, submit/withdraw and the
decision history, and disables Issue/Edit with the reason rather than failing after a click.
The **purchase-order list** has an **Approval** column, a filter (*All approvals · Waiting · Approved · Rejected · Withdrawn · No
longer valid · Expired · Not sent for approval*) and a chip, shown only for a company that uses approvals. The API sends
`approval_status` per row (one query for the page; `null` for every row while approvals are off) and `filter[approval]` judges
an order by its **latest** request. The row menu offers *Submit for approval* and hides *Issue order* while approval stands in
the way.

## Not yet available

- Department- or warehouse-scoped approver eligibility — waits on [Q26](../OPEN-QUESTIONS.md#q26--organisational-and-record-level-access-scope);
  policy conditions may use warehouse/category facts and ceilings are per person meanwhile.
- **Notifications** — intentionally deferred to the ERP-wide Notifications capability
  ([Q33](../OPEN-QUESTIONS.md#q33--erp-wide-notifications-capability)), not unfinished approval work. The engine records everything
  a notification would need (who is eligible, deadlines, overdue flags, decisions), and the inbox is pull-only until that
  shared capability exists; the engine deliberately contains no e-mail, push or approval-specific alerting.
- **Purchase-order approval — not done yet (recorded 2026-10-02 so it is not lost):**
  - *Approval section of the order panel*: who is expected to approve and at which step, the step's due date and whether it is
    overdue, a decision timeline with each approver's note, and the policy that routed the order (the engine already records
    all of this; the screen shows only the status and the decisions).
  - *Bulk action* on the list: **Submit selected for approval** (the bulk bar exists; the action does not).
  - *A filter for "needs approval but not yet submitted"*: the list shows it in the column, but it cannot be a filter because it
    depends on matching policies per order. Needs either a stored "approval required" snapshot written when an order is saved,
    or a policy-matching query; to be decided.
  - *Explicit "approvals in use" flag from the list API*: the column and filter currently appear once any row carries an
    approval status (and stay once seen). An explicit `meta` flag on the list response would remove that inference — decision
    pending with the owner.
  - *Approvals on other list screens* (goods receipts, requests): the same column/filter pattern, per consumer.
- Submitting a material plan for approval (Phase 4 adoption).
- Migrating recalculation, negative-stock and cutover approvals onto the engine.

## Demo approval members and reruns

Demo approval personas are created once by the shared `EnsuresDemoEnvironment::demoMember`
helper, using an active member and the normal hashed-password model path. It reloads persisted
PostgreSQL defaults before eligibility checks: Eloquent does not hydrate those defaults after
inserting a new row. Reruns preserve existing passwords and credential versions, and refuse
suspended/invited/temporary-password members rather than activating them or clearing restrictions.
Complete the normal account activation/password flow for those members before reseeding.
Existing narrow buyer/manager/Finance capability sets remain unchanged; granting a capability
never repairs credential state. Test factories already supply active password-backed actors,
so `PermissionedActor` remains a grant-only helper and preserves negative lifecycle cases.

The demo reconciles lapsed authority/delegation through the existing governance Actions;
it retains the live request and becomes idempotent again after renewal. Production eligibility
is unchanged. Regression tests cover fresh seeding, normal self-service password preservation,
restricted personas, historical states, live approval consumption and lapsed-authority renewal.

## Request-specific document review (ADR 0085)

Policy and request steps store `review_profile varchar(16)` (`COMMERCIAL` by default or
`OPERATIONAL`); operational policy steps cannot use authority limits. The versioned whitelist
in `facts.document_review` stores company/subject identity, capture time, fingerprint and separate
operational/commercial sections. Decisions freeze `authority_snapshot.review_profile`.
The Approvals-owned PO adapter reads supplier/site, terms, lines/specifications, quantities, units,
delivery, pricing, tax, totals and pinned FX. It exposes no GL mappings, credit limits, passwords
or unrelated offers. The same projection powers inbox, detail, decision response and print.
Relevant same-total changes invalidate decisions and the final transition guard.

The migration grants the technical duty only to existing full PO approvers through their existing
company role/direct grant; technical-only newcomers receive only that duty. It prepares still-open
PO requests only when saved routing facts match current facts, without reconstructing completed
history. Missing/mismatched mandatory evidence prevents a decision; withdraw and resubmit stale
requests. Historical approvals without a snapshot preserve their previous transition validity,
but have no fabricated review document. Demo Technical Reviewer has only the technical duty;
the 95,000 base-currency example requires a separate commercial review afterwards and is replenished
idempotently on reseeding. Existing active credentials are preserved.

The UI starts with the request summary, stages and decision controls. The saved document has an
animated Show/Hide review details control; its projected print tree remains available independently.
Live order details remain open with no hide control. The submitted review starts collapsed and
is labelled as the saved approval version, avoiding duplicate expanded documents while preserving
access to the exact reviewed evidence. Embedded approval panels omit the repeated order identity
and status, and decisions refresh both the request and order presentation.
These controls change presentation only: server projection and decision eligibility remain unchanged.

## Who reads limits and written reasons (ADR 0087, Q35)

**Limits.** `GET approval-authorities` needs `approvals.authority.manage` or `approvals.authority.view`; history alone answers 403.
Everyone reads their own through `approval-authorities/mine`. The decision's frozen `authority_snapshot` (which holds the ceiling) is
never returned. The settings screen shows the Authorities panel only to those who may read it.

**Reasons.** The reason on a decision, and the closing reason when a person rejected or withdrew the request, are person-written free
text and can repeat a figure the structured fields withhold, so `ApprovalReasonAccess` makes them follow the evidence: shown to anyone
entitled to the request's evidence (`ApprovalEvidenceAccess`), to `approvals.reason.view` holders, and to the author (a decider and the
principal they acted for; the submitter for their own withdrawal). Otherwise the response carries `reason: null` with
`reason_visible: false` (and `closing_reason_visible: false`), and the screen says "reason not shown to you"; the outcome, decider and
time stay visible. Reasons the system writes (expiry, "document changed", "order cancelled") quote no figures and stay visible. A
submitter without evidence entitlement therefore does not read why their request was rejected.

**Rollout.** One migration gives `approvals.authority.view` and `approvals.reason.view` to every role and person that holds
`approvals.history.view` today (per company, additive, idempotent), so no one loses a read on release. Demo roles "Approvals auditor"
(history + both) and "Approvals history reader" (history only) are built through the role Actions for
`demo-auditor@erp.x1techs.com` and `demo-historian@erp.x1techs.com`. Tests: `ApprovalCeilingAndReasonAccessTest` (response bodies for each
reader, a delegator, a withdrawal and system reasons), `ApprovalAuditorReadsRolloutTest`, `ApprovalAuditorsDemoSeederTest`, and a browser
spec for the withheld note.

## Related

- [ADR 0087](../decisions/0087-approval-limits-and-reasons-are-separate-auditor-capabilities.md)

- [ADR 0085](../decisions/0085-request-specific-document-review-for-approvals.md)

- [ADR 0070](../decisions/0070-shared-configurable-approval-engine.md), [approval-workflows.md](../architecture/approval-workflows.md)
- [Purchasing](purchasing.md), [Material resolution](material-resolution.md)
- [Approvals journey](../journeys/approvals-journey.md)
