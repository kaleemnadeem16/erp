[← Decisions](README.md)

# 0077 — Resolve material cost through one generic contract, with a configurable costing scope

- **Status:** accepted (design only — **no runtime change yet**)
- **Date:** 2026-10-01
- **Affects:** Estimating cost resolution (`IngredientCostResolver` and its four callers), a future read-only Inventory cost
  query, Phase 4 plan adoption and snapshots, [ADR 0072](0072-hand-built-stock-mix-in-base-quantity.md) and
  [ADR 0076](0076-company-control-over-hand-chosen-stock.md) (the mix), [formulations.md](../domain/formulations.md),
  [ADR 0032](0032-per-item-valuation-method.md) (valuation per item)

## Context

Today every material cost in an assembly or formulation explosion comes from one item-only function,
`IngredientCostResolver`. It supports exactly one configuration — AVCO with a single company-wide pool — and throws for FIFO,
SPECIFIC and warehouse- or batch-scoped AVCO, because a pure recipe is not tied to a warehouse and the function has no place,
quantity or chosen stock to cost from. It also returns a real `0` for an item that never received stock, which reads as "free".
Meanwhile the stock-mix feature already prices individual draws from the availability snapshot, so the two disagree about cost
basis for the same material. Phase 4 adoption must snapshot a cost basis that later stock movements cannot change.

Two questions needed an answer before any build: what shape should cost resolution take so it covers every valuation method, and
which stock does a recipe that is not tied to a warehouse cost from?

## Decision

**Nothing in the running system changes now.** `IngredientCostResolver` stays AVCO-only and keeps failing loudly until Phase 4
adoption is built against this design. This ADR fixes the contract, the scope rule and the principles so that work can adopt them
without redesign.

### 1. One contract, strategies behind it

A material cost is resolved from four inputs:

| Input | Meaning |
|---|---|
| Item | The material, which carries its valuation method |
| Required quantity | In the item's stock unit (the same canonical base quantity as [ADR 0072](0072-hand-built-stock-mix-in-base-quantity.md)) |
| Costing scope | `COMPANY`, or a named scope such as one warehouse (see 2); optional |
| Chosen stock | Optional explicit lines (location, batch, quantity) — the hand-built mix |
| As-of | When the cost is read (defaults to now) |

It returns a **resolution** carrying everything a snapshot needs:

- **status:** `COSTED`, `PARTIAL` (some quantity costed, the rest not) or `UNKNOWN`, plus a machine reason for anything but
  `COSTED` (for example `NO_COST_EVIDENCE`, `SCOPE_NOT_COSTABLE`, `METHOD_NOT_SUPPORTED`, `POOL_FROZEN`);
