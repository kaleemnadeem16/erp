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
8. **Assigned buyer (`buyer_user_id`) is NOT a scope path in this decision.** See *Decision needed*.
9. **Reach is judged under the row lock.** Every write locks the order with the reach predicate in the same locking `SELECT`, after the company access barrier. If a concurrent draft edit moved the ship-to while the
   writer waited, PostgreSQL re-evaluates the predicate on the committed row, the row no longer qualifies and the order reads as absent. `IssuePurchaseOrder` no longer decides reach before it locks.
   Proved by a real two-session PostgreSQL test (`PurchaseOrderScopeConcurrencyTest`), which fails if the check is moved back before the lock.
10. **The open-PO cutover exception is narrow.** A person without the issue duty's reach may issue an order only when they hold `purchasing.cutover.commit` as an **unrestricted** grant (the real cutover workflow authority) **and** the
    order is a cutover order. A limited cutover grant, a non-cutover order, or direct Action invocation by anyone else is refused.
11. **Money is judged per order.** `commercial-cost.view` and `purchase-order.create` are each limitable, and a person sees an order's commercial fields (FX, payment terms, totals, line prices and amounts, and approval evidence) only on
    orders inside the **commercial** reach, whatever their operational reach. A total filter or sort (which infers money across the whole list) is refused (403) unless the commercial reach covers everything the person may list. Goods-receipt
    money and receipt cost entry, which are not tied to one reachable order, need an **unrestricted** commercial grant. Supplier offers are item-supplier data, not purchase-order records, and are unchanged.
12. **An edit must leave the order reachable.** Creating or editing an order checks that the saved order is still reachable through a path that really applies to it (created by the actor, or shipping to one of the actor's units). Having an
    own-created path as an alternative never lets someone move another person's order out of their units.

## Intentionally unscoped internal workflows (separate, documented)

These read or move purchase orders for their own duty and are company-wide by design; none returns a PO list or detail to a limited person:
goods-receipt creation/posting/reversal and quality disposition (own duties; receiving against an order is receiving, and general Goods-Receipt access is out of scope here); the open-PO cutover commit and its
issue of cutover orders (`purchasing.cutover.commit`, already refused to any limited person by ADR 0089); approval decisions and the approvals inbox/review projection (`purchasing.purchase-order.approve` with its authority ceilings is
a separate duty and is not limitable here); the inventory overview's inbound figures (already refused to limited people); background numbering and the financial-activity probe.

## Not changed

Commercial-field protection (payment terms, totals, prices) is applied after reach exactly as before. No role grant, demo grant or production assignment changed; no administration screen, delegated administration, Phase 6, Job or reassignment-after-issue work.

## Decision needed (owner) before rollout

- **Should the assigned buyer reach the order?** Today `OWN` is creator-only. Options: (a) add a new scope kind `ASSIGNED` (needs a check-constraint migration on `access_scope_limits`, a `ResolvedScope` path and administration wording), recommended because it lets a company
  choose "mine" = created, assigned or both; (b) redefine `OWN` as creator-or-buyer (no schema, but silently changes the meaning other modules' `OWN` suggests). Until decided a buyer named on someone else's order sees nothing extra.
- Whether an administration screen should offer `OWN` (the screen offers only "only my units" today; `OWN` is assignable through the access API).
- A safe active-member buyer lookup is not delivered: no endpoint exposes company members to a purchaser without a members-administration duty.

## Activation (not done)

Activation is an administrator setting a limit on an assignment, after the owner approves who is limited and checks that limited roles contain only limitable duties. Nothing in this change sets one.

## Consequences

Ratchets: `PurchaseOrderRecordScopeCoverageTest` makes every PO route declare its scope status and checks the read-duty lists against `PurchasingReadAccess`; `PurchaseOrderRecordScopeTest` proves lists, totals, filters, detail, every write at HTTP and Action level,
unit changes, revocation, legacy orders and fail-closed cases.
