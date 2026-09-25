# 0052 — Use progressive inventory package tracking

- **Status:** accepted
- **Date:** 2026-09-26
- **Affects:** item master, Inventory, Purchasing receipts, Phase 3 material resolution,
  Phase 5 reservation and issue

## Context

Companies need different levels of physical-stock detail. One may only need to know that 28 L is
on hand. Another needs “four sealed 5 L cans plus 8 L loose/open”. A regulated or larger operation
may need the identity, batch, expiry, location and remaining quantity of every drum.

The current quantity ledger and valuation layers remain authoritative but cannot reconstruct those
physical distinctions. Forcing individual-container capture everywhere would burden small
companies; keeping only aggregate quantity would make exact pack recommendations impossible.

This follows the progressive-capability pattern in
[ADR 0041](0041-progressive-inventory-location-capabilities.md): one capable model, a simple
default and controlled transitions. Established systems likewise expose aggregate on-hand and
optional physical identity, including
[Dynamics 365 license plates](https://learn.microsoft.com/en-us/dynamics365/supply-chain/warehousing/location-license-plate-positioning),
[Oracle LPN inventory](https://docs.oracle.com/en/cloud/saas/warehouse-management/25b/owmim/inventory-management-modules.html)
and [SAP handling units](https://help.sap.com/docs/SAP_ERP/39d6d7a325d04826b95328b25fad91ab/eee9c353b677b44ce10000000a174cb4.html).

## Decision

### 1. Provide three cohesive tracking modes

| Mode | Recorded physical detail | Proposal precision |
|---|---|---|
| `QUANTITY_ONLY` | Existing aggregate stock quantity by enabled inventory scopes | Approximate; cannot claim exact packs exist |
| `PACKAGE_BALANCE` | Sealed package counts plus grouped open/loose quantity by relevant stock scope | Exact at package-type balance level; no identity per physical container |
| `CONTAINER_TRACKED` | One identity per physical container/package, with remaining quantity and lifecycle state | Exact container choices, subject to current availability |

`QUANTITY_ONLY` is the company default. All modes use the same stock movement and valuation engine;
advanced package records add physical evidence and never become a second independent stock ledger.

### 2. Resolve the mode at item creation and preserve its meaning

Configuration resolves in this order:

1. an explicit item choice;
2. the nearest item-category ancestor with a default;
3. the company default.

The resolved mode is persisted on the item before its first stock movement and is then part of the
item's inventory semantics. Later category/default changes do not reinterpret existing items or
historical transactions.

An upgrade requires reconciliation proving that introduced package/container state equals the
authoritative on-hand quantity. A downgrade is refused while incompatible detail, active
reservations or non-empty container state would be lost. Policy changes use ADR 0047's governed
permission, reason and audit pattern; operational permissions remain a separate Q25 decision.

### 3. Keep package state reconciled to stock movements

Conceptually, package-balance state records sealed counts and grouped open/loose quantity by item,
package definition and applicable warehouse/location/batch/expiry scope. Container state adds a
unique identifier, original quantity, remaining quantity and lifecycle such as sealed, open,
empty, scrapped or quarantined.

Every receipt, issue, transfer, return, adjustment and status change affecting package state is
transactional with the corresponding stock operation. Physical package quantities must reconcile
to the stock ledger under the same scope. Opening a package may change physical state without
changing stock quantity or value, but remains audited.

Reservations are not introduced here. Until Phase 5 reserves a balance/container, Phase 3
availability is an advisory as-of snapshot.

### 4. Fixed-content packages only in the first generation

The package definition references ADR 0050's fixed item quantity. Actual content that varies per
container needs dual recorded quantities and measurement rules; it cannot be represented by
changing a fixed conversion. That remains Q23.

## Consequences

- Small companies can begin without package-scanning overhead.
- Larger or regulated companies can obtain exact remnant, expiry and container choices.
- The UI exposes only the workflow for the item's/company's mode, while the backend remains
  capable of the advanced model.
- Receipts and later stock operations must maintain package reconciliation in advanced modes.
- Mode transitions need explicit reconciliation tooling, not a settings toggle.

## Alternatives considered

**One mandatory container model** — rejected as disproportionate for simple companies.

**Separate schemas or products per company size** — rejected; it would fragment behaviour and
make growth/migration harder.

**Infer packages from quantity layers** — rejected; the same remainder can represent many
different physical arrangements.

**Let category changes dynamically switch existing items** — rejected; history would acquire a
new meaning without the missing physical evidence.

## Related

- [ADR 0041](0041-progressive-inventory-location-capabilities.md)
- [ADR 0047](0047-govern-inventory-structure-and-default-valuation-policies.md)
- [ADR 0050](0050-item-specific-uom-conversion-and-divisibility.md)
- [ADR 0051](0051-phase-3-commercial-packaging-and-supplier-cost-scenarios.md)
- [Inventory packages](../domain/inventory-packages.md)
- [Q23](../OPEN-QUESTIONS.md#q23--variable-content-and-catch-weight-packages)
