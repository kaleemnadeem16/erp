[← Domain index](README.md)

# Package balance integration spike

Verified 2026-10-03. This records existing stock behaviour, not implemented package tracking.
[Proposed ADR 0082](../decisions/0082-reconcile-package-balances-with-stock-operations.md)
remains proposed. The original spike changed no production semantics. The storage foundation below is now shipped, without activation.

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

## Inactive storage foundation — 2026-10-03

Migration 2026_10_03_120000 initially added three empty tables without an operational writer.
The counted-evidence Action and demo below now write these tables; no operational stock
writer, route or tracking-policy setting uses them. They store immutable evidence, not current on-hand projections.
No authority to activate tracking or write operational package state is introduced.

| Table | Stored evidence and constraints |
|---|---|
| inventory_package_operations | Company, item, location, optional batch, company-unique request key, payload hash, COUNTED/RECEIPT provenance, actor, reason and recorded time |
| inventory_package_states | Operation-bound SEALED/OPEN_LOOSE form, HELD/USABLE evidence, definition/name/content snapshot, whole count and exact stock quantity |
| inventory_package_contributions | Ordered positive quantities linking each state to one or more stock layers; unique layer and position within the state |

Company-safe foreign keys and scope triggers enforce location ownership, item/batch identity,
package definition/content identity and contribution item/location/batch/company matching.
Serial layers are excluded. SEALED quantity equals whole count times content; OPEN_LOOSE has
no sealed identity/count. Deferred constraints require every state to have contributions and
their sum to equal its quantity at transaction completion. Multiple contributions may be
inserted before reconciliation is checked. Update/delete triggers protect all three tables,
including raw SQL; models also reject history edits.

PackageOperation, PackageState and PackageContribution are scoped immutable evidence models.
The unique request key is only the database prerequisite for retries: equal-payload replay,
conflict handling, stable scope locks and an authorised posting service remain unfinished.
Snapshots do not yet reconcile against live on-hand or prove availability; no consumer may
present these rows as usable stock. Backdated/reversal and projection rebuilding remain pending.

Nine new storage cases plus inherited baseline coverage pass; combined with the two earlier
suites: **68 tests / 333 assertions**. Including the architecture checks: **105 tests / 402 assertions**.
The architecture check caught a bare actor foreign key during development; it now uses a
company-safe composite reference and the complete checked set passes. This includes database-level rejection and rollback;
it does not establish concurrent operational posting. No demo is added because there is no
new user workflow. The enforcing slice must seed real Actions and supply its journey.

## Authorised counted checkpoint writer — 2026-10-03

RecordPackageCheckpoint now records a complete COUNTED snapshot through one transaction.
This is an internal Action without an HTTP route or UI. It never posts stock or valuation,
enables a policy, or treats the checkpoint as current on-hand after later movements.

Existing inventory.physical_count.create authority is checked directly in the Action, including
retries, and the actor must belong to the current company. This grants counted-evidence
creation only; it does not grant open, issue, QA, activation or valuation access.
Administrators assign that existing capability through Access Administration. No new permission
registration or cost projection is introduced.

The caller supplies the counted total, expected positive remaining quantities keyed by layer,
and ordered sealed-definition/count or loose-quantity rows. Decimal inputs are canonicalised at
four places without rounding. The fingerprint includes actor, reason, item/location/batch,
expected stock and ordered composition; equivalent decimal spellings and layer-map ordering
are equivalent payloads. Composition order is significant bookkeeping evidence.

A company/request advisory transaction lock serialises absent request identities, including
collisions at different locations. Equal fingerprints return the original immutable checkpoint,
even after stock changes; a changed payload is refused. An identical retry is not fresh stock
evidence. New requests lock the location then positive layers in received-time/id order, following
normal movement locking. Stale layer vectors or net incoming-minus-outgoing ledger totals are
refused. Every current positive layer must be fully apportioned exactly once across cohorts;
over/under-count failures roll back the header and all children.

This writer supports NONE/BATCH AVCO/FIFO counted evidence only. It refuses serial/SPECIFIC,
open shortages anywhere on the item and all QA-required receipt layers, including released ones,
until a physical availability adapter exists. The existing layer/ledger total must reconcile;
a quantity variance still uses the governed physical-count workflow. Archived definitions can
describe existing sealed stock and retain their name/code/content snapshot.

