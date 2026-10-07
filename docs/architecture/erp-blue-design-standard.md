[← Architecture](README.md)

# Screen design standard — ERP Blue

**Use this standard for every new or materially restyled screen.** The Purchase Orders list,
create/edit wizard and detail workspace are the reference implementation. Apply the relevant
patterns, not every PO control to every module. Business rules, authorisation and the projected
API remain owned by each feature. Read [user-interface.md](user-interface.md) for interaction
policy and [ui-building-blocks.md](ui-building-blocks.md) before inventing a component.

## Approved colour baseline (2026-10-07)

The owner approved and locked the current ERP Blue light and refined dark palettes on
2026-10-07. These are the default baseline for new and restyled screens. Dark mode uses neutral
black/charcoal surfaces, crisp near-white body text (`#fafafa`), brighter secondary labels
(`#dcdcdc`), clear control edges (`#8a8a8a`) and blue actions (`#60a5fa`). Blue belongs to
interaction and selection; semantic status colours retain their own meaning. Secondary and
tertiary roles preserve hierarchy without making the interface look faded.

Locked means consistent reuse, not permanent immutability: a later explicit owner instruction
may revise the central palette. Do not retune colours during individual screen adoption. Adopt
one screen at a time, reviewing its relevant list/detail/form states in both modes before moving
on. Purchase Orders remains the reference. Preserve each screen's domain features and permission
gates, and use shared templates/styles with scoped layout overrides where needed.

Dark palette acceptance targets follow [WCAG text contrast](https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html)
and [non-text contrast](https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html):
at least 4.5:1 for normal text (including placeholders and status labels), and 3:1 for essential
control boundaries and focus indicators against adjacent surfaces. Decorative separators need
not meet the control-boundary target. Status meaning must also have a visible label; colour alone
is insufficient. Measure resolved foreground/background pairs, including hover and selected
states, rather than judging a colour swatch alone. Automated palette checks supplement rendered
screen accessibility checks; they do not certify the whole application.

Reuse central semantic tokens; do not introduce per-screen palette variants. Colour changes need
an explicit owner instruction. Layout/spacing overrides remain available. Existing screens adopt the standard one at a time as selected with the owner.

## Ownership and reuse

Paths below are relative to `frontend/`.

| Concern | Single owner | How a screen varies it |
|---|---|---|
| Light/dark surfaces, text, borders and state colours | `apps/main/src/palette-erp-blue.css` | Semantic `--ui-*` variables; never inline hex colours in a screen |
| Accent themes and motion | `apps/main/src/themes.css` | Appearance registry selects the theme; no screen-specific mode branches |
| Screen geometry and common role styles | `apps/main/src/screen-templates.css` | Scoped spacing/type variables or component `className` |
| List/detail/form page wrapper | `components/page/screen-template.tsx` | `ScreenTemplate kind="list" / "detail" / "form"`, optional `variables` |
| Page versus record sheet | `apps/main/src/presentation.ts` | Register the screen's presentation choice; retain the same feature content |
| Sections and readable fields | `components/detail/`, `SectionStack` | `fullWidth`, `equalHeight`, local layout props |
| Workflow state appearance | `components/ui/status-badge.tsx` | Domain maps its states to `StatusTone`; always supplies the visible label |
| Optional fields and form steps | `MoreOptionsToggle`, `Collapse`, `WizardShell` | Feature supplies defaults, summaries and validation |
| Paper output | Shared print layouts and document-template registries | Choose a document template independently of the app theme |

`ScreenTemplate` is a layout shell, not a generic CRUD engine. It never fetches data, grants a
permission, chooses an approval action or invents financial totals. New screens compose the
existing blocks; a reusable behaviour belongs in those blocks, not a copy of PO JSX.
The PO's `po-*` style aliases remain compatible. New screens use `erp-screen`, `erp-record` and
`ui-*` roles; they must not adopt a purchasing-specific name.

## Template recipes

### List

Use `ScreenTemplate kind="list"` with `PageHeader`, primary search/filters, optional advanced
filters, active filter chips, `DataTable`/mobile cards and its footer. The header has one prominent
creation action; Print, Columns and Filters are secondary. On a phone the creation action uses the
shared FAB and secondary tools stay in the toolbar/filter sheet.

- Keep search/filter/sort/page in the URL. Closing a child record restores the exact list context.
- Expose every supported filter relevant to this existing screen's scope; never silently omit an
  existing endpoint's filter. Capability-gate financial filters as well as columns.
- Put frequent filters beside search. Put rare criteria in a labelled, animated advanced panel;
  on mobile use the filter sheet. Active chips show a label/value and a named remove control.
