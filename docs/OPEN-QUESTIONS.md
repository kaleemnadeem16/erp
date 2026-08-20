# Open Questions

Decisions not yet made. Each says what it blocks, so work can be sequenced around it.

**When a question is answered:** write an [ADR](decisions/), then delete the entry here.
Questions do not linger once resolved.

---

## Blocking

None currently. Q1, Q2 and Q3 were resolved on 2026-08-10 — see
[ADR 0023](decisions/0023-retention-retainage-enabled.md),
[ADR 0024](decisions/0024-onboarding-base-currency-and-fiscal-year.md) and
[ADR 0025](decisions/0025-configurable-tax-engine.md).

---

## Non-blocking

Q6 (data import mechanism) and Q7 (permissions mechanism) resolved 2026-08-10 — see
[ADR 0027](decisions/0027-generic-column-mapping-import.md) and
[ADR 0028](decisions/0028-dynamic-namespaced-permissions.md). Q7's actual role/permission
*list* is still deferred to Phase 4, per those docs. Q9 (exchange rate source) resolved
2026-08-19 — see [ADR 0031](decisions/0031-manual-exchange-rate-entry.md).

### Q8 — Second customer profile
**Blocks:** Nothing yet — but shapes Phase 3 and the plugin design

The extensibility strategy in [extensibility.md](architecture/extensibility.md) warns against
over-abstracting before a real second customer exists. Knowing who that is — and how their
process actually differs — would let us validate the rate-dimension model against a real case
instead of a hypothetical one.

### Q10 — Sharing master data across a customer's own legal entities
**Blocks:** Nothing in Phase 1. Relevant once a customer wants more than one legal
entity/branch under one login.

`company_id` is the full tenant-isolation boundary
([GLOSSARY.md](GLOSSARY.md#company), [ADR 0004](decisions/0004-multi-tenancy-ready-schema.md)),
not a legal-entity subdivision within one customer. Today, "multi-entity support" means another
row in `companies`, isolated from its sibling exactly as strictly as from an unrelated tenant —
the only planned bridge is user membership across `company_id`s
([multi-tenancy.md](architecture/multi-tenancy.md)'s planned tenant-switching UI), not shared
data. There is no construct for sharing a specific record — e.g. a trading partner in
[parties.md](domain/parties.md) — between two legal entities of the same real customer without
also exposing it to unrelated tenants.

Surfaced while deciding [ADR 0030](decisions/0030-unified-party-model.md) (a global, unscoped
`parties` table was considered and rejected as the wrong fix for this). Options when it becomes
real: a parent "account" grouping above `company_id`; per-record cross-company sharing grants; or
accept duplication and reconcile via an external key. Not decided — no second legal entity
exists yet to design against.

### Q11 — Stock valuation method: AVCO-only, or configurable per item/company
**Blocks:** Nothing in Phase 1. Must be resolved before Phase 2 (stock movement/valuation) is
built — [ADR 0007](decisions/0007-avco-valuation.md) itself says it needs superseding before
Phase 2 ships if FIFO turns out to be genuinely required.

ADR 0007 picked AVCO as one global valuation method for the whole application, reasoned from the
reference business: formulation-based mixing, where a specific receipt's physical identity is
lost the moment it's blended. Raised concern: a different kind of business running on this same
app — e.g. one that buys finished paint and resells it bundled with an application service,
rather than mixing it — has real batch identity through to the sale, and may want FIFO-based
cost attribution specifically to track margin accurately as purchase prices rise over time.

Two things already leave room for this without a redesign: `items.valuation_method` is a plain
string column ([items.md](domain/items.md)), not hardcoded into application logic, and
`stock_layers` (individual receipts — qty, unit cost, date) are retained even under AVCO for
exactly this kind of historical traceability, per ADR 0007's own "Requires" section. Not decided:
whether valuation method becomes genuinely configurable per item/company — meaning AVCO's
running-average recalculation and FIFO's layer-consumption logic would need to coexist, be
selected per item, and both post to GL correctly, which is real engineering work, not a small
toggle — or whether AVCO remains the one method and this business need is served a different way
(richer reporting off `stock_layers` without changing which cost basis actually posts).

### Q12 — Multiple suppliers per item (approved vendor list / sourcing)
**Blocks:** Nothing in Phase 1 — `items.default_supplier_id` covers today's need, a data-entry
default rather than a restriction. Relevant once Phase 2 Purchasing is designed.

`items` stores exactly one `default_supplier_id`; nothing stops purchasing the same item from a
different supplier once Purchasing exists — that history will live on individual purchase
orders/receipts, not on the item. Not decided: whether the app also needs a proper catalog of an
item's multiple suppliers (their own item code, price, lead time, a preferred flag) — the
"approved vendor list" pattern mature ERPs use — or whether querying purchase history is
sufficient on its own. No `purchasing.md` domain doc exists yet to resolve this against; raised
early so it isn't designed as an afterthought once Phase 2 starts.

---

## Answered

Answered questions live in [decisions/](decisions/) as ADRs. See the
[decision index](decisions/README.md) for the full list.
