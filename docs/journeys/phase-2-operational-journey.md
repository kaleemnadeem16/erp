[← Product journeys](README.md)

# Phase 2 operational journey — from setup to controlled stock

- **Implementation status:** partial — the documented Phase 2 UI is implemented; the phase is
  not yet closed because recalculation correction packaging and final accessibility/journey
  acceptance work remain
- **Audience:** company administrator, buyer, receiver/QA operator and inventory controller
- **Starting point:** sign in to `/login`
- **Successful end state:** an issued purchase commitment is received, quality is resolved,
  stock and valuation are visible, and exceptions/counts/cutover are handled through controlled
  workspaces
- **Last verified:** 2026-09-17

This is the teaching path for the system as it exists today. It is not a promise that every ERP
process is complete: supplier invoices/payables belong to Phase 6, job consumption to Phase 5,
and estimating/sales are later phases.

## Journey map

```text
Login
  → company master data and policy setup
  → item + supplier relationship + warehouse/location
  → purchase order (DRAFT → ISSUED)
  → goods receipt (DRAFT → POSTED)
  → optional QA decisions
  → available/on-hand inventory and ledger evidence
  → count, cutover or controlled exception when needed
```

## 1. Enter the application

1. Open `/login` and sign in. The protected application shell then opens at `/` (Dashboard).
2. Use the grouped navigation: Purchasing, Inventory, Stock Operations and Settings. On small
   screens, the same groups are available through the More sheet.
3. If the user lacks a permission, the relevant action must remain unavailable or explain the
   missing permission; do not work around it by editing the database or calling an API directly.

The dashboard is the entry point, not the source of stock truth. Use Inventory Overview and the
Stock Ledger for operational stock questions.

## 2. Prepare the records needed to buy an item

Do this once before the first PO for a new item. Existing records can be reused.

