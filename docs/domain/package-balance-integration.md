[← Domain index](README.md)

# Package balance integration spike

Verified 2026-10-03. This records existing stock behaviour, not implemented package tracking.
[Proposed ADR 0082](../decisions/0082-reconcile-package-balances-with-stock-operations.md)
remains proposed. No schema, production Action, permission or demo activation changes in this spike.

## Evidence from real posting Actions

The compatibility test uses existing Inventory and Purchasing Actions and database transactions.
Its five additional cases establish:

| Case | Observed result | Package integration consequence |
|---|---|---|
| Counted 20 units from receipts of 1 and 19 units; issue 5 under FIFO | Consumption spans both layers, at value 90 | A counted sealed 5-unit package can span cost layers; one layer foreign key per package balance is insufficient |
| Older and newer receipt layers; issue one unit | Oldest eligible layer supplies the issue | Physical choice must agree with the actual allocation; it cannot silently select another receipt cost |
| Receipt and transfer inside an outer transaction, then injected composition failure | Moves, layers and AVCO pool all roll back | A package adapter can participate in the same transaction; adapter correctness still needs implementation tests |
| QA-required receipt of four units, partial PASS of one | One unit issuable; remaining held quantity cannot issue | Scalar QA release does not identify which sealed package was opened |
| Original receipt consumed while another receipt still covers stock | Original receipt reversal refused | Replacement stock does not erase source dependencies |

Test: backend/tests/Feature/Modules/Inventory/PackageBalanceCompatibilityTest.php.
It extends StockIssueTest, so its reported **26 tests / 162 assertions** include inherited
baseline coverage. Before adding the cross-layer count case, the combined compatibility,
quality-disposition and receipt-reversal run passed **68 tests / 406 assertions**.
Pint passes for the new test. These results do not prove package concurrency, reconciliation,
activation or replay behaviour: no package implementation exists yet.

## Writer audit

| Existing boundary | Finding and required adapter |
|---|---|
| PostInventoryMovement, ReceiptPoster, IssuePoster, TransferPoster | Atomic live posting seam; pass the actual layer allocation vector to the package adapter |
| LayerAllocator | Oldest eligible receipt allocation also applies to AVCO physical attribution; FIFO/SPECIFIC persist consumption records, AVCO does not. Do not reconstruct AVCO package attribution from consumption records alone |
| TransferPoster and ShortageSettler | Destination layers follow source allocations; negative-debt settlement can immediately consume incoming quantity. Guard unresolved shortages in affected scopes/pools until explicitly integrated |
| PostQualityDisposition | Appends QA decisions against receipt lines; it does not itself post stock scrap/return movements. Preserve that distinction and keep held/released provenance separate |
| ReceiptPoster reversal and ReverseGoodsReceipt | Source consumption and QA dependencies govern reversibility; package events cannot bypass those checks |
| PostPhysicalCount | Quantity variances use normal receipt/issue posting; an unchanged quantity with changed composition still needs a separate audited package correction |
| Cutover OpeningStateItemPoster | Writes opening moves/layers directly; intercepting only PostInventoryMovement is insufficient |
| ExecuteRecalculation and layer replay/reallocation collaborators | Can rebuild or reassign layer state directly; integrate or guard tracked items before activation |

## Refined proposed contract

Represent physical form independently from layer contribution quantities. A grouped sealed
cohort can have contributions from several stock layers without requiring individual vessel IDs.
Contribution sums must reconcile with physical quantities and the authoritative stock layers.
At a counted activation checkpoint, bookkeeping allocation does not prove historical receipt
provenance; label counted evidence honestly.

Obtain the real allocation vector before changing package projections, including AVCO.
A physical choice incompatible with that vector must return an actionable refusal or a compatible
alternative; never silently replace FIFO costing. Transfers must carry contributions into the
corresponding destination layers in the same transaction.

Partial QA release needs explicit physical evidence when it would split a sealed package.
Opening alone cannot be treated as permission to issue held contents. Reversal after a neutral
open still requires proven source attribution and existing dependency checks; uncertain
pre-checkpoint provenance must not be guessed.

NONE/BATCH with AVCO/FIFO are candidates, not a verified activation matrix. SERIAL/SPECIFIC,
negative-stock settlement and backdated replay remain unavailable for activation until their
adapters are proven. Every direct writer needs integration or an enforcing guard.

## Remaining gate before schema commitment

Specify and test the contribution allocation contract for partial cohort selection, transfers,
QA splits and counted checkpoints. Then refine the proposed ADR and build the transactional
kernel with tracking disabled by default. Real package reconciliation, retry/concurrency,
permissions and activation tests belong to the enforcing implementation slice.
No operational package demo is added to this test-only spike; each enforcing slice must provide
its real Action-based demo and journey.

## Related

- [Inventory packages](inventory-packages.md)
- [Delivery plan](../phases/package-balance-delivery.md)
- [Stock](stock.md)
- [Research gate](../architecture/research-and-design.md)
