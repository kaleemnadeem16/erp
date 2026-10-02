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

The purchase-order/settings, goods-receipt and QA-disposition portions below are all
implemented.

```
purchasing_settings
  company_id                             -- one typed settings row per company
  over_receipt_mode                      -- BLOCK | ALLOW_WITHIN_TOLERANCE
  over_receipt_tolerance_percent
  purchase_order_required

purchase_orders
  id, company_id, number
  supplier_account_id, supplier_site_id NULLABLE
  status                    DRAFT | ISSUED | CLOSED | CANCELLED
  currency_id, fx_rate, fx_rate_date, fx_rate_type
  fx_policy                              -- FLOATING | FIXED, snapshotted on issue
  order_date, expected_delivery_date NULLABLE
  notes NULLABLE                          -- order-level remarks, not any one line's own description
  issued_at NULLABLE, closed_at NULLABLE, cancelled_at NULLABLE
  cancellation_reason NULLABLE
  inventory_cutover_id, import_batch_id NULLABLE  -- both set for an imported open PO
  source_system, source_record_id NULLABLE         -- immutable predecessor identity

purchase_order_lines
  id, company_id, purchase_order_id, line_number
  item_id
  item_code, description                 -- immutable snapshots on issue
  supplier_item_code NULLABLE
  ordered_qty, purchase_uom_id
  stock_qty, stock_uom_id, uom_factor    -- pinned conversion
  unit_price, discount_amount, tax_amount, line_amount
  base_unit_price, base_discount_amount, base_tax_amount, base_line_amount
  expected_delivery_date NULLABLE
  qa_required
  over_receipt_mode, over_receipt_tolerance_percent  -- resolved issue snapshots
  short_closed_stock_qty
  import_batch_row_id, source_record_id NULLABLE  -- immutable imported-line provenance

goods_receipts
  id, company_id, number
  supplier_account_id
  status                    DRAFT | POSTED | REVERSED
  physical_arrival_at, recorded_at
  posting_date
  posted_at NULLABLE, reversed_at NULLABLE, reversed_by NULLABLE
  reversal_of_id NULLABLE, correction_reason NULLABLE
  idempotency_key NULLABLE

goods_receipt_lines
  id, goods_receipt_id, purchase_order_line_id NULLABLE
  item_id, location_id
  received_qty, received_uom_id
  stock_qty, stock_uom_id, uom_factor    -- pinned conversion
  provisional_unit_cost, currency_id, fx_rate, base_unit_cost
  fx_rate_date, fx_rate_type, fx_rate_source
  batch_no, serial_no NULLABLE

quality_dispositions
  id, company_id, goods_receipt_line_id
  source_disposition_id NULLABLE   -- null = allocates from the line's own pending
                                    -- quantity; set = allocates from that (non-terminal)
                                    -- disposition's own remaining balance
  disposition              PASS | ACCEPT_WITH_CONCESSION | RETURN_TO_SUPPLIER |
                           REWORK | SCRAP | PENDING_DECISION
  qty                       -- in the receipt line's own stock UoM; identity (batch/
                             -- serial) is inherited from the line, not stored again here
  reason
  disposed_by, disposed_at
  resulting_stock_move_id NULLABLE  -- always null until the transfer/issue movement
                                     -- boundary exists (see Behaviour below)
  idempotency_key NULLABLE
```

A `quality_inspection_id` column is not implemented: ADR 0043 allows a disposition to
reference "the receipt line directly or through its inspection," and nothing in Phase 2's scope
requires a separate, formally-posted inspection record ahead of the disposition decision — the
disposition enum's own `PASS` outcome already covers the simple case. A future formal-inspection
requirement can add that column without reshaping this table.

`purchase_order_lines` deliberately carries `company_id` even though it belongs to a parent
document. Procurement and reorder reporting queries lines independently across many POs, and the
column permits PostgreSQL composite foreign keys that prevent cross-company item/UoM references.

QA quantities are derived from posted disposition events and their stock movements;
there are no independently editable accepted/rejected counters on a receipt line.

An operational or full-history inventory cutover may stage one `OPEN_PURCHASE_ORDER` row per
still-open predecessor PO line. Purchasing—not the generic importer—groups rows by immutable
source-order identity, rejects inconsistent header or duplicate line facts, and creates an issued
target PO containing the remaining commitment only. It does not recreate historical receipts or
liabilities. The PO retains cutover, import-batch, source-system and source-order identity; each
line retains its import-row and source-line identity. Database constraints make that provenance
tenant-safe, unique and immutable. Retrying the commit returns the already-created orders rather
than duplicating them.