- Use server-searchable comboboxes for growing references; fixed enums use Select. Retain a selected
  value when search results change. Never assume the first 100 rows are the complete catalogue.
- Use a desktop table and default mobile cards, with the saved table/cards choice where relevant.
  Numeric cells align right and use tabular numerals; identity links have stronger weight.
- Enable selection/bulk actions only when there are relevant safe actions. Clear selection when
  page, sort or filters change; show a selected surface/edge and a count, not only tick boxes.
- Row menus contain permitted, state-valid actions. Controlled transitions open the record's own
  confirmation/review flow; do not create an alternative unguarded transition in the list.
- Loading, empty, filtered-empty and failed requests are distinct. Use the shared retry/error
  components; never present a failed request as “0 records”. Name the records in pagination.

Style roles: `ui-btn-filters-active`, `ui-filter-badge`, `ui-filter-panel`,
`ui-filter-panel-title`, `ui-chip-active`, `ui-bulk-bar`, `ui-order-link` and `ui-link-action`.
These role classes work inside the shared screen scope; the feature owns their data/handlers.

### Detail / workflow record

Use `ScreenTemplate kind="detail"` for a page or `RecordSheet` for a child record. Build header
content and workflow actions once, then reuse them in either presentation. `RecordSheet` supplies
`erp-record`, a scoped design provider, the scroll region and optional quiet summary aside.

- Identity, visible state and primary workflow action lead; descriptive metadata is secondary.
- Group facts into named `DetailSection`s; use `DetailField` labels above values. Short related
  sections share a row; long text and line tables are full width. Use `equalHeight` when the
  sections belong together, not indiscriminately for unrelated content.
- Keep ordinary live document details open. Collapse optional evidence/history independently;
  distinguish frozen submitted evidence from a live record, including its field permissions.
- Keep totals/important quantities prominent and show their unit/currency. Use authoritative API
  values. Preserve decimal precision; never reconstruct a financial total for visual symmetry.
- Use status-valid actions, action-local pending/errors and explicit consequences. Cancellation,
  posting, issue and short-close retain their own guarded confirmation/reason flows.
- Missing data shows the shared placeholder or a meaningful “not specified” statement. A company's
  own address is not automatically a delivery destination; current master data is not a snapshot.
- On mobile, actions wrap left below identity and line details can use cards. Do not squeeze a
  wide table into the screen; its own scroll container must be usable.

### Create / edit

Use `ScreenTemplate kind="form"` on a page or `RecordSheet` with outside-dismissal disabled.
Wrap groups in `SectionStack` and reuse `DetailSection`; use `WizardShell` only for a meaningful
multi-stage workflow. A simple master-data form need not become a wizard.

- Put required/frequent fields first. Group related fields and align repeated lines on one grid.
- Multi-stage create validates before moving ahead. Edit reuses the same fields/steps with free
  navigation; don't maintain a second divergent form implementation.
- One disclosure controls each optional block, including each repeated document line. Hidden
  defaults remain saved. A closed block summarises non-default values (“Set: …”); stored values
  must not silently vanish. The menu alternative remains configurable where already supported.
- Use `FormLabelWithHint` for explanations, not errors or important consequences. Controls have
  real labels, focusable refs and visible validation. Clear a stale required error when corrected.
- A phone shows compact steps and aligned actions. The sticky footer contains Back/Next/Save once;
  there must not be a second competing step indicator or Save action in the summary column.
- Review shows the complete proposed document, server-resolved quantities, price bases, totals and
  blockers. The live summary aside steps away when the review itself becomes the summary.
- Restore unfinished work through the shared per-user/tab draft helpers where warranted. Clear it
  only after a successful save or explicit discard; a lookup/API failure must not lose input.
- Quick side-tasks such as adding an address use a centred dialog over the form, then return to it.
  Use shared mutation refresh handling so the list behind a sheet reflects the successful save.

## Visual hierarchy and spacing

The defaults are controlled in CSS, not repeated in each JSX tree.

| Element | Default | Purpose |
|---|---|---|
| Page title | 30px / 36px, 700 | Record/screen identity |
| Sheet title | 20px / 28px, 700 | Identity within a constrained workspace |
| Section title | 15px, 700 | Scan the groups before reading fields |
| Field labels / buttons | 14px, 500 / 600 | Clear labels and actionable controls |
| Values / table body | 15px / 14px, 500 / regular | Facts stay readable; don't bold everything |
| Helper text / metadata | 13px / 12px | Supporting information; never disabled-grey text |
| Inputs / pickers | 40px baseline | Consistent field height; actions retain the shared Button size variants |
| Page padding | 24px desktop, 16px phone | Breathing room without consuming mobile width |
| Section padding / gap | 16px / `--section-gap` (16px) | Consistent grouping rhythm |
| Field gap | 16px | Aligned labels and controls |

