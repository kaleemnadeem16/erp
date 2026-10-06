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

The frontend renders supported fields generically from their definitions, so adding a field
to an exposed form needs no deploy. Current form coverage and the deferred generic table/filter
surfaces are recorded in the implementation section below.

#### Config-driven workflow

This is a planned extension-point sketch, not an implemented engine:

```
workflow_states       entity_type, code, label, is_initial, is_terminal, sort_order
workflow_transitions  from_state_id, to_state_id, required_permission, guard_class
```

Statuses and permitted transitions may eventually be data rather than a hardcoded enum. The two
tables above are enough for a simple permitted transition, but **not** for maker/checker,
multi-stage or multi-department approval: those also need versioned policies, steps, counts,
routing inputs and immutable decisions. See
[approval-workflows.md](approval-workflows.md); do not implement multiple approvals by putting
more meaning into one `required_permission` column.

---

## Progressive capability modes

When a domain needs both a low-hassle path and richer behaviour, prefer a small number of
cohesive company-level modes over many independent feature flags. The database may support the
superset, while Actions validate the selected mode and the UI reveals only relevant controls.
Defaults must be safe and simple; enabling a mode may introduce conditional requirements for
that company, and downgrade must be refused while incompatible data exists. Inventory locations
are the first implementation of this pattern — see
[ADR 0041](../decisions/0041-progressive-inventory-location-capabilities.md). Assembly overhead
policy is the second — see
[ADR 0049](../decisions/0049-assembly-group-overhead-scope.md).

Three concerns stay separate even though one feature touches all three:

1. **Domain capability** — what the backend supports. Build this at the mature/general level
   where a requirement is credible across company sizes, not scaled down because a small company
   won't use most of it today.
2. **Company configuration** — which parts of that capability a given company actually uses.
   Usually just data: rows a company creates (or doesn't) in optional tables and optional
   relationships, not a schema difference between "simple" and "advanced" companies.
3. **UI exposure** — which of the supported capability a screen shows. A small company's settings
   screen may expose one field; an advanced company's the full engine underneath it. Both read and
   write through the same domain/policy code — never a simplified reimplementation for the small
   case and a real one for the advanced case.

*Which* mechanism eventually decides how much UI a given company sees — a company setting, a
permission, a subscription/package tier, deployment configuration — is a packaging decision
separate from the domain design, and doesn't need to be settled to ship the capability: as long as
the advanced parts are optional relationships or optional rows, a company that never touches them
carries no UI and no awareness of them for free.

### Enablement vs. access: two separate axes, not one toggle

When a capability's UI-exposure mechanism *is* being decided (an advanced section behind
`AdvancedOptionsMenu`/`OptionCards`, not a one-off), keep two axes separate rather than reaching
for a single flag. Primary-source research across SAP and Microsoft converges on the same shape:

