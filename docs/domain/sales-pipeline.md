[← Domain index](README.md)

# Sales Pipeline

From first contact to a confirmed job.

```
Lead → Opportunity → Quotation (revisions) → Job
```

## Where it sits

Consumes [assemblies.md](assemblies.md); produces the jobs tracked in
[job-costing.md](job-costing.md). Built in [Phase 4](../phases/README.md).

## Schema

```
leads
  id, company_id
  name, company_name, email, phone, source
  status          NEW | CONTACTED | QUALIFIED | DISQUALIFIED
  owner_id, custom_fields

opportunities
  id, company_id, customer_id NULLABLE, lead_id NULLABLE
  name, estimated_value, currency_id
  stage, probability_pct, expected_close_date
  owner_id

quotations
  id, company_id, customer_id, opportunity_id NULLABLE
  number                              -- from sequences, gapless
  revision_no
  supersedes_id   NULLABLE            -- previous revision
  quotation_date, valid_until
  currency_id, fx_rate
  status          DRAFT | ISSUED | ACCEPTED | REJECTED | EXPIRED | SUPERSEDED
  subtotal, tax_total, total, base_total
  terms, notes, custom_fields

  unique(['company_id', 'number', 'revision_no'])

quotation_lines
  id, quotation_id
  assembly_version_id  → assembly_versions
  description                          -- "Exterior wall, north elevation"
  qty, work_uom_id                     -- 250 sqm
  dimension_values     json            -- resolved rate context, e.g. {surface: rough}
  cost_snapshot        json            -- FULL resolved breakdown — immutable
  unit_cost, margin_type, margin_pct
  unit_price, line_total
  sequence
```

## Behaviour

### Lines are scope items, not products

A quotation line describes work:

> *"Exterior wall, north elevation — 250 sqm, Shade #4471, 2 coats, rough plaster"*

It references an assembly version, a quantity in the work UoM, and the dimension values needed
to resolve [application rates](application-rates.md).

### Revisions

Customers negotiate. A revision creates a **new quotation row** with `revision_no + 1` and
`supersedes_id` pointing at the previous one, which becomes `SUPERSEDED`.

Superseded revisions remain fully readable. "What did we quote in rev A?" must always be
answerable.

### The snapshot

On transition to `ISSUED`, each line's `cost_snapshot` is populated with the **fully resolved
breakdown**: every component, its quantity, its unit cost, the labour rates used, which
application rate was selected, and the FX rate.

This is [invariant 1](../architecture/invariants.md#1-snapshot-on-issue) and the most important
rule in the sales module. A `DRAFT` quotation recomputes live; an `ISSUED` one never does.

When material resolution is package-aware, the snapshot also keeps the recommendation strategy
and version, availability as-of time, generated suggestion, final user selection and internal-cost
evidence. This preserves what the estimate assumed; it does **not** reserve those packages.
Phase 5 revalidates and reserves physical stock. Customer selling price remains a separate Sales
decision even when internal package cost informed the estimate.

### Confirmation

Accepting a quotation creates a **job** ([job-costing.md](job-costing.md)), carrying the
snapshot forward as the estimate baseline. Fires `JobConfirmed`, which
[production and inventory listen to](../architecture/code-structure.md).

### Numbering

From the `sequences` table with row locking. Gapless —
[invariant 8](../architecture/invariants.md#8-gapless-document-numbering).

## Landmines

**Editing an issued quotation.** Must be blocked. Changes create a revision. Without this the
snapshot is meaningless.

**Losing the dimension context.** If a line does not record *which* application rate was
resolved, the cost cannot be explained later — only reproduced, and only if nothing changed.
Store the resolution, not just the result.

**Quote validity.** `valid_until` exists because costs move. An expired quotation should not be
acceptable without re-costing, and that check belongs in the confirmation Action.

**Partial acceptance.** Customers accept some lines and not others. Decide whether that creates
a revision or a partial job — this is a real workflow question that surfaces immediately in
practice and has no obvious default.

## Related

- [material-resolution.md](material-resolution.md) — cost/price separation and planned material
  snapshot boundary
- [inventory-packages.md](inventory-packages.md) — advisory package proposal and snapshot evidence
- [assemblies.md](assemblies.md) — what lines reference
- [job-costing.md](job-costing.md) — what confirmation produces
- [receivables.md](receivables.md) — invoicing against the job
