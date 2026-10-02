[← Roadmap](README.md)

# Item and Purchasing improvements

This sequence turns the item/PO gap review into small deliveries. It supplements the existing
phases; it does not bring every later-phase capability into Phase 2. Follow the
[research gate](../architecture/research-and-design.md) before each business-model extension.

## Delivery sequence

The owner approved [ADR 0080](../decisions/0080-packaged-purchasing-and-independent-price-basis.md).
Implementation is now authorised in committed slices:

- [x] Enforce conversion maintenance permission in Requests and Actions; fix receipt conversion/price normalisation.
- [x] Add fixed named package definitions and supplier offers, with company-safe references and lifecycle guards.
- [x] Add PO package/loose choice, separate price basis, warning/reason evidence and immutable snapshots.
- [x] Add receipt package evidence, partial delivery and replacement-order linkage.
- [x] Expose package/offer maintenance and purchasing/receiving in the existing screens; regenerate client and verify.

Acceptance: equal-content boxes remain distinct; 10 boxes of 2 L priced at PKR 850/L costs
PKR 17,000; loose 6.5 L works; 6+4 boxes receives as 12+8 L; changed masters cannot change issued
terms; denied API/Action callers cannot mutate masters; quantity-only stock never claims sealed packs.
Supplier MOQ/multiple violations warn and require a reason. Physical package balances, containers,
catch weight, price tiers and configurable constraint escalation are deliberate later deliveries.

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

**Resolved under ADR 0080:** conversion store/update/delete and their non-HTTP Actions now enforce
inventory.item.manage; HTTP and direct Action denial is tested. The original step-1 finding was that they
lack the capability check used by the screen. UI gating is not backend enforcement. Record this
in the open questions and resolve the conversion-maintenance boundary before claiming those
writes are permission-protected. Step 1 keeps the API contract unchanged.

## Packaging research discussion brief — 2026-10-02

**State:** requirements and questions captured from the owner discussion; not an accepted model
or a completed ERP comparison. Discuss before implementing. The sequence above is provisional:
this review may change how PO conversion visibility and packaging selection should be delivered.

### Business example

Thinner is one product, held in litres, offered in 0.1 L bottles, 1 L bottles and 5 L cans.
A box may contain 20 × 0.1 L bottles; another may contain 40 of those bottles. Other boxes
contain the 1 L or 5 L packs, with cartons/pallets potentially above them. Counts except the
20-bottle example are illustrative, not mandatory defaults.

A box of 20 × 0.1 L and a box of 2 × 1 L both contain 2 L but are different things to order,
receive and hold. Quantity maths alone cannot identify their contents or physical arrangement.
The owner wants an unambiguous PO choice between individual packs and boxes of specific packs.

### Existing foundation to reuse and verify

- ADR 0050: one stock UoM per item; item/UoM overrides; direct factors against stock UoM;
  divisibility separate from MOQ/order multiples. One generic bottle UoM cannot have multiple
  factors for the same item. Distinct conversion units express the maths today, but their use
  as durable package identities needs assessment.
- ADRs 0051–0053 and the Inventory packages domain: package definitions, package balances,
  physical containers and lot/batch identities are different facts. QUANTITY_ONLY,
  PACKAGE_BALANCE and CONTAINER_TRACKED are progressive capabilities; verify shipped versus
  conceptual scope. Supplier replenishment remains Purchasing-owned.
- The committed step 1 panel exposes existing conversions; it does not implement packaging
  hierarchy, sealed-pack stock or a PO packaging selector.
- Purchasing already defaults purchase UoM by supplier relationship and records conversion
  evidence on lines. Verify snapshot timing, pricing semantics and current API/UI contracts.
- Preserve the Phase 5 physical-package ownership boundary and Q23 catch-weight question.
  Q34 records the separate conversion-write authorisation gap; verify its current status.

### Cases to assess