- **Enablement — company-wide, never per-user.** SAP's Switch Framework activates a Business
  Function for the whole client, gated by a dedicated `S_SWITCH` authorization object distinct
  from the ordinary roles that use the resulting functionality
  ([SAP Help — Switching on Business Functions](https://help.sap.com/docs/ABAP_PLATFORM_NEW/1d51ac4c098042528cadb1d5bcb1ab23/de899442047b0831e10000000a1550b0.html)).
  Dynamics 365 Business Central's Feature Management is explicit that this is the *only* shape it
  offers: "Business Central doesn't let you turn on a feature for a single user or group of
  users... [an] enabled feature applies to all users of an environment," and turning it on
  requires its own admin-only permission set, separate from the permission sets governing daily
  use of what gets enabled
  ([Microsoft Learn — Enabling upcoming features ahead of time](https://learn.microsoft.com/en-us/dynamics365/business-central/dev-itpro/administration/feature-management)).
  This repo already ships exactly this shape — reuse it rather than inventing another: a typed
  company-scoped settings row (`PurchasingSetting`, `InventorySetting`), changed only by an actor
  holding a dedicated `<module>.<policy>.manage` permission, with a mandatory reason and its own
  `<module>.policy-history.view` audit-read permission, separate from `.manage`
  (`purchasing.over-receipt-policy.manage` / `purchasing.policy-history.view` are the shipped
  example — see [authorization.md](authorization.md)).
- **Access, once enabled — the *existing* permission that already gates the base capability, by
  default.** None of the researched systems layer a second, feature-specific permission on top of
  an enabled feature merely to decide who encounters it; Business Central's own model is that an
  enabled feature is simply "available," and the page/action permissions a user already has decide
  what they can do with it. Add a genuinely new, narrower permission here only when the advanced
  capability is more sensitive than the base one it extends (authorization.md's per-feature
  checklist, question 4) — not by default, and not merely because the section is labelled
  "advanced."

**Company-wide is the only enablement scope this pattern covers today.** A narrower,
delegated-to-a-department-head enablement authority is a real question — none of the systems
researched above support it either, they only ever gate *who may flip the company-wide switch*,
never a sub-company enablement scope — and it depends on
[Q26](../OPEN-QUESTIONS.md#q26--organisational-and-record-level-access-scope)'s still-undecided
organisational-unit model. Until Q26 resolves, treat enablement as company-scoped only, gated by
whichever actor already manages other company-wide settings; do not build a department-scoped
enablement mechanism speculatively ahead of that decision.

**A UI/feature-visibility toggle must never itself be a historical fact.** Turning an advanced
view on or off must not retroactively change, hide the effect of, or invalidate already-effective
configuration underneath it — visibility and execution are different concerns, and only execution
changes (posted facts, effective policies) need the
[ADR 0040](../decisions/0040-control-backdated-stock-recalculation-and-corrections.md)-style
correction-not-silent-rewrite discipline. A downgrade may be *refused* while it would orphan
visible, in-use configuration (the same rule ADR 0041 already applies to location-mode downgrades)
— it must never *silently discard or reinterpret* that configuration instead.

Use a plugin only for genuinely customer-specific behaviour, not for a broadly useful capability
that belongs in the core domain. Do not reserve speculative nullable columns for unknown needs;
add advanced fields when their consuming workflow and invariants are known.

For storage, one stable cross-cutting setting may be a typed `companies` column. A cohesive group
of module policies uses a typed one-row-per-company module settings table, as Purchasing does.
JSONB is reserved for flexible, low-risk preferences rather than posting, valuation or approval
policy. If a module outgrows a lone company column, migrate it into its settings table without
changing the public capability contract.

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

### Implemented custom-field settings (2026-10-06)

Settings → **Custom Fields** (`/settings/custom-fields`) lists and adds company-scoped definitions
through `/api/v1/custom-field-definitions`. Administration owns configuration;
`administration.custom-field.manage` is required at HTTP and Action boundaries. Existing full
administration bundles (both role management and access assignment, held in the same role or
as direct grants in the same company) receive that one new capability through an additive,
idempotent migration. Other grants are assigned through Access Administration; no role name is
hardcoded. Run `permission:register` and the usual administrator sync during release.

The entity registry admits `items`, `parties`, `supplier_accounts` and `customer_accounts`, the
four models with implemented storage and validation. Definition reads require configuration
management or maintenance authority for the requested entity; lists remain company-scoped.
The setting supports text, number, date, yes/no and bounded choice. Reference definitions remain
reserved until there is a tenant-safe target registry/picker. Codes/types are fixed: the initial
UI adds definitions, without deletion or type changes that could invalidate stored values.

Items create/edit and supplier registration/edit render definitions generically; new trading
partners created during supplier registration use their own definitions. Customer and independent
Party forms do not exist yet, so configuring those definitions does not invent a new screen.
Item detail displays saved values. Creation validates even omitted custom-field payloads;
blank required text is refused, while `false` and numeric zero are valid. Omitted values on a
partial update preserve existing data. Existing records are not backfilled when a required field
is defined; entering their custom fields subsequently validates the complete value object.

These are descriptive extension fields, not an alternative to governed money, pricing, stock or
approval fields. Generic list columns/search/filter UI and reference editing remain later work.
The opt-in `CustomFieldsDemoSeeder` installs three optional Item fields and values on
`demo-pack-thinner`; reruns preserve operator values and definition labels.

## Related

- [ADR 0010](../decisions/0010-core-plus-plugins.md)
- [ADR 0009](../decisions/0009-company-defined-rate-dimensions.md)
- [code-structure.md](code-structure.md)
- [ADR 0041](../decisions/0041-progressive-inventory-location-capabilities.md)
- [ADR 0049](../decisions/0049-assembly-group-overhead-scope.md)
- [Approval workflows](approval-workflows.md)
- [authorization.md](authorization.md) — the per-feature checklist and permission-registration
  standard the access axis reuses
- [Q26](../OPEN-QUESTIONS.md#q26--organisational-and-record-level-access-scope) — the undecided
  organisational-unit model a future delegated enablement scope depends on
