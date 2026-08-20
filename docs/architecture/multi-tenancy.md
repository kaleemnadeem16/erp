[← Architecture](README.md)

# Multi-Tenancy

The system runs **single-organisation** today. The schema and model layer are built as if
multi-tenant so that enabling it later is days of work, not months
([ADR 0004](../decisions/0004-multi-tenancy-ready-schema.md)).

**Read before writing any migration or model.**

---

## The five rules

### 1. `company_id` on every independently-queried business table

From the first migration. Defaults to `1`. Not nullable.

Named `company_id` rather than `tenant_id` deliberately — multi-entity and multi-branch
structures are useful within a single organisation, so the column has value immediately rather
than sitting unused.

Excluded:

- **Genuinely global tables** (`currencies`, `countries`, system settings) — shared across every
  company, not scoped at all.
- **Child tables that only ever make sense attached to a parent** — line items, join rows,
  version records: `invoice_lines`, `journal_lines`, `job_lines`, `assembly_components`,
  `assembly_versions`, `locations` (bins inside a `warehouse`), `party_contacts`/`party_sites`
  ([parties.md](../domain/parties.md)). Nobody queries "all invoice lines across the company"
  without going through an invoice first — these inherit tenant scope through their parent's
  `company_id` rather than carrying their own. See below.

The dividing line: would a screen ever list rows from this table without first picking a parent?
If yes, it's independently queried — give it `company_id` directly. If no, it's a child — scope
it through the parent. A few tables that are technically derived from a parent document still
get `company_id` directly because they *are* queried independently and heavily —
`customer_ledger_entries`, `stock_moves`, `stock_layers` (statements, valuation, aging) — the
test is query pattern, not data lineage.

#### Child tables scope through their parent

`BelongsToCompany` can't help these — its global scope filters on the model's own `company_id`
column, which a child table doesn't have. Use `App\Support\Concerns\BelongsToCompanyThroughParent`
instead:

```php
class PartyContact extends Model
{
    use BelongsToCompanyThroughParent;

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    protected static function companyScopeRelation(): string
    {
        return 'party';
    }
}
```

Two guarantees, not one:

- **Read side** — a global scope constrains through the named relation (`whereHas('party')`).
  No explicit `company_id` comparison appears here; it's inherited for free because `Party`
  already carries its own `BelongsToCompany` scope. This composes through multiple hops
  automatically — a table two levels removed from `company_id` just names its immediate parent,
  and that parent's own scope (direct, or itself through a further `BelongsToCompanyThroughParent`
  hop) does the rest.
- **Write side** — a `saving` hook re-resolves a new or changed parent through its own scoped
  query before allowing an insert or update.
  `PartyContact::create(['party_id' => <another company's party>, ...])` throws immediately at
  the model layer. This is what makes the guarantee structural rather than
  a convention an Action has to remember to check — the exact risk direct scoping was designed to
  avoid in the first place.

#### Polymorphic logs tag via `tapActivity`, not `BelongsToCompany`

`activity_log` (`spatie/laravel-activitylog`) doesn't fit either pattern above: its `subject` is
polymorphic, so there's no single parent relation to scope through, and it's a vendor model —
extending it just to add `BelongsToCompany` isn't worth it. `company_id` was added by a
follow-up migration (the original deferred it until a real company-scoped model started
logging — Party crossed that line), and
`App\Models\Concerns\LogsActivityWithDefaults::tapActivity()` implements Spatie's `tapActivity()`
hook to stamp it from `CompanyContext` on every entry, regardless of what triggered it — the same
seam every other write path uses.

This tags rows for filtering; it does **not** add a read-side global scope. Querying `Activity`
directly still returns every company today. Scoping reads — and deciding who gets to see this
data and at what level — is deferred until the audit-log API is designed.

### 2. Composite unique constraints — always

```php
$table->unique(['company_id', 'code']);   // ✅
$table->unique('code');                   // ❌ never
```

**This is the expensive one.** Retrofitting means rebuilding every unique index against live
data that legitimately contains cross-company duplicates. The migration cannot be written
safely, so in practice it never happens.

Worth an architecture test asserting no single-column unique index exists on a company-scoped
table.

### 3. Global scope via `CompanyContext`

```php
trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', fn ($q) =>
            $q->where('company_id', app(CompanyContext::class)->id())
        );

        static::creating(fn ($m) =>
            $m->company_id ??= app(CompanyContext::class)->id()
        );
    }
}
```

`CompanyContext` currently returns `1`. Enabling multi-tenancy means changing that resolver to
read from the authenticated user or subdomain — **no query in the application changes.**

### 4. Namespace cache keys and file paths

```php
cache()->get("company:{$companyId}:settings");
Storage::path("companies/{$companyId}/invoices/{$id}.pdf");
```

Retrofitting this causes cross-tenant data leakage, which is the worst bug class this system can
produce. Free to do now; a security incident to fix later.

### 5. No ID enumeration outside the scoped layer

All access goes through scoped Eloquent models. Never accept a raw ID and query without the
scope applied — including in exports, reports and background jobs.

**Queued jobs must carry and restore the company context.** A job that runs without it either
sees nothing or, worse, sees everything.

---

## What enabling multi-tenancy later actually requires

Given the rules above are held:

1. Change the `CompanyContext` resolver to read from the request
2. Add middleware resolving company from subdomain, header or user
3. Add company CRUD and user-to-company assignment
4. Add a tenant-switching UI for users belonging to more than one
5. Audit background jobs for context propagation

Days of work. Without the rules, the same change requires rebuilding unique indexes, auditing
every query, and re-partitioning cache and file storage.

## What is deliberately not built

- Per-tenant databases ([ADR 0004](../decisions/0004-multi-tenancy-ready-schema.md) rejected this
  for now, but the schema does not preclude it)
- Tenant provisioning and onboarding flows
- Per-tenant configuration overrides beyond what
  [extensibility.md](extensibility.md) already provides
- Cross-tenant reporting

## Related

- [invariants.md](invariants.md) — rules 2 and 7
- [database.md](database.md)
- [ADR 0004](../decisions/0004-multi-tenancy-ready-schema.md)
