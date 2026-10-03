[← Domain index](README.md)

# Package tracking policy foundation

Implemented 2026-10-03 under accepted [ADR 0052](../decisions/0052-progressive-inventory-package-tracking.md).
This is creation-time policy resolution and an activation gate, not operational package tracking.

## Creation and provenance

ResolvePackageTrackingPolicy chooses explicit item mode, then the nearest category ancestor
with a default, then the company default. Item's creation hook persists the resolved mode and
source; category walking is company-scoped and detects cycles. Existing items are never
re-resolved during edit, stock posting or changes to category/defaults.

| Field | Meaning |
|---|---|
| companies.default_package_tracking_mode | Company-owned fallback; QUANTITY_ONLY by default |
| categories.default_package_tracking_mode | Nullable category choice; null inherits |
| items.package_tracking_mode | Pinned resolved physical tracking mode |
| items.package_tracking_source | ITEM, CATEGORY, COMPANY or UNATTRIBUTED_QUANTITY_ONLY |
| items.package_tracking_source_category_id | Company-safe reference to the selected ancestor, only for CATEGORY |

Category reassignment preserves the original source. The source category reference preserves
provenance and can prevent deletion of an ancestor used by existing items. No historical
category choice is invented during migration: existing/raw-created rows are known quantity-only
but marked UNATTRIBUTED_QUANTITY_ONLY. ORM-created items record their actual creation source.

This package mode is separate from NONE/BATCH/SERIAL traceability and AVCO/FIFO/SPECIFIC valuation.
It neither replaces those policies nor changes quantity/cost. Company owns its default; no
Inventory Action updates the company profile as part of resolution.

## Disabled advanced modes

PackageTrackingMode names QUANTITY_ONLY, PACKAGE_BALANCE and CONTAINER_TRACKED. Only
QUANTITY_ONLY is implemented operationally. Model guards refuse advanced company/category/item
choices; PostgreSQL check constraints also refuse raw inserts or updates that could enable them.
Item mode and provenance are immutable through model and raw SQL update paths in this slice.

There is no package activation switch, editable policy control or new HTTP setting/input.
Automatic item creation uses its existing request authority; the internal resolver is a
calculation, not a new governed policy-write endpoint. Future company/category maintenance
and item transitions need enforcing permissions, reason/audit and reconciliation under
ADRs 0047/0052 before relaxing the database gates. A counted checkpoint does not activate tracking.

## Verification and demo

Thirteen focused policy cases cover precedence/provenance, persistence across category/default
changes, model rejection, raw SQL activation rejection and honest import provenance.
The policy/demo/replay/architecture and Item/Category API set passed **125 tests / 421 assertions**.
A separate Item/Category model, Company API, item-unit and policy/demo set passed
**75 tests / 217 assertions**; these sets overlap and are not additive.

PackageTrackingPolicyDemoSeeder adds three non-stock demonstration items through CreateItem
and category fixtures through CreateCategory: company fallback, inherited ancestor choice and
explicit item choice. Named items appear in the normal Item list; policy metadata currently
requires developer model inspection. All remain quantity-only and do not post stock.
Module/command local-testing guards and create-once checks preserve manual edits/archives on rerun.
The additive local migration and module seeder ran successfully.

## Delivery boundary

The inactive checkpoint kernel, on-demand reconstruction and guarded creation-time policy
foundation are complete. Operational package receipt/open/unpack/issue/transfer/QA/reversal,
cutover and recalculation adapters are next. Advanced modes remain impossible to persist until
all enabled writers are integrated or guarded and activation reconciliation is proven.

## Related

- [Inventory packages](inventory-packages.md)
- [Integration and replay](package-balance-integration.md)
- [Delivery plan](../phases/package-balance-delivery.md)
- [Proposed ADR 0082](../decisions/0082-reconcile-package-balances-with-stock-operations.md)
- [Policy governance](../decisions/0047-govern-inventory-structure-and-default-valuation-policies.md)
- [Demo rules](../architecture/environments.md#development-demo-data)
