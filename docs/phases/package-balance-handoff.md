[← Roadmap](README.md)

# Package feature — continuation handoff

Recorded 2026-10-03 after feature commit **2df3c84**. This is the continuation point for
Item/Packaging/Purchasing and physical package evidence. It is scoped to this workstream;
other agents' work and the project's overall next phase continue independently.
Update this handoff when the next package slice lands, alongside STATUS and the delivery plan.

## Resume here

**Next implementation: package-aware stock issues, then transfers.** Saved opening/unpacking
is complete as an internal backend evidence slice. No issue/transfer package adapter is started
or left uncommitted at this checkpoint. Do not repeat the opening work or enable tracking yet.

The user authorised incremental implementation, wants meaningful demo scenarios with each slice,
and explicitly requires committing changes because other agents share the checkout. The user
confirmed unopened package counts plus combined open/loose leftovers are enough; individual
container IDs are not required. No further answer is currently needed to start the next slice.

Use the WSL repository **/home/kaleem/projects/erp**, currently branch develop. The Windows
conversation workspace is C:\Users\Kaleem\Documents\ChatGPT\erp; it is not the implementation
checkout. From PowerShell:

```powershell
wsl.exe -d Ubuntu --exec /bin/bash -lc 'cd ~/projects/erp && git status --short && git log -5 --oneline'
```

Read AGENTS.md, STATUS, invariants and the relevant module/domain docs again before editing.
Treat this handoff as a dated baseline, not proof the shared tree has remained unchanged.

## Completed versus still unavailable

| Capability | Current state |
|---|---|
| Named item packages, nested definitions, supplier offers | Delivered; commercial purchasing UI/demo exists |
| Independent purchasing and price basis | Delivered; issued agreements remain frozen; future offer changes affect future orders |
| Supplier changes the current agreement | Existing replacement-order workflow; physical observation does not rewrite price/package agreement |
| Creation-time package policy | Explicit item > nearest category > company, persisted provenance; advanced modes blocked |
| Counted composition checkpoint | Authorised immutable full-scope evidence with layer contributions and stock watermark |
| Historical replay/freshness | Reconstructs stored snapshots; detects newer movement, changed layers, newer full-scope evidence and unsupported scope |
| Observed receipt composition | Internal draft capture plus atomic evidence posting through normal receipt post; initially HELD for QA |
| Open/unpack preview | Read-only exact contribution split against a fresh full-scope snapshot |
| Saved open/unpack | Immutable complete successor, predecessor/selection, reason/actor, duplicate/conflict protection |
| Package-aware issue/transfer/count correction | Not implemented |
| QA split/release, reversal dependency, cutover/recalculation adapters | Not implemented for package state |
| Receipt composition capture and package history/opening screens | Not implemented; current physical slices are internal Actions |
| Live package balances, activation and physical package proposals | Not enabled; all stored item/company modes remain QUANTITY_ONLY |

ADRs 0052/0053/0080 are accepted. Operational refinement **ADR 0082 is still proposed**;
its research and exclusions are the design basis, not permission to advertise completed tracking.
Never edit an accepted ADR to change a decision.

## Exact implementation boundary

Inventory Actions are under backend/app/Modules/Inventory/Actions:

- RecordPackageCheckpoint: complete counted stock/layer reconciliation, COUNTED evidence.
- ReplayPackageCheckpoint: package-state.view, current freshness and immutable composition.
- PreviewPackageOpening: OPEN versus UNPACK proposal; no save/reservation.
- PostPackageOpening: package-state.open **and** package-state.view, complete saved successor.
- RecordReceiptPackageEvidence: atomic receipt-source evidence, called by PostGoodsReceipt.

Purchasing RecordReceiptComposition captures actual sealed/loose arrival independently of the
commercial basis. No observations means no physical state is invented. RECEIPT is partial
inbound evidence and cannot serve as the current full-scope predecessor.

