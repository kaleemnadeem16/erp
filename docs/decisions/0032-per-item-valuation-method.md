[← Decisions](README.md)

# 0032 — Per-item valuation method, not one method for the whole company

- **Status:** accepted
- **Date:** 2026-08-20
- **Affects:** Phase 1 ([items.md](../domain/items.md)), Phase 2 ([domain/stock.md](../domain/stock.md)),
  Phase 6 ([general-ledger.md](../domain/general-ledger.md))
- **Supersedes:** [ADR 0007](0007-avco-valuation.md), whose status is updated to
  `superseded by 0032` in this same commit — the record of its original reasoning stays intact.

## Context

ADR 0007 picked AVCO as a single valuation method for the whole application, reasoned from the
reference business: formulation-based mixing, where a specific receipt's physical identity is
lost the moment it's blended. That reasoning holds for raw materials that get mixed.

It stops holding for a different, equally real shape already visible in this system: a company
that buys finished paint and resells it bundled with an application service, rather than mixing
it. There, batch identity survives all the way to the sale, purchase prices move over time, and
FIFO-based cost attribution is what the business actually wants for accurate margin visibility —
exactly the case ADR 0007's own "Negative" section flagged as unresolved ("some tax jurisdictions
mandate FIFO... this ADR must be superseded before Phase 2 ships"), and logged as
[Q11](../OPEN-QUESTIONS.md#q11--stock-valuation-method-avco-only-or-configurable-per-itemcompany).

One company can genuinely need both at once — raw materials it mixes, and finished goods it
resells — so the fix isn't picking a different single method. It's recognising valuation method
was never a company-wide property; it was always a property of how a specific item's stock
physically behaves.

## Decision

Valuation method is chosen **per item**, with a **default set at category level** that an item
may override.

```
categories.default_valuation_method   NULLABLE  AVCO | FIFO | SPECIFIC
  -- resolved the same way as *_account_id: an item's category, or the nearest ancestor
  -- with this column set, walking parent_id. No forced root default — see items.md.

items.valuation_method                NOT NULL  AVCO | FIFO | SPECIFIC
  -- resolved once, at creation: the value given explicitly (an override), or the
  -- category's resolved default if none is given. Stored as a plain column from then
  -- on — not re-resolved on every read, unlike the GL account columns. This is a
  -- financial-integrity field; it must not silently drift if the category default
  -- changes later.
```

Suggested category defaults (configured per company, not hardcoded): Raw Materials → AVCO,
Resale Goods → FIFO, Unique/Serialized Equipment → SPECIFIC.

**One official method per item, always.** Whatever `items.valuation_method` holds is what drives
that item's Inventory, COGS and GL postings. There is no per-transaction choice and no blending
of two official methods for one item.

**Locked after first transaction.** Once an item has any inventory transaction, its
`valuation_method` must not change through a normal edit. Changing it requires a controlled
conversion/revaluation process — a deliberate Action that recognises the method change and posts
whatever GL adjustment that implies, not a field update. **Not implemented yet**: there is no
inventory-transactions table until Phase 2's `stock_moves` exists, so there is nothing to check
the lock against today. This is a Phase 2 requirement, recorded here so it isn't dropped, not a
gap in this phase.

**Three separate concepts, not one.** Keep these independent — none implies another:

| Concept | Values | Governs |
|---|---|---|
| `items.valuation_method` | `AVCO` \| `FIFO` \| `SPECIFIC` | Accounting cost basis — what posts to the GL |
| `items.tracking` | `NONE` \| `BATCH` \| `SERIAL` | Whether individual units/lots are traceable at all |
| Warehouse removal strategy *(not built)* | `FIFO` \| `FEFO` \| `MANUAL` | Physical picking order on the warehouse floor |

`BATCH` tracking does not imply FIFO costing — a batch-tracked item can still be AVCO-costed.
`SERIAL` tracking does not automatically imply `SPECIFIC` valuation — a serialised item could
still be AVCO- or FIFO-costed if its individual cost isn't what the business cares about. And the
order a warehouse physically picks stock in has no obligation to match the accounting cost basis
— a warehouse can pick oldest-first (FEFO, for shelf life) while the books value stock at a
blended average. Conflating any two of these three is the mistake this table exists to prevent.

**Historical data survives regardless of official method**, so analytical replay is possible
later. Every `stock_move`/`stock_layer` (Phase 2, [stock.md](../domain/stock.md)) retains: item,
quantity, unit cost, transaction/posting date, warehouse and location, batch/serial where
applicable, move type (receipt, issue, return, adjustment, transfer), and the polymorphic source
document reference. This is what ADR 0007 already started with `stock_layers`, extended here to
be a general requirement, not an AVCO-specific one.

**Analytical reports may replay history under a different method than the official one** — e.g.
an AVCO-official item gets an analytical "what would FIFO margin have looked like" report. These
reports:

- Read retained history only. They **never** write a GL entry, alter posted COGS, change an
  inventory balance, or change `items.valuation_method`.
- Are only as good as what was captured. A `SPECIFIC`-basis replay is only possible where the
  exact serial/unit identity was actually recorded at the time — silently estimating it when it
  wasn't captured is not acceptable.

**Not building the alternative costing engines now.** This ADR fixes the schema and the
historical-retention requirement so replay is *possible* later. FIFO's layer-consumption engine,
specific-identification tracking, and analytical replay reports are Phase 2/3 work, built when
the costing phase actually needs them — see [stock.md](../domain/stock.md)'s own "not one method
for the whole company" note for what's deferred versus what's decided now.

## Consequences

- **Positive:** Matches physical reality for every business shape this system needs to support,
  not just the reference formulation business — without forcing a company-wide compromise.
- **Positive:** `stock_layers` was already going to be retained under AVCO (ADR 0007); this
  decision doesn't add new retention burden so much as generalise a requirement that already
  existed, and gives it a reason (future replay) beyond "traceability."
- **Negative:** The Phase 2 costing engine must implement and maintain two live posting paths
  (AVCO's running-average recalculation, FIFO's layer consumption) rather than one, plus whatever
  `SPECIFIC` requires. Real, non-trivial engineering work — not a per-item flag with no cost.
- **Negative:** The valuation-method lock-after-first-transaction rule can't be enforced until
  Phase 2 exists. An item's `valuation_method` is freely editable pre-Phase-2, which is correct
  today (no transactions can exist yet) but must not be forgotten once `stock_moves` lands.
- **Requires:** `Category::resolveDefaultValuationMethod()` (same walk-up-parent pattern as
  `resolve*AccountId()`), and `Item`'s `creating` hook resolving `valuation_method` from an
  explicit override or the category chain, throwing if neither resolves — every item must end up
  with exactly one method, unlike the GL columns which are allowed to resolve to nothing pending
  manual setup.

## Alternatives considered

**One method for the whole company (ADR 0007's original shape)** — rejected. Doesn't fit a
company that both mixes (AVCO-correct) and resells (FIFO-correct) within the same tenant.

**One method per company, not per item** — rejected. Structurally simpler, but doesn't actually
fit the motivating case: the mixing-versus-reselling split can happen *inside* one company, not
just between companies. A per-company switch would force both product lines onto the same method
regardless.

**LIFO as a supported method** — rejected outright. Banned under IFRS; only permitted under US
GAAP, which doesn't apply here. Not offered as an option.

**Standard costing with variances** — already rejected in ADR 0007 as too much process overhead
for a business this size; nothing here reopens that.

**Building FIFO/SPECIFIC costing engines now, alongside this schema change** — rejected. No
`stock_moves` exists yet for any engine to operate on. Building costing logic before Phase 2
exists would be speculative work against a moving target.

## Related

- [ADR 0007](0007-avco-valuation.md) — superseded by this decision, original reasoning preserved
- [domain/items.md](../domain/items.md) — `categories`/`items` schema this decision changes
- [domain/stock.md](../domain/stock.md) — Phase 2 historical-retention requirement
- [Q11](../OPEN-QUESTIONS.md#q11--stock-valuation-method-avco-only-or-configurable-per-itemcompany) — closed by this ADR
