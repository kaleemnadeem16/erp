# Purchasing

Owns supplier sourcing and the purchase-order and goods-receipt source documents that follow it.

**Governed by:** [purchasing.md](../../../../docs/domain/purchasing.md),
[acquisition-costing.md](../../../../docs/domain/acquisition-costing.md) and
[ADR 0033](../../../../docs/decisions/0033-item-suppliers-relationship.md).

## Owns

- `ItemSupplier` — the approved supplier catalogue for an item, including preference and
  item-specific sourcing terms
- `PurchasingSetting` — typed company purchasing policy defaults
- `PurchaseOrder` and `PurchaseOrderLine` — draft commercial commitments and immutable issue
  snapshots with draft, issue, cancel and hard short-close APIs; operational cutover may create
  issued remaining commitments with immutable source provenance
- `GoodsReceipt` and `GoodsReceiptLine` — immutable posted receipt facts with partial/cumulative
  PO receipt control, pinned UoM/cost/FX/tracking identity and idempotent Inventory posting
- `QualityDisposition` — immutable, repeatable QA decisions over a receipt line's pending
  quantity, forming a terminal/non-terminal tree per ADR 0043 (no `quality_inspections` table;
  a disposition references its receipt line or an open parent disposition directly)

## Does not own

- Supplier identity and accounts — Parties owns those
- Items, warehouses, physical stock movements or valuation — Inventory owns those
- Supplier invoices and payment — Phase 6 Accounting

## Key actions

`CreateItemSupplier`, `UpdateItemSupplier` and `ArchiveItemSupplier` are the write boundary for
supplier sourcing. Purchase-order writes likewise use dedicated create, update, issue, cancel and
short-close Actions; purchasing-setting initialisation and updates also remain behind Actions.
`CreateGoodsReceipt` builds draft snapshots; `PostGoodsReceipt` performs deterministic PO-line
locking, tolerance revalidation and atomic posting through Inventory's `PostInventoryMovement`.
`PostQualityDisposition` locks the receipt line (serialising its whole disposition tree),
resolves each allocation's remaining capacity and creates the disposition; it is never updated
or deleted once posted, enforced by both a model guard and a database trigger. Purchasing may
read Parties and Inventory models directly; writes to those modules remain behind their owning
Actions. Item and supplier identity are immutable after creation; archive-and-create replaces a
relationship per ADR 0042.
`CommitInventoryCutoverOpenPurchaseOrders` is the Purchasing-owned cutover boundary: it consumes
validated `OPEN_PURCHASE_ORDER` staging rows, creates issued POs for remaining commitments only and
marks those linked batches committed. It is permissioned and idempotent; Inventory remains the
owner of cutover orchestration and finalisation.

## Authorisation

Purchasing policy management and policy-history viewing use separate permissions; cutover commit
checks `purchasing.cutover.commit`. Operational writes are split into
`purchasing.item-supplier.manage`, `purchasing.purchase-order.create` (draft create/update),
`.issue`, `.cancel`, `.short-close`, `purchasing.goods-receipt.create`, `.post`, `.reverse`, and
`purchasing.quality-disposition.post`. Document index/show require separate
`purchasing.purchase-order.view`, `purchasing.goods-receipt.view` and
`purchasing.item-supplier.view` capabilities or an action capability with a necessary read
dependency. QA disposition reads follow receipt access. The matrix is in `PurchasingReadAccess`
and [ADR 0068](../../../../docs/decisions/0068-purchasing-document-read-capabilities.md).
Purchasing settings remain an authenticated reference read; policy history has its own capability.
The SPA gates matching write controls, while consequential Actions repeat checks for non-HTTP
callers. Commercial amounts are projected separately: PO prices/totals/FX and receipt costs/FX are omitted unless the
caller holds `purchasing.commercial-cost.view` (or `purchasing.purchase-order.create`), and a receipt creator
without it has cost, currency and FX derived from the issued PO
([ADR 0069](../../../../docs/decisions/0069-separate-purchasing-commercial-visibility-from-receiving.md)). PO submission/approval uses the shared Approvals engine (ADR 0070). Before adding it, follow
[authorization.md](../../../../docs/architecture/authorization.md) and
[approval-workflows.md](../../../../docs/architecture/approval-workflows.md); create/edit,
submit, approve, issue, receive, inspect, reverse and history access must each be considered.

## Events

No events emitted yet.

## Item-context supplier maintenance

Item detail reuses ItemSupplierForm and the existing item-supplier endpoints for add/edit/archive/restore. The form fixes the item identity; update payloads exclude both identities. Reads follow existing supplier/PO lookup access and mutation controls require purchasing.item-supplier.manage; Request authorisation and tenant guards remain authoritative. No new API schema or permission registration. PackagedPurchasingDemoSeeder includes an archived trial relationship and preserves a demo user restoring it.

## Observed receipt composition

RecordReceiptComposition records draft sealed/loose physical arrival using receipt creation
authority; PostGoodsReceipt invokes Inventory's evidence adapter in the stock transaction.
Commercial package/price snapshots remain unchanged. Observations freeze after posting,
with initially held evidence for QA. No composition endpoint or screen is exposed yet.
[Contract, tests and limits](../../../../docs/domain/receipt-package-evidence.md).

## Draft receipt editing

UpdateGoodsReceipt and its Request use the existing receipt/create duty, re-resolve and lock the
company receipt, and atomically replace only draft header/lines. DraftGoodsReceiptLines is the
shared internal snapshot builder used by create/update; both Actions guard their caller.
Saved commercial values and exact amounts survive matching-line operational edits. Recorded
package composition prevents replacement. Posted corrections still use reversal.
See [the contract](../../../../docs/domain/purchasing.md#correcting-a-draft-receipt-2026-10-06).

## Default PO Quick Print

The frontend uses a dedicated modular A4 default over existing projected PO/supplier/company data.
It preserves commercial visibility and server totals, distinguishes drafts, and offers a typed
section/data boundary for later backend document templates. No new endpoint or print permission.
See [printing](../../../../docs/architecture/appearance-and-printing.md#default-purchase-order-format-2026-10-06).

## Related

- [Packaged purchasing demo](../../../../docs/journeys/packaged-purchasing-demo.md) — opt-in PackagedPurchasingDemoSeeder; creates definitions and workflow examples once, preserving receiver progress on reruns.
- [Phase 2](../../../../docs/phases/phase-2-purchasing-stock.md)
- [Inventory module](../Inventory/README.md)
- [Parties module](../Parties/README.md)
- [ADR 0042](../../../../docs/decisions/0042-immutable-item-supplier-identity.md)
- [Approval workflows](../../../../docs/architecture/approval-workflows.md)