Draft Goods Receipts are created from issued PO lines. Posting serialises on the receipt and its
PO lines in deterministic order, re-derives cumulative effective receipt quantity, then calls
Inventory's sole movement boundary for every line. AVCO, FIFO and SPECIFIC receipts all create a
move, receipt layer and valuation-feed entry; AVCO additionally updates its locked pool. Posting
the same receipt again resolves the existing result without duplicating quantity or value.

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

### Supplier sourcing identity

An `item_suppliers` row has immutable `item_id` and `supplier_account_id`, per
[ADR 0042](../decisions/0042-immutable-item-supplier-identity.md). Its sourcing terms may change,
but moving the row to a different item or supplier would change its meaning. Archive the old
relationship and create a new one instead; issued purchase documents retain their own snapshots.

The React item-supplier screens enforce the same boundary in their interaction design: item and
supplier are selected on creation and shown read-only on edit, while supplier item code,
priority, lead-time override, minimum order quantity, purchase UoM and active state remain
editable. List filters cover item, supplier, active state and preferred status.

### Purchase unit default

A purchase order line's **Purchase UoM** starts on the unit the PO's supplier sells that item in — the
active `item_suppliers` row's `purchase_uom_id` — and on the item's stock unit when the supplier has no
such row or the row names no unit. It is a default applied when the line's item is chosen, nothing more:
the unit stays editable, changing the supplier afterwards does not rewrite lines already entered (a line
of 10 rolls silently turning into 10 kg would change the stock quantity ordered), and the conversion to
stock is still made by `BuildPurchaseOrderLineAttributes` from the unit the line ends up with. The form
reads the supplier's first 100 active rows; an item beyond that simply starts on its stock unit.

### Normal and partial purchasing

The default user journey is `Create PO → Receive → Enter invoice → Done`. One PO line may be
received or invoiced in several parts; one invoice line may cover several receipts. Outstanding
quantities are calculated from posted allocations, never trusted from a manually editable total.

Original ordered quantity is immutable. A line can be explicitly short-closed; its remaining
quantity then becomes zero and later receipt is blocked unless a future audited reopen/revision
action makes it receivable again. V1 over-receipt is `BLOCK` or `ALLOW_WITHIN_TOLERANCE`, resolved
from PO-line override then company default and snapshotted on issue. It is checked cumulatively in
pinned stock UoM; today the tolerance ceiling is floored whenever the stock UoM's *dimension* is
`COUNT` — an ad hoc stand-in, not a real divisibility rule.
[ADR 0050](../decisions/0050-item-specific-uom-conversion-and-divisibility.md) decided the real
rule (an explicit, item-and-UoM rounding precision, not a dimension inference) but it is not yet
implemented. Above-tolerance approval is deferred.

A future quick/direct-purchase screen may atomically create a receipt and invoice for convenience.
It does not create a special document that lets an invoice alter stock.

The V1 PO API supports validated list/show, nested draft create/update, issue, cancellation and
line short-close endpoints. Issue allocates the gapless number and freezes item/supplier policy,
quantity/UoM, monetary and FX snapshots. Company purchasing defaults use one typed settings row;
reading that singleton initialises its database defaults through an Action when necessary.

