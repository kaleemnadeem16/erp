[← Docs index](../README.md)

# Roadmap

Eight phases, each independently shippable. Current state is in
[STATUS.md](../STATUS.md) — this file is the plan, that file is the progress.

| Phase | Name | Status | Detail |
|---|---|---|---|
| 0 | Foundation | In progress | [phase-0-foundation.md](phase-0-foundation.md) |
| 1 | Master data | Complete | [phase-1-master-data.md](phase-1-master-data.md) |
| 2 | Purchasing & stock | In progress | [phase-2-purchasing-stock.md](phase-2-purchasing-stock.md) |
| 3 | Estimating core | Not started | *expanded when reached* |
| 4 | Sales pipeline | Not started | *expanded when reached* |
| 5 | Job execution | Not started | *expanded when reached* |
| 6 | Finance | Not started | *expanded when reached* |
| 7 | Mobile & integrations | Not started | *expanded when reached* |

Phase docs are written in detail **when the phase is about to start**, using
[templates/phase.md](../templates/phase.md). Writing them all up front produces speculative
fiction that has to be rewritten anyway.

Screen ownership follows [user-interface.md](../architecture/user-interface.md). Each phase writes
its detailed interaction specification when it starts; backend completion alone does not defer an
operational UI silently to a later phase.

---

## Outline

### Phase 0 — Foundation
Environment and cross-cutting scaffolding, no business logic. Docker Compose, production
Dockerfile, Laravel + React skeletons, PostgreSQL-only CI, Sanctum auth, companies, users, roles,
audit log, UoM system, custom fields, numbering sequences, Scramble → Orval pipeline.

**No dependency on any open question — can start immediately.**
→ [phase-0-foundation.md](phase-0-foundation.md)

### Phase 1 — Master data
Items and categories, customers and suppliers (unified party model,
[ADR 0030](../decisions/0030-unified-party-model.md)), currencies and exchange rates (manual
entry, [ADR 0031](../decisions/0031-manual-exchange-rate-entry.md)), chart of accounts. The GL
account mapping on items is defined here even though posting comes later. Backend-only — no new
frontend screens.

*Formerly blocked by Q2 and Q9 — base currency and exchange-rate source now decided
([ADR 0024](../decisions/0024-onboarding-base-currency-and-fiscal-year.md),
[ADR 0031](../decisions/0031-manual-exchange-rate-entry.md)).*
→ [phase-1-master-data.md](phase-1-master-data.md) · [items.md](../domain/items.md) ·
[parties.md](../domain/parties.md) · [multi-currency.md](../domain/multi-currency.md)

### Phase 2 — Purchasing & stock
Purchase orders, supplier sourcing (`item_suppliers`,
[ADR 0033](../decisions/0033-item-suppliers-relationship.md)), goods receipt, warehouses and
locations, stock moves, per-item valuation (AVCO/FIFO/SPECIFIC,
[ADR 0032](../decisions/0032-per-item-valuation-method.md)), physical counts. The negative-stock
and backdated-receipt policies must be decided here. Inventory onboarding also establishes a
controlled cutover/opening state without weakening live policy
([ADR 0036](../decisions/0036-controlled-inventory-cutover.md)).
→ [phase-2-purchasing-stock.md](phase-2-purchasing-stock.md) · [stock.md](../domain/stock.md)

### Phase 3 — Estimating core
**The differentiator.** Formulations with versioning and customer overrides, rate dimensions,
application rates, assemblies, cost roll-up.
→ [formulations.md](../domain/formulations.md) · [application-rates.md](../domain/application-rates.md) · [assemblies.md](../domain/assemblies.md)

### Phase 4 — Sales pipeline
Leads, opportunities, quotations with revisions, cost snapshotting, PDF output. First
substantial UI work — mobile layout patterns should be settled before this. RTL is not
needed — English only ([ADR 0026](../decisions/0026-english-only-no-rtl.md)).

**Owes [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md)'s
multi-owner correction package**: once sold stock exists, a backdated-recalculation correction
delta touching it needs a Sales/COGS correction line — see
[stock-recalculation.md](../domain/stock-recalculation.md)'s "Not yet built."

*Formerly blocked by Q3 — tax model now decided
([ADR 0025](../decisions/0025-configurable-tax-engine.md)).*
→ [sales-pipeline.md](../domain/sales-pipeline.md)

### Phase 5 — Job execution
Jobs, work orders, material issue, timesheets, unified job cost entries, estimate-vs-actual
variance reporting. This is where the system starts paying for itself.

**Owes [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md)'s
multi-owner correction package**: once job-consumed stock exists, a correction delta touching it
needs a Job Execution job-cost correction line — see
[stock-recalculation.md](../domain/stock-recalculation.md)'s "Not yet built."
→ [job-costing.md](../domain/job-costing.md)

### Phase 6 — Finance
Double-entry GL, progress invoicing, receipts, allocation, customer ledger and statements,
aging, realised FX. The largest phase.

**Owes [ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md)'s
multi-owner correction package**: once the GL exists, a correction's economic allocation needs a
balanced-journal representation, not just Inventory's own valuation-adjustment feed — see
[stock-recalculation.md](../domain/stock-recalculation.md)'s "Not yet built."

*Formerly blocked by Q1 and Q3 — retention and tax model now decided
([ADR 0023](../decisions/0023-retention-retainage-enabled.md),
[ADR 0025](../decisions/0025-configurable-tax-engine.md)).*
→ [general-ledger.md](../domain/general-ledger.md) · [receivables.md](../domain/receivables.md)

### Phase 7 — Mobile & integrations
PWA polish, Capacitor wrapper, barcode scanning, push notifications, OAuth connection framework,
Gmail.
→ [ADR 0015](../decisions/0015-capacitor-for-mobile.md)

---

## Sequencing notes

**Phases 3–5 are the differentiator** and the hardest part. Phases 0–2 are table stakes, but
everything else depends on them being right — particularly stock valuation, which feeds every
cost figure in the system.

**Phase 6 could partially precede Phase 5** if invoicing matters more than variance reporting.
The GL is a hard dependency for neither, since [stock](../domain/stock.md) postings can be
buffered and posted once accounts exist.

**Do not build extension points speculatively.** The seams listed in
[extensibility.md](../architecture/extensibility.md) are enough. Further abstraction waits for a
real second customer ([Q8](../OPEN-QUESTIONS.md#q8--second-customer-profile)).

## Related

- [STATUS.md](../STATUS.md) — current progress
- [OPEN-QUESTIONS.md](../OPEN-QUESTIONS.md) — what's blocking what
- [templates/phase.md](../templates/phase.md)
- [User-interface delivery](../architecture/user-interface.md)
