[← Decisions](README.md)

# 0192 — Purchase-order record scope (Q26): own-created and warehouse/unit limits, inactive until granted

- **Status:** accepted (implementation; owner assignment 2026-11-24). **No limit is active**: every existing grant stays company-wide until an administrator sets one.
- **Date:** 2026-11-24
- **Affects:** Phase 2 Purchasing (purchase-order reads and writes), Administration scope limits, Q26
- **Builds on:** [0089](0089-organisation-units-and-record-scope.md) (record scope and organisation units), [0191](0191-record-ship-to-buyer-payment-terms-and-shipping-method-on-purchase-orders.md) (the creator and ship-to it needed), [0096](0096-cross-cutting-feature-standards.md)

## Context

A small company runs Purchasing company-wide and must not be forced to configure departments. A larger company needs a buyer or a site to see only its own orders. ADR 0191 recorded the
two identities scope needs (`created_by`, `ship_to_warehouse_id`); ADR 0089 supplied the resolver, organisation units and the exact-duty adapter contract. This decision applies them to purchase orders.

## Decision

1. **Reuse, do not invent.** A `purchase_order` resource adapter (`PurchaseOrderScopeAdapter`) is registered with `ResourceScopeAdapters`; `RecordScope::resolveForResource` resolves each duty exactly.
   Limits are the existing `access_scope_limits` rows. No table, constraint or scope vocabulary changed.
2. **Meaning of the supported scopes (purchase orders only).**
   - `ALL` (no limit row): every order of the company, including old ones. Unchanged for every existing assignment.
   - `OWN`: orders whose immutable `created_by` is the actor. It does **not** include the assigned buyer.
   - `MY_UNITS`: orders whose `ship_to_warehouse_id` is owned (organisation-unit resource) by an active unit the actor belongs to, or a descendant.
   - Both limited modes on different duties add up for reads (see 4). `OWN_OR_DELEGATED` is a Sales concept (named delegation) and is **unsupported** here: it fails closed, never reinterpreted.
