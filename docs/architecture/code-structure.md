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
      routes.php       registered by InventoryServiceProvider, not routes/api.php
    Parties/           Party, CustomerAccount, SupplierAccount — see ADR 0030
    Sales/
    Purchasing/
    Production/
    Projects/
    Accounting/
    HR/

Master data consumed by several modules (`Item`, `Party` and its accounts) gets its own module
rather than living inside whichever transactional module happens to consume it — splitting a
shared record by consumer (e.g. `CustomerAccount` inside `Sales`, `SupplierAccount` inside
`Purchasing`) just relocates the coupling every other consumer (Receivables, Payables, Production)
would still need, onto two modules instead of one.
  Providers/           AppServiceProvider + one per module/concern (AuthServiceProvider, …)
  Support/             cross-cutting: CompanyContext, Money casts, Sequences, UoM conversion
  Contracts/           interfaces plugins may rebind
```

### Routes

Each module — and each plugin, [extensibility.md](extensibility.md#rung-4--plugin-packages) —
owns a `routes.php` and registers it itself, from its own service provider's `boot()`, rather
than a central `routes/api.php` growing one section per module:

```php
// app/Modules/Inventory/InventoryServiceProvider.php
public function boot(): void
{
    Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/routes.php');
}
```

Register the provider in `bootstrap/providers.php`. `routes/api.php` itself stays deliberately
near-empty — `withRouting()` in `bootstrap/app.php` needs the file to exist, but nothing is
registered in it directly.

**Why not `routes/api.php` requiring each module's file instead?** That still means every new
module edits a shared file to register itself — cheap with two modules, a recurring
merge-conflict hotspot with several, and a hard blocker for plugins, which by definition can't
edit core at all. One pattern for both from the start avoids core quietly taking a shortcut a
plugin structurally can't.

**Gotcha:** only the file passed to `withRouting(api: …)` gets Laravel's `api` middleware group
and `/api` prefix applied automatically. A route file registered from a provider's own `boot()`
doesn't inherit that — add `Route::middleware('api')->prefix('api/v1')` explicitly, as above, or
the routes silently lose CORS, Sanctum's stateful handling and rate limiting.

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

### File size and splitting

A class stays readable below roughly **300 lines** — the same budget the docs use. When a class
outgrows that, split by responsibility behind the same public boundary rather than letting one
file accrete every concern:

- Keep the existing class as the narrow public entry point — nothing outside it changes, and
  tests keep targeting it.
- Move each cohesive concern into its own `final` collaborator class in a subnamespace named
  after the boundary, constructor-injected so the container wires it.
- Collaborators are internal to their boundary: say so in the class docblock, and never call
  them from anywhere else.

`PostInventoryMovement` is the exemplar: the sole stock-write boundary stayed a four-method
Action while receipt, issue, transfer, pool-state, settlement and repair-propagation logic each
live in an `Actions/Movements/` collaborator. The split changed no test, because tests exercise
the boundary.

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

### Reads versus writes across modules

"Modules do not call each other directly" governs **triggering behaviour** — the state-transition
fan-out above. It does not mean every cross-module reference needs an indirection layer:

- **Reads are direct Eloquent**, the same as any foreign key within one module.
  `formulations.customer_id → customer_accounts`, `items.default_supplier_id → supplier_accounts`
  — every `→ table` reference in the domain docs resolves this way. No repository or
  query-service layer exists in this codebase; introducing one for cross-module reads only would
  be new, unused indirection.
- **Writes stay behind the owning module's Actions.** Never mutate another module's tables
  directly. Call the owning module's Action class directly when the write is a synchronous
  requirement of the current request (e.g. Sales creating a customer inline mid-quotation calls
  `Parties\Actions\CreateCustomerAccount`); use an event when the write should happen as a
  decoupled *reaction* to a state transition instead — the `JobConfirmed` shape above.

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
