[← Architecture](README.md)

# Security verification matrix: revocation, suspension and decision-time change

**Baseline:** `develop` at `a885ca0a`, 2026-11-06. **Issue:** #91 (audit F4 and F5), tracker #87.
**Scope:** verification of decision-time authority (revocation, suspension, scope and document change between an Action's early check and its commit). This file records outcomes and the guarding tests, not step-by-step reproductions; the original reproducing tests were kept out of the repository on purpose.

## Status legend

| Status | Meaning | Required reference |
|---|---|---|
| **RESOLVED** | A defect was found and is fixed on `develop`. | Fix commit and the committed regression test |
| **OUTSTANDING** | A known gap that is not fixed yet. | Tracking issue |
| **SAFE** | The safeguard already held; a test guards it. | Regression test |
| **BY DESIGN** | Behaves as the decision records say; a product decision, not a defect. | ADR |
| **NOT TESTABLE** | Nothing to change mid-flight today. | Reason |

Summary: rows 1 to 7, 10, 11, 15 and 17 are RESOLVED; row 9 is RESOLVED for the stock-moving and commitment Actions and OUTSTANDING for the Actions listed under "Outstanding" below (tracked in #91); rows 12 to 14 and 16 are SAFE; row 18 is BY DESIGN with an open product question.

## RESOLVED: rows 1 to 7 and 9 (Purchasing and Inventory)

Fix commits: `572bd1f2` (rows 1 to 7), `be9f8ea6` (row 9, stock and commitment batch).

`IssuePurchaseOrder`, `PostGoodsReceipt` and `PostPhysicalCount` keep their early permission check as a fast refusal, then decide inside the transaction through `App\Support\Scope\DecisionActor`: the shared company access barrier first (before any row lock), then an active-only reload of the person with no cached duties, then the duty (and, for a count, the warehouse reach) is checked again on that reloaded person. A suspended or removed member is now refused even without a race. Guard: `backend/tests/Feature/Security/DecisionTimeAuthorityRaceTest.php` (real two-session staging; every case has a control). Because the access barrier bounds lock waits to one second for the rest of the transaction, a receipt waiting longer than that for another user's order lock now fails and is retried by the caller.

**Row 9, second batch (stock and commitment).** The same pattern now covers every Purchasing and Inventory Action that moves stock or settles a commitment: `ReverseGoodsReceipt`, `PostQualityDisposition`, `PostSupplierReturn`, `CancelSupplierReturn`, `PostStockScrap`, `PostRejectedStockExit`, `IssuePackagedStock`, `TransferPackagedStock` (and through them the grouped issue and transfer entry points), `PostPackageOpening`, `ActivateGroupedPackageTracking`, `CommitInventoryCutoverOpeningState`, `CommitInventoryCutoverOpenPurchaseOrders`, `ApproveInventoryCutover`, `ApproveInventoryCutoverNegativeOpening`, `FinaliseInventoryCutover`, `ExecuteRecalculation`, `CancelPurchaseOrder`, `ShortClosePurchaseOrderLine` and `SubmitPurchaseOrder`. `DecideNegativeStockOverride`, `ReviewNegativeStockOverride`, `DecideRecalculationApproval` and `ResumeRecalculationRegeneration` already decided inside their transaction but on the held person; they now take the barrier and reload first. `DecisionActor::requireAll` covers the Actions that need two duties together. Actions called inside another's transaction (`ReverseGroupedReceiptLine`, `RecordQaDispositionEvidence`, `PostRejectedExitLines`) inherit the decision made by the outer Action. Guard: `backend/tests/Feature/Security/DecisionTimeAuthorityStockRaceTest.php` (reversal, disposition, supplier return, scrap, order cancellation; each with a control).

**OUTSTANDING: row 9, Actions that still check only before their transaction** (tracking issue #91; ranked by consequence, none moves stock or money directly):

1. `RequestStockRecalculation`, `RequestNegativeStockOverride`, `RequestInventoryCutoverNegativeOpening`, `ValidateInventoryCutover`, `CreateInventoryCutover`, `AttachInventoryCutoverBatch`: they open or advance a controlled process that a later, already fenced step decides.
2. `RecordPackageCheckpoint`, `RecordPhysicalCountLine`, `RecordPhysicalCountComposition`, `AddPhysicalCountLine`, `RefreshPhysicalCountLine`, `PackageCountCapture`, `RecordReceiptPackageEvidence`, `RecordReceiptComposition`: evidence capture on drafts; posting is fenced.
3. `CreateSupplierReturn`, `UpdateSupplierReturn`, `CreateStockScrap`, `UpdateStockScrap`, `CancelStockScrap`, `CancelPhysicalCount`, `CreatePhysicalCount`, `CreateGoodsReceipt`, `UpdateGoodsReceipt`, `CreatePurchaseOrder`, `UpdatePurchaseOrder`: drafts that move nothing.
4. `UpdatePurchasingSettings`, `UpdateInventorySettings`, `ReconcilePurchaseOrderFx` (Purchasing FX is owned by ADR 0165), and the master-data Create/Update/Archive Actions for items, categories, warehouses, locations, assignments, quantity units, item suppliers and supplier offers.

Behaviour note: every Action above now waits at the company access barrier like the first three, with the same one-second lock-wait bound. Concurrent use in the Purchasing and Inventory suites showed no new lock-wait timeout.


## RESOLVED: approval decisions (rows 10, 11, 15 and 17)

Fix commit: `7390b9e2`. Decision record for the approval engine: [ADR 0070](../decisions/0070-shared-configurable-approval-engine.md).

`DecideApproval` reloads the actor and checks the effective principal through `DecisionActor`
inside the transaction. The company access fence precedes the review provider's source lock
(PO header, then lines in id order), then the request. Final evidence and expiry checks repeat
under those locks. Expiry or invalidation commits before the refusal and records no decision.
`ApprovalDecisionAuthorityRaceTest` covers all four races and unchanged/delegated controls.

## How it was tested

Each hypothesis runs the real Action in a separate process on its own database connection, holding the Action at a row lock inside its transaction while a security change (permission removal, suspension, scope limit, deadline, document change) commits, then releasing it. A control run with no change confirms the setup lets the Action through. The helper is `backend/tests/Feature/Security/Concerns/TwoSessionRace.php`.

The window being proven is the time between an Action's early check and its commit. It is short when nothing contends and longer whenever the Action waits for a row lock held by another user.

## Reading the result

Statuses are defined in the legend at the top. The Evidence column names the committed test that guards each row, or the commit that fixed it.

## The matrix

| # | Operation | Hypothesis | Status | Evidence | Fence applied or proposed | Activation impact |
|---|---|---|---|---|---|---|
| 1 | `IssuePurchaseOrder` | H1 permission removed mid-flight | **RESOLVED** | `572bd1f2`; `DecisionTimeAuthorityRaceTest` (issue-duty revoked case, with control) | Actor re-read inside the transaction via `DecisionActor`, after the company access barrier | No schema change. A few indexed reads per issue. New refusal for a revoked person |
| 2 | `IssuePurchaseOrder` | H1 user suspended | **RESOLVED** | `572bd1f2`; `DecisionTimeAuthorityRaceTest` (suspended mid-flight and suspended-before-call cases) | Same; member status is now checked | Console, queue and held-object callers are refused when suspended |
| 3 | `PostGoodsReceipt` | H1 permission removed | **RESOLVED** | `572bd1f2`; `DecisionTimeAuthorityRaceTest` (receipt cases, with control) | As row 1 | As row 1 |
| 4 | `PostGoodsReceipt` | H1 user suspended | **RESOLVED** | `572bd1f2`; `DecisionTimeAuthorityRaceTest` | As row 2 | As row 2 |
| 5 | `PostPhysicalCount` | H1 permission removed | **RESOLVED** | `572bd1f2`; `DecisionTimeAuthorityRaceTest` (count cases, with control) | As row 1 | As row 1 |
| 6 | `PostPhysicalCount` | H1 user suspended | **RESOLVED** | `572bd1f2`; `DecisionTimeAuthorityRaceTest` | As row 2 | As row 2 |
| 7 | `PostPhysicalCount` | H2 warehouse scope narrowed mid-flight | **RESOLVED** | `572bd1f2`; `DecisionTimeAuthorityRaceTest` (warehouse-reach case) | Warehouse reach checked inside the transaction | One extra scoped read |
| 8 | `IssuePurchaseOrder`, `PostGoodsReceipt` | H2 record scope | NOT TESTABLE | Neither Action applies a record scope today. If one is added, check it inside the transaction | none | none |
| 9a | Stock-moving and commitment Actions (shared `ActionAuthorization::require` callers) | H1 | **RESOLVED** | `be9f8ea6`; `DecisionTimeAuthorityStockRaceTest` (reversal, disposition, supplier return, scrap, order cancellation, each with control) | Same pattern as row 1 for every listed Action (see above) | Roll-out per module; no new lock-wait timeouts seen in the Purchasing and Inventory suites |
| 9b | Remaining Actions listed under "OUTSTANDING: row 9" | H1 | **OUTSTANDING** | Pre-check only; none moves stock or money directly. Tracking issue #91 | Apply `DecisionActor` in ranked order; keep the cheap pre-check for early refusal | Wide: many callers |
| 10 | `DecideApproval` | H4 approver permission removed | **RESOLVED** | `7390b9e2`; `ApprovalDecisionAuthorityRaceTest::test_approver_permission_revoked_while_waiting_records_no_decision` | See approval resolution above | One extra user read |
| 11 | `DecideApproval` | H4 approver suspended | **RESOLVED** | `7390b9e2`; `test_approver_suspended_while_waiting_records_no_decision` | Same | Same |
| 12 | `DecideApproval` | H4 approval authority (limit) revoked | **SAFE** | Authority is read from the database inside the transaction. Guard: `test_authority_revoked` | none | none |
| 13 | `DecideApproval` | H4 delegation revoked | **SAFE** | Guards: `test_delegation_control_and_revoked`, `test_delegation_control_ok` | none | none |
| 14 | `DecideApproval` | H4 delegator suspended or loses permission | **SAFE** | Delegator is loaded inside the transaction. Guards: `test_delegator_suspended`, `test_delegator_permission_revoked` | none | none |
| 15 | `DecideApproval` | H4 step deadline passes | **RESOLVED** | `7390b9e2`; `test_deadline_passes_while_waiting_and_expiry_survives_the_refusal` | See approval resolution above | A late decision now shows "expired" |
| 16 | `DecideApproval` | H3 order edited through its Action while pending | **SAFE** | Edit refused while a request is pending. Guard: `test_document_edit_through_the_action_is_refused_while_pending` | none | none |
| 17 | `DecideApproval` | H3 order facts changed by a writer that ignores the pending freeze | **RESOLVED** | `7390b9e2`; `test_document_changes_while_waiting_and_invalidation_survives_the_refusal` | Subject lock in the review provider; see approval resolution above | Small |
| 18 | `IssuePurchaseOrder` | H5 stricter policy activated after approval | **BY DESIGN** | [ADR 0070](../decisions/0070-shared-configurable-approval-engine.md) section 3: a request keeps the policy version it was routed under. Outcome is the same in either order | Open product question only: whether approved-but-unissued orders should be re-approved when a stricter policy takes effect. No tracking issue yet | If chosen, existing approved-but-unissued orders would need re-approval on activation |

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

* OUTSTANDING (#91): every Action listed under "OUTSTANDING: row 9" that still checks only before its transaction. They share the same fix.
* HTTP behaviour (middleware, tokens). Suspension deletes tokens and sessions, so a new request is
  refused; an already-running request is not interrupted.
* Behaviour under a long lock wait on a busy production database; the tests stage the wait with a
  held row lock.