PackageOpeningComposition (Inventory/Support) splits ordered multi-layer contributions exactly.
Opening turns sealed content into grouped usable loose stock; unpacking turns outer cartons into
sealed inner packages. Neither writes stock moves/layers/pools/valuation. Held stock is separate.

Kernel tables: inventory_package_operations, inventory_package_states and
inventory_package_contributions. Operation kinds are COUNTED, RECEIPT, OPEN and UNPACK.
Saved transformations carry predecessor_id, selected_state_id/count and unchanged watermark.
States may inherit labels/content through origin_state_id from the immediate predecessor;
archiving/renaming a definition does not rewrite stored identity. Each operation stores a complete
scope snapshot. Latest full-scope replay includes COUNTED/OPEN/UNPACK, excluding RECEIPT.

Request advisory locks share namespace package-checkpoint:<company>:<key>; posting also retains
the stable location lock. A unique company/predecessor index prevents branches. Equal retry
returns original evidence even after later stock changes, with current authority rechecked.
Deferred database checks enforce complete composition, per-state sums, per-layer conservation
and the selected physical transformation. Raw UPDATE/DELETE is refused.

The newest migration is 2026_10_03_180000_add_package_opening_lineage.php, applied locally.
Its downgrade refuses while opening history exists. Earlier kernel, watermark, policy and receipt
migrations were also applied. Do not discard history or relax QUANTITY_ONLY gates for convenience.

## Next slice: concrete starting work

**2026-10-07:** ADR 0090 is accepted and the grouped issue slice is built: the allocation-vector seam (`PostInventoryMovement::issuePosted()` /
`transferPosted()`), the `ISSUE` evidence kind with migration `2026_10_07_100000`, `IssuePackagedStock` and `PackageIssueDemoSeeder`.
See [package-issue-operations.md](../domain/package-issue-operations.md). **Transfer, activation and receipt are done too** (`TransferPackagedStock`; `ActivateGroupedPackageTracking`, fail-closed database guards and the `RECEIPT_IN` leg, ADR 0091, migrations `2026_10_07_110000`, `2026_10_08_100000`, `2026_10_08_110000`; demos for each). **Package-aware physical count is done** (ADR 0092; migration `2026_10_08_120000`; `PackageCountDemoSeeder`). **Next:** receipt reversal, QA splitting, a governed change of tracked levels and a collapse/deactivation decision, container-level tracking, then screens. The steps below this note describe the issue design as it was before this slice and are historical.

1. Re-read the writer audit/contribution contract in package-balance-integration and inspect
   PostInventoryMovement with Actions/Movements/LayerAllocator and the issue collaborator.
2. Design the smallest enforcing issue adapter: an explicit package/loose selection from fresh
   full-scope evidence, linked to the actual posted move and actual layer-allocation vector.
   Existing OPEN/UNPACK conservation constraints preserve quantity; a quantity-changing ISSUE
   needs its own governed evidence contract/migration, not a fake opening or a second count.
3. Keep stock quantity and valuation authoritative. The selected physical contributions must
   agree with the existing allocator. Do not silently change FIFO to satisfy a package choice.
   AVCO does not persist the same consumption vector as FIFO: capture actual allocations at the
   posting boundary rather than deriving them from a guessed before/after quantity.
4. Save movement and package successor in one transaction, with permission, freshness, retry
   and concurrency protections. Preserve ordinary QUANTITY_ONLY posting behaviour. Until all
   writers are integrated/guarded, scalar movements still invalidate kernel freshness.
5. Add real-Action tests and a dedicated create-once demo for loose draws, selection exceeding
   available content, allocator mismatch, rollback and retry. Verify value/quantity against the
   existing stock engine. Then implement transfer remapping to actual destination layers.

This is a continuation direction, not a decided new API/schema. Follow the research/design gate
for unresolved issue semantics and retain the current research where it answers the same question.