After creating an item, its detail screen offers **Pack conversions**. An item manager can add
alternate units there: choose the pack unit and enter how many stock units one pack contains.
For example, a box containing 24 each uses factor 24. Each pack level converts directly to the
stock unit. Stock quantity increment is optional on item create/edit (blank means unrestricted).
These values describe quantity; they do not prove sealed packages exist. Conversion maintenance
adds no approval step; its backend permission gap is explicitly tracked in the
[improvement plan](../phases/item-purchasing-improvements.md#authorisation-review).

| Order | Screen | What to do | Result |
|---|---|---|---|
| 1 | `/categories` | Create or choose the category and its defaults. | The item can inherit applicable defaults. |
| 2 | `/items` | Create the item with type, stock UoM, tracking and valuation method. | The item exists and has an official cost basis. After its first stock move, stock UoM and valuation identity are locked. |
| 3 | `/item-suppliers` | Register the approved item/supplier relationship, supplier SKU, purchase UoM, priority and lead-time/minimum-order terms. | The buyer can source the item from an approved supplier. Item and supplier identities cannot be swapped on edit; archive and create a new relationship instead. |
| 4 | `/warehouses` | Create a warehouse and its first usable location; add or edit locations as the company's mode permits. | The receiver has a physical destination for the receipt. |
| 5 | `/settings/purchasing` and `/settings/inventory` | Review company purchasing tolerance and inventory structure/valuation defaults. Change them only with the required permission and reason. | Policy is auditable and is resolved/snapshotted where the document requires it. |

Supplier identity/accounts and currencies/UoMs are Phase 1 master data. Register suppliers at
`/suppliers/new`: select an existing trading partner or register one, then enter the supplier
account. Supplier maintenance plus terms/mapping management are required; a new partner also
requires Party maintenance. Retry retains a partner already saved. The supplier edit screen
adds addresses with supplier-site maintenance. They must exist before
the purchasing steps, even if their maintenance screens are not part of this Phase 2 navigation.

## 3. Create and issue a purchase order

1. Open `/purchase-orders` and select **New purchase order**.
2. Select the supplier (and site where applicable), order dates and currency. Add one or more
   approved item-supplier lines. Confirm quantity, purchase UoM (a line starts on the unit that
   supplier sells the item in, else the item's stock unit — see
   [purchasing.md](../domain/purchasing.md#purchase-unit-default)), price, tax/discount, expected
   delivery, QA requirement and any advanced FX evidence.
3. Save the document. It is `DRAFT`: it can be edited or cancelled, but it is not yet a
   receivable commitment.
4. Open the PO detail workspace at `/purchase-orders/:id`, review totals, currency/UoM labels,
   supplier and line facts, then choose **Issue** and confirm the consequence.
5. The PO becomes `ISSUED`. The system assigns its gapless number and snapshots item and supplier
   descriptions/codes, quantities, UoMs/conversion, price, tax, currency, FX and over-receipt
   policy. Current master data changes cannot rewrite this issued PO.

An issued PO can be received in parts. If a line will never arrive, use the line's **short-close**
action after reviewing the consequence; the remaining quantity becomes zero and later receipt is
blocked. Cancel is for an unfulfilled PO, not a substitute for reversing a posted receipt.

PO status is `DRAFT → ISSUED → CLOSED` when every line is received or short-closed. It may become
`CANCELLED` through the status-gated cancellation action. Receipt progress is derived from posted
receipts, not typed into the PO.

## 4. Record the physical arrival

1. Open `/goods-receipts` and select **New receipt**.
2. Select the supplier. The form offers only that supplier's issued PO lines with outstanding
   quantity. Enter physical arrival time, posting date, actual quantity/UoM, destination location,
   provisional cost/FX evidence and required batch or serial identity.
3. Save. The receipt is `DRAFT`; the draft claims its quantity so another draft cannot claim the
   same remaining PO quantity. Review the detail workspace before posting. Use **Edit draft**
   (`/goods-receipts/{id}/edit`, receipt-create duty) to correct header/lines before posting.
   Saved lines remain selectable even with no unallocated quantity. Hidden costs are retained.
4. Choose **Post** and confirm the stock consequences. The server rechecks cumulative receipt
   limits and posts the receipt atomically through Inventory's movement boundary.
5. The receipt becomes `POSTED`. It creates the stock move, traceable layer and valuation-feed
   entry; AVCO also updates its resolved pool. On-hand increases, but QA-required quantity is not
   automatically available to issue.

The same receipt cannot be posted twice. A posted receipt is not edited. If it is wrong, use the
detail workspace's **Reverse** action with a reason, provided downstream dependencies allow it.
Reversal changes the receipt status to `REVERSED` and posts the correcting inventory effect; it
does not silently rewrite the original facts. A supplier return is a separate outbound event, not
an automatic PO reopen.

Goods-receipt status is `DRAFT → POSTED → REVERSED`. A PO can close after all its lines reach a
terminal receiving outcome; the receipt itself does not become a supplier invoice.

## 5. Resolve quality assurance when required

QA is performed from the posted receipt detail workspace. It is a quality decision attached to
the receipt line, not a replacement for the physical stock move and not a warehouse location.

1. Inspect the unresolved quantity shown by the server.
2. Post a disposition with a quantity and reason. A root disposition consumes pending receipt
   quantity; an open `REWORK` or `PENDING_DECISION` branch can be resolved later.
3. Use the outcomes correctly:

| Outcome | Terminal? | Availability effect |
|---|---:|---|
| `PASS` | Yes | Releases the dispositioned quantity to available stock |
| `ACCEPT_WITH_CONCESSION` | Yes | Releases it according to the company's concession policy |
| `RETURN_TO_SUPPLIER` | Yes | Not available; requires return handling |
| `SCRAP` | Yes | Not available; destined for destruction |
| `REWORK` | No | Remains open until a later disposition resolves it |
| `PENDING_DECISION` | No | Remains open until a later decision |

Each disposition is immutable and retains actor, time, reason and quantity. The receipt line is
never rewritten with an editable accepted/rejected counter. Only `PASS` and
`ACCEPT_WITH_CONCESSION` quantity is available; terminal does not mean available.

## 6. Check what changed

Use `/inventory-overview` for the operational summary. Read the basis of each figure separately:

| Figure | Meaning after posting |
|---|---|
| On hand | Physical quantity posted into inventory |
| Available | Quantity eligible for issue after QA/hold rules |
| QA held | Quantity still pending or unresolved in QA |
| On order | Remaining quantity on issued POs |
| Shortage | Controlled negative-stock quantity awaiting settlement, where applicable |

Select an item to drill into `/stock-moves` and the stock-move detail. The ledger is immutable
evidence of quantity, location, tracking identity, source document and valuation feed. AVCO/FIFO/
SPECIFIC behaviour is determined by the item's recorded valuation method; money remains exact
decimal data with pinned currency/FX evidence.

## 7. Operational control paths

These are not part of every PO, but are the supported Phase 2 paths when the situation requires
them.

### Physical count — `/physical-counts`

Create a count to snapshot book quantity at an exact item/location/batch/serial identity. Record
the count or recount, review retained attempts, then post only after every line is counted and an
independent actor confirms it. The count becomes `POSTED` and any variance uses the same inventory
movement boundary. Abandon an unused draft with `CANCELLED`; do not edit posted stock.

### Inventory cutover — `/inventory-cutovers`

Use this for controlled opening state from a predecessor system, not ordinary receipts. Create
the cutover, attach validated source evidence, validate and preview exact totals, obtain an
independent approval, commit the opening state, then finalise. The visible status path is
`DRAFT → VALIDATED → APPROVED → FINALISED` (or `FAILED` when processing cannot complete). The
system preserves provenance and reconciles location, valuation-pool and company totals; it does
not weaken live negative-stock policy.

### Negative-stock override — `/negative-stock-overrides`

The normal policy is to block insufficient stock. For an eligible outbound case only, create a
request with the exact shortage, reason and required evidence. An authorised approver decides it
under the configured prior-approval or immediate-review policy; the request and decision are
immutable. If approved, the exact movement may post with controlled provisional valuation and an
open shortage. Compatible inbound stock later settles the shortage and records the valuation
repair. This path never bypasses serial identity, transfer restrictions or other hard blocks.

### Backdated stock recalculation — `/recalculation-requests`

Use this when a historical receipt, issue or transfer needs an authorised replay. Create a
request, review its server-generated impact version, obtain the required independent approvals,
and execute only while the exact approved version and approval deadlines remain valid. If the
impact changes, use a new version rather than silently executing old approval. The correction-
package work that allocates deltas to later Sales, Jobs or Finance owners is not implemented yet;
those later phases own that boundary.

## 8. What happens next, but is not in Phase 2

- **Supplier invoice, three-/four-way matching, payables and payment:** Phase 6. A supplier
  invoice never creates stock.
- **Job/work-order material issue and job cost:** Phase 5. Phase 2 provides the stock boundary
  that later issue screens will call.
- **Estimating, sales and customer documents:** Phases 3–4.
- **Mobile/barcode/offline workflows:** Phase 7.

## Source of truth

- Purchasing rules: [purchasing.md](../domain/purchasing.md)
- Stock and valuation: [stock.md](../domain/stock.md)
- Negative stock: [negative-stock.md](../domain/negative-stock.md)
- Backdated replay: [stock-recalculation.md](../domain/stock-recalculation.md)
- Cutover: [inventory-cutover.md](../domain/inventory-cutover.md)
- Counts: [physical-count.md](../domain/physical-count.md)
- UI conventions and ownership: [user-interface.md](../architecture/user-interface.md)
- Implemented route map: [`router.tsx`](../../frontend/apps/main/src/router.tsx)

## Named packages and independent purchase prices

On Item detail, add fixed packages with their content in the stock unit; an outer package may reference an existing inner package and its count. Create supplier offers under the existing item/supplier relationship, selecting package or loose unit, price basis, currency and optional minimum/multiple. Choose the preferred offer for future orders. To change package content, archive the old package and create another; stock units cannot change once packages exist.

On a draft purchase-order line, select a package or loose buying, enter the order quantity, and select how the supplier prices it. The preview shows order quantity, factor, stock quantity and line amount. Ten 2 L boxes priced at 850 per litre produce 20 L and 17,000. Minimum/multiple exceptions need a reason before saving. Issuing freezes the agreement; later master changes do not rewrite it.

Receive part of the order in its named packages or as loose stock. The outstanding package count is suggested, and the stock movement uses the issued factor and price basis. If the supplier cannot supply the agreed package or price, cancel an unreceived order or short-close its remainder, then create a replacement draft using the previous order ID. Earlier receipts and agreement evidence remain visible.

On Item detail, **Supplier relationships** now supports add/edit/archive/restore in place. The item is fixed, and editing cannot change the supplier identity. Read-only buyers can inspect the terms; changing them requires purchasing.item-supplier.manage. The panel lists archived relationships as well as active ones, paginates, and reports lookup failures. Create an active relationship here before adding an offer below it.

## Company-specific descriptive fields

Settings → **Custom Fields** requires `administration.custom-field.manage`. Choose a supported
record type, code, label and text/number/date/yes-no/choice type; newly defined fields appear on
Item create/edit and supplier registration/edit, with Party fields during new-partner entry.
Required values are checked by the API. After saving a new item, open its detail to add conversions,
packages and supplier offers. The Thinner demo shows three populated custom values; configuring
customer definitions does not imply a customer form has shipped. See
[the implemented scope](../architecture/extensibility.md#implemented-custom-field-settings-2026-10-06).

## Related

- [Product journeys index](README.md)
- [Phase 2 roadmap](../phases/phase-2-purchasing-stock.md)
- [Purchasing module](../../backend/app/Modules/Purchasing/README.md)
- [Inventory module](../../backend/app/Modules/Inventory/README.md)
