[← Decisions](README.md)

# 0033 — `item_suppliers` relationship, not a single `default_supplier_id`

- **Status:** accepted
- **Date:** 2026-08-20
- **Affects:** Phase 2 (Purchasing — table itself is built there, not in Phase 1),
  [items.md](../domain/items.md), [parties.md](../domain/parties.md)

## Context

`items.default_supplier_id` ([items.md](../domain/items.md)) is a single FK, shipped in Phase 1
as a data-entry convenience — it pre-fills a purchase order, it never restricted which supplier
an item could actually be bought from. Real sourcing is rarely single-supplier: an item commonly
has one preferred supplier and one or more approved alternatives, each potentially quoting a
different supplier-side item code, lead time, minimum order quantity and purchase unit of
measure for the same item. None of that has anywhere to live today. Logged as
[Q12](../OPEN-QUESTIONS.md#q12--multiple-suppliers-per-item-approved-vendor-list--sourcing).

## Decision

Add `item_suppliers`, a join between `items` and `supplier_accounts`, built in **Phase 2** (no
`purchasing.md` domain doc exists yet — this decision fixes the shape now so it isn't designed as
an afterthought once that doc is written):

```
item_suppliers
  id
  item_id                → items
  supplier_account_id    → supplier_accounts
  supplier_item_code     NULLABLE   -- the supplier's own SKU for this item, not ours
  priority                NULLABLE  -- 1 = preferred/default; others are valid alternatives
  lead_time_days         NULLABLE   -- overrides supplier_accounts.lead_time_days for this item
  minimum_order_qty      NULLABLE
  purchase_uom_id        NULLABLE   → uoms
  active                 bool

  unique(['item_id', 'supplier_account_id'])
```

**Rules:**

- An item may have one or many active `item_suppliers` rows. `priority = 1` is the
  preferred/default; the rest are valid alternatives a purchase order is free to pick instead —
  nothing about this table restricts a PO's supplier choice, same as `default_supplier_id` never
  did.
- Fields that genuinely vary per (item, supplier) pair — the supplier's own code, lead time,
  minimum order quantity, purchase UoM — live on this join row, not on `items` and not on
  `supplier_accounts`. Same discipline [parties.md](../domain/parties.md#where-a-field-belongs)
  already applies to role accounts: a value that differs by relationship belongs on the row that
  represents the relationship.
- `item_suppliers.lead_time_days` is **not** a duplicate of `supplier_accounts.lead_time_days`
  ([parties.md](../domain/parties.md)) — the latter is that supplier's general default; this
  overrides it for one specific item when set, and falls through to the supplier's general
  default otherwise (same override-then-fall-through shape as
  [ADR 0032](0032-per-item-valuation-method.md)'s category defaults).

**Pricing is deliberately not modelled on this table.** The negotiated purchase price belongs on
the PO line — transactional, becomes purchase history the moment the PO is placed, per
[items.md](../domain/items.md) and how [Q12](../OPEN-QUESTIONS.md) itself was raised. No `price`
column on `item_suppliers`. If standing vendor price lists, quantity breaks, per-currency
pricing, or validity periods turn out to be genuinely required, the shape would be a separate
table:

```
supplier_item_prices        -- NOT decided, NOT being built now — shape only, for reference
  item_supplier_id
  min_qty
  unit_price
  currency_id
  valid_from, valid_to
```

This stays optional and unbuilt until Phase 2's actual purchasing requirements confirm it's
needed — not built speculatively alongside `item_suppliers`.

**Fate of `items.default_supplier_id`.** It shipped in Phase 1 before this decision existed and
stays as-is for now — nothing yet replaces it. Once `item_suppliers` is built in Phase 2, it
becomes redundant with "the `item_suppliers` row with `priority = 1`" and should be deprecated
and removed then, with any set values migrated into an `item_suppliers` row. Not resolved in this
ADR because the replacement doesn't exist yet; recorded here so it isn't forgotten.

## Consequences

- **Positive:** Matches how sourcing actually works — price comparison, availability risk,
  minimum-order economics all depend on being able to name more than one real supplier per item.
- **Positive:** Keeps pricing out of master data. `item_suppliers` describes a *relationship*
  (who can we buy this from, on what terms), not a *quote* (what will they charge this month) —
  the same distinction that keeps GL account defaults on `categories`/`items` instead of
  hardcoded, and keeps historical FX rates in `exchange_rates` instead of on `currencies`.
- **Negative:** One more table and join for anything that reads "who supplies this item" — a
  single-supplier item still needs one `item_suppliers` row rather than a plain column read.
  Accepted; the alternative (a single column) can't express the real cases above at all.
- **Requires:** `unique(['item_id', 'supplier_account_id'])` — an item can't list the same
  supplier twice. `BelongsToCompany` isn't directly applicable (no `company_id` column of its
  own); it scopes through both `item_id` and `supplier_account_id` already being company-scoped,
  same shape as [multi-tenancy.md](../architecture/multi-tenancy.md#child-tables-scope-through-their-parent)'s
  child-table pattern — the actual trait choice is a Phase 2 implementation decision, not fixed
  here.

## Alternatives considered

**Keep only `default_supplier_id`, rely on purchase-order history for "who else have we bought
this from"** — rejected. Doesn't support an *approved* alternative supplier the business hasn't
purchased from yet, doesn't hold supplier-specific SKU/lead time/MOQ/UoM anywhere, and answers
"who has supplied this" rather than "who is allowed to."

**Build `item_suppliers` now, in Phase 1** — rejected. It joins to `supplier_accounts`, which
doesn't exist yet either (deferred pending `chart_of_accounts`, per
[phase-1-master-data.md](../phases/phase-1-master-data.md)'s task order), and there's no
Purchasing module or `purchasing.md` doc to own it. Fixing the *shape* now, building the table in
Phase 2, mirrors how [ADR 0032](0032-per-item-valuation-method.md) fixed the stock-history schema
without building the Phase 2 costing engine.

**A `price` column on `item_suppliers` now** — rejected as overbuilding ahead of confirmed need.
`supplier_item_prices` is named above only so the eventual shape isn't designed from scratch
later; it is not committed to being built.

## Related

- [Q12](../OPEN-QUESTIONS.md#q12--multiple-suppliers-per-item-approved-vendor-list--sourcing) — closed by this ADR
- [items.md](../domain/items.md) — `default_supplier_id`'s Phase 1 role and its Phase 2 fate
- [parties.md](../domain/parties.md) — `supplier_accounts.lead_time_days`, "Where a field belongs"
- [ADR 0032](0032-per-item-valuation-method.md) — the override-then-fall-through shape this reuses
- [ADR 0030](0030-unified-party-model.md) — the role-account field-placement discipline this follows