First-release candidates are NONE/BATCH with AVCO/FIFO. Current checkpoints/opening refuse
SERIAL/SPECIFIC, open shortages, QA-dependent layers, unknown movement watermark and stale stock.
Receipt evidence also refuses serial arrivals and incoming stock consumed by negative-debt
settlement. No expiry/FEFO, reservations, resealing or per-vessel identity has been implemented.
Cutover and recalculation have direct writer paths: review them before any activation claim.

## Demo and last verified state

Local demos are opt-in, guarded for local/testing, use real Actions and skip immutable histories
by stable keys. Never recompute a historical request from today's stock or overwrite manual progress.

| Dedicated fixture | What to inspect |
|---|---|
| demo-pack-thinner | Packaged purchasing examples and fictional counted 5 L can/loose checkpoint |
| Package policy demo items | Company/category/explicit QUANTITY_ONLY provenance |
| demo-physical-receipt-thinner | Mixed sealed/loose, alternative physical cans versus frozen commercial boxes, QA-held receipt |
| demo-package-opening | Fresh two-carton checkpoint for OPEN/UNPACK preview; two FIFO layers |
| demo-package-opened | Saved COUNTED → UNPACK → OPEN → OPEN history; 1 carton + 18 bottles + 0.2 L loose, still 4 L |

Saved demo request keys: demo-saved-opening-count, demo-saved-unpack, demo-saved-open-1/-2.
The saved demo and additive migration loaded successfully locally. Its value is unchanged;
source layers are 1 L at 800 and 3 L at 900. No physical-feature screen exists yet.

Final verified feature baseline: **230 tests / 1,061 assertions**, plus **41 architecture tests /
81 assertions**. These totals include inherited stock/checkpoint cases. Targeted PHPStan and
Pint pass. The committed-data concurrency test proves location-lock exclusion using a separate
database connection. Its disposable test cleanup uses migrate:fresh: ordinary migration rollback
correctly refuses the committed immutable demo history.

Run tests serially on the same test database, or create a separate database through the documented
tmpfs test runner. Architecture isolation assertions expect the normal erp_testing database name;
do not substitute a custom name for that suite without accounting for its expectations.

```bash
docker compose exec -T -e TEST_DB_HOST=postgres-test app php artisan test --compact --filter="PackageOpening|ReplayPackageCheckpointTest|PackageKernelStorageTest|ReceiptPackageEvidenceTest|ReceiptPackageEvidenceDemoSeederTest|PackageTrackingPolicy|PermissionRegistrationTest"
docker compose exec -T -e TEST_DB_HOST=postgres-test app php artisan test --compact --testsuite=Architecture
```

## Shared-checkout coordination

Our latest implementation commit is 2df3c84; preview is ae54349 and receipt integration 76462df.
Concurrent authorisation commits 409b4d3 and 6956a11 are a separate workstream. The latter now
requires explicit actors in master-data/purchase-order Actions such as CreateItem; new demos
must follow current signatures. Some shared README/permission-test updates landed there while
this package slice was being built. Do not revert them or assume the older Action signatures.

At handoff inspection there were no tracked uncommitted changes; frontend/.auth/ and
frontend/apps/e2e/frontend/ were untracked pre-existing artifacts. Their ownership is unknown;
leave them alone. This is not a live inventory of other chats' tasks. Recheck git status/log and
STATUS before resuming; stage explicit owned files only, avoid shared-index commits when another
agent has staged files, and commit each completed slice with its docs/demo changes.

## Related

- [Delivery checklist](package-balance-delivery.md)
- [Saved opening/unpacking](../domain/package-opening-operations.md)
- [Writer audit and contribution contract](../domain/package-balance-integration.md)
- [Receipt evidence](../domain/receipt-package-evidence.md)
- [Tracking policy](../domain/package-tracking-policy.md)
- [Packaged purchasing journey](../journeys/packaged-purchasing-demo.md)
- [Demo rules and test environment](../architecture/environments.md)
- [Project status](../STATUS.md)
