# 0047 — Govern inventory structure and default valuation policies

- **Status:** accepted
- **Date:** 2026-09-09
- **Affects:** Phase 2, Inventory, company settings

## Context

`UpdateInventorySettings` already treats negative-stock, backdating and recalculation settings as
governed company policies: changing one requires a dedicated elevated permission, a reason and an
immutable `InventoryPolicyChange` audit row. Two consequential defaults were deliberately outside
that mechanism while Q17 remained open:

- `inventory_location_mode` controls whether the company may use only the simple warehouse
  location or a structured location hierarchy; and
- `default_avco_pool_scope` selects the valuation-pool identity inherited by later AVCO items.

Both choices affect future operations across the company. A location-mode change can enable or
restrict an entire structural capability, while an AVCO default changes the valuation identity of
every subsequently created AVCO item that does not explicitly override it. Existing domain guards
prevent unsafe location downgrades and prevent an individual item's pool scope changing after its
first movement, but those guards do not record who changed the company policy or why. Retrofitting
that history after production use cannot reconstruct it.

These two settings need distinct authorities. Permission to manage physical location structure
does not imply permission to choose an accounting valuation default, or vice versa.

## Decision

Treat both settings as governed policies through the existing inventory-policy change boundary.

- Changing `inventory_location_mode` requires `inventory.location-policy.manage`, a non-empty
  reason and an immutable audit row in policy group `LOCATION_STRUCTURE`.
- Changing `default_avco_pool_scope` requires `inventory.valuation-policy.manage`, a non-empty
  reason and an immutable audit row in policy group `DEFAULT_AVCO_POOL_SCOPE`.
- A request changing both settings requires both permissions. Each changed group produces its own
  audit row with actor, reason, old/new values and timestamp.
- Supplying either field unchanged is not a policy change and therefore does not create a
  misleading audit event.
- Existing structural guards remain authoritative. Governance does not make a prohibited
  downgrade or post-movement item-policy change permissible.

## Consequences

- The first production configuration has a complete, attributable history for these choices.
- Physical-structure and valuation-policy authority can be assigned independently.
- Clients must provide a reason for either change and handle a `403` when the actor lacks the
  relevant permission.
- `InventoryPolicyChange` remains the single audit mechanism for consequential Inventory company
  settings; no parallel log is introduced.

## Alternatives rejected

**Leave both as ordinary settings** — rejected because they shape company-wide physical or
valuation behaviour, and their missing history cannot be recovered later.

**Use one general inventory-settings permission** — rejected because authority over warehouse
structure and authority over valuation defaults are materially different responsibilities.

**Audit the AVCO default only** — rejected because changing location capability is equally
consequential and can invalidate operating assumptions even where the downgrade guard prevents
the most dangerous transition.

## Related

- [ADR 0039](0039-configurable-avco-valuation-pools.md)
- [ADR 0041](0041-progressive-inventory-location-capabilities.md)
- [Phase 2](../phases/phase-2-purchasing-stock.md)
- [Open questions](../OPEN-QUESTIONS.md)
