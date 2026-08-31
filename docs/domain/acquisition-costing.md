[← Domain index](README.md)

# Acquisition costing

Acquisition costing turns provisional receipt cost and later supplier/landed-cost documents into
the effective cost of purchased inventory without overwriting what was originally estimated.

## Where it sits

```
PO estimate → Goods receipt provisional cost ─┐
Product invoice + landed-cost documents ──────┴→ actual cost → adjustment → stock valuation
```

Phase 2 records provisional receipt valuation and the source identity needed for adjustment.
Phase 6 adds supplier invoices, payables, final matching and GL posting.

## Schema

The event shape below is the required contract; physical table boundaries are finalised with the
Phase 2/6 implementations.

```
acquisition_cost_components
  id, company_id
  goods_receipt_line_id
  component_type           PRODUCT | FREIGHT | DUTY | INSURANCE | HANDLING |
                           NON_RECOVERABLE_TAX | DISCOUNT | REBATE | OTHER
  basis                    ESTIMATE | ACTUAL | ADJUSTMENT
  source_type, source_id, source_line_id NULLABLE
  amount, currency_id, fx_rate, fx_rate_date, base_amount
  effective_date, recorded_at, posting_date
  replaces_estimate_id NULLABLE             -- relationship, never an overwrite

landed_cost_allocations
  id, company_id
  source_type, source_id, source_line_id
  goods_receipt_line_id
  method                   BY_QUANTITY | BY_VALUE | MANUAL
  allocated_amount, currency_id, fx_rate, base_allocated_amount
  rounding_remainder
```

## Behaviour

Acquisition cost includes purchase price, import duty, non-recoverable taxes, freight, insurance,
handling and other directly attributable costs, less trade discounts, rebates and supplier
credits. Recoverable VAT/GST is excluded from inventory cost.

When actual cost arrives, preserve all three views:

```
Estimated freight       1,000.0000
Actual freight          1,200.0000
Adjustment                200.0000
```

The effective freight is 1,200.0000, but the actual event does not update the estimate row.
Product invoices and freight/duty/insurance documents are separate cost sources even when the UI
presents them in one workflow.

V1 allocates landed cost by quantity, receipt value or explicit manual amounts. Every allocation
must reconcile exactly to its source amount. A deterministic final eligible allocation receives
the currency rounding remainder. Weight and volume allocation are deferred until item master data
reliably supplies those measures.

How a later difference affects remaining stock, consumed stock, COGS/variance and closed periods
depends on valuation method and accounting policy. That policy remains outstanding; Phase 2 must
retain source, effective and posting dates plus immutable links so AVCO, FIFO and SPECIFIC can be
handled without reconstructing history.

## Landmines

**Overwriting the estimate.** This destroys expected-versus-actual reporting and makes the
adjustment impossible to audit.

**Double counting.** An actual freight component economically supersedes its corresponding
estimate; summing both as effective cost would charge freight twice. Preserve both events while
deriving the effective component exactly once.

**Treating recoverable tax as inventory cost.** Recoverable VAT/GST is a receivable/control-account
amount, not part of the acquired inventory value.

**Allocation drift.** Independent rounding can allocate 99.9900 from a 100.0000 source. Store and
assign the deterministic remainder so source and allocations reconcile exactly.

**Calling every difference a price variance.** Purchase-price, exchange-rate, tax and landed-cost
differences have different sources and later accounting treatment; retain their identities.

## Related

- [Purchasing](purchasing.md)
- [Stock](stock.md)
- [Multi-currency](multi-currency.md) · [Tax](tax.md)
- [ADR 0032](../decisions/0032-per-item-valuation-method.md)
- [ADR 0035](../decisions/0035-separate-purchasing-operational-and-financial-facts.md)

