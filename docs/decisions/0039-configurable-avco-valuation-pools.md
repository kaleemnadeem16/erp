[← Decisions](README.md)

# 0039 — Use constrained configurable AVCO valuation pools

- **Status:** accepted
- **Date:** 2026-09-01
- **Affects:** Phase 2 Inventory, stock valuation, transfers, negative-stock recovery, inventory
  cutover, backdated replay and Phase 6 Finance
- **Supersedes:** [ADR 0038](0038-company-wide-per-item-avco-pool.md), which fixed AVCO at
  company + item before migration-continuity requirements exposed legitimate warehouse- and
  batch-valued source structures

## Context

[ADR 0032](0032-per-item-valuation-method.md) chooses AVCO, FIFO or SPECIFIC per item, while
[ADR 0037](0037-controlled-negative-stock-overrides.md) deliberately applies AVCO recovery to the
applicable valuation scope. ADR 0038 initially made company + item the sole AVCO pool. That is a
safe operational default, but it cannot continue legitimate warehouse-item or batch-item AVCO
from predecessor systems without forcing an unnecessary valuation-policy conversion at cutover.

The stock engine has not been built yet. Defining explicit pool identity now avoids embedding a
company-item key into every average, shortage, transfer, cutover and replay record, while a small
supported set avoids speculative combinations and unsafe runtime configurability.

## Decision

Phase 2 supports exactly three AVCO scopes:

- `COMPANY_ITEM` — one pool across all company warehouses/locations for the item; the safe default.
- `WAREHOUSE_ITEM` — one pool per warehouse + item; bins in the warehouse share that pool.
- `COMPANY_ITEM_BATCH` — one pool per company + item + normalised batch identity; valid only for
  batch-tracked AVCO items.

`WAREHOUSE_ITEM_BATCH` and other combinations are deferred until a concrete migration or
operational requirement justifies them. Tracking, physical location and valuation-pool identity
remain separate concepts. Batch tracking does not automatically select batch valuation.

The company default is `COMPANY_ITEM` and is used only to resolve an AVCO item's initial setting.
The resolved `avco_pool_scope` is persisted on the item before its first stock movement and does
not dynamically inherit later default changes. It is non-applicable for non-AVCO items. After the
first movement, scope is locked. A later change requires a dedicated valuation-policy migration
with an effective date, opening/rebased pools, quantity/value reconciliation, historical-policy
preservation, approval/audit and future Finance reconciliation.

### Pool state and historical identity

`avco_pools` gives every resolved pool a stable identity and stores its company, item, scope,
optional warehouse/batch key, quantity, inventory value, current average, last valid average and
status. Database checks and partial unique indexes enforce the three valid shapes. Batch pools use
a normalised batch identity, never a free-form batch string as their key.

Every posted valuation entry references the immutable pool identity that governed it at posting
time. Historical entries never re-resolve scope from current item configuration. Shortage and
provisional-cost records retain their posting pool; transfer valuation effects retain source and
destination pools; replay requests retain affected pools; cutover retains source and target
scope/pool identities; policy transitions have effective dates.

### Transfers

A transfer within one resolved pool changes physical custody only: pool quantity, value and AVCO
remain unchanged.

A transfer across pools leaves the source at its official AVCO and enters the destination with
exactly the same base value. The destination AVCO is recalculated from its prior quantity/value
plus the transfer-in. The transfer is a pool inflow, not external acquisition cost. Source and
destination pool identities are retained, rounding cannot create/destroy company value and the
company's total quantity/value remain unchanged. ADR 0037's negative-physical-source block still
applies.

If the exact source location has sufficient quantity but its wider source pool is non-positive,
the transfer uses the last defensible provisional pool AVCO. Both sides are marked provisional
and linked to the source recovery chain. Later repair appends paired adjustments, propagates the
value difference into the destination and affected downstream dependencies, and preserves
company-value neutrality. This does not permit a negative physical-source transfer.

### Negative recovery and replay

ADR 0037's freeze/recovery rule applies independently to the specific configured pool. A negative
location does not freeze AVCO unless its pool is non-positive. When a pool recovers, shortages
and linked adjustments are applied first and exhausted pre-negative inventory is excluded from
the new positive average.

Backdated AVCO replay starts with the affected configured pool and propagates to another pool only
through an actual dependency such as a cross-pool transfer. It includes linked downstream jobs,
margins, shortages and valuation entries while excluding unrelated pools. Preview may
conservatively overestimate; execution adjusts only changed economic results, runs to
deterministic convergence, revalidates stale scope and respects closed-period immutability.

### Migration and cutover

Cutover can continue the same supported source scope by importing and reconciling each opening
pool's quantity, carrying value, derived average, constituent locations and source policy/identity.
If scope changes, source history retains its original scope and official value while cutover
records the approved target scope, constructs target pools from defensible quantity/value and
retains the policy transition.

Combining narrower source pools aggregates their approved quantity/value. Splitting a broad pool
requires defensible allocation of both quantity and carrying value; if source evidence cannot
support that allocation, the target is rejected or uses an explicitly approved revaluation basis.
The ERP never distributes value arbitrarily or rewrites history as though the target scope always
applied. Cutover reconciles exact locations, every target pool and company-wide control totals.

## Consequences

- **Positive:** Companies can continue common company-, warehouse- or batch-level AVCO without a
  forced cutover conversion.
- **Positive:** Explicit immutable pool identity makes transfers, shortages, replay and later
  valuation-policy migrations auditable.
- **Positive:** `COMPANY_ITEM` remains a simple safe default and unsupported combinations remain
  excluded.
- **Negative:** Cross-pool transfers, provisional repair and replay are substantially more complex
  than one company-item average.
- **Negative:** Pool scope adds item configuration, validation, cutover and reporting concepts.
- **Requires:** Concurrency locking targets resolved pool rows, with independent pools able to
  proceed independently.
- **Requires:** Phase 6 reconciles pool values to company inventory totals and preserves paired
  cross-pool value neutrality.
- **Requires:** Scope resolution/locking, transfer conservation, recovery, cutover and multi-pool
  replay receive real PostgreSQL tests before the valuation engine ships.

## Alternatives considered

**Company + item only (ADR 0038)** — rejected as the sole scope because it forces avoidable
conversions for legitimate warehouse- or batch-valued migrations and hard-codes the first stock
schema around one pool key.

**Architect generic pools but enable only company + item** — rejected because known migration
continuity requirements already justify the other two scopes; delaying their behaviour would
leave transfer and replay semantics unresolved.

**Support every geographic/tracking combination** — rejected as speculative. In particular,
`WAREHOUSE_ITEM_BATCH` remains deferred until a real requirement warrants its compound complexity.

**Make scope a mutable setting** — rejected because changing scope changes stock value meaning and
requires a controlled valuation migration, not an ordinary update.

## Related

- [ADR 0032 — Per-item valuation method](0032-per-item-valuation-method.md)
- [ADR 0036 — Controlled inventory cutover](0036-controlled-inventory-cutover.md)
- [ADR 0037 — Controlled negative-stock overrides](0037-controlled-negative-stock-overrides.md)
- [ADR 0038 — Company-wide AVCO pool](0038-company-wide-per-item-avco-pool.md)
- [Stock](../domain/stock.md)
- [Inventory cutover](../domain/inventory-cutover.md)
- [Phase 2 — Purchasing & stock](../phases/phase-2-purchasing-stock.md)
