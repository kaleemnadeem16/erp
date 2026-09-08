[← Domain index](README.md)

# Negative stock, overrides and settlement

The live negative-stock contract fixed by
[ADR 0037](../decisions/0037-controlled-negative-stock-overrides.md): company policy
configuration, authorised override workflows, provisional valuation and shortage settlement. The
core stock model these tables attach to is [stock.md](stock.md).

Availability is exact-location/tracking-specific and independent from valuation scope. Live
stock blocks by default; configured, permissioned overrides apply only to genuine
outbound/consumption classes. Internal transfers, missing serial/SPECIFIC identity, negative
physical-count results and other hard constraints are never overrideable.
Corrections/reversals use a separate workflow.

## Schema

```
inventory_policy_changes                -- immutable audit of governed settings
  id, company_id, policy
  old_values, new_values
  reason, changed_by, changed_at

negative_stock_override_requests        -- immutable shortage + policy snapshot
  id, company_id, item_id, location_id, batch_id NULLABLE
  movement_class, requested_qty, available_qty, shortage_qty
  source_type, source_id, reason_code, explanation
  authorisation_mode, allow_self_review, requested_by, requested_at, review_due_at NULLABLE

negative_stock_override_approvals       -- PRIOR_APPROVAL decision; one per request
  id, company_id, request_id, decision, notes, decided_by, decided_at

negative_stock_override_reviews         -- IMMEDIATE_WITH_REVIEW outcome; one per request
  id, company_id, request_id, outcome, notes, reviewed_by, reviewed_at

negative_stock_shortages
  id, company_id, item_id, location_id, batch_id NULLABLE, avco_pool_id NULLABLE
  qty_open, provisional_unit_cost, status
  source_move_id, override_request_id, settled_at NULLABLE

negative_stock_settlements            -- immutable partial/full inbound allocations
  id, company_id, shortage_id, inbound_move_id, qty_settled
  provisional_unit_cost, actual_unit_cost, unit_cost_difference
  valuation_adjustment, settled_at

stock_transfer_details                -- immutable AVCO pool identity a transfer's value moved
                                       -- between, kept for valuation and audit (ADR 0045: a
                                       -- transfer's rate is final at post time, never repaired)
  id, company_id, stock_move_id
  source_avco_pool_id NULLABLE, destination_avco_pool_id NULLABLE
```

## Policy configuration

`BLOCK` is the per-company default; the authorisation mode defaults to `PRIOR_APPROVAL`; review
SLA defaults to 24 hours and is bounded to 1–168 hours; independent review is the default;
sales/despatch and material consumption are eligible candidates while supplier returns and
scrap/write-off start disabled. Changing any of these fields requires the elevated
`inventory.negative-stock-policy.manage` permission, a reason, and an immutable tenant-safe
old/new audit record. Configuration does not itself authorise a movement — the request,
approval/immediate-review, shortage and settlement workflows remain explicit subsequent steps.

## Requests, approval and review

Override request, approval and review records are immutable, tenant-safe facts. Requests
snapshot exact location/tracking shortage, source, controlled reason, written explanation and
the then-current authorisation mode. Prior approval requires a different user with
`inventory.negative-stock.approve`; immediate mode requires override permission up front and
persists its review deadline, while review requires `inventory.negative-stock.review` and is
independent unless the snapshotted company policy explicitly permits self-review. Every use is
explicit, reasoned, immutably audited and tracked separately by review outcome and shortage
state (`OPEN`, `PARTIALLY_SETTLED`, `SETTLED`). Neither API nor UI applies one silently.

## Authorised outbound posting

The outbound movement boundary re-locks exact physical stock and the override request. A prior
request must have an immutable `APPROVED` decision; immediate mode was already permission-gated
at request time. If live shortage has worsened beyond the reviewed quantity, posting stops. If
stock has recovered, the issue posts normally and leaves the override unused. One request can
create at most one shortage and an already-open shortage at the same exact identity cannot be
deepened.

Supported quantity consumes ordinary layers first. Unsupported AVCO quantity uses the locked
pool's last valid official average; a pool that becomes zero or negative is frozen without
losing that average. Unsupported FIFO quantity uses the most recent compatible actual
acquisition cost. Missing or non-positive cost history blocks posting. The resulting move,
signed valuation feed and `OPEN` shortage are one transaction.

AVCO provisional cost uses the pool's last valid official average, then a specifically linked
trusted expected inbound/acquisition cost, then exactly one unambiguous eligible expected
inbound; multiple plausible sources or no defensible source block, and users never enter an
estimate. FIFO cost prefers a specifically linked PO/expected receipt/inbound; without a link,
exactly one unambiguous eligible expected inbound may be used. The system never chooses an
inbound merely because it is oldest, cheapest or nearest.

### Pool-level insufficiency never blocks physical stock

A multi-location AVCO pool can be dragged non-positive — or below one location's physical stock
— by an authorised shortage at *another* location of the same pool. Availability is
exact-location (Q13.2), so an issue whose source location physically covers it always posts:
nothing physically goes negative, no override is required and no shortage is created. The value
the pool cannot support posts at its last valid official average and the pool freezes at that
rate until inbound recovery. That provisional COGS is final — a later economic correction
belongs to [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md)'s
correction workflow, never a silent rewrite.

## Settlement

