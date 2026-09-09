# 0046 — Require strict reconciliation of the staged inventory opening state

- **Status:** accepted
- **Date:** 2026-09-09
- **Affects:** Phase 2, Imports, Inventory cutover, migration operations

## Context

A predecessor ERP rarely has the same schema as this system. Requiring identical source columns
would make valid migrations impossible, but treating missing stock detail as permission to infer it
would create quantities, values or identities with no trustworthy source.

The boundary that matters is therefore not the legacy export. It is the staged opening state that
Inventory approves and posts. V1 needs to distinguish an explicit, reviewable structural mapping
(for example, a warehouse balance mapped to an `OPENING-BIN`) from an invented allocation (for
example, splitting one company total between Lahore and Karachi without evidence).

## Decision

Use this pipeline: legacy data → mapping/transformation/defaulting → immutable staged opening
inventory → reconciliation → finalisation. The legacy schema may differ, but the staged state must
contain enough trustworthy detail to operate the target inventory model.

- Optional fields may remain null or receive an explicitly approved default.
- A missing location may default to an existing documented opening location inside the explicitly
  supplied warehouse. The default records its value, reason, approver and approval time in the
  immutable staged row. No warehouse or cross-location split is inferred.
- Source record, item, warehouse, valuation identity, quantity, UoM, amount/base amount, batch and
  serial facts cannot be defaulted. Missing required batch/serial identity or warehouse/location
  allocation keeps the cutover unresolved.
- Validation checks exact stock UoM, active stock-holding location, tracking identity, serial
  uniqueness, quantity/value arithmetic and FX/base-value arithmetic before approval.
- Finalisation independently proves the committed ledger equals staged facts by item and exact
  location, every target AVCO pool equals its recorded opening contributions, and company
  quantity/value controls equal the posted result. A `COMMITTED` batch flag alone is insufficient.

## Consequences

- **Positive:** Common warehouse-only legacy exports can use an approved opening bin without
  pretending that the source contained bins.
- **Positive:** Missing batch/serial or geographical allocation cannot silently become live stock.
- **Positive:** Raw input, mapped output and every applied default remain visible together.
- **Negative:** Companies with only company-level totals must perform an external count or
  reconciliation before finalisation; V1 will not spread balances for them.
- **Negative:** Mapping approvals add an operational step and require an authorised cutover
  approver before staging.
- **Requires:** Future transformations that allocate or alter critical facts need a new ADR and a
  dedicated approval/reconciliation model; they cannot be added as generic mapping helpers.

## Alternatives considered

**Require the legacy export to match the target schema exactly** — rejected because harmless schema
differences and absent optional metadata should not block a trustworthy migration.

**Auto-create opening batches or split company/warehouse totals** — rejected because those are
material inventory facts, not formatting transformations.

**Trust domain batch status at finalisation** — rejected because a status transition does not prove
that exact locations, valuation pools and ledger values were actually posted.

## Related

- [ADR 0027 — Generic column-mapping data import](0027-generic-column-mapping-import.md)
- [ADR 0036 — Controlled inventory cutover](0036-controlled-inventory-cutover.md)
- [Inventory cutover](../domain/inventory-cutover.md)
- [Data import](../architecture/data-import.md)
