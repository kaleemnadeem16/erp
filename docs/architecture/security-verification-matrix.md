[← Architecture](README.md)

# Security verification matrix: revocation, suspension and decision-time change

**Baseline:** `develop` at `a885ca0a`, 2026-11-06. **Issue:** #91 (audit F4 and F5), tracker #87.
**Scope:** verification. Rows 1 to 7 were later fixed, and row 9 for the Purchasing and Inventory Actions that move stock (see Resolution); rows 10, 11, 15 and 17 are now corrected by the Approvals/Sales owner. This file records what real
two-session PostgreSQL tests proved, so the fixes can be sized by the owning module.

## Resolution (rows 1 to 7 and 9)

`IssuePurchaseOrder`, `PostGoodsReceipt` and `PostPhysicalCount` keep their early permission check as a fast refusal, then decide inside the transaction through `App\Support\Scope\DecisionActor`: the shared company access barrier first (before any row lock), then an active-only reload of the person with no cached duties, then the duty (and, for a count, the warehouse reach) is checked again on that reloaded person. A suspended or removed member is now refused even without a race. Guard: `backend/tests/Feature/Security/DecisionTimeAuthorityRaceTest.php` (real two-session staging; every case has a control). Because the access barrier bounds lock waits to one second for the rest of the transaction, a receipt waiting longer than that for another user's order lock now fails and is retried by the caller.

**Row 9, second batch (stock and commitment).** The same pattern now covers every Purchasing and Inventory Action that moves stock or settles a commitment: `ReverseGoodsReceipt`, `PostQualityDisposition`, `PostSupplierReturn`, `CancelSupplierReturn`, `PostStockScrap`, `PostRejectedStockExit`, `IssuePackagedStock`, `TransferPackagedStock` (and through them the grouped issue and transfer entry points), `PostPackageOpening`, `ActivateGroupedPackageTracking`, `CommitInventoryCutoverOpeningState`, `CommitInventoryCutoverOpenPurchaseOrders`, `ApproveInventoryCutover`, `ApproveInventoryCutoverNegativeOpening`, `FinaliseInventoryCutover`, `ExecuteRecalculation`, `CancelPurchaseOrder`, `ShortClosePurchaseOrderLine` and `SubmitPurchaseOrder`. `DecideNegativeStockOverride`, `ReviewNegativeStockOverride`, `DecideRecalculationApproval` and `ResumeRecalculationRegeneration` already decided inside their transaction but on the held person; they now take the barrier and reload first. `DecisionActor::requireAll` covers the Actions that need two duties together. Actions called inside another's transaction (`ReverseGroupedReceiptLine`, `RecordQaDispositionEvidence`, `PostRejectedExitLines`) inherit the decision made by the outer Action. Guard: `backend/tests/Feature/Security/DecisionTimeAuthorityStockRaceTest.php` (reversal, disposition, supplier return, scrap, order cancellation; each with a control).

**Row 9, still early-check only, ranked by consequence** (none moves stock or money directly):

1. `RequestStockRecalculation`, `RequestNegativeStockOverride`, `RequestInventoryCutoverNegativeOpening`, `ValidateInventoryCutover`, `CreateInventoryCutover`, `AttachInventoryCutoverBatch`: they open or advance a controlled process that a later, already fenced step decides.
2. `RecordPackageCheckpoint`, `RecordPhysicalCountLine`, `RecordPhysicalCountComposition`, `AddPhysicalCountLine`, `RefreshPhysicalCountLine`, `PackageCountCapture`, `RecordReceiptPackageEvidence`, `RecordReceiptComposition`: evidence capture on drafts; posting is fenced.
3. `CreateSupplierReturn`, `UpdateSupplierReturn`, `CreateStockScrap`, `UpdateStockScrap`, `CancelStockScrap`, `CancelPhysicalCount`, `CreatePhysicalCount`, `CreateGoodsReceipt`, `UpdateGoodsReceipt`, `CreatePurchaseOrder`, `UpdatePurchaseOrder`: drafts that move nothing.
4. `UpdatePurchasingSettings`, `UpdateInventorySettings`, `ReconcilePurchaseOrderFx` (Purchasing FX is owned by ADR 0165), and the master-data Create/Update/Archive Actions for items, categories, warehouses, locations, assignments, quantity units, item suppliers and supplier offers.

Behaviour note: every Action above now waits at the company access barrier like the first three, with the same one-second lock-wait bound. Concurrent use in the Purchasing and Inventory suites showed no new lock-wait timeout.


## Approval decision resolution (rows 10, 11, 15 and 17)

