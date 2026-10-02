[← Decisions](README.md)

# 0071 — Store the material selection strategy as governed, versioned history

- **Status:** accepted
- **Date:** 2026-10-01
- **Affects:** Estimating's Material Preview and its stock proposal, Settings → Material selection,
  [inventory-packages.md](../domain/inventory-packages.md), [ADR 0053](0053-explainable-inventory-package-selection-presets.md)

## Context

ADR 0053 §2 decided that the preset a preview uses (Minimise waste, Lowest internal cost, Fewest packages)
resolves item → nearest category ancestor → company → built-in `MINIMISE_WASTE`, and that changing it affects
future previews only. Until now nothing could be stored at any level: a preview used the built-in default or a
one-off choice, so a paint company could not say "solvents in drums: fewest packages" once and have every preview
follow it.

Estimating already has the pattern for a preference scoped like this — versioned policies with a reason and an actor
(overhead and variance policies) — so this reuses it rather than inventing a governance mechanism.

## Decision

1. **A row per change, kept forever.** `material_selection_policies` holds `scope_type` (`COMPANY`, `ITEM_CATEGORY`,
   `ITEM`), `scope_id` (null only for `COMPANY`, no FK because it points at two tables), the `strategy`, a required
   `reason`, the acting user and `effective_from`/`effective_to`. Exactly one row per scope is `ACTIVE`; a change closes it and
   inserts the next. Rows are never edited or deleted (model guard); only closing an active row is allowed.
2. **Clearing is a recorded choice.** A row with a null `strategy` means "no preference here — inherit", so removing a
   setting keeps who removed it and why. Recording the value already in force writes nothing. Clearing a scope with
   nothing stored is refused.
3. **One resolver.** `ResolveMaterialSelectionStrategies` answers per item — item, then the nearest category ancestor with
   a setting, then the company, then the built-in default — and names which level answered (`ITEM`, `ITEM_CATEGORY`,
   `COMPANY`, `BUILT_IN_DEFAULT`, or `REQUEST_OVERRIDE` for a one-off what-if). A per-request override still beats every stored level and
   stores nothing.
4. **Future only.** The resolver reads the rows in force now. Nothing already produced is recalculated, and the preview's
   evidence fingerprint includes each material's strategy and the row that supplied it, so "Check if still current" reports a
   changed preference as a change.
5. **Permissions.** Changing needs `estimating.selection-policy.manage`; reading the settings and their history needs that or
   `estimating.policy-history.view`. `estimating.internal_cost.view` is unrelated: a strategy is not cost evidence.
6. **API and screen.** `GET`/`POST /material-selection-policies` (no update or delete) and Settings → Material selection: where a
   preference is set, a chooser, the form (preset, reason) and the scope's history. Each material in the preview says which level
   its preset came from.

## Consequences

- **Positive:** a company sets its preference once at the right level and every preview follows it, with who/when/why on record.
- **Positive:** it reuses the overhead/variance shape (versions, reason, actor) and the existing history permission.
- **Negative:** Estimating owns a preference about inventory stock; that is deliberate — Inventory never depends on Estimating,
  and the presets are consumed only by Estimating's proposal.
- **Deferred:** a constrained custom-policy builder (ADR 0053 §4), and any per-preset parameters.

## Alternatives considered

**A nullable column on `items`, `categories` and `companies`** — rejected: no history, no reason, and Inventory would have to
know Estimating's enum.

**Reuse `inventory_policy_changes`** — rejected: it is Inventory's table and permission, and the preference belongs to Estimating.

**A DRAFT → ACTIVE lifecycle like overhead policies** — rejected: a single choice among three named presets has nothing to
author or review before it takes effect.
