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
checks `purchasing.cutover.commit`. Ordinary item-supplier, PO, receipt and QA endpoints are
currently authenticated but do not yet have a complete capability permission matrix. PO approval
does not exist: DRAFT moves directly to ISSUED. Before adding it, follow
[authorization.md](../../../../docs/architecture/authorization.md) and
[approval-workflows.md](../../../../docs/architecture/approval-workflows.md); create/edit,
submit, approve, issue, receive, inspect, reverse and history access must each be considered.

## Events

No events emitted yet.

## Related

- [Phase 2](../../../../docs/phases/phase-2-purchasing-stock.md)
- [Inventory module](../Inventory/README.md)
- [Parties module](../Parties/README.md)
- [ADR 0042](../../../../docs/decisions/0042-immutable-item-supplier-identity.md)
- [Approval workflows](../../../../docs/architecture/approval-workflows.md)