`DecideApproval` reloads the actor and checks the effective principal through `DecisionActor`
inside the transaction. The company access fence precedes the review provider's source lock
(PO header, then lines in id order), then the request. Final evidence and expiry checks repeat
under those locks. Expiry or invalidation commits before the refusal and records no decision.
`ApprovalDecisionAuthorityRaceTest` covers all four races and unchanged/delegated controls.

## How it was tested

Each hypothesis runs the real Action in a forked process on its own database connection. The test
holds a row lock on the first row the Action locks inside its transaction (the purchase order, the
receipt, the count or the approval request). The Action has therefore already passed everything it
checks before its transaction, and is waiting. PostgreSQL is polled until it reports the Action
blocked on a lock; the test then commits the change (permission removed, user suspended, scope
limit added, deadline passed, document changed) and releases the lock. The Action continues and
either refuses or commits. A control run with no change confirms the setup lets the Action
through. The helper is `backend/tests/Feature/Security/Concerns/TwoSessionRace.php`; the
committed guards are `backend/tests/Feature/Security/ApprovalDecisionAuthorityRaceTest.php`.

The window being proven is the time between the Action's early check and its commit. It is short
when nothing contends, and longer exactly when it matters: whenever the Action has to wait for a
row lock another user holds (a second receipt on the same order, an approval being decided).

## Reading the result

* **CONFIRMED**: reproduced; the Action committed after the change had committed. The reproducing
  test is kept outside the repository (scratch), because CI must stay green.
* **SAFE**: the safeguard held; a passing test is committed as a regression guard.
* **BY DESIGN**: behaves as the current decision records say; a product decision, not a defect.

## The matrix

| # | Operation | Hypothesis | Result | Evidence | Proposed minimal fence | Activation impact |
|---|---|---|---|---|---|---|
| 1 | `IssuePurchaseOrder` | H1 permission removed while waiting for the order lock | **CONFIRMED** | Order issued after the grant was deleted and committed (scratch `ScratchAuthorityRace::test_issue_revoked`) | Re-read the actor inside the transaction, after the order lock: active member, in this company, with the capability read from the database (not the held object). Optionally take the shared company access fence first (see below) | No schema change. Two to three indexed reads per issue. New refusal for a revoked person |
| 2 | `IssuePurchaseOrder` | H1 user suspended while waiting | **CONFIRMED** | Issued after suspension committed (`test_issue_suspended`). Stronger: a person already suspended and freshly loaded is still allowed (`test_sequential_suspended_actor_is_still_allowed_by_all_three`) because the Action never looks at member status | Same re-read as row 1 (the status check is the new part) | Console, queue and held-object callers start being refused when suspended. HTTP already refuses through `EnsureUserIsActive`, once per request |
| 3 | `PostGoodsReceipt` | H1 permission removed | **CONFIRMED** | Receipt posted, stock moved, number issued, after the grant was gone (`test_gr_revoked`) | As row 1, after the receipt lock | As row 1. Stock and valuation are irreversible except by a reversal, so this is the costliest of the three |
| 4 | `PostGoodsReceipt` | H1 user suspended | **CONFIRMED** | `test_gr_suspended`, plus the sequential case above | As row 2 | As row 2 |
| 5 | `PostPhysicalCount` | H1 permission removed | **CONFIRMED** | Count posted after the grant was gone (`test_count_revoked`) | As row 1, after the count lock | As row 1 |
| 6 | `PostPhysicalCount` | H1 user suspended | **CONFIRMED** | `test_count_suspended`, plus the sequential case above | As row 2 | As row 2 |
| 7 | `PostPhysicalCount` | H2 an "only my units" limit added while waiting | **CONFIRMED** | Posted into a warehouse the person no longer reaches (`test_count_scope_limited`). The warehouse check runs before the transaction | Move the warehouse-scope check inside the transaction after the count lock. Scope limits are read from the database, so no new lock is needed for the fresh read | One extra scoped read. No data change |
| 8 | `IssuePurchaseOrder`, `PostGoodsReceipt` | H2 record scope | NOT TESTABLE | Neither Action applies a record scope, so there is nothing to change mid-flight. If a scope is added later, add it inside the transaction | none | none |
| 9 | `ActionAuthorization::require` (shared helper) | H1 | **CONFIRMED by construction** | It calls `$actor->hasAnyPermission()` on the held object. Every Action using it checks once and never again. Rows 1 to 6 are the three busiest callers that check before their transaction. The helper itself was not raced for each of its other callers | Offer a second helper (for example `require inside a transaction`) that re-reads the actor, and use it in the Actions that move money, stock or approvals first. Do not change the helper's cheap pre-check, which is still right for early refusal | Wide: many callers. Roll out per module, highest consequence first |
| 10 | `DecideApproval` | H4 approver permission removed while waiting | **FIXED** | Passing real two-session guard: `test_approver_permission_revoked_while_waiting_records_no_decision` | Implemented as described in Approval decision resolution above | One extra user read. No schema change |
| 11 | `DecideApproval` | H4 approver suspended while waiting | **FIXED** | Passing real two-session guard: `test_approver_suspended_while_waiting_records_no_decision` | Implemented as described in Approval decision resolution above | Same |
| 12 | `DecideApproval` | H4 approval authority (limit) revoked while waiting | **SAFE** | Refused with "not eligible". The authority is queried from the database inside the transaction. Guard: `test_authority_revoked` | none | none |
| 13 | `DecideApproval` | H4 delegation revoked while waiting | **SAFE** | Refused. Guard: `test_delegation_control_and_revoked` (with its control `test_delegation_control_ok`) | none | none |
| 14 | `DecideApproval` | H4 delegator suspended, or delegator permission removed | **SAFE** | Both refused: the delegator is loaded inside the transaction. Guards: `test_delegator_suspended`, `test_delegator_permission_revoked` | none | none |
| 15 | `DecideApproval` | H4 step deadline passes while waiting | **FIXED** | Passing real two-session guard: `test_deadline_passes_while_waiting_and_expiry_survives_the_refusal` | Implemented as described in Approval decision resolution above | Behaviour change only for a decision made after the deadline. A person can see "expired" instead of an approval that arrived late |
| 16 | `DecideApproval` | H3 order edited through the normal edit Action while the decision is in flight | **SAFE** | The edit is refused ("waiting for approval") because a pending request exists, and the approval completes against the unchanged order. Guard: `test_document_edit_through_the_action_is_refused_while_pending` | none | none |
| 17 | `DecideApproval` | H3 order facts changed by a writer that does not honour the pending freeze | **FIXED** | Passing real two-session guard: `test_document_changes_while_waiting_and_invalidation_survives_the_refusal` | Implemented as described in Approval decision resolution above | Small. Needs a subject-lock hook in the approval subject registry |
| 18 | `IssuePurchaseOrder` | H5 a stricter policy activated after the approval | **BY DESIGN** | An order approved under policy version 1 still issued after a two-stage version 2 was activated (scratch `test_h5_policy_activated_stricter_after_approval_then_issue`). ADR 0070 section 3 says a request keeps the version it was routed under. Activation and issuing share no lock, but the outcome is the same in either order, so there is no race to fix | Product decision only: if an approved-but-unissued order should be re-approved when a stricter policy takes effect, `RequireApproval` would compare the request's policy version to the current one | If chosen, existing approved-but-unissued orders would need re-approval on activation day |