Inbound at an open shortage's exact identity — a receipt, or a transfer arriving at the
shortage's location — is routed through settlement before ordinary receipt averaging. Inbound
elsewhere in a frozen pool posts normally: the receipt joins the pool's quantity and value, the
blended average stands in until the shortage itself settles, and one location's shortage never
blocks receiving at the rest of the pool. A settlement never attaches to an incompatible
identity — compatibility is exact by company, item, location and batch, and PostgreSQL enforces
it.

Settlement locks compatible shortages oldest-first. Each allocation is immutable and snapshots
settled quantity, provisional and actual base costs, their difference, the valuation adjustment
and the inbound movement. Partial settlement leaves `PARTIALLY_SETTLED`; the final allocation
stamps `SETTLED` and `settled_at`. Receipt layers retain the full received quantity for
traceability but only inbound excess has `qty_remaining`, so settled quantity cannot be issued
again. A transfer that settles repairs each settled slice at the exact cost of the incoming
layer it consumes, not a weighted average that matches no consumed layer.

The inbound's ordinary positive valuation remains unchanged. A zero-quantity valuation-only row
appends `provisional − actual` for the settled quantity (`VALUATION_ADJUSTMENT` at an AVCO
pool), bringing the original provisional issue to actual cost without mutation. AVCO applies
receipt and repair together: a non-positive remainder stays frozen at its last valid average;
once positive, the recovered average re-derives from the pool's actual quantity and value. FIFO
performs the same append-only repair without creating a fake shortage layer.

## Transfers from a frozen pool

If a transfer's physical source location has sufficient quantity but its wider source pool is
non-positive, the transfer prices at the pool's frozen `last_valid_avg_unit_cost`. This never
permits a negative physical-source transfer — availability is still checked at the exact source
location — and there is nothing provisional about the resulting value.

Per [ADR 0045](../decisions/0045-avco-transfer-rate-final-not-provisional.md), that rate is
**final at post time**, exactly like a physically-covered issue drawn from the same frozen pool
(see "Pool-level insufficiency never blocks physical stock" above). A transfer's exact source
location is always fully physically backed — `PostInventoryMovement::transfer()` has no
negative-stock override path, and blocks any quantity exceeding physical availability there — so
a non-positive wider pool only ever reflects unrelated shortage debt at some *other* location
sharing that pool, never anything about the physical units this transfer is moving. A later
settlement of that unrelated debt, at whatever rate, never revalues an already-posted transfer:
there is no provisional flag, no repair, and no cascade between pools. `stock_transfer_details`
retains both pool identities for valuation and audit, but nothing on it is ever corrected.

This does not change how a transfer's *destination* settles an existing shortage at its own exact
location — see "Settlement" above (`applyTransferSettlements` repairs at the exact incoming layer
or pool rate consumed, unrelated to source-side pool freezing).

## Cutover openings

Cutover-only AVCO/FIFO negative openings require exact identity, defensible cost, approval,
resolution ownership/date and per-location/pool/company reconciliation. Acceptance immediately
creates an `OPEN_CUTOVER_SHORTAGE` linked to official opening state and governed by normal
compatible settlement and append-only repair; historical source negatives remain separate
migration evidence. Its target defaults to cutover plus 30 elapsed days; an alternative requires
explicit approval/reason, while overdue status escalates and reports but never creates an
automatic inventory adjustment. The opening grants no live authority and SPECIFIC/serial
negatives remain blocked. See
[inventory-cutover.md](inventory-cutover.md#dates-negative-balances-and-corrections).

## User interface

The negative-stock override HTTP surface is exposed per
[phase-2-purchasing-stock.md](../phases/phase-2-purchasing-stock.md#close-out): the header
(`NegativeStockOverrideRequest`) is create/read-only over HTTP (`POST`/`GET
/api/v1/negative-stock-override-requests`, `GET .../{id}`), and `POST .../decide` /
`POST .../review` are named actions on that *same* resource — unlike the cutover negative-opening
exception, decide/review don't get their own addressable resource, since a request only ever
receives the one its snapshotted `authorisation_mode` calls for. `UpdateInventorySettings` (the
4th action on the original close-out list) was already exposed via `PATCH /api/v1/inventory-settings`
before this work started.

There is no status column on the request — unlike physical counts and inventory cutover, this
table intentionally carries no derived state, only the immutable facts it snapshotted. The
`/negative-stock-overrides` list, create and `/negative-stock-overrides/:id` detail screens derive
a display status entirely client-side from `authorisation_mode` plus whether `approval`/`review`
is present (and, for a still-pending immediate review, whether `review_due_at` has passed). The
detail screen shows only the one of Decide/Review that's still outstanding for the request's mode,
same "refetch after every mutation, no optimistic state" rule as physical counts and cutover. This
queue produces the `override_request_id` an issue's own posting call already knows how to consume
(see "Authorised outbound posting" above) — the screen itself never posts a movement.

## Related

- [stock.md](stock.md) — the core movement, layer and pool model
- [stock-recalculation.md](stock-recalculation.md) — backdated recalculation and corrections
- [inventory-cutover.md](inventory-cutover.md) — controlled history and opening-state migration
- [ADR 0037](../decisions/0037-controlled-negative-stock-overrides.md) — the complete policy
- [ADR 0039](../decisions/0039-configurable-avco-valuation-pools.md) — AVCO pool scopes
- [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md) —
  correction workflow for later economic differences
- [ADR 0045](../decisions/0045-avco-transfer-rate-final-not-provisional.md) — a transfer's rate
  from a frozen pool is final, never repaired
