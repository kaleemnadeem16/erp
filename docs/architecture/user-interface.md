[← Architecture](README.md)

# User-interface delivery

The React SPA is an operational ERP interface, not a collection of CRUD forms. Each phase ships
the screens needed to perform and understand the backend workflows it introduces. A phase is not
UI-complete merely because its endpoints have generated hooks.

## Design gate before a workflow is built

For each workflow, write a short interaction specification in its domain document before coding:

1. Identify the primary users, decisions and frequent tasks; distinguish daily operations from
   setup, exception and audit work.
2. Inventory the complete lifecycle and permission matrix. State which actions are available in
   each status, which are irreversible and what confirmation/reason is required.
3. Define list, create/edit and detail/workspace information architecture, including saved or
   URL-backed filters, empty/loading/error states and the mobile alternative to a dense table.
4. Prototype the highest-risk flow using realistic data. Review keyboard operation, focus order,
   validation recovery, decimal/date presentation, destructive-action prevention and narrow-screen
   use before settling API additions.
5. Reuse `core-ui` patterns where they fit, but perform a consistency/accessibility audit instead
   of copying accidental limitations from the first screen.
6. Record the agreed screen inventory and acceptance scenarios in the phase/domain docs, then
   implement through generated API hooks and test the critical user journey.

The baseline is WCAG 2.2 AA. In particular, errors must be identified in text and associated with
their controls; keyboard focus must remain visible and unobscured; consequential financial/data
submissions need review, confirmation or a reversible correction path; and pointer targets must be
at least 24 by 24 CSS pixels or have the required separation. Frequent mobile actions should aim
for the stronger 44 by 44 target. References:
[WCAG 2.2](https://www.w3.org/TR/WCAG22/),
[target-size guidance](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum) and
[GOV.UK interaction patterns](https://design-system.service.gov.uk/patterns/).

## Shared ERP interaction rules

- Lists answer an operational question, not merely expose a table. Show useful defaults and totals,
  preserve filters in the URL and provide a card/detail alternative on narrow screens.
- An **already-existing** list screen exposes the filters, sort fields and includes its own
  backend endpoint validates for it — a control per accepted `filter.*`/`sort` value that
  endpoint already offers this exact screen, not just whichever ones the first draft happened to
  need. This is not "every backend capability needs a frontend" (see
  [extensibility.md](extensibility.md#progressive-capability-modes) — backend ahead of UI is
  often the right shape); it's narrower: a screen that already exists silently ignoring part of
  its own endpoint's validated query surface. If a validated filter is deliberately left off for
  now (rare, high-cardinality, admin-only, or genuinely gated behind a company/advanced mode —
  `AdvancedOptionsMenu`/`OptionCards` for that), say so in the screen's own comment rather than
  leaving the gap to be silently rediscovered.
- Document detail is a workspace: identity and status first, lines and totals together, then audit
  history and secondary metadata. Available actions derive from server state and permission.
- Draft line entry must support keyboard-efficient repeated work. Do not hide validation until the
  final document submission, and never calculate money with JavaScript floating point.
- Issuing, posting, approving, reversing, cancelling, short-closing and policy changes use named
  actions. Explain consequences before irreversible actions and show the recorded reason/history
  afterwards.
- Reference selectors search by human identity (code and name); raw numeric IDs are an explicitly
  documented temporary fallback, not a finished interaction.
- Dashboards and summaries must state their quantity/value basis and freshness. “On hand”,
  “available”, “on order”, “QA held” and “shortage” are different facts and must not be collapsed
  into a single ambiguous stock number.
- Row-level quick actions (edit, archive, etc.) are icon-only buttons via `DataTable`/
  `CursorDataTable`'s `rowActions` prop — a trailing column in table view, a top-right cluster in
  card view, one render function driving both, on every breakpoint. Only wire up an action once its
  backend contract is actually safe to expose one-click — e.g. Items exposes archive/activate
  (`PATCH` toggling the existing `status` field) but deliberately not hard-delete, since that
  endpoint has no guard against an item still referenced by stock/purchasing records.

## Advanced options: one menu per page, not one checkbox per section

"Keep the UI minimum, features maximum": when a create/edit screen has a field group that already
has a sensible default and is only occasionally overridden (a foreign-currency exchange rate, a
non-default policy, anything in the same shape), **the default is this pattern, for every such
group on every screen, unless the user explicitly asks for something else for that specific
screen.** Don't invent a bespoke inline show/hide control per screen.

- **One toggle per page, not one per section.** A single "Advanced options" button lives in the
  screen's own `PageHeader` `actions` (top-right alongside the title on wide screens; aligned to
  the right edge when the header stacks on mobile) — not inside the section
  it controls, and not a separate control per optional group. Opening it shows a menu of
  checkboxes, one per advanced option the page currently has. This scales to a page with several
  optional groups without the header accumulating several separate toggle buttons.
- **The section is absent, not collapsed.** Unchecked, it isn't on the page at all — no title, no
  placeholder line, nothing to scroll past. Checking its box in the Advanced options menu inserts
  the whole section (title, description, fields) exactly where an always-visible section would
  sit; unchecking removes it and snaps its fields back to their default values, so re-checking
  never reveals a stale override.
- **Components**: `components/ui/advanced-options-menu.tsx` (`AdvancedOptionsMenu` — the header
  button + checkbox menu) and `components/ui/advanced-option-section.tsx`
  (`AdvancedOptionSection` — a plain conditional wrapper around the section, no chrome, no state
  of its own).
- **Ownership split**: the *form* component owns the state and the reset-on-uncheck logic (it's
  the only thing with `form.setValue`); the *screen* wrapping it only decides where the menu
  button renders. A form reports its current options through an `onAdvancedOptionsChange`
  callback prop; the screen stores that in state and passes it to `AdvancedOptionsMenu` in its
  `PageHeader`. This is what lets the same form component be used from both a create and an edit
  screen without duplicating the toggle/reset logic in either one.
- **Reference usage**: `PurchaseOrderForm`'s exchange-rate section, wired up from both
  `purchase-order-create-screen.tsx` and `purchase-order-edit-screen.tsx`.

## Extending these patterns to another screen

`DataTable`/`CursorDataTable`/`PageHeader`/`ItemForm` all follow the same shape: every behaviour
introduced here (`view`/`cardColumns`, `rowActions`, `primaryAction`, `backTo`, `onCancel`, …) is an
**additive, opt-in prop** with today's behaviour as the default when it's omitted. A screen that
passes none of them renders exactly as it did before any of this existed. This is deliberate:

- A change that should apply to *every* screen (a layout fix, a new capability, a bug in how cards
  render) belongs in the base component (`components/data-table/*`, `components/page/page-header.tsx`,
  etc.), not copy-pasted per screen.
- A screen that genuinely needs different behaviour opts out or overrides locally (e.g. a screen with
  several status-gated actions keeps using `actions` instead of `primaryAction`) rather than the base
  component growing a special case for it.
- Don't wire a new prop into a screen "for consistency" before that screen actually needs the
  behaviour — a future new list screen still starts from a plain table with top-inline buttons and
  only opts into `view`/`primaryAction`/`rowActions`/etc. once it actually needs them.

## Mobile list-screen patterns

Concrete implementation of the card-view/bottom-nav requirements above and in
[ADR 0015](../decisions/0015-capacitor-for-mobile.md). Piloted end-to-end on the Items list
(`features/items/items-list-screen.tsx`), then rolled out to every other list screen (Purchase
Orders, Goods Receipts, Warehouses, Categories, Item Suppliers, Stock Ledger, Physical Counts,
Inventory Cutovers, Negative-Stock Overrides, Stock Recalculation) — this is now the standing
convention for list screens, not a pilot.

- **Bottom tab nav**: exactly 4 primary tabs + a "More" bottom sheet, below `md` — a native tab bar
  stops feeling native past ~5 slots, so it's a fixed set rather than the full nav list scrolling
  horizontally. The "More" sheet groups the remaining items the same way the `md`-and-up sidebar
  does (see below) rather than listing them flat. The primary 4 (currently Dashboard, Items,
  Purchase Orders, Goods Receipts) is a temporary fixed set — see the code comment in
  `app-shell.tsx` — future work makes it dynamic per user (permissions + visit frequency) once
  permissions are fully dynamic.
- **Table/card toggle**: `DataTable`/`CursorDataTable`'s `view`/`onViewChange`/`cardColumns` props.
  Table is the default and only option at `md` and up; below `md`, cards are the default, user-
  togglable back to table, persisted per-screen via `useListViewPreference`
  (`lib/use-view-preference.ts`, localStorage, key `erp.list-view.<screenKey>` — a client-only
  preference, swappable for a DB-backed user-settings table later without changing call sites).
- **Column visibility persistence**: `useColumnVisibilityPreference` (`lib/use-column-visibility-
  preference.ts`) is a drop-in replacement for a plain `useState<VisibilityState>` — same
  localStorage mechanism as the view toggle (key `erp.columns.<screenKey>`), so a screen's Columns
  dropdown choices (which also drive which fields show in card body, see below) survive a reload.
- **Card content**: reuses each list's existing `DataColumn[]` — mark one column `cardRole: 'title'`
  and optionally one `cardRole: 'subtitle'`; every other `screenVisible` column becomes a label/value
  row, with the same `cell` renderers (badges, formatting) as the table, so a card never looks
  different from its row.
- **Primary action placement**: `PageHeader`'s `primaryAction` prop, not `actions`. Renders inline on
  desktop (identical position to before); renders as a fixed `MobileFab` bottom-right below `md`.
  Screens with more than one action (status-gated workflow buttons, for example) keep using
  `actions` instead, unchanged on every breakpoint — `primaryAction` is for the single "new record"
  case only. Because the FAB is fixed-position, it sits on top of whatever scrolls to that spot —
  a screen using `primaryAction` must reserve extra bottom padding below `md` (the `p-4 pb-24
  md:p-6` pattern in `items-list-screen.tsx`) so trailing content (a pagination row, the last card)
  never ends up underneath it. This is on top of, not instead of, `AppShell`'s own bottom-nav
  clearance.
- **Filter progressive disclosure**: below `md`, non-essential filters (everything but the primary
  search box) move behind a "Filters" button (active-count badge) opening a bottom `Sheet`
  (`components/ui/sheet.tsx`); desktop's inline filter row is unchanged. Filters stay live inside the
  sheet, matching desktop's instant-filter behaviour — no staged "Apply" step.

## Desktop sidebar: grouping, quick access, active colour

The `md`-and-up sidebar (`app-shell.tsx`) groups its ~15 nav items into domain sections instead of
one flat list — grouping by domain and keeping a small algorithmic "most used" row separate from the
full menu is the pattern that recurs across SAP/NetSuite-style ERPs and general admin-dashboard
practice; deep collapsible nesting was deliberately avoided (real complaint from Zoho users: features
buried in submenus become undiscoverable).

- **Groups**: `navGroups` in `app-shell.tsx` — Dashboard standalone, then Purchasing / Inventory /
  Stock Operations as domain sections. Always expanded, no collapse state — every item stays one
  click away. Settings is deliberately **not** one of these groups: it's cross-cutting configuration,
  not a workflow module, and on desktop lives only in the top-bar Settings (gear) menu — see
  [ui-building-blocks.md](ui-building-blocks.md). It's still declared as `settingsNavGroup` (not
  rendered in the sidebar) so its routes resolve a top-bar title the same way every other nav item
  does, and so the mobile "More" sheet — which has no separate top bar — can still reach it: that
  sheet derives its grouped view from `navGroups` **plus** `settingsNavGroup`, filtered to whatever
  isn't already a primary tab.
- **Quick access (hybrid: automatic + pinnable)**: a slim bar above the content area (`md`-and-up
  only, right-aligned) shows up to `MAX_QUICK_LINKS` (4) icon shortcuts, excluding Dashboard.
  Pinned items (hover any sidebar row → the pin icon that appears) always show, in pin order; any
  remaining slots fill with whatever's visited most often. Both pins and visit counts live in
  localStorage (`lib/use-nav-usage.ts`, keys `erp.nav-pinned` / `erp.nav-visits`) — no backend for
  this yet, so this is a client-only preference exactly like the view-toggle/column-visibility
  patterns above; swapping the read/write functions in that one file for a real endpoint later
  won't touch `app-shell.tsx`. A visit is recorded once per route change, resolved to its *owning*
  nav item via `resolveNavPath` (longest-prefix match) so `/items/5/edit` counts as a visit to
  `/items`, not a separate untracked path.
- **Active-item colour**: `--nav-active` / `--nav-active-foreground` (`apps/main/src/index.css`), a
  light-blue tint + darker blue text/icon instead of the generic `bg-accent` grey every other active
  state in the app still uses. Deliberately isolated to these two CSS custom properties, called out
  in the CSS comment as a stand-in ahead of Phase 4's company-wide theming — swapping the palette
  later is a one-place edit. Every non-Dashboard `NavLink` also dropped its `end` prop (Dashboard
  keeps it, since `/` would otherwise prefix-match everything) so a detail/edit route now correctly
  highlights its owning list item instead of highlighting nothing.

## Form controls: searchable pickers and date fields

- **Pickers**: a dropdown over reference data that grows with the business — items, suppliers,
  categories, UoMs, currencies, locations, warehouses, accounts — is a searchable `Combobox`
  (`components/ui/combobox.tsx`) whatever it holds today. A bounded enum (status, type, policy: a
  handful of fixed values) stays a plain `Select`; searching a short fixed list is friction, not
  help. In list-screen filters the "All …" choice is simply the first option.
- **Dates**: `DateField` (`components/ui/date-field.tsx`) — typed `mm/dd/yyyy` segments plus a
  calendar popup (`calendar.tsx`). Only the Month segment and the calendar button are Tab stops, so
  Tab always moves on to the next field; ←/→ move between segments and ↑/↓ step a value. A native
  `<input type="date">` can't do this — the browser keeps its own segment focus and script can't
  change it — which is the only reason this is custom. It keeps the native input's value contract
  (ISO `yyyy-mm-dd`, or `''`; only complete, valid dates are emitted), so it drops into
  `FormControl` with no schema change. `datetime-local` fields stay native for now.

## Create flows in a side sheet

A multi-step "New …" flow — and a record's detail view — opens as a right-hand sheet over its list
instead of a page of its own. Purchase orders are the reference: `purchase-order-create-screen.tsx`,
`purchase-order-detail-screen.tsx`, `wizard-shell.tsx`, and `record-sheet.tsx` for the chrome.

- **Route**: `new` and `:id` are *child* routes of the list (`router.tsx`), so the list stays
  mounted, dimmed, behind the sheet, and the list's filters (held in the URL) are carried across
  opening, closing, and the trip to the full-page edit and back.
- **Page or sheet is one word**: both presentations are implemented and kept. Each screen takes a
  `presentation` prop and `apps/main/src/presentation.ts` picks per screen — see
  [ui-building-blocks.md](ui-building-blocks.md) for what exists and how to add another.
- **Sizing**: `RecordSheet` sizes are a share of the screen with a floor and ceiling — forms use
  `lg`; a data-dense record (the PO detail's lines table) uses `xl`, and in sheet mode drops the
  unit's long name and wraps descriptions so no column, Short-close included, is pushed off-screen.
  A table that can still overflow takes `scrollRegionLabel` so its scroll area is keyboard-reachable.
- **Steps**: `WizardShell` is an indicator, the active step's fields and a sticky Back/Next footer.
  "Next" validates only that step (`form.trigger`); on the last step the same button saves. It is
  one persistent button, not a Next that swaps for a Save — the swap could land under the same click
  and submit the form when the user only meant to advance.
- **Closing is always safe**: on a create form a click outside the sheet is ignored (read-only detail
  sheets do close on it), and the close button / Esc close instantly with no "discard?" prompt. The form keeps what was entered (`lib/session-draft.ts` —
  sessionStorage keyed by user, alive for the tab) and offers it back: the list's button reads
  "Continue new purchase order", and the sheet reopens on the same step with a "Picked up your
  unfinished purchase order" line and a **Start over** control, the only way to discard. Saving
  clears it and a blank form is never kept. The wording is "unfinished", never "draft" — a saved PO
  in `DRAFT` status is a different thing.
- **Edit stays a full page**, every section at once: the user is changing a complete record, not
  building one.
- **Considered and rejected**: a "discard changes?" confirmation — added friction, and it still
  makes someone choose between their entries and checking another screen; a modal blocks everything
  behind it, which is the wrong container for a task that needs other information (see NN/g's
  [modal vs nonmodal guidance](https://www.nngroup.com/articles/modal-nonmodal-dialog/)). A
  non-modal sheet — a side modal was the ask, and navigating away would unmount it anyway. Saving an
  incomplete PO to the server — the API validates a complete order.

## Screen ownership by phase

This is a durable allocation, not a complete speculative design. The detailed interaction
specification is written when each phase starts.

| Phase | Screen families owned |
|---|---|
| 0 — Foundation | Authentication, application shell, account/company context and foundational administration needed by later workflows |
| 1 — Master data | Items/categories, parties/customer/supplier accounts, currencies/rates, UoMs and chart-of-account maintenance; screens may land with the first consuming phase but remain owned by their domain |
| 2 — Purchasing & stock | Supplier sourcing; purchasing settings; purchase orders; goods receipts/reversals; QA dispositions; warehouses/locations; inventory overview; stock ledger; physical counts; cutover; negative-stock and recalculation exceptions |
| 3 — Estimating | Formulations/versions, application rates, assemblies, cost roll-up and estimate-building workspaces |
| 4 — Sales | Leads, opportunities, quotations/revisions, customer-facing document output and sales-owned correction visibility |
| 5 — Job execution | Jobs, work orders, material issue/return, time entry and estimate-versus-actual operational views |
| 6 — Finance | Supplier invoices/matching/payment, customer invoicing/receipts/allocation, journals, periods, tax, ledgers, ageing and finance-owned correction resolution |
| 7 — Mobile & integrations | Field-optimised navigation, barcode/camera flows, offline/retry states, notifications and integration administration |

Cross-module journeys should feel continuous, but each state-changing screen calls the owning
module's API. A later phase extends an earlier workspace with links or owned facts; it does not
move the earlier workflow into the later phase.

## Phase 2 completion boundary

Before Phase 2 is complete, research and agree the interaction specification and then deliver:

- an inventory overview that explains on-hand, available, QA-held, shortage and on-order quantity,
  supports item/warehouse/location/tracking drill-down and links to the immutable ledger;
- purchase-order list, draft create/edit and detail workspaces with issue, cancel and line
  short-close actions;
- goods-receipt list, create/detail, post and reversal workflows, optimised for repeated line and
  tracking entry;
- receipt-line QA disposition workflow, including unresolved quantity and disposition history;
- purchasing and inventory policy/settings screens with permission-aware reasons and audit context;
- a UX consistency/accessibility pass over existing item, supplier, warehouse, ledger, count,
  cutover, negative-stock and recalculation screens.

Supplier invoicing/matching remains Phase 6; job material issue remains Phase 5. Phase 2 screens
must show those boundaries honestly rather than offering actions whose owning documents do not yet
exist.

## Related

- [UI building blocks](ui-building-blocks.md) — inventory of the reusable pieces and kept alternatives
- [Appearance and printing](appearance-and-printing.md) — light/dark/theme tokens and Quick Print branding
- [API conventions](api-conventions.md)
- [Code structure](code-structure.md)
- [Extensibility](extensibility.md)
- [Roadmap](../phases/README.md)
- [Phase 2](../phases/phase-2-purchasing-stock.md)
- [ADR 0002](../decisions/0002-api-first-react-spa.md) ·
  [ADR 0006](../decisions/0006-generated-api-client.md) ·
  [ADR 0015](../decisions/0015-capacitor-for-mobile.md)
