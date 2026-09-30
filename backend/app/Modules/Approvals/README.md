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

## Capabilities

`approvals.policy.manage`, `approvals.authority.manage`, `approvals.delegation.manage`, `approvals.settings.manage`,
`approvals.history.view`. Reading one's own inbox and delegating one's own approvals need no capability.

## Does not own

Departments or record-level scope (Q26), notifications, or the documents being approved.