Main roles: pale canvas, raised surface, quiet panel, sunken/disabled surface, stronger control
border and lighter container hairline. Dark mode preserves these distinctions using neutral black/charcoal
surfaces, readable grey/white text and lighter blue interaction colours. Canvas, cards, panels,
fields, ordinary hover states and borders stay neutral; blue is reserved for interactive elements,
focus, selection and active state indicators. Brand colour identifies an
interaction/selection, not every section. Business state colours are independent of the accent.
Status roles are draft (neutral), pending (amber), active (blue), progress (violet), success (green)
and danger (red). Always show the state label; colour alone is insufficient.

Selected rows have a tinted surface and edge. Fields use an accent border and soft halo on focus;
buttons/links use a visible outline with clearance. Destructive actions and errors keep semantic
state colours, even if the company chooses an amber accent.

Known inherited limitation: light-mode field borders `#CBD5E1` and hover `#94A3B8` remain the PO
reference values, below 3:1 on white. The darker focus boundary is visible; this does not make the
resting boundary compliant. Do not claim full WCAG conformance based only on an axe pass.

## Overrides and portal inheritance

```tsx
<ScreenTemplate
  kind="detail"
  header={<PageHeader title="Goods receipt" />}
  variables={{ '--section-gap': '1.25rem', '--field-gap': '1.25rem' }}
>
  <DetailPageLayout>{/* domain sections */}</DetailPageLayout>
</ScreenTemplate>
```

Override order: shared defaults → template kind → explicit screen `variables` / component props.
Colours continue to use semantic roles and the active light/dark palette. A persistent per-screen
exception belongs in a named screen configuration, with its reason, rather than ad-hoc global CSS.
`RecordSheet` also accepts `variables`. `ScreenDesignProvider` merges nested overrides; shared
Sheet, Dialog, Popover, Select and Dropdown contents forward these explicit variables through
React context to their portal DOM roots. A component's own `style` is the last override there.
This avoids the old bug where the screen changed colour but its body-portalled picker did not.
Raw CSS-class-only overrides do not travel through React context: put transferable values in
`variables`, or explicitly supply a content class to that portal.

## Themes and future company defaults

The default is **ERP Blue** in both modes. Its stored theme ID remains `slate` so existing browser
preferences and the pre-paint script remain valid. Ocean and Amber still select alternate accents.
Palette primitives are namespaced `--erp-blue-*`; shared components consume `--ui-*` or established
semantic tokens such as `--card`, `--primary`, `--status-warning`. Legacy `--po-*` names are aliases.
New themes must implement light and dark interaction families; never hardcode blue into a screen.

Appearance resolution remains app default → company default → user choice, field by field.
The current company layer is empty and user choices are browser-local. A later authorised company
settings API will feed `setCompany`, and user preferences will feed `setUser`; the screens should
not change. This work does not implement those endpoints or persist company preferences. Backend
configuration must select validated theme/layout identifiers or bounded tokens, not executable
markup or arbitrary screen code. Permission rules never become theme configuration.

Paper remains white and independently branded. Reuse the shared print layouts or a registered
formal document template; do not print the application DOM or apply dark-mode tokens to paper.
PO Template 1 is a document template, not the list/detail/form screen template.

## Required checklist before shipping a screen

1. Choose list/detail/form and page/sheet; reuse the shared shell and controls.
2. Map every visible action/field/filter/print projection to existing capability and record scope;
   complete [authorisation](authorization.md)'s checklist, even when no new permission is needed.
3. Keep query context, defaults, input and appropriate view/column preferences across navigation.
4. Cover loading, empty, validation, denied, failed and successful states with the shared patterns.
5. Verify default light and dark, the chosen accent, keyboard/focus, mobile width and open portals.
6. Test important behaviours (filter request, selection, disclosure/default preservation, review,
   successful refresh), not merely that a class exists. Keep fixtures independent of mutable demos.
7. Update the matching domain/module/journey docs and STATUS alongside the work. Describe exceptions.

Adoption is incremental: PO list/page/sheets and Appearance settings use the shared templates now;
all screens receive the revised default semantic colours. Other screens' layout/behaviour retrofit
is a separate feature-by-feature pass. New screens must start on this standard.

## Related

- [UI building blocks](ui-building-blocks.md)
- [User-interface delivery](user-interface.md)
- [Appearance and printing](appearance-and-printing.md)
- [Authorisation](authorization.md)
- [Tech stack](tech-stack.md)
