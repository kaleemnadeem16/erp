[← Decisions](README.md)

# 0014 — Modular monolith, not microservices

- **Status:** accepted
- **Date:** 2026-07-29
- **Affects:** all backend structure

## Context

An ERP spans many areas — inventory, sales, purchasing, production, projects, accounting, HR.
These are natural service boundaries, and the temptation to split them is strong.

But ERP data is densely interconnected and highly transactional. Confirming a job touches
stock reservations, the GL and job costing **in one transaction**. Distributed transactions
across services would replace a database guarantee with an application-level saga.

## Decision

A single Laravel application organised into modules by domain:

```
app/Modules/{Inventory,Sales,Purchasing,Production,Projects,Accounting,HR}/
    Models/  Actions/  Http/{Controllers,Requests,Resources}/  Events/  Listeners/
```

- Business logic lives in **Actions** (`CreateQuotation`, `PostJournalEntry`); controllers are
  thin
- **Modules communicate via events**, not direct calls. Sales fires `JobConfirmed`; Production
  and Inventory listen
- Plain PSR-4 folders — no `nwidart/laravel-modules`

## Consequences

- **Positive:** Real database transactions across module boundaries. This is the decisive
  argument.
- **Positive:** One deployment, one test suite, one migration history.
- **Positive:** Event-based boundaries mean a module *could* be extracted later if it ever
  genuinely needs to be — the seams already exist.
- **Positive:** The same event mechanism is what plugins hook into
  ([0010](0010-core-plus-plugins.md)), so it is continuously exercised.
- **Negative:** Module boundaries are conventions, not enforced by the compiler. Cross-module
  direct calls will creep in without discipline. Worth an architecture test.
- **Negative:** The whole application scales as a unit. Not a concern at this size.

## Alternatives considered

**Microservices** — rejected. Distributed transactions across densely coupled ERP data, for a
team of this size, with no scaling pressure.

**`nwidart/laravel-modules`** — provides structure and per-module migrations out of the box.
Rejected: its magic (separate autoloading, module registry, its own artisan commands) costs more
in confusion than plain namespaced folders save.

**Flat Laravel structure** (`app/Models`, `app/Http/Controllers`) — the framework default.
Rejected: at ERP scale, `app/Models` becomes 150 files with no indication of which belong
together, and there are no seams for plugins to attach to.

## Related

- [architecture/code-structure.md](../architecture/code-structure.md)
- [0010 — Core + plugins](0010-core-plus-plugins.md)
