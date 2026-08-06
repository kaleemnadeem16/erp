[← Architecture](README.md)

# Database

**PostgreSQL 16 only** ([ADR 0020](../decisions/0020-postgresql-only.md)). MySQL and MariaDB
support was dropped along with the shared-hosting tier.

This replaces the former `database-portability.md`. The rules that existed purely for MySQL
compatibility are gone; the rules that existed for *other* reasons remain and are marked as such.

---

## Rules that still apply

These are unaffected by dropping MySQL — they exist for different reasons.

| Rule | Why |
|---|---|
| **Composite unique constraints** — `unique(['company_id','code'])` | [Multi-tenancy](multi-tenancy.md), not portability. Unchanged. |
| **`decimal(19,4)` for money**, never float | Precision. [ADR 0012](../decisions/0012-multi-currency-dual-amounts.md) |
| **Normalise codes and emails to lowercase in the application** | Postgres is case-sensitive, so `PIG-001` and `pig-001` are distinct rows. Predictable and explicit — preferred over `citext`, which surprises people later. |
| **Separate large data backfills from schema migrations** | No longer *required* (Postgres has transactional DDL), but a backfill inside a schema migration holds locks for the duration. Split for lock duration, not correctness. |

---

## What is now available

Previously banned for MySQL compatibility. Several map directly onto problems already identified
in the domain model — use them.

### JSONB for custom fields

```sql
ALTER TABLE items ADD COLUMN custom_fields jsonb NOT NULL DEFAULT '{}';
CREATE INDEX items_custom_fields_gin ON items USING gin (custom_fields jsonb_path_ops);
```

[Custom fields](extensibility.md#runtime-custom-fields) become genuinely queryable. Containment
(`@>`) and path queries are indexable, so rung 3 of the variation ladder performs rather than
merely working.

Laravel's `where('custom_fields->key', …)` still works and remains the default. Reach for raw
JSONB operators when the query is genuinely a containment search.

### Recursive CTEs for formulation explosion

[formulations.md](../domain/formulations.md) flags nested formulations as a landmine — an N+1
roll-up in PHP with a depth cap. A recursive CTE does it in one query:

```sql
WITH RECURSIVE explode AS (
    SELECT fl.input_item_id, fl.qty, 1 AS depth
    FROM formulation_lines fl
    WHERE fl.formulation_version_id = ?
  UNION ALL
    SELECT fl.input_item_id, e.qty * fl.qty, e.depth + 1
    FROM explode e
    JOIN formulations f ON f.output_item_id = e.input_item_id
    JOIN formulation_lines fl ON fl.formulation_version_id = f.active_version_id
    WHERE e.depth < 10
)
SELECT * FROM explode;
```

Keep the depth guard — it protects against cycles that slipped past validation.

### Exclusion constraints for version periods

Formulation and assembly versions must not have overlapping effective periods. Enforce it in the
database rather than in application code:

```sql
ALTER TABLE formulation_versions
  ADD CONSTRAINT no_overlapping_periods
  EXCLUDE USING gist (
    formulation_id WITH =,
    daterange(effective_from, effective_to, '[)') WITH &&
  );
```

Requires `btree_gist`. This makes
[invariant 4](invariants.md#4-versioned-records-are-immutable-once-used) structurally
enforceable instead of merely documented.

### Partial indexes

Most ERP queries filter on active rows. Index only those:

```sql
CREATE INDEX items_active ON items (company_id, code) WHERE deleted_at IS NULL;
```

Smaller indexes, faster writes — and [dbvm has 1 vCPU](environments.md#dbvm--data-tier), so this
is not a micro-optimisation.

### Materialised views for reports

Aging, stock valuation and job variance are expensive and rarely need to be second-fresh.
Precompute and refresh on a schedule. Again: 1 vCPU on the database host.

### Native full-text search

`tsvector` with a GIN index. **Laravel Scout is no longer needed** — it existed only as a
portability abstraction. See [tech-stack.md](tech-stack.md).

### Also available

`ILIKE` · arrays · `RETURNING` · `DISTINCT ON` · generated columns · `LISTEN/NOTIFY` ·
row-level security · **PostGIS** (already installed on dbvm — available for site geography if
field service ever needs it).

---

## Cautions

Available does not mean advisable.

- **Row-level security** is attractive for [multi-tenancy](multi-tenancy.md) but is a *second*
  enforcement layer, never a replacement for the Eloquent global scope. Two mechanisms
  disagreeing is worse than one.
- **Materialised views** are stale by definition. Never use one where a user expects to see
  their own just-saved change.
- **JSONB is not a schema.** Custom fields are the designed exception. Core domain data gets
  columns.
- **Recursive CTEs** can run away. Always cap depth.

---

## Testing

Single engine, so the CI matrix collapses:

```yaml
services:
  postgres:
    image: postgres:16
```

The dual-engine matrix and its drift risk are gone. What replaces that discipline: migrations
are reviewed for lock duration, and any query touching a table expected to exceed ~100k rows
gets an `EXPLAIN` in review.

## Related

- [ADR 0020](../decisions/0020-postgresql-only.md) — the decision and what it unlocks
- [invariants.md](invariants.md) · [multi-tenancy.md](multi-tenancy.md)
- [environments.md](environments.md) — the 1 vCPU constraint that shapes indexing
