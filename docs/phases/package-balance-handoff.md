[← Roadmap](README.md)

# Package feature — continuation handoff

Rewritten 2026-10-08 at the **grouped package tracking v1 completion point: commit `1c73d81`**, accepted as complete by the owner. The v1 sign-off in
[package-balance-ui.md](package-balance-ui.md#v1-sign-off-2026-10-08) is the authority for what v1 is. This file is scoped to the package workstream;
other agents' work continues independently. Update it, STATUS and the delivery plan when the next package slice lands.

## Resume here

**Next build, when the owner says go:** the rejected-stock exit — [rejected-stock-exit-plan.md](rejected-stock-exit-plan.md), four slices, starting with the shared exit core. Until then nothing is in flight.

**Nothing is in flight. Do not start container-level tracking, and do not extend v1 implicitly.** Each enhancement below is a **new design slice**: draft the
proposal (an ADR where a decision is needed), get the owner's answers, then implement. The owner's stated priority is **supplier return / scrap for rejected
grouped stock first**, because it closes the most obvious remaining operational dead end (rejected packages stay on hand today).

Use the WSL repository **/home/kaleem/projects/erp**, branch develop; another agent works in the same tree, so commit your own files by explicit path and never
stash. Read AGENTS.md, STATUS, the invariants and ADRs 0090–0094 before editing; treat this handoff as a dated baseline, not proof the tree is unchanged.

## 1. v1 complete

Activation (one atomic, database-validated transition from a reconciled count); receipt with observed physical composition; **QA-held receipt** (inspection-required
deliveries arrive held); issue and transfer by physical draw; physical count by composition (composition-only versus governed variance, found cost); whole-receipt
reversal with a declared draw; **QA disposition** by declared portion (usable or rejected); **fail-closed database enforcement** for every stock writer;
**permissions** (capabilities, rollout migration `2026_10_08_140000`, role recipes, warehouse scope, reason codes); **mobile verification** at about 390 px for count, issue,
transfer and reversal; Playwright coverage of each flow and of quantity-only parity. Decisions: ADRs 0090 (issue/transfer), 0091 (activation/enforcement), 0092 (count),
0093 (reversal), 0094 (supplier QA). Screens and API: [package-balance-ui.md](package-balance-ui.md).

## 2. Known limitations (accepted, each refused or explained in the product)

- **Rejected stock** stays on hand — shown apart, never usable — until a return/scrap movement exists (the same is true of quantity-only stock).
- **QA / count restrictions:** a location holding held or rejected packages cannot be counted until QA has decided them; a QA line with any disposition cannot be reversed.
- **Held-package restrictions:** held packages cannot be issued, transferred, opened or counted; a sealed held can is released or rejected whole, never opened or split.
- **Unsupported writers** (refused with a message and by the database): cutover opening state, recalculation, negative-stock overrides and shortage settlement, scalar issue/transfer/count of a grouped item; serial, SPECIFIC-valued and QA-dependent-layer stock cannot be activated or counted; activation, deactivation and tracked-level changes are one-way for now.
- **Reach:** Issue and Transfer are launched from the item page, one location at a time; role recipes are documented, not shipped as templates.

## 3. Future enhancements (each a new design slice; none started)

1. **Supplier return / scrap of rejected stock — next, owner priority.** Decided in [ADR 0097](../decisions/0097-supplier-return-and-scrap-of-rejected-stock.md) (accepted 2026-10-08); the build plan is [rejected-stock-exit-plan.md](rejected-stock-exit-plan.md); **not started**. It also gives
   rejected quantity-only stock an exit.
2. A governed **change of tracked levels**.
3. **Deactivation / collapse** back to quantity-only.
4. **Container-level tracking** (individual vessels and remnants) — only after the above are settled and the owner asks.
5. **Line-level or partial receipt reversal** (a Purchasing decision).
6. **Count approval thresholds** (value-based, through the shared approval engine, for both kinds of item).
7. **QA / count reconciliation** of held stock.
8. **Standalone Issue / Transfer screens** (menu entries, multi-location).
9. **Package-aware proposals in the Material Preview** — a *deferred estimating enhancement* (owner decision 2026-10-08: not ahead of return/scrap); also needs the residual/waste variance bands.

## Historical baseline (2026-10-03)

The sections below describe the package work as it stood before the grouped issue, transfer, activation, count, reversal and QA slices. They are kept for the
reasoning and the kernel boundary, not as current state; where they say "not implemented", the sign-off above supersedes them.

## Historical: implementation boundary at 2026-10-03

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

## Historical: the next slice as planned on 2026-10-03

**2026-10-07:** ADR 0090 is accepted and the grouped issue slice is built: the allocation-vector seam (`PostInventoryMovement::issuePosted()` /
`transferPosted()`), the `ISSUE` evidence kind with migration `2026_10_07_100000`, `IssuePackagedStock` and `PackageIssueDemoSeeder`.
See [package-issue-operations.md](../domain/package-issue-operations.md). **Transfer, activation and receipt are done too** (`TransferPackagedStock`; `ActivateGroupedPackageTracking`, fail-closed database guards and the `RECEIPT_IN` leg, ADR 0091, migrations `2026_10_07_110000`, `2026_10_08_100000`, `2026_10_08_110000`; demos for each). **Package-aware physical count and receipt reversal are done** (ADR 0092/0093; migrations `2026_10_08_120000`, `2026_10_08_130000`; demos). **First UI slice built** ([package-balance-ui.md](package-balance-ui.md): item card, activation wizard, receipt composition, issue dialog, `reason_code`s, Playwright lifecycle spec). **Grouped package tracking v1 is signed off** (see the UI doc's v1 sign-off; ADR 0094 supplier QA built). **Next, only after a decision each:** supplier return/scrap movement for rejected stock, a governed change of tracked levels and a collapse/deactivation decision, line-level reversal (a Purchasing decision), container-level tracking, then screens. The steps below this note describe the issue design as it was before this slice and are historical.

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

## Historical: demo and verified state at 2026-10-03

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

## Historical: shared-checkout notes at 2026-10-03

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
