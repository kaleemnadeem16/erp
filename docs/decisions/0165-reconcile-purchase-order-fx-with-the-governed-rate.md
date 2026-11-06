[← Decisions](README.md)

# 0165 — Reconcile a purchase order's FX rate with the governed exchange rate

- **Status:** proposed (defaults below await the owner's confirmation: 0.5% tolerance, 7-day rate age, who may fix a rate)
- **Date:** 2026-11-06
- **Affects:** Purchasing (`ReconcilePurchaseOrderFx`, create/update Actions, `purchase_orders` evidence columns, `purchasing.purchase-order.fx-override`), `config/purchasing.php`
- **Builds on:** 0012 (dual amounts), 0031 (manual exchange rates), 0070 (approval engine), 0118 (governed rates for quotations; its resolver is Sales-owned and is not reused)

## Context

Issue #90, audit finding F3. `POST/PATCH /purchase-orders` required `fx_rate`, `fx_rate_date`, `fx_rate_type` and `fx_rate_source` from the client and stored them as typed.
`BuildPurchaseOrderLineAttributes` multiplied every line by that rate to get `base_line_amount`; `PurchaseOrderApprovalSubject` routed on the sum of those base amounts; the
goods receipt copies the line's rate and posts `base_amount` into the stock movement. Nothing compared the rate with the Currency module's governed `exchange_rates`. A USD 10,000
order (PKR 2.8M at 280) entered at rate 28 was accepted, routed as PKR 280,000 (below an over-PKR-1M approval band) and valued in stock at PKR 280,000. A regression test proves it
(`PurchaseOrderFxGovernanceTest`).

## Decision

1. A rate typed on a purchase order is reconciled when the order is created, and when an edit changes the currency, rate, rate date, rate type, FX policy or override reason. Unrelated edits are not re-checked. Migrated cutover orders (`forWorkflow`) are historical and are not checked.
2. The governed rate is the company's stored rate of the same rate type from the order currency to the base currency, the latest dated on or before the order's rate date and at most `purchasing.fx.max_rate_age_days` (7) old. The base currency is always 1. No base currency, no rate type match or no fresh rate means there is nothing to check against.
3. Within `purchasing.fx.tolerance_percent` (0.5%) of the governed rate the typed rate is accepted as typed; the governed rate and its date are stored beside it (`fx_governed_rate`, `fx_governed_rate_date`).
4. Outside tolerance, or with no governed rate, the rate is accepted only when the order declares `fx_policy = FIXED`, carries a written `fx_override_reason` (at least 10 characters: the contract or forward reference), and the actor holds `purchasing.purchase-order.fx-override`. The reason and the person (`fx_override_by`) are recorded, and the reason travels in the approval request's evidence.
5. Otherwise it is refused with 422 on `fx_rate` (`FX_RATE_OUTSIDE_TOLERANCE`, `FX_RATE_UNGOVERNED`, `FX_BASE_CURRENCY_MISSING`) or on `fx_override_reason` (`FX_OVERRIDE_REASON_REQUIRED`). We refuse rather than snap to the governed rate, so the buyer sees the difference and a genuine contract rate is not silently replaced.
6. `purchasing.purchase-order.fx-override` is STAGED: registered, granted to no role.

## Consequences

- The approval band and receipt valuation follow a rate that is either governed or explicitly, accountably declared; no approval policy change is needed.
- A company must keep dated rates for its purchasing currencies (Currency module) or orders in those currencies are refused until a rate is entered or an authorised fixed rate is declared.
- An approval policy cannot yet route on "rate was overridden" (the approval fact vocabulary has no boolean); the override reason is shown as evidence only.
- Open: whether the receipt should also revalue when a FLOATING order's rate moves is untouched (receipts still use the order's pinned rate).
