[← Product journeys](README.md)

# Phase 3 material-planning journey — from configured rules to a checked material plan

- **Implementation status:** partial — the Phase 3 estimating and preview screens described here are
  implemented; adopting a plan on a quotation revision, approval-required variance rules and
  package-level proposals are not (see "Not yet available")
- **Audience:** estimator, inventory/planning lead, company administrator
- **Starting point:** `/material-preview`
- **Successful end state:** the person knows what an assembly technically requires for a customer,
  quantity and context, what the company could supply from its own stock, and whether a different
  planned quantity would need a reason or be blocked — with nothing saved or reserved
- **Last verified:** 2026-09-30

## Purpose

An estimator asks "what does this job need, can we cover it from stock, and is what I am planning
acceptable?" before committing to a quote. The preview answers from the company's own rates,
recipes, stock and rules, and is honest that it is advisory: it reserves nothing and can be out of
date the moment it is shown.

## Before you start

| Need | Where | Notes |
|---|---|---|
| Rate dimensions, application rates | `/rate-dimensions`, `/application-rates` | [application-rates.md](../domain/application-rates.md) |
| Formulations | `/formulations` | [formulations.md](../domain/formulations.md) |
| An assembly with an active version | `/assemblies` | [assemblies.md](../domain/assemblies.md) |
| Stock in warehouses and locations | `/warehouses`, Inventory screens | A STRUCTURED-mode company can mark which locations count as usable ([ADR 0067](../decisions/0067-governed-per-location-usability-mark.md)) |
| Variance reasons and rules | `/settings/material-variance` | The company's own; nothing is built in ([ADR 0054](../decisions/0054-risk-based-material-plan-variance-controls.md)) |
| Permissions | — | `estimating.material_preview.run` to run it; `estimating.inventory_availability.view` for stock; `estimating.internal_cost.view` for internal cost; `estimating.material_variance_policy.manage` for the rules |

## Journey map

```text
Set up rules and reasons → open Material Preview → choose assembly, quantity, context
  → Calculate → read requirement, stock proposal, cost
  → (optional) plan a different quantity → Calculate again → read the verdict
  → Check if still current before relying on it
```

## Steps

### 1. Set the company's variance rules (once, and when policy changes)

- **Screen:** `/settings/material-variance`.
- **User does:** adds the reasons a plan may differ from the standard (for example "Porous surface"),
  then sets rules at company, category or item scope: an over- or under-plan difference, measured as
  a quantity, a percentage or an internal-cost impact, at or beyond a threshold, with an effect —
  record only, reason required or blocked. The most specific scope wins; the strongest matching band wins.
- **System does:** versions each rule set (draft, then active), keeps history and never edits an
  active version in place. "Approval required" and waste rules are not offered yet.
- **Next:** without any rules, any non-zero difference simply needs a reason.

### 2. Ask the question

- **Screen:** `/material-preview`.
- **User does:** picks the assembly, version (or the effective active one), customer, work quantity
  and unit, and any rate dimensions. With stock access they can also narrow the warehouse or
  location and, under Advanced options, choose how stock is drawn.
- **System does:** on **Calculate preview**, resolves the requirement through the application rate,
  formulation and cost layers, with the selected rate's match method and any default-fallback warning.
- **Next:** the result appears below; changing any input that changes which materials apply clears it.

### 3. Read the answer

- **Resolved requirement** — quantity per leaf material with where each contribution came from.
- **Available stock** — a recommended draw and alternatives by strategy, per location, showing stock
  held for QA, stock that does not count (and whether the location's type or your company's mark
  decided) and any shortage. Quantity-only and approximate, and it says so.
- **Internal cost** — only with `estimating.internal_cost.view`; otherwise it says it is hidden and
  never shows zero.

### 4. Try a different quantity (what-if)

- **User does:** types a planned quantity (any convertible unit), optionally a reason and note, under
  a material and calculates again.
- **System does:** judges the plan against the standard and returns, per material, the difference,
  the effect, the reason status (no reason needed, reason given, reason missing, not accepted), the
  conditions that matched and any it had to skip and why, plus a headline saying whether the whole
  plan could be adopted as it stands. With cost access it also shows what the plan would cost against the
  standard, per material and in total, and says which materials have no cost evidence. The stock proposal then
  draws for the planned amount.
- **Next:** add the missing reason, or change the quantity when policy blocks it, and calculate again.

### 5. Check it is still current

- **User does:** presses **Check if still current**.
- **System does:** asks the same question again and compares the evidence fingerprint: "Still
  current" if nothing it rests on changed, otherwise it says something changed and refreshes the figures.

## Status and consequence table

| Object | State | What it means | Consequence |
|---|---|---|---|
| Preview result | shown | A hypothetical, read-only answer | Nothing is saved, audited or reserved |
| Variance verdict | reason missing | The rule needs an explanation | Plan cannot be adopted yet |
| Variance verdict | not accepted (blocked) | Policy refuses this difference | A reason does not lift it; change the quantity |
| Location | marked counting / not counting | The company overrode the type default | Availability follows the mark |

## Exceptions and corrections

- A retired reason, an item the assembly does not need, or a unit that does not convert is rejected with
  the field that is wrong, not ignored.
- A lookup that fails to load says why and offers **Try again**; an empty list always means empty.
- Without stock or cost permission the corresponding section says it is hidden — hidden is not zero.

## Not yet available

- Adopting a plan on a quotation revision, approval, and audit of adopted variances — Phase 4.
- Approval-required variance rules (shared approval workflow) and waste/residual rules (package tracking).
- Package-level proposals and stored strategy settings; department-limited availability (Q26).

## Source of truth

- Domain: [material-resolution.md](../domain/material-resolution.md), [inventory-packages.md](../domain/inventory-packages.md)
- ADRs: [0054](../decisions/0054-risk-based-material-plan-variance-controls.md), [0058](../decisions/0058-phase-3-material-resolution-preview-contract.md), [0064](../decisions/0064-pickers-report-a-failed-lookup-and-offer-retry.md), [0067](../decisions/0067-governed-per-location-usability-mark.md)
- UI code: `frontend/packages/core-ui/src/features/material-preview/`, `.../material-variance/`

## Related

- [Product journeys](README.md)
- [Phase 3 plan](../phases/phase-3-estimating-core.md)
- [Phase 2 operational journey](phase-2-operational-journey.md)
