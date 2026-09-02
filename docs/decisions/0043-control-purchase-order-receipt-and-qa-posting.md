[← Decisions](README.md)

# 0043 — Control purchase-order, receipt and QA posting

- **Status:** accepted
- **Date:** 2026-09-02
- **Affects:** Phase 2 Purchasing and Inventory, Phase 6 Accounting,
  [purchasing.md](../domain/purchasing.md), [acquisition-costing.md](../domain/acquisition-costing.md)

## Context

Purchase orders, physical receipts, quality decisions, supplier invoices and payments often
differ in quantity, date, price and currency. Treating them as one editable record would erase
those differences and corrupt stock, supplier performance and later three-/four-way matching.
Phase 2 also needs one deterministic answer for partial and excess delivery, short-close,
tracked inventory, reversals, provisional valuation and concurrent posting before purchase-order
and goods-receipt code is built.

The common workflow must remain easy for a small company while preserving the controls needed by
larger organisations. Approval, AP matching and service acceptance are not all V1 features, but
V1 must not choose a schema or posting boundary that prevents them.

## Decision

### Separate immutable facts

Purchase Order, Goods Receipt, QA Inspection/Disposition, Supplier Return, Supplier Invoice and
Payment are separate business facts. Issuing a PO snapshots its resolved commercial data;
posting a receipt or QA disposition makes that event immutable. Corrections use explicit
revision, reversal or compensating transactions rather than editing posted history.

Receipt progress is derived only from posted, non-reversed receipt history. Original ordered
quantity is never rewritten. PO header completion is derived when every line is terminal through
full receipt, short-close or cancellation.

### Partial receipt and short-close

A PO line supports repeated partial receipts and stores `short_closed_qty`. Conceptually:

```
remaining_qty = max(0, ordered_stock_qty - effective_posted_received_stock_qty - short_closed_stock_qty)
```

Receipt reversal removes its effective quantity through linked reversing history; it does not
edit the receipt. Short-close is a hard stop: any later receipt against that line is rejected.
A future reopen/revision requires an explicit audited action; V1 need not implement reopening.

### V1 over-receipt policy

V1 supports exactly `BLOCK` and `ALLOW_WITHIN_TOLERANCE`. Resolution is PO-line override then
company purchasing default. The future resolution chain may insert an item-supplier default
between them. The resolved mode and percentage are snapshotted on PO issue, so later settings
changes do not alter an issued commitment.

Tolerance is evaluated cumulatively using pinned stock-UoM quantities across effective posted
receipts. For indivisible items, the calculated maximum is floored, never rounded upward. V1
blocks anything above the maximum. A future approval grants a maximum cumulative quantity for
one PO line, not permission for one delivery; approval workflow is deliberately deferred.

### PO and receipt FX

A PO snapshots `FLOATING` or `FIXED` as its FX policy. Every financial event retains the
transaction currency, rate actually used, rate date/type/source and base amount. `FLOATING`
events resolve the applicable rate under their own accounting/posting-date policy. `FIXED`
preserves the PO's explicit commercial rate where later accounting policy requires it. PO,
receipt and invoice rates are not assumed equal.

Receipt monetary facts belong on receipt lines. Each PO-backed line retains its source currency,
provisional unit cost and FX evidence. V1 permits multiple PO lines on one receipt only when they
belong to the same company, supplier and transaction currency. That is validation, not a schema
limitation.

### Receipt posting boundary

Goods Receipt is `DRAFT`, `POSTED` or `REVERSED`. Posting is atomic, pessimistically locked and
idempotent:

1. Lock the Goods Receipt and require `DRAFT`.
2. Collect referenced PO-line IDs, sort ascending and lock them `FOR UPDATE` in that order.
3. Recalculate effective cumulative receipts and revalidate tolerance and short-close.
4. Validate company, supplier/currency grouping, pinned UoM, warehouse/location, tracking and QA.
5. Create inventory movements and provisional valuation/GR-IR effects.
6. Mark the receipt `POSTED` and commit.

The same receipt cannot post twice, including after a commit/response timeout. All multi-row lock
sets use deterministic ordering. Batch or serial identity is required before posting tracked
stock in V1; anonymous tracked inventory never enters on-hand stock.

### QA is a state dimension with posted dispositions

QA state is independent from physical location. Inspection-required receipt quantity initially
has a pending QA state. One or more immutable QA Disposition events later allocate quantity or
exact tracking identities from a source state to `ACCEPTED`, `REJECTED` or a future disposition.
Each event references the receipt line directly or through its inspection and records the
source/result state, quantity or identities, posting time, actor, reason/notes and generated stock
movements.

