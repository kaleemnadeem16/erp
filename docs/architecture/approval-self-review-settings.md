# Approval self-review settings and mandatory independent review

**Status:** inventory of current behaviour and one open production-readiness requirement (PR-1). No decision is taken here and no engine or workflow change is made in Phase 4. Related: [ADR 0185](../decisions/0185-configure-company-business-values-and-fix-technical-invariants.md) (mandatory internal maker/checker is an invariant), [ADR 0188](../decisions/0188-record-phase-4-closure-owner-decisions-second-response-2026-11-12.md), [approval workflows](approval-workflows.md).

## 1. Settings found (grep of Approvals, Purchasing, Estimating, Inventory, Sales)

| Setting | Where it acts | Default | Operational meaning | Asserting tests |
|---|---|---|---|---|
| Approvals step `allow_submitter` | `ApprovalPolicyStep`, `DecideApproval` (authorised-step check), `ApprovalInbox` | `false` (column default, `CreateApprovalPolicy`) | When `false`, the person who submitted the request (or the delegating principal) cannot satisfy that step. When `true`, the submitter may satisfy it. Per step, set through the policy create/update requests. | `ApprovalEngineTest::test_the_submitter_cannot_satisfy_a_step_unless_that_step_allows_it` (refusal when `false`; approval by the submitter when `true`) |
| Inventory `negative_stock_allow_self_review` (company setting) | `RequestNegativeStockOverride` snapshots it onto the request as `allow_self_review`; `ReviewNegativeStockOverride` reads the snapshot | `false` | Applies only to the `IMMEDIATE_WITH_REVIEW` mode (stock has already moved): when the snapshot is `true`, the requester may record the post-movement review of their own override. | `NegativeStockPolicyTest` (default `false`); `NegativeStockOverrideWorkflowTest` (self-review refused when the snapshot is `false`, and a later setting change does not alter the stored snapshot). No test asserts the `true` case. |
| Inventory `PRIOR_APPROVAL` mode | `DecideNegativeStockOverride` | no setting | The requester can never decide their own prior approval; a different user is always required. | `NegativeStockOverrideWorkflowTest` |
| Sales quotation approval (discount authority and legacy quotation approvals) | `QuotationApprovalSubject::mayDecide`, `DecideQuotationApproval` (`APPROVAL_SELF_APPROVAL`) | not configurable | Refuses the submitter, delegated actors and people who took part in the draft, regardless of `allow_submitter`. | `QuotationApprovalApiTest` (`APPROVAL_SELF_APPROVAL`); `DiscountAuthorityApprovalTest::test_a_routing_step_allowing_the_submitter_still_cannot_let_the_submitter_or_a_delegate_decide` (present in the working tree at the time of writing; confirm it is committed) |
| Sales price-reference activation | `ApproveSalesPriceReference` plus database trigger | not configurable | Proposer and approver must differ, at action and database level. | `DiscountAuthorityPolicyTest`/reference tests (activation requires an independent person; raw-SQL self activation refused) |

No self-approve or self-review setting exists in Purchasing or Estimating beyond the generic engine's `allow_submitter`. The Inventory recalculation approval has no self-review exception (documented in `RecalculationRiskClassifier`).

## 2. Conflict with mandatory independent review (ADR 0185 / 0188)

- **Sales discount-authority maker/checker: verified and unchanged.** `allow_submitter` cannot bypass it (see the last test above). No conflict.
- **Generic engine, `allow_submitter = true`: CONFLICTS** where independent approval is mandatory. The engine lets a submitter satisfy their own step on subjects that do not add their own `mayDecide` refusal (Purchasing purchase orders and the Estimating subjects). ADR 0185/0188 name a "submitter-may-approve flag" among controls that are not a company business value where independence is mandatory.
- **Inventory `IMMEDIATE_WITH_REVIEW` with `negative_stock_allow_self_review = true`: CONFLICTS** where the review is a mandatory control: the requester reviews their own override. `PRIOR_APPROVAL` does not conflict (always a different user).
- Sales reference activation: no conflict.

## 3. Defaults are restrictive and must stay so

Both settings default to `false`. Nothing may change a default, seed a policy with `allow_submitter = true`, or enable `negative_stock_allow_self_review` in a shipped preset or demo seed. Any future change that widens self-review is a decision under PR-1, not an implementation detail.

## 4. PR-1: generic self-approval settings vs mandatory independent review

**Named production-readiness requirement. Unresolved; no decision taken; no engine or workflow change in Phase 4.**

The generic `allow_submitter` option and the Inventory `negative_stock_allow_self_review` option can let one person act as both maker and checker, which conflicts with mandatory independent review where it is mandatory. Options for the owner:

- **(a) Remove** both settings (always require a different user).
- **(b) Keep as an audited opt-in**, with an ADR amendment naming where it is allowed, a dedicated permission, a recorded reason and visible audit evidence.
- **(c) Keep only where independence is not mandatory**, with the engine refusing the option for steps and subjects classified as mandatory-independent.

Tracked by GitHub issue #121 (workstream `audit-decisions`). Needs an owner decision before any code.