| Case | Example / question |
|---|---|
| Fixed and nested packs | Bottle → box → carton → pallet; alternative boxes and equal-volume packages with different contents |
| Supplier-specific offers | Supplier A sells 20-bottle boxes; B sells 24; applicable packaging, supplier SKU and defaults |
| Variable content / catch weight | A nominal 25 kg sack actually weighs 24.7 kg; record count and measured content |
| Independent price basis | Order boxes, pay per litre, kg, bottle or per 100/1,000 units |
| Order constraints | MOQ, multiples, fractional packs, rounding, warning/blocking and authorised exceptions |
| Kits / assortments | One purchased set contains resin, hardener and thinner; not a single-item conversion |
| Variants | Grade/colour versus packaging; when separate item identities are necessary |
| Returnable packaging | Drum ownership, return lifecycle and refundable deposit |
| Open packs / remnants | A 5 L can has 2 L remaining; quantity-only cannot infer sealed inventory |
| Traceability | Batch, expiry and serial identity across packs and outer containers |
| Weight / volume | Density-based kg↔litre conversion, including batch variability and measurement conditions |
| Delivery planning | Partial receipts, split destinations, requested/promised dates and packing discrepancy |
| Repacking / decanting | Drum → cans; packaging materials, loss and cost consequences |
| Identifiers / handling | Package-level barcodes, dimensions, weights and scanning needs |

### Small-to-large design boundary

Aim for one coherent model: a small business can define a few purchasing packs, order them and
track aggregate quantity without mandatory scanning or container administration. Larger companies
can enable physical package balances, hierarchy, barcodes and individually tracked containers.
Distinguish domain capability, company configuration and UI exposure, following
[extensibility](../architecture/extensibility.md#progressive-capability-modes).

Neither a mandatory large-warehouse workflow nor an expensive-to-migrate simple schema is a
predetermined choice. These are candidate capabilities, not a promise to build all of them now.

### Questions and expected discussion output

1. Map responsibility across item, package definition, supplier offer, PO, receipt and physical
   stock. Resolve when packaging means one item, a variant, a kit or multiple measured quantities.
2. Explain nested/alternative package identity while respecting ADR 0050's direct conversion
   factors. Consider equal quantities, different contents, supplier-specific choices and barcodes.
3. Walk through Item → supplier → packaging → quantity → price basis → PO → receipt using
   Thinner. Explain mixed-pack lines, defaults, validation and what the buyer sees before saving.
4. Identify the snapshot boundary and correction rules so later master-data edits cannot rewrite
   issued documents. Explain opening/repacking/transfers/returns and reconciliation to the stock
   ledger, without inventing physical evidence in QUANTITY_ONLY mode.
5. Complete the authorisation checklist: read/write/override/approval duties, field sensitivity,
   company scope, Action enforcement, UI capabilities and tests.
6. Reuse sufficient existing research first. For unanswered semantics compare two to four mature
   ERPs from primary sources; record dates, convergence, meaningful differences, failure modes,
   realistic alternatives and remaining uncertainty. Do not simply copy a vendor hierarchy.
7. Produce a case/support matrix, conceptual recommendation, rejected alternatives, simple and
   advanced user experiences, and a revised delivery sequence with dependencies and acceptance
   criteria. Identify new/superseding ADRs and domain/phase/open-question updates needed.

No migrations or implementation of the new business shape during the discussion. Accepted ADRs
remain immutable; any changed decision needs a superseding ADR. Preserve company scope, decimal
arithmetic, historical evidence, module ownership and the established stock/valuation authority.

## Related

- [Items](../domain/items.md)
- [Purchasing](../domain/purchasing.md)
- [Phase 2 operational journey](../journeys/phase-2-operational-journey.md)
- [Inventory packages](../domain/inventory-packages.md)
- [Authorisation](../architecture/authorization.md#per-feature-authorisation-checklist)
- [Open questions](../OPEN-QUESTIONS.md)

### Delivered screens and verification — 2026-10-02

Item detail now maintains named packages and supplier offers, including a preferred offer. Purchase orders select package or loose buying and a separate price unit/denominator, with server-calculated quantity and amount previews and recorded ordering exceptions. Receipts default to outstanding package counts and can receive loose stock against the frozen agreement. Replacement drafts link a cancelled or short-closed order. Package content and the item stock unit are protected by application and database guards.

Validation: 106 focused backend tests (including inherited foundation cases), the production frontend build, and the packaged-purchasing browser checks plus existing conversion/supplier-unit checks. Physical package balances, containers, catch weight and package-aware allocation remain deferred under ADR 0080.