Disposition posting locks the affected receipt/tracking state and prevents concurrent allocation
of the same pending quantity. It may create movement from receiving/quarantine to storage,
rejected, scrap or return staging, but location never substitutes for QA state. The original
Goods Receipt remains unchanged through partial and repeated dispositions.

### Reversal, supplier return and direct receipt

A Receipt Reversal means the original posting was incorrect. It records reversal time, actor and
reason and creates explicit reversing inventory and accounting effects. It is blocked when
downstream transfer, consumption, QA or financial dependencies cannot be cleanly reversed;
another compensating workflow must then be used.

A Supplier Return means a real receipt was followed by goods leaving for the supplier. It is a
separate transaction and does not automatically reopen the PO or create a replacement expectation.

PO is required by default. A controlled PO-less receipt may later cover samples, emergency or
authorised low-value procurement. It requires explicit provisional unit cost, currency and FX
evidence; it never silently derives cost from last purchase price or inventory average. Opening
inventory uses ADR 0036's dedicated cutover mechanism, never direct receipt.

### Acquisition cost, GR/IR and service purchasing

Receipt valuation separates capitalisable acquisition costs from recoverable tax and other
non-capitalisable amounts and remains compatible with later landed-cost allocation. Conceptually,
goods receipt produces Debit Inventory/acquisition asset and Credit GR/IR. A later supplier
invoice clears GR/IR and recognises AP plus separately identified quantity, price, FX and tax
variances. Phase 2 records the valuation feed/bridge identity; balanced GL and AP matching remain
Phase 6.

`receipt_required_before_invoice` is not universal. Physical goods use Goods Receipt. Services
and non-stock procurement remain structurally capable of a future service-acceptance or direct
invoice workflow, which V1 does not implement.

## V1 and deferred capabilities

V1 includes PO issue snapshots, partial receipt, hard short-close, `BLOCK`/percentage tolerance,
same-supplier/currency receipt grouping, line-level receipt currency/cost, pinned UoM, tracked
identity before posting, atomic/idempotent receipt posting, explicit receipt reversal, QA-pending
state and repeatable posted QA dispositions, provisional valuation and GR/IR feed identity.

Deferred capabilities are above-tolerance approval, item-supplier tolerance defaults, audited
reopen/revision, supplier return and replacement expectations, PO-less receipt workflows, service
acceptance, AP matching/payment holds, balanced journals and quantity-before-serialization. If
high-volume operations later need the last capability, it becomes an explicit
pending-serialization state/process rather than weakening posted-receipt identity.

## Consequences

- **Positive:** Physical, commercial, quality and financial truth remain independently auditable.
- **Positive:** Partial/over receipt and concurrent posting have deterministic rules before data
  and history exist.
- **Positive:** The simple V1 policy does not preclude later approvals, mixed-currency receipts or
  service procurement.
- **Negative:** Posting requires more rows, locks and explicit reversal/disposition workflows than
  an editable received-quantity field.
- **Negative:** A mistaken short-close or receipt cannot be silently corrected.
- **Requires:** Real PostgreSQL concurrency tests for same-line receipts, duplicate receipt post,
  deterministic multi-line locks and concurrent QA disposition.
- **Requires:** Boundary tests for exact/above tolerance, whole-unit flooring, cumulative partials,
  short-close, reversal dependencies, immutable snapshots, pinned UoM/tracking, company isolation,
  V1 currency grouping and independent Inventory/GR-IR effects.

## Alternatives considered

**Update received/accepted quantities directly on the PO or receipt** — rejected because it loses
the events needed to explain partial delivery, reversal and changing QA outcomes.

**Allow unlimited excess with permission in V1** — rejected because the approval model and roles
are not ready; bounded tolerance plus a hard block is complete and safe.

**Use warehouse location as QA status** — rejected because physical custody and quality state can
change independently.

**Use header currency as the authoritative receipt cost** — rejected because it prevents future
mixed-currency relaxation and obscures each PO line's commercial/FX evidence.

**Create stock from the supplier invoice** — rejected because invoice quantity is a financial
claim, not evidence of physical receipt.

**Treat receipt reversal and supplier return alike** — rejected because correction of an error and
a later real outbound event have different history, PO and accounting meaning.

## Related

- [ADR 0035](0035-separate-purchasing-operational-and-financial-facts.md)
- [ADR 0036](0036-controlled-inventory-cutover.md)
- [ADR 0037](0037-controlled-negative-stock-overrides.md)
- [ADR 0040](0040-control-backdated-stock-recalculation-and-corrections.md)
- [Purchasing](../domain/purchasing.md)
- [Acquisition costing](../domain/acquisition-costing.md)
- [Stock](../domain/stock.md)
- [Multi-currency](../domain/multi-currency.md)
