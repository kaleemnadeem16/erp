[← Architecture](README.md)

# Data Import

How a client's existing data — customers, items, suppliers, opening balances — gets into the
system, without a bespoke script per client
([ADR 0027](../decisions/0027-generic-column-mapping-import.md)).

**Read when building or extending an import for a new entity.**

## The problem

Onboarding clients arrive with whatever software or spreadsheet layout they already used —
there is no common source column set to assume. A fixed source→target mapping per client does
not scale and re-derives the same logic by hand every time.

## The mechanism

```
target schema (per entity) ──┐
                              ▼
   upload  →  column mapping  →  staging table  →  validate  →  preview  →  commit
                   ▲
          saved mapping template (reusable per client)
```

1. **Target schema.** Each importable entity (customers, items, suppliers, opening balances, …)
   documents the fields it accepts — required, optional, types, reference lookups.
2. **Upload + mapping.** The user uploads any Excel/CSV. A mapping screen matches arbitrary
   source columns to target fields — the system never assumes source column names.
3. **Templates.** A completed mapping can be saved and reused, so repeat imports from the same
   client (e.g. a monthly opening-balance refresh) skip re-mapping.
4. **Staging.** Rows land in a staging table first, never directly into live tables.
5. **Validation.** Required fields, data types, and reference resolution (does this UoM/customer
   code already exist) run against staged rows. Failures are per-row, not all-or-nothing.
6. **Preview + commit.** The user sees a validation summary and error report before anything
   commits to live tables.

## Schema

```
import_profiles
  id, company_id
  entity_type        Customer | Item | Supplier | OpeningBalance | …
  name

import_mappings                     -- one saved template
  id, import_profile_id
  source_column       -- as it appeared in the client's file
  target_field
  transform            NULLABLE     -- e.g. date format, unit conversion

import_batches
  id, import_profile_id
  file_name, uploaded_at, uploaded_by
  purpose             STANDARD | INVENTORY_CUTOVER | FINANCE_OPENING
  status              STAGED | VALIDATED | COMMITTED | FAILED

import_batch_rows
  id, import_batch_id
  row_number
  raw_data            json          -- as uploaded
  mapped_data          json          -- after applying the mapping
  status              PENDING | VALID | INVALID | COMMITTED
  errors              json          NULLABLE
```

## Behaviour

### Unmapped columns

A source column with no matching target field doesn't have to be dropped — it can be routed into
the existing custom-field mechanism ([extensibility.md](extensibility.md), rung 3) instead, so
data a client tracks that core doesn't model yet isn't lost.

### Idempotency

Imports must be safe to re-run. Rows upsert by a natural/external key (e.g. the client's own
customer code), not insert-only — corrected files get resent in practice, and re-running an
import should not create duplicates.

### Opening balances are a special case

Opening balances (Phase 6) import against the same mechanism but need a defined cutover date and
post as a balancing journal entry, not bypass the GL — see
[general-ledger.md](../domain/general-ledger.md).

### Inventory cutover is a domain-controlled use of this engine

Inventory migration reuses these profiles, mappings, staging rows, previews and idempotency
controls; it is not a second import mechanism. The generic engine must not write stock tables or
bypass live Actions itself. An Inventory-owned cutover Action interprets approved staged data,
creates the method-specific opening state and records immutable source provenance.

Historical source facts may legitimately violate the company's live negative-stock or backdating
policy. They remain evidence inside a controlled pre-cutover batch and cannot be submitted through
ordinary stock APIs. See [inventory-cutover.md](../domain/inventory-cutover.md) and
[ADR 0036](../decisions/0036-controlled-inventory-cutover.md).

## Landmines

**Committing without a staging step.** The moment invalid rows can reach live tables directly,
a bad file corrupts real data instead of failing a validation report.

**Silent partial commits.** If some rows in a batch fail validation, decide and make visible
whether the valid rows commit anyway or the whole batch waits — don't let this be accidental.

**Reference resolution ambiguity.** A source value like a customer name, not a code, needs a
defined matching rule (exact, fuzzy, manual reconciliation) — silently guessing produces wrong
links that are hard to notice later.

## Related

- [extensibility.md](extensibility.md) — custom-field landing spot for unmapped columns
- [domain/general-ledger.md](../domain/general-ledger.md) — opening balance posting
- [domain/inventory-cutover.md](../domain/inventory-cutover.md) — inventory-specific cutover contract
- [ADR 0027 — Generic column-mapping data import](../decisions/0027-generic-column-mapping-import.md)
- [ADR 0036 — Controlled inventory cutover](../decisions/0036-controlled-inventory-cutover.md)
