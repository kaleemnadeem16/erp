[← Architecture](README.md)

# Multi-Tenancy

The system runs **single-organisation** today. The schema and model layer are built as if
multi-tenant so that enabling it later is days of work, not months
([ADR 0004](../decisions/0004-multi-tenancy-ready-schema.md)).

**Read before writing any migration or model.**

---

## The five rules

### 1. `company_id` on every business table

From the first migration. Defaults to `1`. Not nullable.

Named `company_id` rather than `tenant_id` deliberately — multi-entity and multi-branch
structures are useful within a single organisation, so the column has value immediately rather
than sitting unused.

Excluded: genuinely global tables (`currencies`, `countries`, system settings).

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
