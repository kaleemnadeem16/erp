[← Architecture](README.md)

# Approval workflows

Approval is a document-specific, auditable process layered on top of permissions. A user may be
eligible to approve purchase orders without every purchase order being approved, and without that
user being eligible for every amount, department or stage.

This is a design baseline, not an accepted generic-engine ADR. Existing Inventory workflows are
implemented module-specifically; the open decisions are tracked as
[Q27–Q28](../OPEN-QUESTIONS.md#q27--shared-approval-workflow-contract).

## Permission, eligibility and decision are different

```text
permission       “may approve purchase orders in principle”
      +
scope/authority  “for this department/site and up to this limit”
      +
workflow state   “this document currently needs this approval step”
      +
independence     “this actor did not perform a conflicting earlier duty”
      ↓
decision fact    “user 42 approved step 2 of revision 3 at this time, with reason”
```

All conditions are required. A role or permission assignment is never itself an approval record.

## What already exists

Inventory has several strong concrete patterns:

- cutover approval rejects its creator and validator;
- physical-count posting rejects the count creator;
- negative-stock and negative-opening approvals require an independent actor;
- high-risk recalculation requires two distinct approvers, supports approval expiry and requires
  extra authority for the second high-risk decision;
- decisions store actor, timestamp and notes/reason and preserve immutable history.

These prove that the current Actions, user identity, permissions and audit foundations can enforce
maker/checker and multiple-approver rules.

## What does not exist

- Purchase orders move directly from `DRAFT` to `ISSUED`; there is no submit/approval lifecycle,
  approval actor or approval chain.
- No shared approval policy, policy version, step, assignment, request or decision model exists.
- No departments/organisation units or authority limits exist.
- The proposed `workflow_states`/`workflow_transitions.required_permission` sketch in
  [extensibility.md](extensibility.md#config-driven-workflow) is not implemented and is too small
  by itself for parallel approvals, counts, thresholds, expiry or separation of duties.
- There is no inbox/delegation/escalation model for approvers.

## Required shared contract

The first implementation should be driven by a concrete workflow such as Purchase Order approval,
then extract only the parts genuinely shared with quotations, discounts, supplier invoices,
payments, stock adjustments and policy changes.

A credible shared shape needs concepts equivalent to:

```text
approval_policies
  id, company_id, entity_type, name, status

approval_policy_versions
  id, approval_policy_id, version, effective_from, status
  matching_conditions              -- typed/validated policy, not arbitrary executable code

approval_steps
  id, policy_version_id, sequence
  mode                             -- SEQUENTIAL | PARALLEL
  required_permission
  required_approvals
  organisation_scope_type nullable
  authority_rule nullable
  expires_after nullable

approval_requests
  id, company_id, entity_type, entity_id, entity_revision
  policy_version_id
  submitted_by, submitted_at, status
  input_snapshot                   -- amount/currency/department/risk facts used for routing

approval_decisions
  id, company_id, approval_request_id, approval_step_id
  decision                        -- APPROVED | REJECTED
  decided_by, decided_at, reason
  authority_snapshot              -- why the actor was eligible at decision time
```

Names and normalisation remain open. The invariants do not:

- policy/version and routing inputs are snapshotted when submitted;
- decisions are append-only facts;
- a changed document revision requires re-evaluation and normally a new approval request;
- required approvals count distinct eligible actors;
- self-approval/conflicting-duty rules are checked from stored actors;
- approval never silently issues/posts the document unless the domain explicitly defines one
  atomic transition;
- rejection, withdrawal, expiry, delegation and resubmission have explicit outcomes;
- currency thresholds define the comparison currency and pinned conversion evidence;
- the document-owning module remains responsible for its state transition and business guards.

## Purchase-order example

A likely lifecycle is:

```text
DRAFT → SUBMITTED → PARTIALLY_APPROVED → APPROVED → ISSUED
             │               │                │
             └───────────────┴──→ REJECTED / returned for revision
```

Possible company policy—not yet a decision:

1. Buyer with `purchasing.purchase_order.create` creates and edits the draft.
2. `purchasing.purchase_order.submit` freezes a revision for review.
3. Department budget owner approves the business need.
4. Purchasing authority approves supplier/terms.
5. Finance approves when value exceeds a configured threshold.
6. `purchasing.purchase_order.issue` sends/commits only after all required decisions exist.

Steps may be sequential or parallel. The submitter cannot satisfy an independent approval step.
Whether the final approver may also issue is a company policy, not an assumption.

## Authority and thresholds

Avoid permissions such as `approve-under-10000` or one role per currency limit. Keep the stable
capability (`purchasing.purchase_order.approve`) separate from configured authority data:

- organisation scope;
- document/type scope;
- monetary ceiling and currency basis;
- risk/category constraints;
- validity dates;
- delegation/temporary cover.

Changing a user's authority affects future decisions only. Historical decisions retain the
authority snapshot that made the actor eligible at the time.

## Department and same-department approvals

“Two approvals from the same department” and “one approval from each of two departments” are
workflow rules, not two permissions. The policy step must identify the required organisation
scope and count distinct actors. A user belonging to several departments may satisfy only the
specific step they choose/are assigned to, and one decision must not silently count twice unless
the policy explicitly allows it.

The organisation model itself remains open in
[Q26](../OPEN-QUESTIONS.md#q26--organisational-and-record-level-access-scope).

## Interaction with record visibility

An approver needs access to the approval task and enough document fields to make the decision,
but does not automatically need unrestricted access to every record in the module. An approval
inbox can grant task-scoped visibility while the request is active. Sensitive fields still follow
their own field permissions.

List/show/export/search APIs must apply the same record-scope policy. Notifications must not leak
document details to an ineligible recipient.

## Per-workflow design checklist

Before adding any approval transition, document and test:

1. what event submits the document and which revision is frozen;
2. which permission makes an actor eligible for each step;
3. scope, thresholds and routing inputs;
4. number of approvals and sequential/parallel behaviour;
5. maker/checker and other conflicting-duty exclusions;
6. approval expiry, delegation, escalation and unavailable approvers;
7. rejection, return-for-edit, withdrawal and resubmission behaviour;
8. what changes invalidate existing decisions;
9. who may issue/post after approval;
10. immutable actor/time/reason/authority evidence;
11. read/field access while reviewing;
12. concurrency and idempotency when two approvers decide at once.

## Phase ownership

- **Cross-cutting foundation:** shared vocabulary, policy/decision contract and authorisation
  integration.
- **Purchasing:** PO/receipt/supplier-invoice approval rules and state transitions.
- **Sales:** quotation, discount and price-override approval.
- **Estimating:** material-plan variance approval when ADR 0054's configured risk band requires it.
- **Inventory/Job Execution:** movement, scrap, substitution and operational exception approval.
- **Finance:** invoice/payment/journal approval and segregation of duties.

Each module owns its document and business facts. A shared approval component coordinates
decisions; it must not become a generic service that mutates arbitrary module status columns.

## Related

- [Authorisation](authorization.md) — capabilities, record scope and maintenance checklist
- [Extensibility](extensibility.md#config-driven-workflow) — the earlier minimal workflow sketch
- [Purchasing](../domain/purchasing.md) — first likely approval consumer
- [ADR 0028](../decisions/0028-dynamic-namespaced-permissions.md)
- [ADR 0054](../decisions/0054-risk-based-material-plan-variance-controls.md) — material-plan
  variance controls consume this contract when approval is configured
