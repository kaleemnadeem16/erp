[← Domain index](README.md)

# Inventory Cutover

How inventory from a predecessor system becomes an auditable opening state without weakening
normal stock controls. The generic mapping/staging mechanism is defined in
[data-import.md](../architecture/data-import.md); this document defines inventory semantics.

## Modes

| Mode | Imports | Does not pretend |
|---|---|---|
| Opening state | Quantity, carrying value and continuing valuation state at cutover | To contain historical movements |
| Operational cutover | Opening state plus open operational documents | That open POs are receipts or liabilities |
| Full history | Available source movements and official valuations, then an opening state | That incomplete detail is reconstructed fact |

The mode is selected per company and recorded on the cutover. Different entities may be loaded
in dependency order, but there is one inventory cutover timestamp and one reconciled inventory
opening state.

## Planning schema

```
inventory_cutovers
  id, company_id
  mode                         OPENING_STATE | OPERATIONAL | FULL_HISTORY
  cutover_at, source_system
  target_valuation_policy      json         -- per-item/category resolution snapshot
  status                       DRAFT | VALIDATED | APPROVED | FINALISED | FAILED
  approved_by NULLABLE, approved_at NULLABLE
  finalised_by NULLABLE, finalised_at NULLABLE
  control_quantity, control_value, base_control_value
  exception_summary            json NULLABLE

inventory_cutover_batches
  id, company_id, inventory_cutover_id, import_batch_id
  entity_type                  OPEN_DOCUMENT | MOVEMENT | LAYER | OPENING_STATE | …
  source_record_count, accepted_record_count, rejected_record_count
  source_quantity, source_value, base_source_value
  status

inventory_source_facts
  id, company_id, inventory_cutover_id, import_batch_row_id
  source_system, source_entity_type, source_record_id
  source_effective_at, source_posting_date NULLABLE
  item_id, location_id NULLABLE
  source_valuation_method, source_avco_scope NULLABLE
  source_valuation_pool_identity NULLABLE
  qty, uom_id
  unit_cost NULLABLE, amount, base_amount
  currency_id NULLABLE, fx_rate NULLABLE
  batch_no NULLABLE, serial_no NULLABLE
  source_payload                 json       -- immutable evidence, not live state
  UNIQUE (company_id, source_system, source_entity_type, source_record_id)

inventory_cutover_avco_pools
  id, company_id, inventory_cutover_id, item_id
  source_scope, target_scope
  source_pool_identity NULLABLE, target_avco_pool_id
  warehouse_id NULLABLE, batch_id NULLABLE
  opening_quantity, opening_value, base_opening_value, derived_average
  policy_transition_effective_at, revaluation_basis NULLABLE
```

Exact normalised layer/movement columns are reconciled during implementation. Source facts must
not be hidden solely in JSON when a field drives stock identity, valuation or reconciliation.

## Workflow and boundaries

1. Define source system, cutover timestamp, mode, target item valuation methods/scopes and control
   totals.
2. Load dependencies through the generic import staging engine: master data and mappings before
   open documents, movements, layers and opening state.
3. Validate references, tenant ownership, precision, UoM dimensions, currency/FX snapshots,
   serial uniqueness, layer totals and source-to-opening reconciliation.
4. Preview every rejection, warning, synthetic layer and negative-opening exception.
5. Approve using a dedicated cutover permission; commit idempotently through inventory-owned
   cutover Actions in a controlled maintenance window.
6. Reconcile quantity/value by item, exact location, target valuation pool and company total, then
   finalise. Finalisation freezes the batch
   and activates live [ADR 0037](../decisions/0037-controlled-negative-stock-overrides.md)/Q14
   policy after `cutover_at`.

The generic engine owns upload, mapping, staging and batch mechanics. Inventory owns validation
and state creation. Purchasing owns imported open POs. Finance later owns the balancing opening
journal. Cross-module writes use Actions; the importer does not write domain tables directly.

## Valuation continuity

### Same method after cutover

- AVCO reconciles exact-location quantities, every resolved target pool's quantity/carrying value
  and company-wide control totals under
  [ADR 0039](../decisions/0039-configurable-avco-valuation-pools.md). Continuing the same supported
  scope imports one opening pool per source identity; the official average is derived, never
  accepted as an independent total that may disagree.
