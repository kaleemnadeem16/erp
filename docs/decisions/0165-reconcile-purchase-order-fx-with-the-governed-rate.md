[← Decisions](README.md)

# 0165 — Reconcile a purchase order's FX rate with the governed exchange rate

- **Status:** proposed (revised 2026-11-12 per owner direction: tolerance and rate age become per-company configuration; the fixed-default behaviour below is what is implemented today)
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

## Revision 2026-11-12: per-company FX governance (owner direction, proposal only)

Decisions 2 and 3 above describe what is **implemented today** with fixed defaults read from `config/purchasing.php`. The owner's direction is that these are company business values, not
code constants (see [0185](0185-configure-company-business-values-and-fix-technical-invariants.md)). Proposed:

1. **Per-company configuration.** The FX tolerance and the maximum governed-rate age are configurable per company. Initial values for every company: 0.5% and 7 days.
2. **Versioned.** Each setting is versioned with an effective date and a change history (who, when, previous value, reason); a change never rewrites an earlier version.
3. **Approved sources.** A company declares its approved exchange-rate sources and rate types; only governed rates of an approved source/type are used for reconciliation.
4. **Settings UI.** Edited through the existing Purchasing/Settings UI standard, with strict server-side validation (bounded numeric ranges, whole days, effective dates not in the past
   (no reason-based exception; see the policy effective-time definition in [0185](0185-configure-company-business-values-and-fix-technical-invariants.md)), approved sources must exist). Requires a dedicated settings permission decided separately.
5. **FIXED-rate exceptions are unchanged in kind:** a written reason plus the STAGED, ungranted `purchasing.purchase-order.fx-override` duty. Finance is the intended future holder; no role is
   granted by this ADR.
6. **Evidence is never retroactive.** Each order stores the exact validation evidence applied when it was recorded (tolerance, age limit, setting version, governed rate and date, source). A later
   setting change never silently changes or re-judges an existing order.

| Part | State |
|---|---|
| Reconciliation against governed rate, tolerance and age check, governed rate/date stored, FIXED + reason + STAGED duty, 422 codes | **Implemented** with fixed defaults (0.5%, 7 days) |
| Per-company tolerance and rate-age settings, versions with effective dates and history, approved sources/types, settings UI and permission, per-order evidence of the setting version applied | **New work, not started; needs separate owner authorisation** |

## Consequences

- The approval band and receipt valuation follow a rate that is either governed or explicitly, accountably declared; no approval policy change is needed.
- A company must keep dated rates for its purchasing currencies (Currency module) or orders in those currencies are refused until a rate is entered or an authorised fixed rate is declared.
- An approval policy cannot yet route on "rate was overridden" (the approval fact vocabulary has no boolean); the override reason is shown as evidence only.
- Open: whether the receipt should also revalue when a FLOATING order's rate moves is untouched (receipts still use the order's pinned rate).
