[← Domain index](README.md)

# Stock

Quantities on hand, their movement, and their valuation. Valuation is the part that matters most
— it feeds every formulation roll-up, every material issue, and COGS.

Valuation method is **AVCO** ([ADR 0007](../decisions/0007-avco-valuation.md)).

## Where it sits

The bottom of the costing chain — the source of ingredient cost for
[formulations.md](formulations.md). Built in [Phase 2](../phases/README.md).

## Schema

```
warehouses
  id, company_id, code, name, is_default

locations
  id, warehouse_id, code, name          -- bins/zones; a warehouse has at least one

stock_moves
  id, company_id
  item_id, from_location_id NULLABLE, to_location_id NULLABLE
  qty, uom_id
  moved_at
  source_type, source_id                -- polymorphic: GRN, issue, adjustment, transfer
  unit_cost, currency_id, fx_rate       -- cost at the time of the move
  -- NULL from_location = receipt from outside; NULL to_location = issue/consumption

stock_layers                            -- one per goods receipt; kept for traceability
  id, company_id
  item_id, location_id
  qty_received, qty_remaining
  unit_cost, currency_id, fx_rate, base_unit_cost
  received_at
  source_type, source_id

item_average_costs                      -- materialised AVCO
  item_id, company_id
  qty_on_hand, avg_unit_cost, base_avg_unit_cost
  updated_at

stock_valuation_entries                 -- cost impact of each move; feeds the GL
  id, stock_move_id
  qty, unit_cost, amount, base_amount
  account_id
```

## Behaviour

### AVCO

On receipt:

```
new_avg = ((qty_on_hand × avg_unit_cost) + (received_qty × received_unit_cost))
          ÷ (qty_on_hand + received_qty)
```

Issues are costed at the current average. The recalculation must be **transactional and
concurrency-safe** — two simultaneous receipts computing from the same starting average
produce a silently wrong cost.

Layers are still written on receipt. AVCO does not require them, but they give traceability
("what did we pay in March?") and keep a future move to FIFO possible.

### Move types

| From | To | Meaning |
|---|---|---|
| NULL | location | Goods receipt |
| location | NULL | Issue to a work order, or write-off |
| location | location | Transfer |
| location | location | Adjustment after a physical count |

One table, one polymorphic source reference. Every quantity change in the system is a
`stock_move` — there is no other way to alter stock.

### GL posting

See [general-ledger.md](general-ledger.md). Receipt debits Inventory; issue to a job debits WIP.

## Landmines

**Negative stock.** Decide the policy explicitly: block, warn, or allow. Allowing it makes AVCO
undefined (you cannot average against a negative quantity) and produces cost figures that look
real. Recommendation: **block by default, configurable per company.**

**Recalculating average under concurrency.** Two receipts processed simultaneously will both
read the pre-update average. Requires row locking, and a test that actually exercises it.

**Backdated receipts.** A receipt entered with an earlier date than existing issues means those
issues were costed at an average that has since changed. Either recalculate forward (expensive,
correct) or block backdating past the last issue (simple). **Decide before Phase 2 ships** —
retrofitting recalculation over history is unpleasant.

**Purchase UoM vs stock UoM.** Bought in 25 kg drums, consumed in grams. Convert on receipt and
store in the item's stock UoM; keep the purchase UoM on the purchase line for reference.

**Stock value drifting from the GL.** The inventory account balance and the sum of stock
valuation must agree. Build the reconciliation report early — discovering a discrepancy a year
in is far worse than catching it in week one.

## Related

- [items.md](items.md) — what is stocked
- [formulations.md](formulations.md) — consumes stock cost
- [general-ledger.md](general-ledger.md) — where valuation posts
- [ADR 0007](../decisions/0007-avco-valuation.md)