- **unit cost** and **total cost** — both `null`, never `0`, when unknown;
- **source type** — which family produced it (`INVENTORY_VALUATION` now; see 6) and the **method/strategy** within it;
- **scope requested** and **scope used**, with a flag when the used scope differs (for example a warehouse was asked for but the
  item's pool is company-wide);
- **basis** — the facts the number rests on, in a method-neutral list of entries: the pool and its average and status (AVCO),
  the layers and the quantity taken from each with their unit costs (FIFO), the batch or serial and its cost (SPECIFIC), or the
  chosen lines priced individually;
- **as-of** time, and a **fingerprint** over the basis, so a later read can say "the basis has changed".

Each valuation method is a **strategy** implementing one interface (`supports(item)`, `resolve(request)`), registered in a
registry keyed by method. AVCO, FIFO and SPECIFIC are three such strategies; a future method is a new strategy and no branch is
edited. An item whose method has no registered strategy resolves `UNKNOWN / METHOD_NOT_SUPPORTED` — never an exception that hides
the rest of an estimate, never a guess.

**Where it lives.** The strategies read pools, layers and batches, which are Inventory's data, and `LayerAllocator` is documented
as internal to the sole posting boundary. So the strategies live behind a new **read-only Inventory query** beside
`QueryStockAvailability` (reusing the same cost evidence, not calling the posting allocator). Estimating owns the *scope rule*
(2) and the *snapshot* (4), and calls the query; it never reads layers directly.

### 2. Costing scope — configurable, optional, layered

A **costing scope** says whose stock a recipe is costed from. It is either `COMPANY` (all the company's stock) or a named scope —
today a single warehouse; the type is open so a later cost group or site can be added without changing the contract.

It is resolved in this order, first that applies winning:

1. **Explicitly chosen stock** (a hand-built mix) — the mix *is* the scope; its lines are priced from the stock they name.
2. **The document's costing scope** — a quotation or plan may name one.
3. **The company's default costing scope** — a governed setting, `COMPANY` unless the company sets otherwise.
4. **Unknown** — if the selected or default scope cannot produce a valid cost, the result is `UNKNOWN` with the reason.

The company default is **not** a company-wide pool by decree. It is a setting with the same governed-history pattern as
[ADR 0071](0071-stored-material-selection-strategy-as-governed-history.md) and [ADR 0076](0076-company-control-over-hand-chosen-stock.md):
one row per change, reason, actor, future-only, resolved by scope. Company-wide *is* the built-in default, so a company that
never touches it behaves as today.

How a scope meets each item's own cost structure:

| The item's cost is… | Scope asked for | Result |
|---|---|---|
| AVCO, one company-wide pool | `COMPANY` or a warehouse | The company pool; scope used is `COMPANY`, flagged as differing when a warehouse was asked for (the item's cost simply isn't site-specific). |
| AVCO, pool per warehouse | A warehouse | That warehouse's pool. |
| AVCO, pool per warehouse | `COMPANY` | `UNKNOWN / SCOPE_NOT_COSTABLE` — averaging unequal pools is a policy this ADR does not invent. |
| FIFO | `COMPANY` or a warehouse | Layers in that scope, oldest first, **as if issued now** (3). |
| SPECIFIC | Any, without a chosen batch/serial | `UNKNOWN` — a specific item is costed by what is chosen; with chosen stock, from that stock. |

**UI stays optional.** A company on company-wide AVCO sees nothing new. The default-scope setting appears under Settings →
Material selection only when the company has more than one warehouse; the document-level scope control is part of an advanced
disclosure and hidden while the default is company-wide and nothing else is in play. Neither is required to get a cost.

### 3. Preview is read-only; FIFO means "as if issued now"

Cost resolution never locks, reserves or claims anything and never writes. A FIFO resolution lists the layers a hypothetical issue
*would* take at this moment, labelled an **estimate**. Real consumption stays a posting concern: when stock is actually issued,
`PostInventoryMovement` locks and allocates the layers it finds then, and the actual cost can differ. Nothing in the preview is
promised to posting.

### 4. Adoption snapshots the resolved cost basis

When a Phase 4 quotation revision adopts a plan, it stores the whole resolution per material — status, costs, source, scopes,
basis, as-of and fingerprint — with the revision. Later stock movements, receipts, recalculations or policy changes therefore
cannot change an approved estimate. Revalidation recomputes the fingerprint and may *report* that the basis has drifted; it never
silently rewrites the snapshot ([invariants 1 and 4](../architecture/invariants.md)). The Phase 4 design must accept the
resolution as an opaque, versioned value, so adding a field to the basis later does not invalidate old snapshots.

### 5. Unknown is never zero

An unresolvable cost is `UNKNOWN` and carries a reason. It is shown as unknown, excluded from totals with the total marked
incomplete (as `PARTIAL`), and is never treated as free or as passing a limit. This is already the rule for the stock-choice
control ([ADR 0076](0076-company-control-over-hand-chosen-stock.md), "not judged"). The current resolver's `0` for a never-received
item is a legacy exception; migrating the callers to the contract in Phase 4 is where it is retired, and that migration must
list every place a total silently absorbed it.

### 6. Estimating cost is a separate family, not inventory valuation

Standard cost, replacement cost and latest purchase price are *estimating* costs: they answer "what should this cost" rather than
"what did our stock cost". They are **not** added to inventory valuation methods. If wanted, they arrive later as another
strategy family selected by a separate estimating-cost policy, reported with a different source type, so a snapshot says plainly
which kind of cost it holds. The contract's `source type` field exists for this and nothing is built for it now. Such a family
would first need its own research and a revisit of the "not from a price list" rule in
[formulations.md](../domain/formulations.md).

## Consequences

- **Positive:** one answer to "what does this material cost, from what, and how sure are we", shared by the assembly preview, the
  variance preview, the mix and (later) adoption — removing today's disagreement between recipe cost and mix cost.
- **Positive:** every valuation method, and later ones, fit without a branch; a company that never configures anything sees no change.
- **Positive:** snapshots carry their evidence, so an approved estimate stays explainable and stable.
- **Negative:** the preview will show an *estimate* for FIFO, which can differ from what posting later consumes; the UI must say
  so every time.
- **Negative:** until Phase 4 adopts the contract, FIFO and warehouse-scoped materials still cannot be costed in assembly
  previews (unchanged, documented).
- **Negative:** one more setting and, later, one more field on a document; both are hidden for companies that do not need them.
- **Requires:** a read-only Inventory cost query with strategy tests per method; a governed default-scope setting
  (permission proposed as `estimating.costing_scope.manage`, not yet registered); a Phase 4 snapshot field that stores the
  resolution as a versioned value; a migration plan for the four current callers.

## Open points left to the implementation

- Whether `COMPANY` over per-warehouse AVCO pools should ever be a derived, quantity-weighted figure instead of `UNKNOWN`. Left
  `UNKNOWN` because it would be a new accounting policy.
- How a document names its scope when it spans several warehouses (this ADR resolves one scope per document line set).
- The exact fingerprint inputs per strategy; each strategy defines its own.

## Alternatives considered

**A FIFO-only path now** — rejected by the owner and by the analysis: it would hard-code one method, and its snapshot shape would
be decided before adoption exists.

**Company-wide scope only (every method)** — rejected: it can cost from a warehouse that will never make the job, so a
multi-site company's quotes would be wrong, and SPECIFIC has no company-wide meaning.

**A required document warehouse** — rejected: it exposes warehouse complexity to small companies that need none and gives no
answer when none is chosen.

**Cost from standard or latest purchase price instead of stock** — kept out of inventory valuation and deferred as a separate
estimating-cost family (6); it is a different source with its own maintenance burden and a conflict with the current formulation rule.

**Implement the abstraction now** — rejected (option B chosen): the snapshot and scope fields belong to the Phase 4 adoption
design, and shipping a resolver without them risks rework.

## Research basis

*Question:* how do mature ERPs choose the stock or price scope for a planned or estimated cost, and how do they keep that apart
from actual inventory consumption? *Accessed:* 2026-10-01. Existing project research was reused for the mix and the valuation
methods ([ADR 0072](0072-hand-built-stock-mix-in-base-quantity.md), [ADR 0032](0032-per-item-valuation-method.md)); this adds the
costing-scope question.

| System | What it does | Source |
|---|---|---|
| SAP S/4HANA / ERP | A **costing variant** says what a cost estimate is for and which prices value its materials; costing is performed at **plant** level (valuation area), with variants global or plant-local. Actual cost for a confirmed order uses a separate costing variant. Planned and actual are different valuations. | [Costing Variants](https://help.sap.com/docs/SAP_S4HANA_CLOUD/7f47a6d9441c46ac86c96fd27f6015f0/0a97d7531a4d414de10000000a174cb4.html), [valuation variant glossary](https://help.sap.com/doc/saphelp_ewm900/9.0/en-US/35/26b752afab52b9e10000009b38f974/content.htm?no_cache=true) |
| Oracle Cost Management (Fusion) | A **valuation structure** sets the granularity items are costed at — cost organisation (mandatory) plus optionally inventory organisation, subinventory, locator, lot, serial, grade — and is set per item through a cost profile. A **cost scenario** scopes planned standards to a cost organisation and cost book. | [Items, valuation units and cost profiles](https://docs.oracle.com/en/cloud/saas/supply-chain-and-manufacturing/25d/faims/items-valuation-units-and-cost-profiles.html), [Using Supply Chain Cost Management](https://docs.oracle.com/en/cloud/saas/supply-chain-and-manufacturing/19d/fapma/cost-planning.html) |
| Dynamics 365 Supply Chain | **Costing versions** hold *planned* costs (for simulation and to seed actual-cost items) separately from *standard* costs; item cost records can differ by **site**, effective date and status; the current active record estimates production cost, while base purchase and sales prices are explicitly *not* site-specific. | [Costing versions overview](https://learn.microsoft.com/en-us/dynamics365/supply-chain/cost-management/costing-versions) |
| Odoo | The costing method (standard, AVCO, FIFO) is set on the **product category**; cost is real-time per product, with no per-warehouse costing setting documented. | [Inventory valuation configuration](https://www.odoo.com/documentation/18.0/applications/inventory_and_mrp/inventory/product_management/inventory_valuation/inventory_valuation_config.html) |

*Common pattern:* the **scope of costing is a configuration, not a constant** — plant (SAP), valuation structure (Oracle), site
and version (Dynamics) — while a small system (Odoo) simply costs company-wide. All four keep **planned or estimated cost
distinct from actual inventory consumption** (separate costing variant, cost scenario, costing version; Odoo's difference being
that standard price is a separate method). *Differences:* SAP and Dynamics scope by plant or site, Oracle by a configurable
hierarchy of up to lot and serial, Odoo not at all; SAP and Dynamics let planned cost come from non-stock prices, which supports
keeping an estimating-cost family separate (6). *Fit:* a configurable scope with a company-wide built-in default serves Odoo-sized
and Oracle-sized companies alike, and a contract that reports the scope used and the basis gives Oracle-style traceability
without its hierarchy. *Remaining uncertainty:* the SAP costing-variant page could not be fetched directly (only the search-result
summary of it was available), so its detail rests on that summary and the glossary page; Oracle's granularity list comes from the
search summary of the cost-profile pages. Neither affects the decision, which only needs the pattern.

## Related

- [ADR 0032](0032-per-item-valuation-method.md), [ADR 0071](0071-stored-material-selection-strategy-as-governed-history.md),
  [ADR 0072](0072-hand-built-stock-mix-in-base-quantity.md), [ADR 0076](0076-company-control-over-hand-chosen-stock.md)
- [formulations.md](../domain/formulations.md), [material-resolution.md](../domain/material-resolution.md),
  [stock.md](../domain/stock.md)
- [Phase 3](../phases/phase-3-estimating-core.md)