Eleven additional writer cases cover authority, revoked-authority retries, equivalent retries,
conflicts, freshness, rollback, cross-layer sealed evidence, precision and QA refusal.
A second database connection verifies exclusion by the held request lock; this is not a complete
multi-process simultaneous posting test. Combined package, demo and architecture checks pass
**143 tests / 590 assertions**. Direct cutover/recalculation writers still require adapters or
guards before activation; this checkpoint does not establish operational tracking concurrency.

PackageCheckpointDemoSeeder is opt-in through demo:seed and runs after packaged Purchasing.
It records a fictional counted arrangement of 5 L cans plus loose contents on the demo thinner,
using the real Action. It explicitly labels counted evidence rather than inferred receipt
provenance. Its stable key skips reruns, preserving immutable history and manual stock progress.
The command and module retain local/testing guards. The local additive migration and this
module seeder were run successfully; no production data was changed.

Remaining kernel work: projection rebuilding, policy resolution and operational posting adapters.
Activation, QA physical splitting, package open/unpack/transfer and reversal workflows remain
unimplemented.

## On-demand history projection — 2026-10-03

ReplayPackageCheckpoint reconstructs each immutable state and its ordered layer contributions
without reading today's package names or costs. It returns exact recorded/loose totals, composition,
integrity status, current ledger quantity and explicit mismatch reasons. No projection cache or
second quantity ledger is introduced. This replays full checkpoint snapshots; operational event
deltas are not implemented. The result always says tracking_active=false.

The internal Action enforces inventory.package-state.view and actor/company matching.
Count creation alone does not grant read access, and no valuation capability is required because
no financial columns are projected. Administrators assign the new capability through Access
Administration; the demo maintainer receives it on the module-seeder rerun. There is no new HTTP
route, response Resource, generated client or screen.

New checkpoints capture the maximum stock move ID in their exact item/location/batch scope.
A nullable company-safe stock_move_watermark column preserves existing immutable rows as unknown;
there is no guessed backfill. Movement history changing invalidates a match even when a receipt
and reversal return quantities and positive layers to their original values. Identical write
retries return the original watermark rather than refreshing historical evidence.

Replay takes the same location lock as ordinary posting/checkpoint creation before reading live
layers and movement totals. A checkpoint matches current stock only when its contributions
reconcile, it is latest in its exact scope, the movement watermark and remaining layer vector
match, ledger quantity matches, and supported QA/shortage/identity checks pass. Unknown watermark,
later movement, supersession, changed stock, incomplete evidence and unsupported scope are explicit
reasons; none activates tracking or proves live issue eligibility. Direct cutover/recalculation
writers still need integration/guards before any operational activation.

Seven additional replay cases cover exact deterministic reconstruction, later issue, equal-total
receipt/reversal history, supersession, archived/renamed definitions, unknown legacy evidence and
separate read authority. The existing demo test also invokes the real reader and verifies inactive
tracking. Combined package, demo, permission-registration and architecture checks pass
**157 tests / 653 assertions** (including inherited baseline coverage).
The local additive watermark migration and module-seeder rerun succeeded. The existing local
checkpoint remains immutable and therefore has an unknown watermark; fresh installations record
one. Do not overwrite an old checkpoint merely to make the demo appear fresh.

Kernel projection reconstruction is now implemented on demand. The
[policy foundation](package-tracking-policy.md) now pins creation-time semantics and blocks
advanced modes in the database. Operational adapters, QA evidence and activation reconciliation
remain.

## First operational adapter

[Observed receipt evidence](receipt-package-evidence.md) now captures draft physical composition
and writes immutable RECEIPT operations with source-line provenance inside normal stock posting.
These are inbound historical snapshots, not current projections. Checkpoint reconstruction uses
only COUNTED operations for supersession. Other operational adapters and activation remain pending.

## Related

- [Inventory packages](inventory-packages.md)
- [Delivery plan](../phases/package-balance-delivery.md)
- [Stock](stock.md)
- [Research gate](../architecture/research-and-design.md)
