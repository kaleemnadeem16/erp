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

## Related

- [Data import](../../../../docs/architecture/data-import.md)
- [ADR 0027](../../../../docs/decisions/0027-generic-column-mapping-import.md)
- [Inventory cutover](../../../../docs/domain/inventory-cutover.md)
