# Inventory

Owns everything the company buys, holds, makes, consumes or sells, and the hierarchy that
groups it.

**Governed by:** [docs/domain/items.md](../../../../docs/domain/items.md) — the domain rules
this implements. [docs/domain/stock.md](../../../../docs/domain/stock.md) governs quantities,
movement and valuation, built in Phase 2.

## Owns

- `Category` — hierarchical grouping with GL-account defaults items inherit
  ([Phase 1](../../../../docs/phases/phase-1-master-data.md))
- `Item` — everything bought, held, made, consumed or sold; one table with a `type`
  discriminator (`RAW`/`SEMI_FINISHED`/`FINISHED`/`SERVICE`/`LABOUR`/`PLANT`) rather than
  separate product/service/resource tables ([Phase 1](../../../../docs/phases/phase-1-master-data.md))
- `Warehouse`, `StockMove`, `StockLayer` — Phase 2, per
  [docs/domain/stock.md](../../../../docs/domain/stock.md)

## Does not own

- GL posting — account columns on `Item`/`Category` are references only, Accounting owns what
  posts against them
- Purchasing and sales documents that reference items — Purchasing/Sales own those, this module
  owns only what they reference

## Key actions

`CreateCategory`/`UpdateCategory`/`ArchiveCategory` and
`CreateItem`/`UpdateItem`/`ArchiveItem` own the transactional write boundary used by HTTP and
future non-HTTP entry points. Model guards still enforce tenant, hierarchy and valuation
invariants regardless of which Action invokes them.

## Events

No events emitted yet.

## Non-obvious

`Category.parent_id` self-references `categories` and is guarded on save against two things a
plain foreign key can't stop on its own: pointing at another company's row, and reparenting a
category under its own descendant (which would turn GL-account resolution into an infinite
loop). See the `guardParent()` docblock on the model.

`Item.valuation_method` resolves differently from the GL account columns: it's set once, at
creation (an explicit override, or `Category::resolveDefaultValuationMethod()`), and stored
directly rather than re-resolved on every read — a later change to the category default must not
silently move an already-created item's official cost basis. See
[ADR 0032](../../../../docs/decisions/0032-per-item-valuation-method.md).

## Related

- [domain/items.md](../../../../docs/domain/items.md)
- [domain/stock.md](../../../../docs/domain/stock.md)
- [decisions/0032-per-item-valuation-method.md](../../../../docs/decisions/0032-per-item-valuation-method.md)
- [architecture/multi-tenancy.md](../../../../docs/architecture/multi-tenancy.md)
