# Approvals module

The shared approval engine ([ADR 0070](../../../../docs/decisions/0070-shared-configurable-approval-engine.md); domain rules in
[approvals.md](../../../../docs/domain/approvals.md)). It decides whether the required approvals exist for a document *as it is
now*; it never mutates another module's status and never issues or posts anything.

## Owns

- Tables: `approval_subject_settings`, `approval_policies` (+ `_conditions`, `_steps`), `approval_authorities`,
  `approval_delegations`, `approval_requests` (+ `approval_request_steps`), `approval_decisions` (immutable at the database).
- `Support\ApprovalSubject` / `ApprovalSubjectRegistry`: what a consuming module registers (code, label, approve capability, typed
  facts, and `routing(id)` — the facts, base-currency amount, pinned currency evidence and inbox summary of one document now).
- Actions: `SubmitForApproval`, `DecideApproval`, `WithdrawApproval`, `RequireApproval` (the guard a module calls before it acts),
  `InvalidateApprovals`, `ExpireOverdueApprovals`, `ResolveApprovalPolicy`, plus policy, setting, authority and delegation
  management Actions.
- `Support\ApprovalEligibility` (capability + authority ceiling, directly or under a delegation) and `ApprovalInbox` (uses the same
  eligibility as a decision — no second definition).

## Adding a consumer

1. Implement `ApprovalSubject` in the owning module and register it from that module's service provider
   (`afterResolving(ApprovalSubjectRegistry::class, …)`), as Purchasing (`PURCHASE_ORDER`) and Estimating (`MATERIAL_VARIANCE`) do.
2. Register the module's submit and approve capabilities in its permission vocabulary.
3. Submit through `SubmitForApproval`, and call `RequireApproval` before acting on the document. Freeze or invalidate the document
   when it changes; follow the per-workflow checklist in
   [approval-workflows.md](../../../../docs/architecture/approval-workflows.md).

### Record-scoped subjects

A subject that must also apply its own record policy (OWN or ALL scope, separation of duties, no delegates) implements `Support\RecordScopedApprovalSubject::mayDecide()`. `ApprovalEligibility`
asks it inside the single eligibility every route shares (inbox, the generic decision route and the owning module's Action), so a capability plus a ceiling can never reach a record the module hides.
Sales' `QUOTATION_REVISION` ([ADR 0158](../../../../docs/decisions/0158-approve-quotation-concessions-through-the-shared-approvals-engine.md)) is the first user.

### Opt-in strict Sales contract

`StrictApprovalSubject` selects effective company-local policies and refuses equal best priority.
`TransactionalApprovalSubject` wraps submission, decision and withdrawal before engine preflight
or request locks; it can pin submission evidence and check request-wide expiry. For opt-in subjects,
a fresh submission ends stale/expired pending evidence before capturing a new request. The final locked
decision repeats preflight for every subject. A fresh actor and principal are checked behind the
company access fence; review providers lock the source document before the request and its steps.
Expiry or changed-document invalidation commits before its refusal is returned. `ProtectedApprovalEvidenceSubject` keeps independent
field authority on current and historical deciders too. Sales is the sole opt-in consumer; Purchasing
and Estimating retain ADR 0070 selection and lifecycle semantics. Configuration statement barriers
serialise shared engine policy/authority writers with Sales authority generations (proposed ADR 0164).

## Capabilities

`approvals.policy.manage`, `approvals.authority.manage`, `approvals.delegation.manage`, `approvals.settings.manage`,
`approvals.history.view`. Reading one's own inbox and delegating one's own approvals need no capability.
Delegation lists show the caller's own involvement unless they hold delegation management.
Decision eligibility requires an active actor and active delegated principal, each past any
mandatory temporary-password change; suspension cannot be bypassed through retained authority.

## Document review

`Support/Review` owns the versioned whitelist registry, frozen snapshots and request-specific
projection ([ADR 0085](../../../../docs/decisions/0085-request-specific-document-review-for-approvals.md)).
The PO adapter lives here and never changes Purchasing data. New consumers register their own
provider and optional technical-duty capability; do not reuse a PO permission for another subject.
Missing mandatory evidence prevents decisions. Relevant changes invalidate approval even when
routing totals are unchanged. `approvals.document.technical-review` is additive and narrowly
backfilled from existing full PO approval grants; it grants no ordinary PO or pricing read.

## Workspace presentation

`/approvals` uses the shared list template, neutral request cards and a request-specific record
sheet for evidence and decisions. Saved table/card layouts share a paginated footer. The complete
actionable inbox supports local text/type/ID and overdue filtering before pagination; audit history uses the existing status/type/ID filters and pagination. History
text search is labelled as page-local. Approval coverage uses animated disclosure and create/revoke
dialogs; revoke controls follow the existing delegator/management gate. All existing eligibility, evidence/reason projection and
`approvals.history.view` gates remain unchanged. See the
[workspace specification](../../../../docs/domain/approvals.md#approval-workspace-first-draft-2026-10-07).

## Non-disclosure and business dates (addendum to ADR 0070, issue #89)

- **A hidden request is a 404.** Showing, deciding or withdrawing a request the caller may not see (not the submitter, not an
  auditor, never asked to decide, never decided) answers exactly like an unknown id: the same 404, body and headers. The check
  runs in the Form Request `authorize()` before validation, so an invalid body cannot tell them apart either. An approvals
  manager may still withdraw any request of the company. Revoking a delegation you are not part of is likewise a 404 (the
  delegate, who can see it, still gets 403). Cross-company ids were already 404.
- **403 means a missing capability only.** Policy and authority routes take the id as a string and resolve the record after the
  Form Request judged the capability, so a caller without it gets the same 403 for an existing and an unknown id.
- **Authority and delegation dates are business dates.** `valid_from` / `valid_to` are compared with today's date in the company's
  time zone (`CompanyClock::today()`, the same clock Numbering uses), not the UTC application day: a grant for the 11th starts
  at local midnight and lasts until the end of the local 11th. This covers `inForce()`, `ApprovalEligibility` and the `in_force`
  field. Timestamps (`created_at`, `revoked_at`, deadlines) stay instants.

## Does not own

Departments or record-level scope (Q26), notifications (deliberately deferred to the shared ERP-wide capability, Q33 — do not add
approval-specific e-mail/push here), or the documents being approved.
