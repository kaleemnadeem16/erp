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

## Executable contribution contract — 2026-10-03

Tests/Support/PackageContributionPrototype is deliberately test-only. Twelve unit cases
exercise the proposed contract; together with the real-Action compatibility suite,
**38 tests / 180 assertions pass**. Pint passes on both new files.

A cohort has whole package count, snapshotted content and ordered positive layer contributions.
Its contribution total must equal count times content exactly at four decimals. Selecting whole
packages takes the required quantity from that cohort's ordered contributions, then requires
the resulting per-layer vector to equal the authoritative stock allocation. Vector equality is
by layer and quantity, not array iteration order. No alternate receipt cost is substituted.
The remainder reconciles to the unselected count, including repeated selections and exact
small decimal quantities. Invalid counts and finer-than-ledger quantities are refused.

QA usability is checked per selected layer after allocation compatibility. A one-unit release
cannot release a sealed five-unit package. An explicit open and separate availability evidence
are prerequisites for consuming the released portion; this prototype does not invent that
workflow or post a QA decision. Cohorts must already be segregated by physical and QA evidence.

Transfers map each selected source contribution to its distinct posted destination layer,
preserving quantities without merging away attribution. Missing, extra or collapsed mappings
are refused. Actual posting supplies the map; the prototype cannot predict database identities.

The order within a counted cohort is deterministic bookkeeping, not identified-vessel evidence.
A count checkpoint may assign contributions across layers but must label that provenance
COUNTED, rather than asserting it was verified on the historical receipt. Before activation,
receipt-backed held stock needs attributable physical evidence; an aggregate count alone does
not resolve an unknown held/usable split.

## Next enforcing slice

Build the transactional kernel with tracking disabled by default, carrying this contract into
production only under the proposed ADR's delivery gates. No stock writer calls the prototype.
The operational adapter must pass actual AVCO/FIFO allocation and transfer mappings, revalidate
company/scope and state under locks, and integrate or guard every direct writer.

Real package reconciliation, retry/concurrency, immutable events, permissions and activation
tests remain implementation work. SERIAL/SPECIFIC, negative debt and backdated replay remain
excluded candidates until their adapters are proven. The spike does not prove loose selection,
open/unpack projection changes or receipt reversal of package events.
No operational package demo is added to this test-only spike; each enforcing slice must provide
its real Action-based demo and journey.

## Related

- [Inventory packages](inventory-packages.md)
- [Delivery plan](../phases/package-balance-delivery.md)
- [Stock](stock.md)
- [Research gate](../architecture/research-and-design.md)
