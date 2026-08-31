[← Domain index](README.md)

# Purchasing

Purchase orders and goods receipts record what a company agreed to buy and what physically
arrived. Supplier invoicing and acquisition costing are connected to that flow but remain
separate facts, per [ADR 0035](../decisions/0035-separate-purchasing-operational-and-financial-facts.md).

## Where it sits

```
                         PURCHASE ORDER
                               │
                               ▼
                         GOODS RECEIPT
                         ╱           ╲
                        ▼             ▼
                  QA inspection   Supplier invoice
                        ╲             ╱
                         ▼           ▼
                      Matching and approval
                               │
                               ▼
                      Acquisition costing
```

Receipt and inspection are Phase 2 concerns. Supplier invoices, payables, matching approval and
GL posting are implemented in Phase 6; this document fixes their integration boundary now so the
Phase 2 receipt model does not close it off.

## Schema

Annotated planning schema; precise table names may be reconciled when the module is built.

```
purchase_orders
  id, company_id, number
  supplier_account_id, supplier_site_id NULLABLE
  status                    DRAFT | ISSUED | CLOSED | CANCELLED
  currency_id, fx_rate, fx_rate_date, fx_rate_type
  order_date, expected_delivery_date NULLABLE
  issued_at NULLABLE, closed_at NULLABLE, cancelled_at NULLABLE
  cancellation_reason NULLABLE

purchase_order_lines
  id, purchase_order_id, line_number
  item_id
  item_code, description                 -- immutable snapshots on issue
  supplier_item_code NULLABLE
  ordered_qty, purchase_uom_id
  stock_qty, stock_uom_id, uom_factor    -- pinned conversion
  unit_price, discount_amount, tax_amount, line_amount
  base_unit_price, base_discount_amount, base_tax_amount, base_line_amount
  expected_delivery_date NULLABLE
  qa_required

goods_receipts
  id, company_id, number
  supplier_account_id
  status                    DRAFT | POSTED | REVERSED
  physical_arrival_at, recorded_at
  posting_date
  posted_at NULLABLE, reversed_at NULLABLE
  reversal_of_id NULLABLE, correction_reason NULLABLE

goods_receipt_lines
  id, goods_receipt_id, purchase_order_line_id NULLABLE
  item_id, location_id
  received_qty, received_uom_id
  stock_qty, stock_uom_id, uom_factor    -- pinned conversion
  provisional_unit_cost, currency_id, fx_rate, base_unit_cost
  batch_no, serial_no NULLABLE

quality_inspections
  id, company_id, goods_receipt_line_id
  status                    DRAFT | POSTED | REVERSED
  inspected_qty, inspection_uom_id, stock_qty, uom_factor
  result                    PASS | FAIL
  inspected_at, posted_at NULLABLE
  notes NULLABLE

quality_dispositions
  id, company_id, quality_inspection_id
  disposition              PASS | ACCEPT_WITH_CONCESSION | RETURN_TO_SUPPLIER |
                           REWORK | SCRAP | PENDING_DECISION
  qty, stock_qty, uom_factor
  reason, authorised_by NULLABLE, authorised_at NULLABLE
  resulting_stock_move_id NULLABLE
```

QA quantities are derived from posted inspection/disposition events and their stock movements;
there are no independently editable accepted/rejected counters on a receipt line.

Future Phase 6 records must support many-to-many line allocations rather than assuming one PO,
one receipt and one invoice:

```
supplier_invoice_line_allocations        -- Phase 6 contract, not a Phase 2 table
  supplier_invoice_line_id
  purchase_order_line_id NULLABLE
  goods_receipt_line_id NULLABLE
  allocated_qty, stock_qty, uom_factor
  allocated_amount, base_allocated_amount
```

## Behaviour

### Normal and partial purchasing

The default user journey is `Create PO → Receive → Enter invoice → Done`. One PO line may be
received or invoiced in several parts; one invoice line may cover several receipts. Outstanding
quantities are calculated from posted allocations, never trusted from a manually editable total.

