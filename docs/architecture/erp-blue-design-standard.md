[← Architecture](README.md)

# The `erp-blue` design standard (Purchase Orders first)

- **Status:** the standard being proven on one screen. The Purchase Orders list and its side panels (order detail,
  create, edit) use it; no other screen does yet. Dark mode and other palettes come after the owner approves it.
- **Where it lives:** [palette-erp-blue.css](../../frontend/apps/main/src/palette-erp-blue.css), imported by
  `index.css`. Tests: `frontend/apps/e2e/tests/purchase-orders-theme-audit.spec.ts`.

Read [appearance-and-printing.md](appearance-and-printing.md) first for the light/dark/theme machinery this sits beside,
and [ui-building-blocks.md](ui-building-blocks.md) for the shared controls.

## How the colours are organised

Three layers, each only reading the one above:

```
palette tokens   --erp-blue-color-*, --erp-blue-status-*, --erp-blue-shadow-*   (every name carries the palette name)
      ↓
screen aliases   --po-*   (defined under  :root:not(.dark) :is(.po-screen, .po-detail)  — light mode only)
      ↓
components       classes and the shared shadcn tokens (--background, --card, --border, --primary …) re-pointed inside the scope
```

- **A palette never uses a generic name** (`--color-primary`). A future `emerald` or `violet` palette defines
  `--emerald-color-*` beside it and cannot overwrite it.
- **Components never hold a hex value.** If a colour is missing it is added as a palette token first.
- **Scope, not global.** `.po-screen` (the list) and `.po-detail` (the order side panels and page) opt a screen in. A screen
  that carries neither class is untouched, and `.dark` is untouched: the shared look still applies there.

## The rules the standard encodes

| Area | Rule |
|---|---|
| Surfaces | Pale canvas (`#F8FAFC`) behind white cards, panels and tables. |
| Borders | Field borders (`#CBD5E1`) are stronger than **container borders** — cards, panels, tables and row dividers use a lighter hairline (`#E9EEF4`) so containers recede and controls stand out. |
| Text | Primary for values and titles, secondary for labels and helper text, tertiary only for metadata. Never disabled-grey for readable information. |
| Weight | Page title 700, section titles 700, table headers 600, buttons 600, dropdown values 500, labels 500. Body table text 14 px. |
| Controls | 40 px tall on desktop, flat (no resting shadow), icons 18 px in the secondary text colour. |
| Focus | Fields: brand-blue border plus a soft halo (`--erp-blue-color-focus-halo`). Buttons, tick boxes, links: a 2 px outline with a 2 px gap. Never a white offset ring plus a thick blue ring. |
| Selection | A selected row gets the selected surface **and** a left edge — never the tick box alone. |
| Status | One pill component (`PurchaseOrderStatusBadge`) for every view, with the text label always shown. DRAFT draft · WAITING APPROVAL pending · ISSUED active · PARTIALLY RECEIVED progress (violet) · CLOSED success · CANCELLED/REJECTED danger. |
| Side panels | White section cards with a bold title and a divider; label above value; rows that share a line are the same height (`DetailPageLayout equalHeight`). |

## Accessibility findings (computed 2026-10-01)

All text pairs reach 4.5:1 or better, including every status pill. Focus ring and selected edge reach 3:1.
Known shortfalls kept on purpose, to be decided with the palette: the field border `#CBD5E1` is 1.48:1 on white and its hover
`#94A3B8` 2.56:1 (WCAG asks 3:1 for a control's boundary); `#64748B` would pass (4.76:1). Row dividers and disabled text are
exempt.

## Shared components changed, and how they stay opt-in

Each addition is an optional prop that defaults to today's behaviour, so every other screen renders as before:
`Combobox.icon`, `DateField.pickerPosition`/`hidePicker`, `DataTable.toolbarStart`/`recordNoun`/`numberedPagination`/
`viewOptionsIcon`/`viewOptionsTriggerClassName`/`actionsHeader`, `DetailPageLayout.equalHeight`, `RecordSheet.className`.

## Rolling it out to the next screen

1. Put `po-screen` (a list) or `po-detail` (a record panel/page) on the screen's root — or, if the same palette is meant to
   apply more widely, widen the `:is(...)` scope in the CSS rather than copying rules.
2. Reuse the shared components' opt-in props above; add a prop (default off) rather than a screen-specific selector.
3. Give the screen a spec modelled on `purchase-orders-theme-audit.spec.ts` that also asserts an untouched screen has none of it.
4. Dark mode: add a `.dark` mapping of the same `--erp-blue-*` names (do not edit the light values), then lift the
   `:root:not(.dark)` guard.

## A rule from the same work: screens refresh after every saved change

`createAppQueryClient` (`packages/core-ui/src/lib/query-client.ts`) refetches whatever is on screen after any successful
mutation, and holds the mutation open until that finishes. A list kept mounted behind a side sheet used to keep the old
status after an order was issued. Only a mutation that merely computes an answer (the material preview) is exempt; add its
mutation key to `READ_ONLY_MUTATIONS` if a new one appears. `purchase-order-sheets.spec.ts` guards it.

## Related

- [appearance-and-printing.md](appearance-and-printing.md) · [ui-building-blocks.md](ui-building-blocks.md) ·
  [tech-stack.md](tech-stack.md)
