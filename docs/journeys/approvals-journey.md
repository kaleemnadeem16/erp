[← Product journeys](README.md)

# Approvals journey — from switching it on to an approved order

- **Implementation status:** partial — the engine, settings screens, inbox, delegation, purchase-order approval and the
  variance preview are implemented; approval notifications, department-scoped approvers and plan adoption are not
- **Audience:** administrator or purchasing lead (set-up), buyer (submits), approver, finance
- **Starting point:** `/settings/approvals`
- **Successful end state:** a purchase order over the company's threshold is approved by the people its policy names, within
  their limits, and only then issued
- **Last verified:** 2026-09-30

## Purpose

A company decides which documents need a second pair of eyes, who those eyes are and how much each person may approve. Small
companies leave it off; nothing changes for them. See the rules in [approvals.md](../domain/approvals.md) and the reasoning in
[ADR 0070](../decisions/0070-shared-configurable-approval-engine.md).

## Before you start

| Need | Where |
|---|---|
| The set-up capabilities | `approvals.settings.manage`, `approvals.policy.manage`, `approvals.authority.manage` |
| People who can approve | Grant `purchasing.purchase-order.approve` (a role such as Purchasing Manager) |
| People who can submit | Grant `purchasing.purchase-order.submit` (e.g. the Buyer template) |
| Demo | Sign in as `demo-buyer@`, `demo-manager@` or `demo-finance@erp.x1techs.com` with the demo password |

## Journey map

```text
Switch a document kind on → write a policy (conditions + steps) → set approval limits
  → buyer submits an order → approvers decide (in order, within limits, or as a delegate)
  → approved → issue     (edit, cancel or reject at any point → submit again)
```

## Steps

### 1. Switch it on

- **Screen:** `/settings/approvals` → *What needs approval*.
- **User does:** sets a kind of document to *When a policy applies* (asked only when a policy matches) or *Always required*
  (a document with no matching policy cannot proceed).
- **System does:** nothing is asked of anyone until this is set.

### 2. Write a policy

- **Screen:** *Approval policies* → **Add a policy**.
- **User does:** names it, sets a priority, adds conditions ("Order total is at least 100,000") and steps ("Purchasing manager — 1
  approval, within each approver's limit"; a later stage "Finance — 2 approvals"). Steps sharing a stage number run in parallel.
- **System does:** saves a **draft**. **Activate** makes it live and supersedes the previous version; an active version is never
  edited — use **New version**.

### 3. Set approval limits

- **Screen:** *Approval limits* → **Add a limit** (a ceiling in base currency, or no ceiling, optionally for a period).
- Only steps marked "use authority limits" look at them. Changing a limit affects future decisions only.

### 4. Submit an order

- **Screen:** the purchase order detail → **Submit for approval** (needs `purchasing.purchase-order.submit`).
- **System does:** routes it by its total in base currency (at the order's own pinned rate), freezes the facts and opens the first
  stage. The order cannot be edited while it waits: **Withdraw** first.

### 5. Decide

- **Screen:** `/approvals` (the inbox) or the order's own approval panel.
- **User does:** approves, or rejects with a reason. A delegate sees what they may decide *as* the person they cover.
- **System does:** checks the order has not changed, that the person's authority covers the amount, that the submitter is not
  approving their own submission (unless that step allows it) and that they have not already filled a slot. Only when every step of
  every stage is approved does the order become approvable to issue.

### 6. Issue

- The **Issue** button is disabled, with the reason, until the order is approved. If it is edited after approval, the approval is
  void and it must be approved again.

## Cover while you are away

`/approvals` → **Delegate my approvals**: choose a colleague and dates. They act as you, within *your* limit — never more. They
still cannot approve something they submitted.

## Status and consequence table

| Request | Meaning | What happens next |
|---|---|---|
| Waiting for approval | Open steps need decisions | The order is frozen; issuing is refused |
| Approved | Every required step approved | The order can be issued as it stands |
| Rejected | An approver refused, with a reason | Edit, then submit again (a new request) |
| Withdrawn | The submitter took it back | Edit and resubmit |
| No longer valid | The order changed after submission/approval | Submit again |
| Expired | A step's time limit ended the request | Submit again |

## Exceptions and corrections

- Overdue steps are flagged and can still be decided; an escalation capability can widen who may approve once overdue.
- A rejected or invalidated request is history; it is never reopened.

## Not yet available

- Notifications for approvers (a request waiting, a step overdue): intentionally deferred to the ERP-wide Notifications
  capability ([Q33](../OPEN-QUESTIONS.md#q33--erp-wide-notifications-capability)); until then the inbox is pull-only.
- Approver eligibility by department or warehouse ([Q26](../OPEN-QUESTIONS.md#q26--organisational-and-record-level-access-scope)).
- Adopting a material plan that needs variance approval (Phase 4).

## Source of truth

- Domain: [approvals.md](../domain/approvals.md); ADR: [0070](../decisions/0070-shared-configurable-approval-engine.md)
- UI: `frontend/packages/core-ui/src/features/approvals/`, `.../purchase-orders/purchase-order-approval-section.tsx`

## Reviewing the document

Open a request from the inbox. A normal PO step shows saved supplier, terms, item/specification,
quantity, delivery and pricing sections. Print uses those same saved sections. General PO access
is not needed. For specialist review, configure a technical step and assign the technical-review
duty, then a separate commercial step; the technical view and print omit monetary fields.
Missing saved evidence disables approval/rejection and explains withdrawal/resubmission. Changes
to quantities, terms or pricing require fresh approval, even if the total stays the same. Past
reviewers see their saved version and original profile. Older completed requests may have no
saved review document; the UI says so rather than reconstructing it from current data.

## Related

- [Document-review decision](../decisions/0085-request-specific-document-review-for-approvals.md)

- [Product journeys](README.md)
- [Phase 2 operational journey](phase-2-operational-journey.md)
- [Phase 3 material-planning journey](phase-3-material-planning-journey.md)
