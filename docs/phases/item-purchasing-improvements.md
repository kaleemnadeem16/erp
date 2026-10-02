[← Roadmap](README.md)

# Item and Purchasing improvements

This sequence turns the item/PO gap review into small deliveries. It supplements the existing
phases; it does not bring every later-phase capability into Phase 2. Follow the
[research gate](../architecture/research-and-design.md) before each business-model extension.

## Delivery sequence

1. [x] **Item pack-conversion access:** reuse ADR 0050's editor within item details, fix its item
   identity to the current record, retain pagination and server errors, and expose stock quantity
   increment in item create/edit/detail. Creating an item leads directly to its conversions.
2. [ ] **PO entry conversion visibility:** display the authoritative conversion factor and stock
   quantity before saving; cover item/supplier/unit changes, unavailable conversions and decimal
   arithmetic. Reuse ADR 0050; do not invent sealed-package availability.
3. [ ] **Supplier terms within item context:** expose the existing supplier relationships with
   their Purchasing read/write capabilities; preserve supplier-specific purchase-unit defaulting.
4. [ ] **Supplier ordering constraints research:** decide MOQ units, warnings versus blocking,
   order multiples, rounding, override authority and issue-time snapshots. ADR 0050 leaves MOQ
   enforcement/order multiples open. Compare two to four mature ERPs from primary sources and
   record synthesis, alternatives and the chosen scope before implementation.
5. [ ] **Purchasing commercial basis research:** decide percentage discounts and pricing per
   quantity/UoM, including rounding and historical evidence. Reuse existing cost and currency
   contracts. Tax integration must follow ADRs 0025/0056, with current authoritative guidance
   verified at implementation; a manual rate field is not the tax engine.
6. [ ] **Delivery planning research:** decide receiving destination, split schedules and requested
   versus promised dates before committing a schema; separate operational delivery from receipts.
7. [ ] **Select further capabilities by workflow:** identifiers, dimensions/weights, shelf life,
   certifications, landed costs and project allocations require bounded requirements and owners.
   Their absence is not sufficient reason to add columns. Physical package tracking remains the
   Phase 5-owned extension under ADRs 0051–0053/0072; catch weight remains Q23.

## Step 1 evidence and acceptance

Existing research and the governing decision are
[ADR 0050](../decisions/0050-item-specific-uom-conversion-and-divisibility.md) and
[units of measure](../domain/units-of-measure.md#item-specific-conversions). No new business
model or market comparison is needed. Each level retains a direct stock-unit factor.

- Item details show only that item's conversions, including empty, loading/error and paginated
  states; the item cannot be switched by the panel or URL filters.
- Item managers can add, edit and remove conversions in place. Identity stays create-only;
  successful changes refresh the list and failures remain visible.
- Stock increment round-trips through the existing item API, with blank meaning unrestricted.
  The label distinguishes divisibility from supplier order multiples; this does not introduce
  PO precision enforcement.

Validation: the frontend production build passes. The focused browser spec passes both the
real item-scoping/edit-form check and the injected API-failure check (plus authentication setup).
Conversion mutation APIs are reused unchanged; this slice does not claim new backend permission
coverage or new PO quantity constraints.

## Authorisation review

No new permission is registered. Item writes already require `inventory.item.manage`; the
conversion panel's write controls use it too. Conversion reads are authenticated, company-scoped
reference data, with no monetary fields, assignment scope or approval transition. Existing
company-scoped requests/models protect references; no schema, deployment grants, history,
separation-of-duties or approval behaviour changes here.

**Existing backend gap:** conversion store/update/delete and their non-HTTP Actions currently
lack the capability check used by the screen. UI gating is not backend enforcement. Record this
in the open questions and resolve the conversion-maintenance boundary before claiming those
writes are permission-protected. Step 1 keeps the API contract unchanged.

## Related

- [Items](../domain/items.md)
- [Purchasing](../domain/purchasing.md)
- [Phase 2 operational journey](../journeys/phase-2-operational-journey.md)
- [Inventory packages](../domain/inventory-packages.md)
- [Authorisation](../architecture/authorization.md#per-feature-authorisation-checklist)
- [Open questions](../OPEN-QUESTIONS.md)
