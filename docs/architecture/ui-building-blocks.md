[← Architecture](README.md)

# UI building blocks

What reusable UI already exists in `frontend/packages/core-ui/src`, what it is for, and the
alternatives we are deliberately **keeping** while the design is still being tried. **Check here
before building a control, layout or behaviour** — most of what a new screen needs is below.
Why the interaction rules are what they are lives in [user-interface.md](user-interface.md); this
file is only the inventory.

Paths are relative to `frontend/packages/core-ui/src`.

## Form controls

| Block | File | Use it for |
|---|---|---|
| `Combobox` | `components/ui/combobox.tsx` | A searchable single-select over reference data that grows (items, suppliers, categories, UoMs, currencies, locations, warehouses, accounts). Takes `options: {value,label}[]`; forward its `ref` from `field.ref` so RHF can focus it. |
| `Select` | `components/ui/select.tsx` | A bounded enum of a handful of fixed values (status, type, policy). Not for data that grows. |
| `DateField` | `components/ui/date-field.tsx` | Any date. Typed `mm/dd/yyyy` segments plus a calendar popup; Tab leaves the field, ←/→ move between segments, ↑/↓ step. ISO `yyyy-mm-dd` / `''` value, so it replaces `<Input type="date">` with no schema change. Takes `aria-label` when there is no `FormLabel`. |
| `Calendar` | `components/ui/calendar.tsx` | The month grid inside `DateField`'s popup; usable on its own. |
| `Form*` | `components/ui/form.tsx` | React-Hook-Form wiring. `FormControl` passes `id`, `aria-describedby`, `aria-invalid` **and** `aria-labelledby`, which is what labels composite controls such as `DateField`. |
| `AdvancedOptionsMenu` / `AdvancedOptionSection` | `components/ui/advanced-option*.tsx` | Keep a rarely-changed section out of the page until it is opted into. |
| `OptionCards` | `components/ui/option-cards.tsx` | Pick one of a few options that read better as small preview cards than as text — a colour mode, a theme, a document design. Native radio inputs underneath (`sr-only`), so grouping/arrow-key nav/announcements are the browser's own. |

Native `datetime-local` is still used where a time is needed (goods-receipt arrival, recalculation
date) — `DateField` is date-only.

## Page chrome and layout

| Block | File | Use it for |
|---|---|---|
| `PageHeader` (+ `MobileFab`) | `components/page/page-header.tsx` | Title, breadcrumbs, actions; `primaryAction` becomes a FAB below `md`. |
| `DetailPageLayout` / `DetailSection` / `DetailField` | `components/detail/` | Read-only record views: cards of label/value pairs. |
| `WizardShell` | `components/page/wizard-shell.tsx` | Any multi-step create flow: step indicator, the active step's content, a sticky Back/Next footer. Presentational — the caller owns step state and validation. |
| `RecordSheet` / `RecordSheetAside` | `components/page/record-sheet.tsx` | A record as a right-hand side sheet: title, optional header `icon`, header actions, scrolling body, `size` (`md`/`lg`/`xl`, each floor capped at `100vw` so it never overflows a narrow screen), `dismissOnOutsideClick` (off for forms). `RecordSheetAside` portals children into the sheet's own quiet side column (only shown from `@4xl` up) from anywhere inside the sheet's content — e.g. a live order summary — without lifting that state to the screen that mounts the sheet. Room inside the scroller so focus rings aren't clipped. Opens/closes with a slide animation (`--animate-sheet-in-*`/`-out-*` in `themes.css`); closing always plays the exit animation before `onClose` fires. |
| `Sheet*` | `components/ui/sheet.tsx` | The raw Radix dialog primitives (also the mobile "More" and Filters sheets). Reach for `RecordSheet` for records. |

## Behaviour

| Block | File | Use it for |
|---|---|---|
| `Presentation` + the switch | `lib/presentation.ts`, `apps/main/src/presentation.ts` | Showing a record screen as a page **or** a side sheet, chosen per screen in one place. |
| `session-draft` | `lib/session-draft.ts` | Keeping unfinished form work for the tab's lifetime, per user (`readDraft` / `writeDraft` / `clearDraft`). Used by the PO create form; any create form can adopt it. |
| `useFocusOnOpen` | `lib/use-focus-on-open.ts` | Move focus to a just-opened confirmation panel's heading. |
| `list-query-state` | `lib/list-query-state.ts` | List filters/sort/page kept in the URL. This is why sheet routes carry the list's `?…` string across navigations — the list stays mounted behind the sheet and reads its state from it. |
| `appearance-store` / `AppearanceProvider` | `stores/appearance-store.ts`, `components/theme/appearance-provider.tsx` | Light/dark mode and accent theme, company/user-layered. See [appearance-and-printing.md](appearance-and-printing.md). |
| `print-settings-store` | `stores/print-settings-store.ts` | Company name/logo/accent/footer for every Quick Print document — `PrintLayout` reads it directly, so a screen never needs its own print branding. See [appearance-and-printing.md](appearance-and-printing.md). |

An in-place confirm, not a modal, for a destructive action that only affects what's already on the
current screen (discarding an unfinished purchase order — see `startOver` in
`purchase-order-form.tsx`): swap the trigger button for a short question plus a destructive/outline
button pair, inside the same tinted banner. Reach for a confirmation dialog instead when the action
affects data already saved elsewhere.

Test helpers (`frontend/apps/e2e/tests/support.ts`): `pickCombobox(page, label, option, { searchText?, within? })`,
`pickSelect`, `readCell`. Use `within` when a dialog sits over a page that has a same-named field.

## Alternatives kept on purpose

Nothing here has been deleted to make way for something else. To try a different one, change the
named switch; everything else is already wired.

| Screen | Alternatives | Switch |
|---|---|---|
| Purchase order — create | Side-sheet wizard (current) · the same wizard on a full page | `presentation.purchaseOrderCreate` |
| Purchase order — detail | Side sheet (current) · full page | `presentation.purchaseOrderDetail` |
| Purchase order — edit | Full page only (flat form, every section at once) | — |
| Date entry | `DateField` (current) · native `<input type="date">` via `Input` (still available; its Tab walks the browser's own segments) | swap the component at the call site |

### Making another screen a sheet-or-page

1. Accept `presentation?: Presentation` and build the header pieces once; render them through
   `RecordSheet` for `"sheet"` and `PageHeader` for `"page"` (see `present()` in
   `purchase-order-detail-screen.tsx`).
2. Add the screen's key to `apps/main/src/presentation.ts`.
3. In `router.tsx`, nest the sheet variant as a child route of the list (with `<Outlet />` beside the
   list) so the list stays mounted; keep the page variant as its own top-level route. Both are
   conditional on the switch.
4. Carry the list's query string in every navigation between list, sheet and edit
   (`navigate({ pathname, search })`), or the list behind the sheet loses its filters.

## Related

- [User-interface delivery](user-interface.md) — the interaction rules and the reasoning
- [Architecture index](README.md)
- [Code structure](code-structure.md)
