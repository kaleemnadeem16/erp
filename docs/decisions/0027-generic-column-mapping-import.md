[← Decisions](README.md)

# 0027 — Generic column-mapping data import

- **Status:** accepted
- **Date:** 2026-08-10
- **Affects:** Phase 1 (core engine), Phase 6 (opening balances), [architecture/data-import.md](../architecture/data-import.md)

## Context

[Q6](../OPEN-QUESTIONS.md) flagged that onboarding clients need existing data — customers,
items, suppliers, opening balances — migrated in. The user was explicit that a fixed
source-column → target-column script per client does not scale: clients arrive with whatever
software or spreadsheet layout they already had, with no common column set to assume. The
product is also intended to be sold to more than one company eventually
([ADR 0010](0010-core-plus-plugins.md)), which rules out hand-built one-off import scripts as a
standing practice.

## Decision

Import is a **generic, entity-agnostic mapping engine**, not per-client scripts:

- Each importable entity (customers, items, suppliers, opening balances, …) exposes a documented
  target field schema.
- A user uploads any Excel/CSV. The system does not assume source column names — a mapping step
  lets them match arbitrary source columns to target fields.
- Mappings can be **saved as reusable templates** per client, so repeat imports (e.g. monthly
  balance updates) don't require re-mapping.
- Import runs through a **staging table** first: validates required fields, data types, and
  reference lookups (e.g. does this UoM code exist), then shows a preview and an error report
  before anything commits.
- Unmapped source columns can optionally be routed into the existing custom-field mechanism
  ([extensibility.md](../architecture/extensibility.md), rung 3) instead of being silently
  dropped.
- Re-imports are **idempotent** — upsert by a natural/external key, since corrected files get
  resent in practice.

## Consequences

- **Positive:** one engine covers every client regardless of their source software, instead of
  bespoke onboarding engineering each time — fits the whitelabel direction.
- **Positive:** the custom-field synergy means data a client tracks that core doesn't model yet
  isn't lost at import time.
- **Negative:** this is a real subsystem — staging tables, a mapping UI, a validation layer — not
  a one-off CSV parser. It's meaningfully more work than a fixed-format import script.
- **Requires:** an `import_profiles` / `import_mappings` schema per entity, a staging-table
  pattern, and per-row error reporting rather than all-or-nothing failure.

## Alternatives considered

**Fixed per-client scripts** — rejected. Doesn't scale past the first customer, and re-derives
the same mapping logic by hand every time.

**One canonical spreadsheet template clients must conform to** — rejected. Explicitly ruled out:
real clients arrive with varied existing software and column layouts; forcing them to reformat
before import pushes the mapping work onto the client instead of the system.

## Related

- [architecture/data-import.md](../architecture/data-import.md)
- [architecture/extensibility.md](../architecture/extensibility.md) — custom-field landing spot for unmapped columns
- Q6 — Data migration from an existing system *(resolved, removed from open questions)*