Purchase-order approval is **optional and company-configured** through the shared approval engine
([ADR 0070](../decisions/0070-shared-configurable-approval-engine.md), [approvals.md](approvals.md)). The order keeps its
`DRAFT`/`ISSUED` status; approval lives on a request beside it. With the company's approval mode for `PURCHASE_ORDER` at its
default (`NONE`) nothing changes. Otherwise a buyer with `purchasing.purchase-order.submit` submits the draft
(`POST /purchase-orders/{id}/submit`); it is routed by its total in base currency (at the order's own pinned rate) and the
matching policy's stages and steps; the order is frozen while the request is pending (withdraw to edit); approvers hold
`purchasing.purchase-order.approve` and, where a step uses limits, an amount ceiling; issuing asks the engine and is refused
until the order is approved *as it now is* — any edit or cancel voids an earlier approval. Migrated cutover orders are not asked
again. The order's `approval` block reports the state. Draft create/update, issue, cancel and
short-close have separate `purchasing.purchase-order.*` capabilities; receipt create/post/reverse,
QA disposition posting and item-supplier maintenance likewise have distinct Purchasing-owned
permissions. Per [ADR 0068](../decisions/0068-purchasing-document-read-capabilities.md), PO,
receipt (including QA disposition) and item-supplier list/detail reads now require their
respective `*.view` capability or an action capability whose workflow needs the read. Receiving
and QA actions can read POs; PO creation can read item-supplier sourcing terms. Finance and other
read-only users need explicit view grants rather than write power. These remain company-wide reads
pending Q26 record scope. Commercial amounts (PO prices, discount/tax/line and header totals, FX evidence; receipt provisional/base unit
cost and FX evidence) are omitted from every projection, print view and monetary sort unless the caller holds
`purchasing.commercial-cost.view` (or `purchasing.purchase-order.create`, which needs them to draft a priced PO)
— [ADR 0069](../decisions/0069-separate-purchasing-commercial-visibility-from-receiving.md). A receipt creator
without that capability submits quantity, unit, location and tracking identity only; the server derives the
provisional cost, currency and FX evidence from the issued PO line and rejects any monetary override. That is a
provisional valuation basis, not invoice matching or landed cost. Which orders need which approvals
([Q28](../OPEN-QUESTIONS.md#q28--purchase-order-approval-policy): amounts, currencies, suppliers) is the company's own policy
content, configured on Settings → Approvals; the [approval-workflow boundary](../architecture/approval-workflows.md) still
applies. Creating/editing, submitting, approving and issuing are independently grantable powers; approval eligibility is never
inferred from access to the PO screen.

### User interface

The Phase 2 operational purchasing UI is being delivered as one continuous PO→receipt→QA journey
under [user-interface.md](../architecture/user-interface.md). The first slice is the Purchase
Orders list at `/purchase-orders`: server-paginated, URL-filtered by search, supplier and status,
responsive through the shared table/card foundation, and linked from application navigation. The
create screen provides repeatable lines, supplier/default-currency resolution, item-driven
description/UoM defaults, explicit FX evidence and QA selection. Once a supplier is chosen, an
optional site picker offers that supplier's own order-from/ship-from addresses (`GET
/api/v1/supplier-accounts/{id}/sites`), with an inline "add a site" dialog (`POST .../sites`) for
a first-time address — see [parties.md](parties.md#shared-sites). The detail screen and Quick
Print both show the resolved site's address once one is set. The status-gated detail workspace
shows immutable issued facts and confirms issue, cancellation and short-close consequences before
calling their named actions. Drafts can be reopened in the same repeatable-line form. The detail
response computes exact transaction/base totals with decimal arithmetic and derives received stock
quantity from posted receipts only, so a reversed or still-draft receipt never inflates operational
progress. Outstanding stock quantity nets out posted receipts *and* quantity already held by any
other still-unposted draft against the same line — not just this order's own progress, but what
remains free to claim at all — so a second draft can't be opened against quantity a first,
unposted one already claims; the screen pairs these with currency and UoM reference labels.

The Goods Receipts list at `/goods-receipts` is server-paginated and URL-filtered by supplier and
status. Draft entry begins with the supplier and offers only that supplier's issued PO lines with
a remaining outstanding balance — ordered, less posted receipts and quantity already held by other
drafts; the issued PO snapshot supplies item, currency, FX and
provisional-cost defaults while the receiver records physical time, posting date, put-away
location, actual quantity/UoM and required batch or serial identity. The detail workspace confirms
the stock consequences before posting and requires a reason before reversal. Posted
inspection-required lines expose unallocated and unresolved quantities beside their immutable
disposition history; root decisions and follow-up resolution of open `REWORK` or
`PENDING_DECISION` branches are posted inline without calculating QA balances in React.

### Document boundaries

- Draft documents are editable and cancellable.
- Issued POs snapshot resolved item, supplier, quantity, UoM, price, currency, FX and tax figures.
- Posted receipts and quality dispositions are immutable. Correction uses a linked correction, reversal,
  return or adjustment selected after checking downstream dependencies.
- Only an inventory movement changes physical stock. Posting a supplier invoice never does.
- Receipt posting creates the stock move and provisional valuation atomically and idempotently.
- PO receipt progress is derived from posted quantities. A cached workflow status, if used for
  queries, is not the authoritative balance.
- Posting locks the draft receipt and referenced PO lines in deterministic ID order, revalidates
  all cumulative quantities and creates movement/valuation effects in one idempotent transaction.
- Receipt reversal corrects an erroneous posting; supplier return records a later real outbound
  event. Neither mutates the original and a return does not automatically reopen the PO. For
  reversal, "does not mutate" means posted quantity, cost and line facts stay untouched — the
  same original `goods_receipts` row transitions `status` to `REVERSED` and records
  `reversed_at`/`reversed_by`/`correction_reason` on itself, exactly like a PO's own `status`
  transitions after issue. `reversal_of_id` is not populated by reversal itself; it is reserved
  for a separate, later feature that links an ordinary replacement receipt back to the one it
  corrects.

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

Inspection-required quantity starts QA-pending. Each partial or final decision is a separately
posted QA Disposition referencing the receipt line directly (a root disposition) or a still-open
`REWORK`/`PENDING_DECISION` disposition as its source, plus quantity, actor, reason and any
generated movement. `PASS`, `ACCEPT_WITH_CONCESSION`, `RETURN_TO_SUPPLIER` and `SCRAP` are
terminal — nothing may be posted against them as a source — while `REWORK` and `PENDING_DECISION`
stay open, carrying their own remaining balance for a later disposition to resolve. Multiple
dispositions may consume the pending quantity over time; concurrent allocation of the same
quantity is locked out by serialising on the receipt line. The posted receipt itself is never
updated with later QA outcomes.

Terminal and *available* are different questions. Of the four terminal outcomes, only `PASS` and
`ACCEPT_WITH_CONCESSION` release quantity to available stock; `SCRAP` and `RETURN_TO_SUPPLIER` are
just as terminal (the QA decision is final) but the quantity must never be treated as available —
it is destined for destruction or return. See the landmine below.

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

`purchase_orders.expected_delivery_date` is the common supplier promise and the useful date for
order lists, summaries and an order whose lines arrive together. A line date is still required by
the model because staggered deliveries are normal; in entry it is an optional override. On save,
a blank line date inherits the header date and the resolved value is stored on the line, so issued
planning snapshots never depend on later reinterpretation of a header default.

Every quantity retains the document UoM, stock UoM and pinned conversion used when issued or
posted. Changes to current UoM master data never recalculate historical documents.
`BuildPurchaseOrderLineAttributes` resolves an item-specific `item_quantity_units` override first
— which may cross UoM dimension for that one item (a box of eaches, a roll of metres) — falling
back to the global, dimension-locked `UomConversionService` only when no override exists, per
[ADR 0050](../decisions/0050-item-specific-uom-conversion-and-divisibility.md). A genuine pack
purchase UoM is now actually usable, closing the gap that left `item_suppliers.purchase_uom_id`
(ADR 0033) shape-only since Phase 2 shipped.

Every monetary row stores transaction and base-currency amounts with its pinned FX rate and rate
reference. PO, receipt, supplier invoice and landed-cost documents can legitimately use different
rates; purchase-price and FX variances must not be collapsed into one unexplained difference.
For a PO line, `base_line_amount` is the rounded transaction `line_amount` converted at the pinned
FX rate. Individually converted base unit-price, discount and tax fields are informative components;
their separately rounded values are not used to reconstruct the authoritative base line total.
The PO snapshots a `FLOATING` or `FIXED` FX policy as well as its rate evidence. Receipt currency,
provisional cost and FX evidence live on each line. V1 accepts multiple PO-backed lines on one
receipt only for one supplier and one transaction currency.

Tracked inventory requires batch/serial identity before V1 receipt posting. PO-less receipt is a
controlled future exception requiring explicit provisional cost/currency/FX; it is not an opening
stock path. Physical goods use receipt, while service/non-stock lines remain compatible with a
future acceptance or direct-invoice workflow.

### Search and filtering

Purchase orders provide multi-attribute search and structured filters:
- **Global search (`filter[search]`)**: Matches across PO number, legacy cutover/import source
  reference (`source_record_id`), supplier account code and party name, line item code and
  description, and line supplier SKU (`supplier_item_code`). Order-level notes are excluded to
  ensure search results remain predictable.
- **Advanced filters**:
  - Date ranges: `filter[order_date_from]`, `filter[order_date_to]`, `filter[expected_delivery_from]`,
    `filter[expected_delivery_to]`, `filter[issued_from]`, `filter[issued_to]`.
  - Relationships: `filter[item_id]` (orders containing line item), `filter[supplier_account_id]`,
    `filter[supplier_site_id]`, `filter[currency_id]`.
  - Lifecycle: `filter[status]` (`DRAFT`, `ISSUED`, `CLOSED`, `CANCELLED`).
  - Commercial value: `filter[total_min]`, `filter[total_max]` (gated by
    `purchasing.commercial-cost.view`).


## Landmines

**Invoice quantity is not physical quantity.** An invoice for 1,100 after a receipt of 1,000
creates a quantity variance and hold, not another 100 units of stock.

**Totals can match while lines are wrong.** Opposing quantity and price errors can produce equal
document totals. Always match lines and allocated quantities.

**QA state is not a warehouse.** Moving stock to a quarantine bin may be useful, but requiring a
move solely to express “inspection pending” combines physical and quality truth.

**QA-pending stock is not available stock, and terminal doesn't mean available.** Physical receipt
already posts full quantity on hand regardless of `qa_required`, but the outbound issue boundary
excludes from availability quantity that has never had a disposition posted against it, open
`REWORK`/`PENDING_DECISION` quantity, **and** terminal `SCRAP`/`RETURN_TO_SUPPLIER` quantity —
only quantity covered by a `PASS` or `ACCEPT_WITH_CONCESSION` disposition may be issued. The
boundary derives this by tracing a stock layer/move's `source_id` back to its receipt line and
checking its dispositions; `StockIdentity` and the movement tests cover the rejection paths.

**Blind reversal of a transaction chain.** A receipt may already be inspected, transferred,
consumed or financially matched. Validate dependencies and create explicit correcting events;
never edit the original or automatically unwind unrelated downstream facts.

**Inventory timing policy.** Negative stock is governed by
[ADR 0037](../decisions/0037-controlled-negative-stock-overrides.md); backdated receipts and
corrections are governed by [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md).

## Packaged/loose purchasing (ADR 0080)
 
Supplier offers are child records of item_suppliers, not replacement relationships. An offer
names a fixed package or loose unit, supplier SKU, default, MOQ/multiple, lead time, validity
and price/currency with an independent price unit and denominator. Minimums/multiples use the
line order basis; violating them requires a recorded reason, without rounding the quantity.
Price tiers and configurable escalation remain deferred.
 
PO draft lines snapshot package identity/content, order label, offer terms/SKU, price basis,
priced quantity and exception evidence. Issuing preserves that resolution; offer/master edits
apply to new drafts only. Receiving the agreed package reuses issued content, supports partial
receipts and records package/count alongside stock quantity. Alternate loose receipt units
normalise gross provisional price; exact provisional amounts feed the existing stock engine.
Discount/tax acquisition allocation remains the separate acquisition-costing capability.
Replacement linkage requires a cancelled or short-closed previous commitment.
 
Package writes use inventory.item.manage. Offer writes require purchasing.item-supplier.manage
and existing commercial access; offer reads use supplier read/manage or PO-create capability,
with prices projected only to commercial viewers. Both maintenance Actions repeat checks.
The line-preview endpoint is read-only, uses PO-create access and the same conversion/price
resolver; receipt/issue/post permissions remain unchanged. No new permission vocabulary or
role assignment is required.
 
Validation: 64 focused Purchasing tests pass, including equal-content identities, independent
pricing, partial receipts, changed-offer history, quantity increments and warning/reason handling.
 
## Related

- [Material resolution](material-resolution.md) — Phase 3 reports internal-stock shortages;
  Purchasing owns any later supplier replenishment
- [Inventory packages](inventory-packages.md) — receipts create enabled package/container state,
  while the stock ledger remains authoritative
- [ADR 0051](../decisions/0051-phase-3-commercial-packaging-and-supplier-cost-scenarios.md)
  — keeps internal-inventory proposals separate from supplier sourcing
- [Approval workflows](../architecture/approval-workflows.md) — maker/checker and multi-stage PO
  approval boundary
- [Acquisition costing](acquisition-costing.md)
- [Stock](stock.md)
- [Parties](parties.md) · [Items](items.md)
- [Units of measure](units-of-measure.md) · [Multi-currency](multi-currency.md)
- [ADR 0035](../decisions/0035-separate-purchasing-operational-and-financial-facts.md)
- [ADR 0043](../decisions/0043-control-purchase-order-receipt-and-qa-posting.md)
- [ADR 0050](../decisions/0050-item-specific-uom-conversion-and-divisibility.md) — item-specific
  purchase-UoM conversion, implemented via `ItemUomConversionService`
- [Inventory cutover](inventory-cutover.md)
- [Phase 2](../phases/phase-2-purchasing-stock.md)
- [User-interface delivery](../architecture/user-interface.md)
