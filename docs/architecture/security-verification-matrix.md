[← Architecture](README.md)

# Security verification matrix: decision-time authority

**Issue:** #91 (audit F4 and F5), tracker #87.
**Scope:** whether an Action still honours a revocation, suspension or scope change that commits between its early check and its commit. This file records only the outcome per finding: status, a one-line finding, the reference and the guarding test file. It deliberately contains no reproduction steps.

## Status legend

| Status | Meaning | Required reference |
|---|---|---|
| **RESOLVED** | A defect was found and is fixed on `develop`. | Fix commit and the committed regression test |
| **OUTSTANDING** | A known gap that is not fixed yet. | Tracking issue |
| **SAFE** | The safeguard already held; a test guards it. | Regression test |
| **BY DESIGN** | Behaves as the decision records say; a product decision, not a defect. | ADR |
| **NOT TESTABLE** | Nothing to change mid-flight today. | Reason |

Summary: rows 1 to 7, 9a, 9b, 9c, 9d, 10, 11, 15 and 17 are RESOLVED; rows 12 to 14 and 16 are SAFE; row 8 is NOT TESTABLE; row 18 is BY DESIGN with an open product question (#106, #92). No Inventory or Purchasing row is currently OUTSTANDING. Row 9c was found incomplete on 2026-11-12 (two Inventory Actions had been missed) and is closed by row 9d. Actions in other modules that check authority only before their transaction have not been inventoried; they are tracked in #112 and are not cleared by this matrix. Status for row 9d is local-test evidence; exact-head CI is required before it is relied on.

## The matrix

Coverage of rows 9a to 9c: each commit changed roughly 22 to 25 Actions, and the named test file exercises only a sample of them (9a: receipt reversal, QA disposition, supplier return, scrap, purchase order cancellation; 9b: count create and cancel, cutover create, draft purchase order edit; 9c: warehouse edit, item-supplier archive, purchasing policy, inventory policy, FX rate). The remaining Actions in each commit follow the same reviewed pattern; there is no per-Action test for them. A static guard (`backend/tests/Architecture/InventoryPurchasingDecisionAuthorityGuardTest.php`) fails when an Inventory or Purchasing Action decides authority only before its transaction, and package-state secondary permissions have their own tests (`PackageStateDecisionAuthorityRaceTest`).

Fixes follow one pattern: the Action decides inside its transaction from the stored, active actor (`App\Support\Scope\DecisionActor`), behind the company access barrier. Decision record for the approval engine: [ADR 0070](../decisions/0070-shared-configurable-approval-engine.md).

| # | Operation | Finding | Status | Reference | Regression test file |
|---|---|---|---|---|---|
| 1 | `IssuePurchaseOrder` | A removed duty was not re-checked at commit | **RESOLVED** | `572bd1f2` | `DecisionTimeAuthorityRaceTest` |
| 2 | `IssuePurchaseOrder` | A suspended member was not re-checked at commit | **RESOLVED** | `572bd1f2` | `DecisionTimeAuthorityRaceTest` |
| 3 | `PostGoodsReceipt` | A removed duty was not re-checked at commit | **RESOLVED** | `572bd1f2` | `DecisionTimeAuthorityRaceTest` |
| 4 | `PostGoodsReceipt` | A suspended member was not re-checked at commit | **RESOLVED** | `572bd1f2` | `DecisionTimeAuthorityRaceTest` |
| 5 | `PostPhysicalCount` | A removed duty was not re-checked at commit | **RESOLVED** | `572bd1f2` | `DecisionTimeAuthorityRaceTest` |
| 6 | `PostPhysicalCount` | A suspended member was not re-checked at commit | **RESOLVED** | `572bd1f2` | `DecisionTimeAuthorityRaceTest` |
| 7 | `PostPhysicalCount` | A narrowed warehouse reach was not re-checked at commit | **RESOLVED** | `572bd1f2` | `DecisionTimeAuthorityRaceTest` |
| 8 | `IssuePurchaseOrder`, `PostGoodsReceipt` | Record scope: neither Action applies one today | **NOT TESTABLE** | Reason: nothing to change mid-flight; add the check inside the transaction if a record scope is introduced | none |
| 9a | Stock-moving and commitment Actions | Same finding as rows 1 and 2 | **RESOLVED** | `be9f8ea6` | `DecisionTimeAuthorityStockRaceTest` |
| 9b | Process-opening, evidence-capture and draft Actions | Same finding as rows 1 and 2 | **RESOLVED** | `2225e3c3` | `DecisionTimeAuthorityDraftRaceTest` |
| 9c | Settings, FX reconciliation and master-data Actions | Same finding as rows 1 and 2 | **RESOLVED** | `7b156c54` | `DecisionTimeAuthorityMasterDataRaceTest` |
| 9d | `MaintainItemPackaging`, `DeleteWarehouseLocationAssignment` | Same finding as rows 1 and 2 (found after row 9c) | **RESOLVED** | `f72d1fd2` | `DecisionTimeAuthorityInventoryD2RaceTest`, `D2AuthorityPathsTest` |
| 10 | `DecideApproval` | A removed approver duty was not re-checked at decision | **RESOLVED** | `7390b9e2` | `ApprovalDecisionAuthorityRaceTest` |
| 11 | `DecideApproval` | A suspended approver was not re-checked at decision | **RESOLVED** | `7390b9e2` | `ApprovalDecisionAuthorityRaceTest` |
| 12 | `DecideApproval` | Approval authority limit is read inside the transaction | **SAFE** | Held before this work | `ApprovalDecisionAuthorityRaceTest` |
| 13 | `DecideApproval` | Delegation is read inside the transaction | **SAFE** | Held before this work | `ApprovalDecisionAuthorityRaceTest` |
| 14 | `DecideApproval` | Delegator status and duty are read inside the transaction | **SAFE** | Held before this work | `ApprovalDecisionAuthorityRaceTest` |
| 15 | `DecideApproval` | A passed step deadline was not re-checked at decision | **RESOLVED** | `7390b9e2` | `ApprovalDecisionAuthorityRaceTest` |
| 16 | `DecideApproval` | Editing a pending order through its Action is refused | **SAFE** | Held before this work | `ApprovalDecisionAuthorityRaceTest` |
| 17 | `DecideApproval` | Reviewed order content was not re-checked at decision | **RESOLVED** | `7390b9e2` | `ApprovalDecisionAuthorityRaceTest` |
| 18 | `IssuePurchaseOrder` | Whether approved-but-unissued orders should be re-approved when a stricter policy takes effect (open product question, not a defect) | **BY DESIGN** | [ADR 0070](../decisions/0070-shared-configurable-approval-engine.md) section 3; tracked in #106 and #92 | none |

Regression tests live under `backend/tests/Feature/Security/`, except `D2AuthorityPathsTest` (`backend/tests/Feature/Modules/Inventory/`). The static guard lives under `backend/tests/Architecture/`.