A future quick/direct-purchase screen may atomically create a receipt and invoice for convenience.
It does not create a special document that lets an invoice alter stock.

### Document boundaries

- Draft documents are editable and cancellable.
- Issued POs snapshot resolved item, supplier, quantity, UoM, price, currency, FX and tax figures.
- Posted receipts and inspections are immutable. Correction uses a linked correction, reversal,
  return or adjustment selected after checking downstream dependencies.
- Only an inventory movement changes physical stock. Posting a supplier invoice never does.
- Receipt posting creates the stock move and provisional valuation atomically and idempotently.
- PO receipt progress is derived from posted quantities. A cached workflow status, if used for
  queries, is not the authoritative balance.

### Location, quality and availability

These answer different questions:

| Dimension | Question | Example |
|---|---|---|
| Physical location | Where is it? | Warehouse A / Bin 12 |
| QA state | What is its quality state? | PENDING |
| Availability | May an operation use it? | BLOCKED |

Quarantine may be a physical location for one company and only a QA/hold state for another.
Availability is derived from inspection state, holds and company policy wherever possible. A
concession is a recorded failure explicitly accepted by an authorised user; it may produce
available or restricted stock according to policy without erasing the failed result.

V1 assumes normal company-owned purchases. Supplier-, customer- and consignment-owned stock are
not modelled until a real requirement justifies that additional dimension.

### Matching contract

Three-way matching compares PO, receipt and supplier invoice. Four-way matching additionally
compares accepted inspection quantity. `qa_required` and “QA acceptance blocks payment approval”
are independent settings: inspected goods may still use three-way matching.

Matching is per line/allocation and compares item, quantity, UoM conversion, unit price, currency,
discount and tax—not document totals alone. Expected variance classes include quantity, purchase
price, UoM, tax, discount, FX, landed cost and QA acceptance.

A supplier invoice can be recorded when unmatched or outside tolerance. The financial workflow
places it on hold until it is approved, corrected, credited or rejected. Exact tolerance values,
approval authority and matching status names remain Phase 6 decisions.

### Dates and units

Physical arrival, document, inspection, recorded-at, posting, due and reversal dates are distinct
facts even when the UI initially defaults several to today.

Every quantity retains the document UoM, stock UoM and pinned conversion used when issued or
posted. Changes to current UoM master data never recalculate historical documents. Conversion
must remain within the same UoM dimension.

Every monetary row stores transaction and base-currency amounts with its pinned FX rate and rate
reference. PO, receipt, supplier invoice and landed-cost documents can legitimately use different
rates; purchase-price and FX variances must not be collapsed into one unexplained difference.

## Landmines

**Invoice quantity is not physical quantity.** An invoice for 1,100 after a receipt of 1,000
creates a quantity variance and hold, not another 100 units of stock.

**Totals can match while lines are wrong.** Opposing quantity and price errors can produce equal
document totals. Always match lines and allocated quantities.

**QA state is not a warehouse.** Moving stock to a quarantine bin may be useful, but requiring a
move solely to express “inspection pending” combines physical and quality truth.

**Blind reversal of a transaction chain.** A receipt may already be inspected, transferred,
consumed or financially matched. Validate dependencies and create explicit correcting events;
never edit the original or automatically unwind unrelated downstream facts.

**Unsettled inventory policy.** Negative-stock and backdated-receipt behaviour remain Q13/Q14 and
must be decided before the valuation engine ships.

## Related

- [Acquisition costing](acquisition-costing.md)
- [Stock](stock.md)
- [Parties](parties.md) · [Items](items.md)
- [Units of measure](units-of-measure.md) · [Multi-currency](multi-currency.md)
- [ADR 0035](../decisions/0035-separate-purchasing-operational-and-financial-facts.md)
- [Phase 2](../phases/phase-2-purchasing-stock.md)

