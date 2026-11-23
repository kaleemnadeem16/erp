[← Decisions](README.md)

# 0191 — Record ship-to, buyer, payment terms and shipping method on purchase orders

- **Status:** accepted (implementation; owner assignment 2026-11-16)
- **Date:** 2026-11-16
- **Affects:** Phase 2 Purchasing (purchase orders, approval fingerprint, print), Inventory warehouses/locations, Parties supplier terms; prepares Q26 Purchasing record scope
- **Builds on:** [0070](0070-shared-configurable-approval-engine.md), [0079](0079-central-numbering-framework.md), [0089](0089-organisation-units-and-record-scope.md), [0165](0165-reconcile-purchase-order-fx-with-the-governed-rate.md)

## Context

The first purchase-order release deliberately left four facts out: where goods are delivered, who the buyer is, payment terms of the order itself, and how the goods travel. The PO print
said "a missing delivery destination is explicitly unspecified", payment terms were read from the supplier's current record (so an old issued order drifted when the supplier changed),
and Q26 recorded that purchasing documents cannot be record-scoped until a creator and a ship-to are recorded.

## Decision

1. **Ship-to.** `ship_to_warehouse_id` (a warehouse of this company; composite company key) and an optional `ship_to_location_id` tied to that very warehouse by the composite key
   `(warehouse_id, id)` on `locations`, so a location of another warehouse or company cannot be named. Both are validated as active at draft save and again when the order is issued.
   Clearing or changing the warehouse clears the location. A warehouse alone is enough; the location is only a refinement.
2. **Identities.** `created_by` is the person who drafted the order, set by the server (never from the request) and unchangeable at any stage — that is the identity Q26 will scope on.
   `buyer_user_id` is the optional named buyer/requester (an ACTIVE user of this company). Neither is back-filled for existing orders: nobody recorded them, and none is invented.
3. **Payment terms.** `payment_terms_days` is the order's own override. On a draft, NULL means "follow the supplier's default" (shown, never stored). **Issuing freezes the effective value**
   and its origin (`payment_terms_source` = `PURCHASE_ORDER` or `SUPPLIER_DEFAULT`), so a later supplier edit cannot move an issued order's terms. An issued order that predates this
   change has neither stored and reads the supplier's current default (documented, not rewritten). Terms are commercial and follow `purchasing.commercial-cost.view`.
4. **Shipping method** is free text (at most 100 characters; blank means none). No company list exists yet; one can replace it later without changing the column's meaning.
5. **Immutability.** After issue, ship-to, buyer, terms and shipping method are as immutable as price, supplier and FX: the model refuses a change and the database guard
   (`guard_purchase_order_mutation`, extended) refuses it even from raw SQL. The creator is guarded at every stage.
6. **Approval.** The routing fingerprint carries each of these facts **only when the order states it** (terms only when the order's own override stands), so an order approved before
   this change keeps the fingerprint it was approved on, and changing any stated fact on a draft voids its approval (the update Action ends it; a change made behind the Action's back
   fails the fingerprint check at issue). Payment terms that merely follow the supplier are not part of it. They are not declared policy facts: no approval rule routes on them yet.
7. **Everything is optional.** No existing order, import, cutover or API client has to send any of it; a cutover-committed order records its committing person as creator.

## Consequences

- The API exposes the identities on the list and names, ship-to detail and effective terms on the single-order reads; the form, detail, summary and print show them.
- Q26 Purchasing record scope is **not** built here; its two prerequisites (creator, ship-to) now exist for new orders. Back-filling old orders needs a separate owner decision.
- Not built: a people lookup for choosing another person as buyer (the form offers the signed-in person and whoever the order already names; the API accepts any active user), a
  company shipping-method list, allowing buyer reassignment after issue, and pre-filling receipt locations from the ship-to.

## Alternatives rejected

- **Store the supplier default on every draft** — freezes a value nobody chose and goes stale while still a draft.
- **Leave terms to follow the supplier forever** — an issued order's terms would change when the supplier is edited.
- **One field for creator and buyer** — drafting and responsibility differ (a requester drafts for a buyer), and Q26 needs the immutable one.
- **Back-fill creator and ship-to from guesses** — fabricates ownership evidence.

## Related

[Purchasing domain](../domain/purchasing.md) · [Purchasing README](../../backend/app/Modules/Purchasing/README.md) · [Q26](../OPEN-QUESTIONS.md)
