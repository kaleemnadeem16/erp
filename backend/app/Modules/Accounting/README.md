# Accounting

Owns the chart of accounts and the fiscal calendar. Not double-entry posting itself — that's
Phase 6, per [ADR 0011](../../../../docs/decisions/0011-double-entry-open-item-ar.md).

**Governed by:** [docs/domain/general-ledger.md](../../../../docs/domain/general-ledger.md).

## Owns

- `ChartOfAccount` — hierarchical, company-scoped chart of accounts
  ([Phase 1](../../../../docs/phases/phase-1-master-data.md))
- `FiscalYear`, `AccountingPeriod` — the accounting calendar, created explicitly rather than
  auto-generated
- `JournalEntry`, `JournalLine` — not yet built; Phase 6

## Does not own

- Posting logic — `stock_valuation_entries` (Inventory, Phase 2) and other module-owned feeds
  produce the *inputs* to a posting; Accounting owns turning a feed into an actual balanced,
  immutable journal entry, not the feed itself
- Item/category GL-account defaults — those live on `Item`/`Category` (Inventory module); this
  module only owns the accounts they point at

## Key actions

None yet — `ChartOfAccount`/`FiscalYear`/`AccountingPeriod` so far have no Action beyond direct
create/update; add here once a real one exists (e.g. period close, in Phase 6).

## Events

No events emitted yet.

## Non-obvious

`ChartOfAccount.parent_id` is guarded identically to `Category.parent_id`
(`App\Support\Concerns\GuardsCompanyScopedHierarchy` — the same trait, not a duplicate
mechanism): no self-parenting, no cycles, parent must resolve in the current company.

`FiscalYear`/`AccountingPeriod` overlap is enforced with a Postgres exclusion constraint
(`EXCLUDE USING gist`), not an application check — see the migrations. A period falling inside
its fiscal year's date range *is* an application-level guard (`AccountingPeriod::booted()`) —
that containment can't be expressed as a same-table `CHECK`.

## Related

- [domain/general-ledger.md](../../../../docs/domain/general-ledger.md)
- [domain/items.md](../../../../docs/domain/items.md) — the GL mapping this module is referenced by
- [architecture/database.md](../../../../docs/architecture/database.md) — exclusion-constraint pattern
