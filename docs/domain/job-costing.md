[← Domain index](README.md)

# Job Costing

Execution and, more importantly, **estimated versus actual**. Variance reporting is the reason
to build this system rather than a quoting tool.

```
Job → Work Order (make it) → Material Issue + Timesheets (do it) → Variance
```

## Where it sits

Created from an accepted quotation ([sales-pipeline.md](sales-pipeline.md)); consumes
[stock.md](stock.md); billed by [receivables.md](receivables.md). Built in
[Phase 5](../phases/README.md).

**Planning boundary:** the schema below is documented-only; R7 must reconcile multiple accepted
scopes, job multiplicity and operational gates under [R1 contracts](../phases/phase-4-r1-decision-matrix.md).
[ADR 0109](../decisions/0109-shared-commercial-quotation-lines-and-optional-estimating.md) allows non-Assembly commercial scope;
an accepted scope need not imply an Assembly baseline or even a job. R7 must establish applicable
estimate handover and fulfilment paths without fabricating estimates. Acceptance/preparation is not work authority, and ADR 0104 does not impose CustomerAccount at
every confirmed handover. Phase 5 owns execution.

[Initial R7 handover review](../phases/phase-4-r7-handover-research.md) is proposed under Q47.
[Accepted R6 commitments](sales-customer-decisions.md) are independent of Job creation; original
source/consent and baseline evidence cannot be rewritten by operational mapping or cancellation.
The schema below remains historical planning, not an approved one-quote/one-Job contract.

## Schema

```
jobs
  id, company_id, customer_id
  quotation_id                    -- the accepted revision
  number, job_date
  status        PLANNED | IN_PROGRESS | COMPLETED | CANCELLED
  site_address, custom_fields
  currency_id, fx_rate

job_lines
  id, job_id, quotation_line_id
  description, qty, work_uom_id
  estimate_snapshot   json        -- carried forward from the quotation
  contract_value                  -- for progress billing
  progress_pct                    -- work completed

work_orders                       -- production: mix the shade
  id, company_id, job_id NULLABLE
  formulation_version_id
  output_item_id, planned_qty, produced_qty, output_uom_id
  status       DRAFT | RELEASED | IN_PROGRESS | COMPLETED | CANCELLED

work_order_materials
  id, work_order_id, item_id
  planned_qty, issued_qty, uom_id
  -- issues create stock_moves

timesheets
  id, company_id, job_id, job_line_id NULLABLE
  employee_id, labour_item_id
  work_date, hours, hourly_rate
  status       DRAFT | SUBMITTED | APPROVED | POSTED

job_cost_entries                  -- UNIFIED actuals ledger
  id, company_id, job_id, job_line_id NULLABLE
  kind         MATERIAL | LABOUR | PLANT | SUBCONTRACT | OVERHEAD
  source_type, source_id          -- stock move | timesheet | purchase invoice
  qty, uom_id, unit_cost
  amount, currency_id, fx_rate, base_amount
  incurred_at
```

## Behaviour

### One unified actuals table

`job_cost_entries` is deliberate. Every actual cost — material issued, labour posted,
subcontract invoiced, plant used — writes a row with the same `kind` taxonomy as
[assembly components](assemblies.md).

Variance becomes one query:

```sql
SELECT kind,
       SUM(amount) AS actual
FROM job_cost_entries
WHERE job_id = ?
GROUP BY kind
```

compared against the estimate snapshot, grouped the same way.

Splitting actuals across five module-specific tables makes the single most valuable report in
the system expensive to produce — which means it doesn't get produced.

### Labour: estimated by rate, actual by timesheet

- **Estimate** — a rate per work unit on the assembly. This is how contractors quote.
- **Actual** — hours logged against the job, at the employee's or labour class's rate.

Both flow into the same comparison. This is the standard job-costing pattern.

### Work orders

Production consumes stock and produces a finished quantity. Material issue creates
[stock moves](stock.md) and posts `job_cost_entries` rows of kind `MATERIAL`.

A work order may exist without a job (making stock for inventory) or against a job (making it
for this customer).

### GL flow

```
Material issued   Dr WIP   Cr Inventory
Timesheet posted  Dr WIP   Cr Labour Absorbed
Job completed     Dr COGS  Cr WIP
```

See [general-ledger.md](general-ledger.md).

## Landmines

**Variance nobody looks at.** The report only has value if it is reviewed while the job is still
running. Build it into the job screen, not a separate reporting menu.

**Timesheet approval lag.** Hours logged but unapproved are real costs not yet visible. Decide
whether unapproved timesheets appear in variance — recommendation: **yes, flagged**, because
hidden cost is worse than provisional cost.

**Overhead allocated at the wrong time.** If overhead is applied at completion, in-progress jobs
look more profitable than they are. Apply progressively.

**Comparing against a stale estimate.** Variance compares actuals to the snapshot, never to a
recomputed current cost. If the estimate itself was wrong, that is a *quoting* problem and the
report should make the distinction visible.

**Rework and scope change.** Extra work must attach to the job as a variation with its own
estimate, not silently inflate actuals against the original. Without this, variance blames
execution for what was actually a scope change.

## Related

- [material-resolution.md](material-resolution.md) — standard/planned/actual material states and
  Phase 5 ownership
- [sales-pipeline.md](sales-pipeline.md) · [assemblies.md](assemblies.md)
- [stock.md](stock.md) · [receivables.md](receivables.md)
