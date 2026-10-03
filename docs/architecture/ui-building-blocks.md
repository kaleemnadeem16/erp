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
| `Collapse` | `components/ui/collapse.tsx` | Show/hide a block with the same slide-and-fade the Advanced filters panel uses. Content stays mounted (so typing is kept and nothing re-renders on opening); while closed it is invisible, inert and hidden from assistive technology. Pair it with `MoreOptionsToggle`. |
| `MoreOptionsToggle` | `components/ui/more-options-toggle.tsx` | **One** show/hide control for a block of optional fields that already have defaults ("More options" / "Hide options"). The fields are hidden, not off — they are saved either way — and while closed the toggle says what is set away from the default ("Set: discount or tax"). The default way to put rarely-changed fields away; the Purchase Order form uses it for the order (replaces-order, exchange rate) and once per line. |
| `AdvancedOptionsMenu` / `AdvancedOptionSection` | `components/ui/advanced-option*.tsx` | Keep a rarely-changed section out of the page until it is opted into. The checkbox-menu alternative — kept, and still switchable on the purchase order form with `purchaseOrderOptionsStyle` in `apps/main/src/presentation.ts` (`"disclosure"` or `"menu"`). A page has one; a **repeated block** (a line of an order) can have its own — pass `label` and `ariaLabel` ("Options for line 2") so the buttons are told apart. Ticking a group off puts its fields back to their defaults; a group whose fields already hold a value opens by itself, so nothing set is ever hidden (see `PurchaseOrderLineFields`). |
| `FieldHint` / `FormLabelWithHint` | `components/ui/field-hint.tsx` | A small (i) beside a label that says what the field does and what a value means — hover, keyboard focus or tap; also read by screen readers. For "what does this do?", not for errors or must-read rules (those stay visible). It is a keyboard stop, so it sits in the Tab order between fields. |
| `OptionCards` | `components/ui/option-cards.tsx` | Pick one of a few options that read better as small preview cards than as text — a colour mode, a theme, a document design. Native radio inputs underneath (`sr-only`), so grouping/arrow-key nav/announcements are the browser's own. |
| `PasswordInput` | `components/ui/password-input.tsx` | An `Input[type=password]` with a show/hide eye toggle — used wherever a screen has its own password field (account settings' current/new password) rather than the login screen's inline one-off. |

Native `datetime-local` is still used where a time is needed (goods-receipt arrival, recalculation
date) — `DateField` is date-only.

## Page chrome and layout

| Block | File | Use it for |
|---|---|---|
| `PageHeader` (+ `MobileFab`) | `components/page/page-header.tsx` | Title, breadcrumbs, actions; `primaryAction` becomes a FAB below `md`. |
| `DetailPageLayout` / `DetailSection` / `DetailField` | `components/detail/` | Read-only record views: cards of label/value pairs. `DetailPageLayout` is a `@container` grid — short sections (a handful of fields) share a row from `@2xl` up instead of each stacking full-width; mark a section `fullWidth` (a table, a long-text-heavy section) to keep it on its own row. `DetailSection`'s own field grid stays viewport- (not container-) based, since the same component is also used standalone, at full width, inside every create/edit form. |
| `WizardShell` | `components/page/wizard-shell.tsx` | Any multi-step create flow: step indicator, the active step's content, a sticky Back/Next footer. Presentational — the caller owns step state and validation. | `freeNavigation` lets any step be opened at any time — used when **editing** (Purchase Orders edit walks the same three steps as create).
| `RecordSheet` / `RecordSheetAside` | `components/page/record-sheet.tsx` | A record as a right-hand side sheet: title, optional header `icon`, header actions, scrolling body, `size` (`md`/`lg`/`xl`, each floor capped at `100vw` so it never overflows a narrow screen), `dismissOnOutsideClick` (off for forms). `RecordSheetAside` portals children into the sheet's own quiet side column (only shown from `@4xl` up) from anywhere inside the sheet's content — e.g. a live order summary — without lifting that state to the screen that mounts the sheet. Room inside the scroller so focus rings aren't clipped. Opens/closes with a slide animation (`--animate-sheet-in-*`/`-out-*` in `themes.css`); closing always plays the exit animation before `onClose` fires. |
| `Sheet*` | `components/ui/sheet.tsx` | The raw Radix dialog primitives (also the mobile "More" and Filters sheets). Reach for `RecordSheet` for records. |
| `Dialog*` | `components/ui/dialog.tsx` | A small **centered** modal for a quick, self-contained side-task started from inside a bigger form or sheet (adding a supplier site without leaving the PO being drafted — see `SupplierSiteDialog`). Scrolls internally past `90vh` rather than overflowing off-screen. Prefer `RecordSheet` for a record in its own right; stacking two side sheets reads as "which one am I in", where a centered dialog over a sheet reads as "a quick side-task, then back". |

## Behaviour

| Block | File | Use it for |
|---|---|---|
| `useListViewPreference` + `DataTableViewToggle` for document lines | `lib/use-view-preference.ts` | Table or cards for the **lines of a document** (PO review and order detail) follows the list rule: a wide screen always gets the table, a phone starts on cards and shows the toggle (`md:hidden`). |
| `Presentation` + the switch | `lib/presentation.ts`, `apps/main/src/presentation.ts` | Showing a record screen as a page **or** a side sheet, chosen per screen in one place. |
| `session-draft` | `lib/session-draft.ts` | Keeping unfinished form work for the tab's lifetime, per user (`readDraft` / `writeDraft` / `clearDraft`). Used by the PO create form; any create form can adopt it. |
| `useFocusOnOpen` | `lib/use-focus-on-open.ts` | Move focus to a just-opened confirmation panel's heading. |
| `list-query-state` | `lib/list-query-state.ts` | List filters/sort/page kept in the URL. This is why sheet routes carry the list's `?…` string across navigations — the list stays mounted behind the sheet and reads its state from it. |
| `appearance-store` / `AppearanceProvider` | `stores/appearance-store.ts`, `components/theme/appearance-provider.tsx` | Light/dark mode and accent theme, company/user-layered. See [appearance-and-printing.md](appearance-and-printing.md). |
| `print-settings-store` | `stores/print-settings-store.ts` | Company name/logo/accent/footer for every Quick Print document — `PrintLayout` reads it directly, so a screen never needs its own print branding. See [appearance-and-printing.md](appearance-and-printing.md). |
| Top-bar Settings menu | `SETTINGS_MENU` in `layouts/app-shell.tsx` | A `DropdownMenu` beside the dark-mode toggle, grouped **Company** (Purchasing/Inventory/Printing — affects everyone) vs. **You** (Account/Appearance — this person). The *only* way into Settings on desktop — the sidebar itself never lists it (see [user-interface.md](user-interface.md#desktop-sidebar-grouping-quick-access-active-colour)); the mobile "More" sheet still surfaces it via `settingsNavGroup` since mobile has no top bar. Add a new settings screen to both this menu and `settingsNavGroup`. |
| Top-bar user menu | Trailing `DropdownMenu` in `layouts/app-shell.tsx` | The circular initials avatar at the far right of the top bar — replaced a plain email-plus-"Log out"-button pair. Opens to the signed-in user's name/email, an **Account** link (`/settings/account`), then **Log out** last, the layout convention most dashboards use. |
| `Tooltip*` | `components/ui/tooltip.tsx` | A hover label for an icon-only control. `AppShell` mounts one `TooltipProvider` above every routed screen, so a screen only wraps the trigger: `<Tooltip><TooltipTrigger asChild><Button size="icon" aria-label="…">…</Button></TooltipTrigger><TooltipContent>…</TooltipContent></Tooltip>`. **Wire it at the call site, never inside `Button` itself** — an earlier attempt at a `Button`-level "any icon button with an `aria-label` gets one for free" auto-wrap silently broke every icon button that's *also* another Radix primitive's `asChild` target (e.g. the Settings gear's `DropdownMenuTrigger asChild`), because `Button` would then return a `Tooltip` root instead of the single ref-forwarding element that trigger's `Slot` needs to clone onto. Chaining is fine (`TooltipTrigger asChild` wrapping `DropdownMenuTrigger asChild` wrapping a plain `Button`) — it's `Button` deciding to interpose a non-passthrough component on its own that breaks. |
| `formatDate` / `formatDateTime` | `lib/format-date.ts` | The one date-formatting decision, so every column/field makes it the same way: `formatDate` (day only) for passive `created_at`-style metadata, `formatDateTime` (no seconds) for an operational event more than one of which can land on the same day (`moved_at`, `posted_at`, `requested_at`, an approval's `decided_at`, …). Both return `undefined` for a missing date, not `''` — `DataColumn`'s `formatColumnValue` and `DetailField` both turn `null`/`undefined` into a "—" placeholder, not an empty string. |
| `ListError` (+ `DataTable`'s `error` / `onRetry`) · `describeLoadError` · `shouldRetryQuery` | `components/data-table/list-error.tsx`, `lib/api-errors.ts`, wired in `apps/main/src/main.tsx` | What a list shows when its request failed: the screen's context message, the *cause* (no permission, the server's objection to a filter, a server fault, offline, session expired) and a "Try again" button; the "0 records" footer is hidden. A list screen passes `error` and `onRetry` from its query next to `isError`. `shouldRetryQuery` is the app-wide retry rule — only transient failures (network, 408, 429, 5xx), twice — so a definitive 403/422 shows at once instead of after ~10 s of skeletons. See [ADR 0061](../decisions/0061-retry-only-transient-failures-and-say-why-a-list-failed.md). |
| `Combobox`'s `loadError` / `loading` · `lookupState` · `LookupState` | `components/ui/combobox.tsx`, `lib/api-errors.ts` | A picker fed by a request spreads `{...lookupState(query, 'customers')}` so a failed lookup says "Couldn't load customers" with the cause and a "Try again" button instead of reading as "No results" ([ADR 0064](../decisions/0064-pickers-report-a-failed-lookup-and-offer-retry.md)). A filter-controls component that gets options as props takes a `…Lookup: LookupState` prop. |
| `formatLocation` / `formatLocationOption` | `lib/format-location.ts` | The one way to show a location: `warehouse / location` (a picker option adds ` — Name`). A location's code is unique only within its warehouse, so a bare `location.code` cannot say where stock is — it once made every transfer between two warehouses read "stock → stock". Returns `undefined` for a missing location so a table cell or detail field shows its "—" placeholder. See [ADR 0059](../decisions/0059-name-a-location-as-warehouse-and-location.md). |
| `getInitials` | `lib/initials.ts` | First + last initial from a full name, for an avatar circle — shared by the top-bar user menu and the account settings page's own avatar so they never drift. |

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
| Purchase order — edit | Side sheet (current; the flat form, every section at once, with the order summary beside it) · full page | `presentation.purchaseOrderEdit` |
| Document numbering — editor | Side sheet over the list (current; the child route `/settings/document-numbering/:type`) | — |
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
