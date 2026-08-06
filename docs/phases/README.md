[← Docs index](../README.md)

# Roadmap

Eight phases, each independently shippable. Current state is in
[STATUS.md](../STATUS.md) — this file is the plan, that file is the progress.

| Phase | Name | Status | Detail |
|---|---|---|---|
| 0 | Foundation | Not started | [phase-0-foundation.md](phase-0-foundation.md) |
| 1 | Master data | Not started | *expanded when reached* |
| 2 | Purchasing & stock | Not started | *expanded when reached* |
| 3 | Estimating core | Not started | *expanded when reached* |
| 4 | Sales pipeline | Not started | *expanded when reached* |
| 5 | Job execution | Not started | *expanded when reached* |
| 6 | Finance | Not started | *expanded when reached* |
| 7 | Mobile & integrations | Not started | *expanded when reached* |

Phase docs are written in detail **when the phase is about to start**, using
[templates/phase.md](../templates/phase.md). Writing them all up front produces speculative
fiction that has to be rewritten anyway.

---

## Outline

### Phase 0 — Foundation
Environment and cross-cutting scaffolding, no business logic. Docker Compose, production
Dockerfile, Laravel + React skeletons, dual-database CI, Sanctum auth, companies, users, roles,
audit log, UoM system, custom fields, numbering sequences, Scramble → Orval pipeline.

**No dependency on any open question — can start immediately.**
→ [phase-0-foundation.md](phase-0-foundation.md)

### Phase 1 — Master data
Items and categories, customers, suppliers, currencies and exchange rates, chart of accounts.
The GL account mapping on items is defined here even though posting comes later.

*Blocked by [Q2 — base currency](../OPEN-QUESTIONS.md#q2--base-currency).*
→ [items.md](../domain/items.md) · [multi-currency.md](../domain/multi-currency.md)

### Phase 2 — Purchasing & stock
Purchase orders, goods receipt, warehouses and locations, stock moves, AVCO valuation, physical
counts. The negative-stock and backdated-receipt policies must be decided here.
→ [stock.md](../domain/stock.md)

### Phase 3 — Estimating core
**The differentiator.** Formulations with versioning and customer overrides, rate dimensions,
application rates, assemblies, cost roll-up.
→ [formulations.md](../domain/formulations.md) · [application-rates.md](../domain/application-rates.md) · [assemblies.md](../domain/assemblies.md)

### Phase 4 — Sales pipeline
Leads, opportunities, quotations with revisions, cost snapshotting, PDF output. First
substantial UI work — mobile layout patterns and RTL ([Q5](../OPEN-QUESTIONS.md#q5--localisation-and-rtl))
should be settled before this.

*Partially blocked by [Q3 — tax model](../OPEN-QUESTIONS.md#q3--tax-model).*
→ [sales-pipeline.md](../domain/sales-pipeline.md)

### Phase 5 — Job execution
Jobs, work orders, material issue, timesheets, unified job cost entries, estimate-vs-actual
variance reporting. This is where the system starts paying for itself.
→ [job-costing.md](../domain/job-costing.md)

### Phase 6 — Finance
Double-entry GL, progress invoicing, receipts, allocation, customer ledger and statements,
aging, realised FX. The largest phase.

*Blocked by [Q1 — retention](../OPEN-QUESTIONS.md#q1--retention--retainage) and
[Q3 — tax model](../OPEN-QUESTIONS.md#q3--tax-model).*
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
