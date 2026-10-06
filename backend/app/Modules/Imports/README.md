# Imports

Owns the reusable ADR 0027 mapping and staging boundary. It accepts arbitrary source rows,
applies a saved company-scoped mapping, validates the mapped rows against a target schema and
retains a previewable error report.

## Owns

- `ImportProfile` and its reusable `ImportMapping` rows.
- `ImportBatch` identity, source checksum and lifecycle.
- Immutable raw/mapped `ImportBatchRow` evidence and per-row validation results.
- `ImportTargetRegistry`, through which an owning domain registers schemas supported for a
  particular import purpose.

## Does not own

- Inventory, Purchasing, Accounting or any other domain table.
- Domain commit/finalisation. There is deliberately no generic commit callback: an owning
  module Action must consume a validated batch and enforce its own approval and reconciliation.
- File parsing or the mapping UI yet; this foundation starts at already-parsed source rows.

## Key actions

`CreateImportProfile` creates a saved mapping only when every target field belongs to a registered
schema. `StageImportBatch` maps rows, freezes a SHA-256 source identity and makes retries
idempotent within company and purpose. `ValidateImportBatch` records per-row schema errors and
freezes the batch for preview. Neither Action can write domain state.

Inventory opening profiles may also carry an approved `DEFAULT_IF_MISSING` transformation for a
non-critical target field or documented opening location. The value, reason, approver and approval
time are stored on the mapping; each application is copied into the immutable staged row. Critical
facts (including quantity/value, warehouse, item/UoM, valuation identity, batch and serial) cannot
be defaulted, and staging never splits a source balance across target locations (ADR 0046).

## Audit boundary

Staging re-queries the profile in the active company before loading mappings, so a hydrated
foreign profile cannot be used by a non-HTTP caller. Validation likewise re-queries its batch.
`ImportProfileTenantIsolationTest` proves the staging boundary; the entry-point architecture test
still forbids HTTP/console/job exposure until capabilities and staged-row projection are designed.

## Related

- [Data import](../../../../docs/architecture/data-import.md)
- [ADR 0027](../../../../docs/decisions/0027-generic-column-mapping-import.md)
- [Inventory cutover](../../../../docs/domain/inventory-cutover.md)
- [ADR 0046](../../../../docs/decisions/0046-strict-staged-inventory-cutover-reconciliation.md)