- FIFO prefers remaining layers with quantities, costs and original dates. Summarised data creates
  one synthetic opening layer dated at cutover and marked as such.
- SPECIFIC requires every on-hand serial/unit and individual carrying cost. Missing identity is a
  blocking error; the company must supply detail or choose another target method through an
  explicit cutover decision.

### Method change at cutover

Historical facts keep their source method. The target method starts from a reconciled opening
state at `cutover_at`: FIFO→AVCO derives one opening average; AVCO→FIFO creates a synthetic opening
layer unless reliable residual-layer detail exists; any move to SPECIFIC requires exact units.
The item stores only the post-cutover official method. Provenance records what changed and why.

AVCO scope can likewise continue or change at cutover. Source history retains its historical
scope/pool identity and official value. Target pools are established from approved quantity/value,
with an effective-dated source→target policy transition. Combining source pools aggregates their
approved totals. Splitting a broad source pool requires defensible quantity and carrying-value
allocation; if evidence cannot support it, reject the target scope or use an explicitly approved
revaluation/cutover basis. Never distribute value arbitrarily or rewrite history as though the
target scope always applied.

## Dates, negative balances and corrections

Pre-cutover source dates are evidence and may show negative stock or backdating that live policy
would reject. They do not pass through live posting and do not authorise new exceptions.

A negative opening is permitted only for AVCO/FIFO through the dedicated cutover workflow when
forcing it to zero would falsify the predecessor system's approved closing position. It requires
company, item, exact location and known compatible batch where applicable; SPECIFIC, missing
serial identity and invented location/batch provenance are blocked. Required evidence includes
official source quantity and carrying value, source system and record identity, a defensible cost
under [ADR 0037](../decisions/0037-controlled-negative-stock-overrides.md), reason, supporting
evidence, responsible owner, resolution plan and target date.

Dedicated cutover approval and control-total reconciliation are required. Ordinary APIs, normal
negative-stock permissions, direct generic-import writes and reusable-template bypasses cannot
invoke this authority; cutover and live override permissions do not imply one another. Each
accepted exception creates an `OPEN` cutover-shortage record visible from go-live and settled by
supported inbound/corrective movements under ADR 0037. It creates no fake FIFO layer. AVCO
negative-opening and freeze/recovery treatment applies independently to the affected configured
pool, while cutover also reconciles exact locations and company-wide totals. It grants no right
to deepen the shortage after go-live outside normal ADR 0037 policy.

The target resolution date defaults to 30 elapsed days after cutover. A different date requires
explicit approval with the reason and approver retained. An overdue shortage escalates to its
owner/management and remains prominent in reporting, but never triggers an automatic receipt,
write-off, reversal or other invented event. Reconciliation retains predecessor closing totals,
imported opening totals, approved migration adjustments, accepted negative exceptions and the
resulting ERP opening totals for later inventory/GL reconciliation.

Posted operational transactions cannot be imported with an effective time after `cutover_at`.
After finalisation, corrections use immutable normal stock adjustments/reversals in an allowed
period. Source facts and committed batches are never overwritten.

## Completeness and degradation

Full history is accepted only to the level supported by the source export. Missing batch/serial,
layer, UoM, FX or value detail is reported explicitly. The implementation may downgrade the plan
to operational or opening-state migration; it must not invent precise history. Analytical replay
is available only where retained evidence supports it.

Acquisition-cost components are retained separately when supplied. They must not be collapsed
into product price, and open/unmatched source financial documents remain Phase 6 concerns.

## Related

- [Stock](stock.md)
- [Purchasing](purchasing.md)
- [Acquisition costing](acquisition-costing.md)
- [Data import](../architecture/data-import.md)
- [ADR 0036](../decisions/0036-controlled-inventory-cutover.md)
- [ADR 0037](../decisions/0037-controlled-negative-stock-overrides.md)
- [ADR 0038](../decisions/0038-company-wide-per-item-avco-pool.md)
- [ADR 0039](../decisions/0039-configurable-avco-valuation-pools.md)
