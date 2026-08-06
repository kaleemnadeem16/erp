[← Architecture](README.md)

# Code Structure

A modular monolith ([ADR 0014](../decisions/0014-modular-monolith.md)). One Laravel application,
modules split by domain, communicating through events.

---

## Backend layout

```
app/
  Modules/
    Inventory/
      README.md        ← what this module owns, key actions, events. REQUIRED.
      Models/          Item, StockMove, StockLayer, Warehouse
      Actions/         ReceiveStock, IssueMaterial, RecalculateAverageCost
      Http/
        Controllers/Api/V1/
        Requests/
        Resources/
      Events/          StockReceived, StockIssued
      Listeners/
      Policies/
    Sales/
    Purchasing/
    Production/
    Projects/
    Accounting/
    HR/
  Support/             cross-cutting: CompanyContext, Money casts, Sequences, UoM conversion
  Contracts/           interfaces plugins may rebind
```

## The layers

| Layer | Responsibility | Must not |
|---|---|---|
| **Controller** | Validate, call an Action, return a Resource | Contain business logic |
| **Action** | One business operation, transactional | Know about HTTP |
| **Model** | Relations, casts, scopes | Contain business logic |
| **Resource** | API response shape | Query |
| **Event** | Announce that something happened | Return values |

### Actions

Business logic lives in single-purpose classes:

```php
final class ConfirmQuotation
{
    public function __invoke(Quotation $quotation, User $by): Job
    {
        return DB::transaction(function () use ($quotation, $by) {
            // …
            event(new JobConfirmed($job));
            return $job;
        });
    }
}
```

Why: controllers stay thin enough that adding a second entry point (a queued job, a console
command, a mobile-specific endpoint) is trivial. This is also what keeps
[ADR 0002](../decisions/0002-api-first-react-spa.md)'s cost contained.

### Events between modules

Modules **do not call each other directly.**

```
Sales fires JobConfirmed
    ├─ Production  → creates work orders
    ├─ Inventory   → reserves stock
    └─ Accounting  → nothing yet, but the seam exists
```

Two benefits: modules stay independently understandable, and this is exactly the mechanism
plugins hook into ([extensibility.md](extensibility.md)) — so it is exercised continuously
rather than being untested plugin-only scaffolding.

**Constraint:** listeners that must be transactional with the emitting action run synchronously —
reserving stock for a confirmed job cannot happen "eventually". Queue only what genuinely
tolerates delay: emails, exports, report generation.

---

## Frontend layout

```
packages/
  core-ui/         components, screens, hooks, layouts
  core-api/        generated client + types (orval output, committed)
apps/
  main/            the standard product
  <customer>/      imports core-ui, overrides specific screens
```

```
core-ui/src/
  components/      shadcn primitives + shared composites (DataTable, MoneyInput, …)
  features/
    quotations/    components, hooks, routes for one feature
    items/
  layouts/
  lib/
```

Organised by **feature**, not by file type. A feature folder holds everything for that screen so
work stays local.

---

## Naming

| Thing | Convention | Example |
|---|---|---|
| Action | Verb phrase, `__invoke` | `ConfirmQuotation` |
| Event | Past tense | `JobConfirmed` |
| Listener | What it does | `ReserveStockForJob` |
| Resource | Model + `Resource` | `QuotationResource` |
| Table | Plural snake_case | `quotation_lines` |
| Enum column | Singular string + PHP enum | `status` → `QuotationStatus` |

## Finding your way around the code

[ADR 0022](../decisions/0022-code-documentation-strategy.md). Deliberately **not** a file-by-file
map — that rots silently and sends readers confidently to the wrong place.

| Tier | What | Maintenance |
|---|---|---|
| **Module `README.md`** | What the module owns, what it doesn't, key Actions, events emitted and consumed | Hand-written; changes when *responsibilities* change, not when a file is added |
| **`docs/reference/`** | Generated indexes of Actions, events, routes, schema | Regenerated in CI, **fails on diff** — cannot rot |
| **File header docblock** | Only where purpose isn't obvious from path and name | Lives with the code |

**The rule:** if a competent developer cannot guess what a file does from its location and name,
either **rename it** or add a header comment. Never add a line to a central map — renaming is
usually the better fix, and a map would have hidden the need for it.

A module README is required before a module's first feature merges. Template:
[templates/module-readme.md](../templates/module-readme.md).

## Testing

| Type | Scope |
|---|---|
| Feature | An endpoint end-to-end, against a real database |
| Unit | Actions and calculations — especially cost roll-up |
| Architecture | Module boundaries, no single-column unique indexes, no `float` on money |

Cost roll-up, FX and allocation logic need genuine unit coverage. They are the parts where a
subtle error produces plausible-looking wrong numbers rather than a crash.

## Related

- [extensibility.md](extensibility.md)
- [api-conventions.md](api-conventions.md)
- [ADR 0014](../decisions/0014-modular-monolith.md)
