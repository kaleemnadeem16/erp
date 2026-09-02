[← Architecture](README.md)

# Extensibility

How different companies get different behaviour without forking the application
([ADR 0010](../decisions/0010-core-plus-plugins.md)).

**Read when handling a per-customer difference.**

---

## The variation ladder

Always use the cheapest rung that works.

| Rung | Mechanism | Handles | Cost |
|---|---|---|---|
| 1 | **Configuration** | Tax rules, numbering formats, margin type, approval thresholds, terminology | Settings change |
| 2 | **Company master data** | Rate dimensions, UoMs, assemblies, formulations, chart of accounts, workflow states | Data entry |
| 3 | **Custom fields** | "We also track site supervisor" | No migration, no deploy |
| 4 | **Plugin package** | Genuine behavioural change — a different pricing algorithm | A Composer package |
| 5 | **Fork** | Last resort. Should be rare enough to be notable. | Permanent divergence |

Rungs 1–3 absorb most of what initially looks like a code requirement. The formulation model is
itself an example: *Surface Type* is not a core concept but a company-defined rate dimension
([ADR 0009](../decisions/0009-company-defined-rate-dimensions.md)), so a screeding company
defines *Thickness (mm)* instead and core never changes.

---

## Rung 4 — plugin packages

```
erp-core/              the product. stable. everyone gets it.
erp-plugin-acme/       Composer package, Acme-specific
erp-plugin-bravo/      Composer package, Bravo-specific
```

Deployment is core + that customer's plugin, built as one image
([ADR 0013](../decisions/0013-docker-dev-and-production.md)). Fixes propagate via `composer
update`. A plugin's service provider registers its own routes, migrations, models, listeners,
policies and container bindings without touching core.

### Extension points core must provide

These must exist from the start. Retrofitting seams into a codebase that never had them is most
of a rewrite.

#### Events on every state transition

```
QuotationIssued · QuotationApproved · JobConfirmed · WorkOrderReleased
MaterialIssued · TimesheetPosted · InvoicePosted · ReceiptAllocated · CostRolledUp
```

Plugins subscribe; core never knows they exist. This is the same mechanism modules use to talk
to each other ([code-structure.md](code-structure.md)), so it is continuously exercised.

#### Container-bound contracts

Core depends on interfaces and binds defaults. A plugin rebinds one line.

```php
interface PricingEngine   { public function priceFor(QuotationLine $line): Money; }
interface CostingStrategy { public function rollUp(AssemblyVersion $v, Context $c): CostBreakdown; }
interface NumberingScheme { public function next(string $docType, Company $c): string; }
interface ApprovalPolicy  { public function requiresApproval(Document $d): bool; }
interface TaxResolver     { public function resolve(InvoiceLine $line): TaxResult; }
```

```php
// erp-plugin-acme/src/AcmeServiceProvider.php
$this->app->bind(PricingEngine::class, AcmeTieredPricing::class);
```

#### Pipelines

Quote confirmation, invoice posting and cost roll-up run as pipelines, so a plugin can insert a
stage without rewriting the flow.

```php
app(Pipeline::class)->send($quotation)->through(config('erp.pipelines.quote_confirmation'));
```

#### Runtime custom fields

The highest-leverage feature for avoiding forks — adding a field must not require a developer.

```
custom_field_definitions
  id, company_id
  entity_type       Quotation | Item | Customer | Job | …
  code, label
  data_type         string | number | date | bool | select | reference
  options json, validation json
  is_required, is_searchable, sort_order

-- storage: `custom_fields` json column on each extendable table
```

Stored as **`jsonb` with a GIN index** ([database.md](database.md#jsonb-for-custom-fields)), so
containment and path queries are genuinely fast rather than merely working. This is what makes
rung 3 a real alternative to a plugin instead of a slow one.

Laravel's `where('custom_fields->x', …)` remains the default; raw JSONB operators are for
containment searches where they earn their place.

The frontend renders these generically from the definitions, so a new field appears in forms,
tables and filters with no deploy.

#### Config-driven workflow

```
workflow_states       entity_type, code, label, is_initial, is_terminal, sort_order
workflow_transitions  from_state_id, to_state_id, required_permission, guard_class
```

Statuses and permitted transitions are data, not a hardcoded enum. A customer needing an extra
approval step configures it.

---

## Progressive capability modes

When a domain needs both a low-hassle path and richer behaviour, prefer a small number of
cohesive company-level modes over many independent feature flags. The database may support the
superset, while Actions validate the selected mode and the UI reveals only relevant controls.
Defaults must be safe and simple; enabling a mode may introduce conditional requirements for
that company, and downgrade must be refused while incompatible data exists. Inventory locations
are the first implementation of this pattern — see
[ADR 0041](../decisions/0041-progressive-inventory-location-capabilities.md).

Use a plugin only for genuinely customer-specific behaviour, not for a broadly useful capability
that belongs in the core domain. Do not reserve speculative nullable columns for unknown needs;
add advanced fields when their consuming workflow and invariants are known.

## Frontend extensibility

Usually forgotten, and harder than the backend. Handled by **build-time composition**, not a
runtime plugin registry — simpler, type-safe, no dynamic-import machinery.

```
packages/core-ui/     components, screens, hooks
packages/core-api/    generated client + types
apps/acme/            imports core-ui; overrides specific screens
```

Vite builds per customer. Shared fixes land in `core-ui` and every app picks them up on the next
build.

For lighter variation, core screens expose named slots:

```tsx
<QuotationForm slots={{ afterLineItems: <AcmeSiteDetailsPanel /> }} />
```

---

## The risk

The extension points add roughly **15–25%** to the initial build, repaid at the second customer.

The opposite failure is equally real: over-abstracting core into unreadable indirection before
any evidence of where variation actually occurs.

**Mitigation: build seams only at the extension points listed above.** Everything else stays
concrete until a real second customer creates real evidence. See
[Q8](../OPEN-QUESTIONS.md#q8--second-customer-profile).

## Related

- [ADR 0010](../decisions/0010-core-plus-plugins.md)
- [ADR 0009](../decisions/0009-company-defined-rate-dimensions.md)
- [code-structure.md](code-structure.md)
- [ADR 0041](../decisions/0041-progressive-inventory-location-capabilities.md)
