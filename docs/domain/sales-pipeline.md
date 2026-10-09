[← Domain index](README.md)

# Sales Pipeline

From first contact to a confirmed job.

**R1 research complete (2026-10-09); implementation not started.** The
[accepted decision matrix](../phases/phase-4-r1-decision-matrix.md) is the current foundation contract:

- [0098](../decisions/0098-hybrid-sales-opportunity-requirement.md): optional Lead intake, hybrid
  opportunity policy, STRICT atomic replacement and archive-only legacy records.
- [0099](../decisions/0099-separate-quotation-issue-and-crm-association.md): consistent current
  family association, eligible OPEN targets and immutable per-revision issue linkage.
- [0100](../decisions/0100-independent-quotation-scopes-and-alternative-groups.md),
  [0101](../decisions/0101-separate-accepted-business-and-remaining-forecast.md),
  [0102](../decisions/0102-govern-forecast-trust-and-explicit-valuation.md): independent/exclusive
  scope, accepted versus remaining business, explicit forecast basis, persistent review and dated FX.
- [0103](../decisions/0103-issue-quotations-to-party-backed-prospects.md),
  [0104](../decisions/0104-separate-customer-acceptance-and-readiness.md): eligible Party-backed
  issue/valid acceptance before CustomerAccount; no automatic work/credit/financial authority.
- [0105](../decisions/0105-support-generic-party-identity-and-conversion.md): organisation/individual
  contracting identity, controlled reuse/restore/conversion, independent financial roles.
- [0106](../decisions/0106-separate-sales-ownership-and-access.md): eligible accountable owner,
  reassignment history and access separately governed.
- [0107](../decisions/0107-separate-opportunity-stages-and-outcomes.md),
  [0108](../decisions/0108-expose-a-progressive-basic-crm.md): stable outcomes/partial-win indication,
  reasoned closure/reopen, configurable stages and progressive basic CRM.

**The schema and behaviour sketch below are historical planning, not an accepted physical model.**
Customer-only recipient fields, one mutable opportunity link, simplistic win/job transitions and
numbering assumptions must be reconciled by R2–R9 before implementation. R1 does not choose tables,
revision mechanisms, partial-acceptance calculations, job multiplicity or accounting gates. Advanced
capabilities remain deferred. See the [Phase 4 plan](../phases/phase-4-sales-quotations-and-handover.md).

**R2 initial evidence (2026-10-09):** [structure/scope recommendations](../phases/phase-4-r2-quotation-structure.md)
await six owner decisions, including the Assembly-only conflict. They are proposals, not a new
accepted model; implementation and physical schema remain deferred.

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
decision even when internal package cost informed the estimate. Its resolver and issued-document
evidence are defined in [sales-pricing.md](sales-pricing.md): source/rule version, typed inputs and
intermediate results, calculated and final prices, rounding and any authorised override.

An adopted standard-to-planned material variance also snapshots ADR 0054's policy evaluation,
actor and any required reason/approval. A material change re-evaluates the policy and cannot carry
an approval from the earlier revision onto changed facts.

### Confirmation

Accepting a quotation creates a **job** ([job-costing.md](job-costing.md)), carrying the
snapshot forward as the estimate baseline. Fires `JobConfirmed`, which
[production and inventory listen to](../architecture/code-structure.md).

### Numbering

From the `sequences` table with row locking. Gapless —
[invariant 8](../architecture/invariants.md#8-document-numbers-are-unique-issued-by-one-framework-and-gapless-only-where-a-series-says-so).

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
- [sales-pricing.md](sales-pricing.md) — Phase 4 price resolution and quotation price snapshot
- [tax.md](tax.md) — tax determination after net price
- [inventory-packages.md](inventory-packages.md) — advisory package proposal and snapshot evidence
- [ADR 0054](../decisions/0054-risk-based-material-plan-variance-controls.md) — adoption audit and
  risk-based explanation/approval/blocking
- [assemblies.md](assemblies.md) — what lines reference
- [job-costing.md](job-costing.md) — what confirmation produces
- [receivables.md](receivables.md) — invoicing against the job
