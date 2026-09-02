[← Decisions](README.md)

# 0041 — Use one capable inventory schema with progressive location capabilities

- **Status:** accepted
- **Date:** 2026-09-02
- **Affects:** Phase 2, Inventory, imports, [stock.md](../domain/stock.md),
  [inventory-cutover.md](../domain/inventory-cutover.md)

## Context

Small companies may only need one or two warehouses and should not have to configure zones,
barcodes or warehouse-management rules. Larger companies and migrations from SAP, Odoo,
ERPNext or bespoke systems may already distinguish hierarchical zones/bins, receiving,
quarantine, dispatch, returns, transit and production locations. Building separate simple and
advanced schemas would make imports, reports, upgrades and stock history diverge. Exposing every
advanced field to every company would make the simple case unnecessarily difficult.

## Decision

Use one location-capable schema for every company and progressively expose behaviour through a
company setting. `SIMPLE` is the default; `STRUCTURED` is explicitly enabled. Advanced WMS is a
future set of cohesive capabilities, not a selectable empty mode and not dozens of independent
flags.

- Every warehouse always has at least one real location. In `SIMPLE`, warehouse creation creates
  it automatically and additional location creation is rejected; clients may hide the location.
- `STRUCTURED` exposes hierarchical locations, fixed operational types, optional barcodes,
  ordering, stock-bearing/grouping semantics and warehouse-location roles.
- Enabling a capability makes only its own inputs and invariants required. Backend Actions enforce
  company-policy requirements; database checks, unique constraints and foreign keys enforce
  row-level and relational integrity even outside HTTP.
- `SIMPLE` → `STRUCTURED` preserves identities and history. Downgrading is rejected while any
  structured location data or role assignment exists; the system never discards or flattens it.
- Imports map source hierarchies/types/keys through ADR 0027's staging and provenance contract.
  Source identifiers do not become ad-hoc columns on each domain table.
- Capacity, temperature/hazard controls, putaway/picking, replenishment, doors/staging
  determination and automation remain deferred until their workflows exist.

## Consequences

- **Positive:** A small company sees a minimal warehouse workflow while every company shares the
  same stock identity, reporting and migration model.
- **Positive:** Common SAP storage structures, Odoo typed locations and ERPNext warehouse trees
  have explicit mapping targets without requiring an enterprise-only fork.
- **Positive:** Companies can adopt bin-level control later without rewriting historical stock.
- **Negative:** The core schema contains fields that `SIMPLE` companies do not interact with.
- **Negative:** Mode transitions and conditional requirements need Action-level tests; ordinary
  PostgreSQL `CHECK` constraints cannot safely reach into a company-settings row.
- **Requires:** All supported write entry points use Inventory Actions, and mode downgrades prove
  no persisted structured dependency exists.

## Alternatives considered

**Separate simple and advanced schemas** — rejected because imports, stock queries and upgrades
would need two implementations and eventually drift.

**Expose every WMS field to every company** — rejected because optional complexity would dominate
the common one-location workflow.

**Many independent feature flags** — rejected because combinations multiply faster than they can
be explained or tested. Add cohesive capabilities only when a real workflow exists.

**Add an `ADVANCED` mode now** — rejected because no advanced WMS workflow exists yet; offering a
mode that changes nothing is misleading.

## Related

- [ADR 0027](0027-generic-column-mapping-import.md)
- [ADR 0036](0036-controlled-inventory-cutover.md)
- [Stock](../domain/stock.md)
- [Inventory cutover](../domain/inventory-cutover.md)
- [Extensibility](../architecture/extensibility.md)
