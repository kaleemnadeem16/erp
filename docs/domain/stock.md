[← Domain index](README.md)

# Stock

Quantities on hand, their movement, and their valuation. Valuation is the part that matters most
— it feeds every formulation roll-up, every material issue, and COGS.

Valuation method is chosen **per item**, not one method for the whole company — AVCO, FIFO or
SPECIFIC, defaulted from the item's category — see [items.md](items.md#valuation-method) and
[ADR 0032](../decisions/0032-per-item-valuation-method.md) (supersedes the original single-method
[ADR 0007](../decisions/0007-avco-valuation.md)). The schema below is written so that whichever
method is official for an item, the history needed to replay it under a *different* method
analytically is still captured — see "Historical retention for replay" below.

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
  source_type, source_id                -- polymorphic: GRN, issue, adjustment, transfer, return
  unit_cost, currency_id, fx_rate       -- cost at the time of the move
  batch_no, serial_no      NULLABLE     -- captured whenever items.tracking says so; see items.md
  -- NULL from_location = receipt from outside; NULL to_location = issue/consumption

stock_layers                            -- one per goods receipt; the FIFO consumption unit,
                                         -- and AVCO's traceability record
  id, company_id
  item_id, location_id
  qty_received, qty_remaining
  unit_cost, currency_id, fx_rate, base_unit_cost
  received_at
  source_type, source_id
  batch_no, serial_no      NULLABLE     -- as above

item_average_costs                      -- materialised AVCO; only meaningful for
                                         -- AVCO-official items — see items.md#valuation-method
  item_id, company_id
  qty_on_hand, avg_unit_cost, base_avg_unit_cost
  updated_at

stock_valuation_entries                 -- cost impact of each move; feeds the GL
  id, stock_move_id
  qty, unit_cost, amount, base_amount
  account_id
```

## Behaviour

### Valuation by method

Per-item, not one method for the whole company — [items.md](items.md#valuation-method),
[ADR 0032](../decisions/0032-per-item-valuation-method.md). Each item's `valuation_method` picks
which of these applies; none of this is built yet beyond AVCO's original design (Phase 2).

**AVCO.** On receipt:

```
new_avg = ((qty_on_hand × avg_unit_cost) + (received_qty × received_unit_cost))
          ÷ (qty_on_hand + received_qty)
```

Issues are costed at the current average, materialised in `item_average_costs`. The
recalculation must be **transactional and concurrency-safe** — two simultaneous receipts
computing from the same starting average produce a silently wrong cost.

**FIFO.** No materialised running cost — `stock_layers.qty_remaining` *is* the state. An issue
consumes the oldest layer(s) with `qty_remaining > 0` first, costed at each consumed layer's
`unit_cost`, decrementing `qty_remaining` (partial-layer consumption when the issue exceeds one
layer's remaining quantity). Real layer-consumption logic, not a small variation on AVCO.

**SPECIFIC.** One `stock_layers` row per exact unit — the issue must name which specific
unit/serial it consumes, costed at exactly that unit's `unit_cost`. Only meaningful for
`SERIAL`-tracked items where identity was genuinely captured at receipt; see "Historical
retention for replay" below.

`stock_layers` is written on every receipt regardless of the item's official method — under
AVCO it exists purely for traceability, under FIFO/SPECIFIC it's the data the costing logic
actually consumes.

### Move types

| From | To | Meaning |
|---|---|---|
| NULL | location | Goods receipt (including a customer return) |
| location | NULL | Issue to a work order, write-off, or a return to supplier |
| location | location | Transfer |
| location | location | Adjustment after a physical count |

Returns aren't a third physical direction — a customer return is shaped like a receipt, a
return to supplier like an issue. What distinguishes a return from an ordinary receipt/issue is
`source_type`, not `from`/`to`.

One table, one polymorphic source reference. Every quantity change in the system is a
`stock_move` — there is no other way to alter stock.

### Historical retention for replay

The schema above retains enough to replay history under a *different* method than an item's
official one, analytically — e.g. an AVCO-official item gets a "what would FIFO margin have
looked like" report from the same `stock_layers`/`stock_moves` rows, without re-running receipts.

Rules, per [ADR 0032](../decisions/0032-per-item-valuation-method.md):

- Analytical replay reads retained history only. It never writes a GL entry, alters posted
  COGS, changes an inventory balance, or changes `items.valuation_method`.
- A `SPECIFIC`-basis replay is only possible where the exact serial/unit identity was actually
  captured at the time (`batch_no`/`serial_no` on the relevant `stock_layers` row). Estimating it
  after the fact when it wasn't captured is not acceptable — the report should say "not
  available," not guess.
- Not building the replay engine now — this is Phase 2/3 work, done when the costing phase
  actually needs it. What matters today is that no schema decision closes the door on it later.

### GL posting

See [general-ledger.md](general-ledger.md). Receipt debits Inventory; issue to a job debits WIP.
Whichever method is official for the item drives what the debit/credit amounts actually are.

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

**Changing an item's `valuation_method` after it has stock moves.** Must be locked once any
`stock_move` references the item — see [items.md](items.md#valuation-method) and
[ADR 0032](../decisions/0032-per-item-valuation-method.md). This is the phase where that lock
first has something real to check against; it isn't enforceable before `stock_moves` exists, and
must not be forgotten once it does.

## Related

- [items.md](items.md) — what is stocked, and where `valuation_method` is chosen
- [formulations.md](formulations.md) — consumes stock cost
- [general-ledger.md](general-ledger.md) — where valuation posts
- [ADR 0032](../decisions/0032-per-item-valuation-method.md) — per-item valuation method
- [ADR 0007](../decisions/0007-avco-valuation.md) — superseded by 0032, original AVCO reasoning
