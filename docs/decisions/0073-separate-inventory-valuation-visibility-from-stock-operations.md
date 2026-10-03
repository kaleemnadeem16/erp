[← Decisions](README.md)

# 0073 — Separate Inventory valuation visibility from stock operations

- **Status:** accepted — its quantity-read paragraph is superseded by [ADR 0083](0083-layered-stock-read-capabilities-and-blind-counting.md); the valuation boundary stands
- **Date:** 2026-09-30
- **Affects:** Inventory read projections, stock ledger and overview screens, company roles

## Context

ADR 0069 separates Purchasing supplier economics from physical receiving but explicitly leaves Inventory valuation reads for a later review. The stock ledger, layers, AVCO pools and overview currently expose cost, FX or value to any authenticated company user. A warehouse worker needs quantities, identity and location; those do not imply authority to inspect inventory asset value. Included valuation entries and printed tables must not bypass a hidden column.

## Decision

- Register `inventory.valuation.view` as a separate, assignable read capability. It is not implied by a receiving, stock-operation, valuation-policy-management, Purchasing commercial-cost or Estimating internal-cost capability. Companies can combine these in roles where a person genuinely needs several views. The configured administrator receives it through normal permission registration/sync; existing operational users require explicit assignment.
- Keep stock-move, stock-layer, AVCO-pool and inventory-overview quantity and identity reads available to authenticated company users under their current tenant scope. Omit monetary cost, FX and value fields in their API resources without the new capability. This includes stock-move `valuation_entries`, even if a caller requests that include, and both inventory-overview rows and summary totals. Do not substitute zero or null for a hidden amount.
- The SPA removes valuation columns, cards, detail sections and print fields for unauthorised users. The backend projection is authoritative; UI gating only improves clarity.
- This first implementation covers those four read surfaces. Recalculation, cutover, physical-count, policy and negative-stock exception responses still need their own workflow and field review before claiming system-wide Inventory cost secrecy. No posting or historical valuation fact changes.

## Why this boundary

The reviewed ERP patterns distinguish operational inventory movement from value reporting and control access to the latter. Odoo's stock report explicitly carries unit cost and total value, while its access-right system assigns visibility through roles/groups. Dynamics 365 documents cost-sheet access for accounting duties rather than ordinary warehouse work. Those products package their screens differently, so this ERP uses a narrow capability on monetary API fields while preserving the operational quantity view.

## Alternatives considered

**Reuse `purchasing.commercial-cost.view`** — rejected: supplier prices and recorded stock valuation are related but different duties. A Finance user may need one without the other.

**Reuse `estimating.internal_cost.view`** — rejected: that permission controls estimating previews; seeing a cost used in an estimate does not grant the full stock valuation ledger.

**Require valuation access for the entire stock ledger** — rejected: it would remove movement and quantity visibility needed for physical custody.

**Hide only in React** — rejected: direct API calls, includes and print data would still disclose amounts.

## Research basis

Checked 2026-09-30: how do mature ERPs distinguish operational stock visibility from valuation visibility? Official [Odoo stock valuation guidance](https://www.odoo.com/documentation/19.0/applications/inventory_and_mrp/inventory/inventory_valuation/operations_valuation.html) identifies movement value and stock unit cost/total value as report data; [Odoo access rights](https://www.odoo.com/documentation/19.0/applications/general/users/access_rights.html) governs access through user and group assignments. Official [Dynamics 365 costing-sheet guidance](https://learn.microsoft.com/en-us/dynamics365/supply-chain/cost-management/costing-sheets) lists accounting roles for cost access; its [warehouse-worker access guidance](https://learn.microsoft.com/en-us/dynamics365/fin-ops-core/fin-ops/sysadmin/security-gov-device-based-access) limits warehouse workers to their job function. The shared principle is least-privilege monetary access; neither vendor dictates this ERP's exact permission name or field projection.

## Related

- [Stock](../domain/stock.md)
- [Authorisation retrofit](../architecture/authorization-retrofit.md)
- [ADR 0069](0069-separate-purchasing-commercial-visibility-from-receiving.md)
