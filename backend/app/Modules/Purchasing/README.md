# Purchasing

Owns supplier sourcing and the purchase-order and goods-receipt source documents that follow it.

**Governed by:** [purchasing.md](../../../../docs/domain/purchasing.md),
[acquisition-costing.md](../../../../docs/domain/acquisition-costing.md) and
[ADR 0033](../../../../docs/decisions/0033-item-suppliers-relationship.md).

## Owns

- `ItemSupplier` — the approved supplier catalogue for an item, including preference and
  item-specific sourcing terms
- Purchase orders and goods receipts — later Phase 2 work

## Does not own

- Supplier identity and accounts — Parties owns those
- Items, warehouses, physical stock movements or valuation — Inventory owns those
- Supplier invoices and payment — Phase 6 Accounting

## Key actions

`CreateItemSupplier`, `UpdateItemSupplier` and `ArchiveItemSupplier` are the write boundary for
supplier sourcing. Purchasing may read Parties and Inventory models directly; writes to those
modules remain behind their owning Actions.

## Events

No events emitted yet.

## Related

- [Phase 2](../../../../docs/phases/phase-2-purchasing-stock.md)
- [Inventory module](../Inventory/README.md)
- [Parties module](../Parties/README.md)