3. **Limitable duties:** `purchase-order.view/create/submit/issue/cancel/short-close`, plus `commercial-cost.view` (a field permission that never confers reach, listed so a limited buyer's role may carry it).
   Approve, FX-override, goods-receipt, quality, supplier-return, cutover and numbering duties are not limitable here. A limited assignment whose **role also carries any unsupported duty fails closed** (reaches nothing),
   the same rule as Sales, so limited roles must be built only from limitable duties.
4. **Reads.** The reach for lists, counts, filters, search, sort, includes and detail is the widest reach among the PO record duties the person holds
   (`view`, `create`, `issue`, `cancel`, `short-close`). A Goods-Receipt, quality-disposition or cutover duty confers PO reads **only when the person holds no PO record duty at all**; it then keeps its
   existing meaning (company-wide when unrestricted, "my units" by ship-to when so limited). So an unrelated unrestricted receiving duty can never widen a limited PO grant.
5. **Writes** (update, submit, issue, cancel, short-close) are judged by the **exact duty** being exercised, at HTTP entry and again inside the Action, after the company access barrier, from the freshly loaded actor, under the
   row lock (`PurchaseOrderAccess::lockForDuty`). An order outside reach is a **non-disclosing 404** (`ModelNotFoundException`), identical to an absent id. Missing permission stays 403 (before the record is looked up).
6. **Creating.** A limited creator may not file an order into a warehouse outside their reach, nor leave the ship-to empty when only `MY_UNITS` applies (they would lose sight of it) — 422 on `ship_to_warehouse_id`; the same
   holds when an edit moves the ship-to. Naming a replaced order (`replaces_purchase_order_id`) requires reaching it; otherwise it reads as absent (404).
7. **History.** Orders from before ADR 0191 have no creator and no ship-to. They match neither limited path, so a limited person never reaches them and nobody is invented as their creator; unrestricted persons keep them.
   No back-fill is performed (a back-fill would manufacture identities).
8. **`ASSIGNED` is the assigned-buyer scope (owner-approved 2026-11-24).** An order is reached when its `buyer_user_id` is the actor. It is a scope of its own: it is not `OWN` (an order the actor merely drafted is not reached by it) and not
   `OWN_OR_DELEGATED`. It is an *alternative path of the same duty*, additive with `OWN` and `MY_UNITS`: a person limited twice on one duty reaches the union. **Being named buyer confers no capability** (the duty must still be held), and
   **a missing buyer confers no reach** (nobody is inferred). Where it is supported:
   - the migration widens the `access_scope_limits` check (`ASSIGNED`); its rollback is **refused** while any assignment still carries it, so a restriction is never silently dropped or widened;
   - `ResolvedScope` carries an assigned path and `RecordScope::resolveForResource` honours it only where the resource adapter declares it; the legacy resolver (Inventory's warehouse scope) knows only units and **fails closed** on it, and the
     Sales quotation adapter does not declare it, so Sales and Inventory semantics are unchanged;
   - the Purchase Order adapter declares it for the limitable duties; reads, writes, commercial reach, money filters/sorts, approval evidence and print all use the same reach test (`PurchaseOrderReach`);
   - **create and draft edit** must leave the order reachable through a path that really applies: an `ASSIGNED`-only creator has to name themselves buyer (422 on `buyer_user_id` otherwise) and cannot hand a draft to somebody else; another authorised
     path for the same duty (for example `OWN`) frees the choice. The buyer of an issued order stays immutable (ADR 0191); there is no reassignment after issue and no historical back-fill;
   - Administration validates it like the other record-limited scopes (every duty of the role must support it, otherwise 422), roles report their supported `limit_modes`, and the members screen offers "Company-wide / Only my units / Only records I
     created / Only records assigned to me" per given role, only where the server says the role supports it.
   - **Buyer lookup:** `GET /api/v1/purchase-order-buyers?search=` returns `{id, name}` of ACTIVE members of this company only, to a person who holds `purchasing.purchase-order.create`; no email, status, roles, permissions, units or limits. The purchase-order
     form's buyer picker uses it.
9. **Reach is judged under the row lock.** Every write locks the order with the reach predicate in the same locking `SELECT`, after the company access barrier. If a concurrent draft edit moved the ship-to while the
   writer waited, PostgreSQL re-evaluates the predicate on the committed row, the row no longer qualifies and the order reads as absent. `IssuePurchaseOrder` no longer decides reach before it locks.
   Proved by a real two-session PostgreSQL test (`PurchaseOrderScopeConcurrencyTest`), which fails if the check is moved back before the lock.
10. **The open-PO cutover exception is narrow.** A person without the issue duty's reach may issue an order only when they hold `purchasing.cutover.commit` as an **unrestricted** grant (the real cutover workflow authority) **and** the
    order is a cutover order. A limited cutover grant, a non-cutover order, or direct Action invocation by anyone else is refused.
11. **Money is judged per order.** `commercial-cost.view` and `purchase-order.create` are each limitable, and a person sees an order's commercial fields (FX, payment terms, totals, line prices and amounts, and approval evidence) only on
    orders inside the **commercial** reach, whatever their operational reach. A total filter or sort (which infers money across the whole list) is refused (403) unless the commercial reach covers everything the person may list. Goods-receipt
    money and receipt cost entry, which are not tied to one reachable order, need an **unrestricted** commercial grant. Supplier offers are item-supplier data, not purchase-order records, and are unchanged. The API tells the screen, order by order, whether its money is shown (`commercial_visible`) and, for the list, whether a total filter or sort will be answered (`commercial_query`); the detail screen, its headings and controls, and the print follow those, never the general permission hook.
12. **An edit must leave the order reachable.** Creating or editing an order checks that the saved order is still reachable through a path that really applies to it (created by the actor, or shipping to one of the actor's units). Having an
    own-created path as an alternative never lets someone move another person's order out of their units.

## Intentionally unscoped internal workflows (separate, documented)

These read or move purchase orders for their own duty and are company-wide by design; none returns a PO list or detail to a limited person:
goods-receipt creation/posting/reversal and quality disposition (own duties; receiving against an order is receiving, and general Goods-Receipt access is out of scope here); the open-PO cutover commit and its
issue of cutover orders (`purchasing.cutover.commit`, already refused to any limited person by ADR 0089); approval decisions and the approvals inbox/review projection (`purchasing.purchase-order.approve` with its authority ceilings is
a separate duty and is not limitable here); the inventory overview's inbound figures (already refused to limited people); background numbering and the financial-activity probe.

## Not changed

Commercial-field protection (payment terms, totals, prices) is applied after reach exactly as before. No role grant, demo grant or production assignment changed; no administration screen, delegated administration, Phase 6, Job or reassignment-after-issue work.

## Decisions that remain with the owner before any rollout

- Which people or roles are actually limited, and to which scope (nothing is set).
- Whether limited roles are built for Purchasing (a limited role must contain only limitable duties; mixed roles reach nothing).
- A back-fill of creator, buyer or ship-to for old orders (recommended: none, it would invent identities).

## Activation (not done)

Activation is an administrator setting a limit on an assignment, after the owner approves who is limited and checks that limited roles contain only limitable duties. Nothing in this change sets one.

## Consequences

Ratchets: `PurchaseOrderRecordScopeCoverageTest` makes every PO route declare its scope status and checks the read-duty lists against `PurchasingReadAccess`; `PurchaseOrderRecordScopeTest` proves lists, totals, filters, detail, every write at HTTP and Action level,
unit changes, revocation, legacy orders and fail-closed cases.