## What is not a problem

* Authority limits, delegations and delegators are re-read from the database inside the decision
  transaction. They do not need a fence.
* Editing a pending order through the Action is refused. The pending-approval freeze works, but it
  is a status check, not a lock.
* Issuing consumes an approval only after re-checking the order's current facts under the order
  lock. A stale approval cannot become an issued order.

## Sizing the fix: smallest fence per operation

Do not copy the quotation all-company lock everywhere. Use the smallest tool that closes each gap:

1. **Fresh actor read inside the transaction** (rows 1 to 7, 10, 11). One indexed read of the
   user (active, this company) and a capability check that bypasses the held object's cached
   permissions. No lock. This removes the stale-object and queue-worker holes, including the larger
   one: a suspended member is allowed today even with no race at all. `Sales/Support/Kernel/ActiveActor`
   is the existing pattern for the status half.
2. **Shared company access fence** (`CompanyAccessFence::lock()`, a single row, not `lockAll`) only
   for the operations the owner considers irreversible: goods receipt, physical count, order
   issue, and the approval decision. Security writers already take the matching exclusive lock
   (a database trigger on users, grants and scope limits), so a revocation then waits for the
   in-flight operation or is seen by it. Take the fence first, before any row lock; the repository's
   own fence test proves the opposite order deadlocks. Its lock timeout is one second, so a long
   security write makes the operation fail rather than wait.
3. **Source-level lock for the document** (rows 15 and 17): no company fence. The order row is
   already the lock Issue, Edit and the review provider use; the decision only has to use it too,
   in the same order (order, then request).

The fresh read (item 1) closes every case in this matrix except a revocation that commits between
the fresh read and the commit. Item 2 closes that last gap.

## Not covered

* Every other Action that checks only before its transaction. Rows 1 to 6 are the three the audit
  named; the audit's `ActionAuthorization` finding implies more. They share the same fix.
* HTTP behaviour (middleware, tokens). Suspension deletes tokens and sessions, so a new request is
  refused; an already-running request is not interrupted.
* Behaviour under a long lock wait on a busy production database; the tests stage the wait with a
  held row lock.
