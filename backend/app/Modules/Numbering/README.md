# Numbering module

The central document-numbering framework ([ADR 0079](../../../../docs/decisions/0079-document-numbering-system-templates-over-a-variable-registry.md);
guide: [document-numbering.md](../../../../docs/architecture/document-numbering.md)). It issues the number a document carries when it
becomes official. It knows nothing about the documents themselves: each hands it a *numbering context*.

## Owns

- Tables: `numbering_series`, `numbering_series_versions` (append-only), `numbering_series_rules`, `numbering_counters`,
  `numbering_counter_adjustments`, `numbering_allocations` (append-only, the frozen log), `numbering_constants`.
- Reads the company time zone from the existing `companies.accounting_timezone`; it adds no company column.
- `Support\NumberableDocumentType` / `NumberingRegistry`: the contract a module implements to make a document numberable.
- `Services\NumberingService::allocate(NumberingContext)`: the one entry point. `NumberingPreview` is the tester (never takes a
  number). `UniquenessChecker`, `TemplateValidator`, `VariableResolver`, `SeriesRouter`, `CounterStore`.
- Two allocation strategies behind `AllocationStrategy`: `LockedCounterStrategy` (GAPLESS) and `SequenceCounterStrategy` (ALLOW_GAPS).
- Actions: `EnsureDefaultSeries`, `CreateSeries`, `SaveSeriesVersion`, `CreateSeriesRule`, `SaveConstant`, `RaiseCounter`.
- `Http\\Controllers\\Api\\V1` + `routes.php` (`/api/v1/numbering/*`) and `Services\\NumberingOverview` (what the screen lists); the screens live in `frontend/packages/core-ui/src/features/numbering`.
- The `FiscalCalendar` port; Accounting fulfils it (`AccountingFiscalCalendar`).

## Adding a numbered document

See [document-numbering.md](../../../../docs/architecture/document-numbering.md#adding-a-numbered-document). In short: implement
`NumberableDocumentType`, register it from the module's provider, register its manage permission, and call
`NumberingService::allocate()` in the action that makes the document official.

## Rules to keep

- Never compute or edit a number outside the engine; a number is plain stored text and is never recomputed.
- Never put document-specific code in this module; a new variable is supplied by the document through its context.
- Versions, allocations, adjustments and constants are history: never edit or delete.
