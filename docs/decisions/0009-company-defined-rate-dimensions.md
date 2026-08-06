[← Decisions](README.md)

# 0009 — Company-defined rate dimensions

- **Status:** accepted
- **Date:** 2026-07-29
- **Affects:** Phase 3 (estimating core)

## Context

Material consumption per unit of work varies by context. In painting, coverage depends on
**surface type** — rough plaster absorbs far more than smooth plaster or metal.

The obvious implementation is a `surface_type_id` column. That would hardcode a paint concept
into core, and every other industry would need a code change: screeding varies by *thickness*,
crop spraying by *crop*, cleaning by *soiling level*.

## Decision

The axes along which rates vary are **data, defined per company**, not schema.

```
rate_dimensions          company-defined axis    "Surface Type" / "Thickness" / "Crop"
rate_dimension_values    its values             "Rough plaster" / "50mm" / "Cotton"
application_rates        qty per work unit
application_rate_dimensions   which combination this rate applies to
```

Core code never references a surface, a thickness or a crop. It resolves a rate by matching a
combination of dimension values.

## Consequences

- **Positive:** New industries are supported by configuration, not code. This is rung 2 of the
  variation ladder in [0010](0010-core-plus-plugins.md) and absorbs a large share of what would
  otherwise look like fork requests.
- **Positive:** A company can add a dimension without a deploy.
- **Negative:** Rate resolution is a lookup over a value combination rather than a column
  comparison — more complex to implement, and needs care to stay efficient.
- **Negative:** Less discoverable. A developer cannot read the schema and learn that surface
  type matters; that lives in the company's data. Mitigated by
  [domain/application-rates.md](../domain/application-rates.md) carrying worked examples.
- **Requires:** a defined resolution rule when no exact match exists — fallback behaviour must
  be explicit, not accidental.

## Alternatives considered

**Hardcoded `surface_type`** — simplest, and correct for exactly one industry. Rejected as the
first step toward per-customer forks.

**Custom fields on the rate record** — the generic custom-field mechanism already exists. Rejected
because rate resolution needs to *match* on these values, and matching against JSON is both
awkward and slow. Dimensions are structural, not decorative.

**EAV for the whole rate model** — maximally flexible, unreadable and unqueryable. Rejected.

## Related

- [domain/application-rates.md](../domain/application-rates.md)
- [0008 — Assembly abstraction](0008-assembly-abstraction.md)
- [0010 — Core + plugins](0010-core-plus-plugins.md)
