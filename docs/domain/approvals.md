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

- **Distinct people.** One person can never fill two slots of a step: not twice personally, not personally and as a delegate,
  not as two different delegators' principal. The database has unique keys on `(step, decided_by)` and `(step, principal_id)`.
- **Maker/checker per step.** The submitter cannot satisfy a step unless that step sets `allow_submitter`. Off by default; a
  small company may turn it on for low-value steps deliberately.
- **Changes void approvals.** The request stores a hash of the routing facts. Every decision and every later use
  (`RequireApproval`) re-reads the document; a mismatch cancels the request (`INVALIDATED`) and the document must be submitted
  again. An approval given at 80,000 cannot be used to issue an 800,000 order.
- **Currency evidence.** Amounts are routed in base currency at the document's own pinned rate. The request keeps the original
  amount and currency, the rate, its date and source, and the converted amount, so an approval never moves when a rate does.
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
(ceilings), `approvals.delegation.manage` (delegate on someone's behalf and see everyone's delegations — anyone may delegate their
own), `approvals.history.view` (see every request). Per-subject capabilities live with the module:
`purchasing.purchase-order.submit`, `purchasing.purchase-order.approve`, `estimating.material_variance.approve`.

## API

`GET approval-subject-types` and `PUT approval-subject-types/{type}/mode`; `approval-policies` (create → draft, `PATCH` a draft,
`POST …/activate`, `DELETE` a draft); `approval-authorities` (+ `mine`); `approval-delegations`; `approval-requests` (audit list,
`inbox`, `show`, `POST …/decide`, `POST …/withdraw`). A request is visible to its submitter, to auditors, and to anyone who can
act on it or has acted on it.

## Screens

`/settings/approvals` — what needs approval (per subject type), policies (list, editor, versions), approval limits.
`/approvals` — the inbox (including what a person may decide as someone's delegate), an audit tab for those who may, and
"Cover while you are away" (delegations). The purchase-order detail screen shows the approval state, submit/withdraw and the
decision history, and disables Issue/Edit with the reason rather than failing after a click.

## Not yet available

- Department- or warehouse-scoped approver eligibility — waits on [Q26](../OPEN-QUESTIONS.md#q26--organisational-and-record-level-access-scope);
  policy conditions may use warehouse/category facts and ceilings are per person meanwhile.
- **Notifications** — intentionally deferred to the ERP-wide Notifications capability
  ([Q33](../OPEN-QUESTIONS.md#q33--erp-wide-notifications-capability)), not unfinished approval work. The engine records everything
  a notification would need (who is eligible, deadlines, overdue flags, decisions), and the inbox is pull-only until that
  shared capability exists; the engine deliberately contains no e-mail, push or approval-specific alerting.
- Submitting a material plan for approval (Phase 4 adoption).
- Migrating recalculation, negative-stock and cutover approvals onto the engine.

## Related

- [ADR 0070](../decisions/0070-shared-configurable-approval-engine.md), [approval-workflows.md](../architecture/approval-workflows.md)
- [Purchasing](purchasing.md), [Material resolution](material-resolution.md)
- [Approvals journey](../journeys/approvals-journey.md)
