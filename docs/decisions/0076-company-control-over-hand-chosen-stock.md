[← Decisions](README.md)

# 0076 — Let each company choose how tightly hand-chosen stock is controlled

- **Status:** accepted
- **Date:** 2026-10-01
- **Affects:** Estimating's hand-built stock mix ([ADR 0072](0072-hand-built-stock-mix-in-base-quantity.md)), Settings → Material selection,
  the shared approval engine ([ADR 0070](0070-shared-configurable-approval-engine.md)), the stored-preference pattern ([ADR 0071](0071-stored-material-selection-strategy-as-governed-history.md))

## Context

ADR 0072 left one question to the owner: should the *cost* of a hand-built mix ever need a reason or approval? Research
(ADR 0072, "Research basis") found that mature ERPs treat choosing stock by hand as ordinary operational flexibility, controlled
by *who may do it*, with cost following the valuation model and recorded on the transaction. None documents an approval triggered by
the cost difference. Companies still differ in how much control they want, and the existing variance bands cannot carry it (they
judge planned-vs-standard quantity, not the cost of a draw against the proposal). So the choice belongs to the company, in terms
that fit the question.

## Decision

1. **A company chooses one of four levels** for hand-chosen stock:

   | Level | Meaning |
   |---|---|
   | `NOT_ALLOWED` | Follow the proposal; hand-built mixes are refused and the control is not offered. |
   | `INFORMATION` (default) | Allowed; the mix's cost is shown against the proposal's, nothing is required. |
   | `REASON_REQUIRED` | Allowed; when the mix costs at least *X*% more than the proposal, a written reason is needed. |
   | `APPROVAL_REQUIRED` | Allowed; at or above *X*% more, the mix would need approval under the shared engine. |

   The threshold *X* is a percentage of the proposal's cost, required for the two stricter levels. A cheaper mix never triggers
   either. A mix whose cost cannot be judged (no cost evidence for the mix or the proposal, or a proposal that costs nothing) is
   reported "not judged" — never treated as within the limit.
2. **Company-wide now; built to extend.** The rule is stored with a scope (`COMPANY`, `ITEM_CATEGORY`, `ITEM`) exactly like
   ADR 0071, and resolved item → nearest category ancestor → company → built-in `INFORMATION`. Only the company scope can be set
   today (the API refuses the others), so adding category or item scope later is a request-validation and UI change, not a
   data or resolver change.
3. **Governed like every policy.** A change needs `estimating.stock_mix_policy.manage`, a written reason, and is kept as a row
   per change with its actor; it affects only later previews. Reading it needs that or `estimating.policy-history.view`.
4. **Who may build a mix is its own capability**, `estimating.stock_mix.choose`, on top of `estimating.inventory_availability.view`
   (the one control the research found). Without it the mix input is refused.
5. **The approval level is real or absent.** `APPROVAL_REQUIRED` is selectable only while the company has approvals switched on for
   this kind of decision (a new `STOCK_MIX` subject on the shared engine; facts: extra cost over the proposal in base
   currency, the percentage, material, category). If approvals are later switched off, a stored approval level fails closed:
   the verdict is "blocked", never silently downgraded to a reason.
6. **Preview now, enforcement at adoption.** A preview commits nothing, so the stricter levels only *say* what would be needed
   (reason missing or provided; the approval and its steps), exactly as the variance preview does. Enforcement and submission wait
   for adopting a plan on a quotation revision (Phase 4) or issuing stock to a job (Phase 5), where the cost is committed.
7. **Visible regardless of cost permission, shown by it.** The control is judged on the server whoever is asking; the observed
   percentage and costs are hidden (never zero) without `estimating.internal_cost.view`.

## Consequences

- **Positive:** a company picks how tight it wants to be; small companies see no change (`INFORMATION`).
- **Positive:** the approval level reuses the shared engine, so thresholds, stages, limits and delegation come for free.
- **Positive:** category and item scope, and the cost-baseline change at adoption, are additions, not redesigns.
- **Negative:** for average-cost items every source costs the same, so the stricter levels never fire for them; that is correct,
  and the settings screen says so.

## Alternatives considered

**Reuse the variance bands** — rejected (ADR 0072): different baseline and direction.

**A permission only, no levels** — rejected: the owner wants a company choice of tightness.

**Per-item and per-category scope now** — deferred by the owner; the storage shape makes it additive.
