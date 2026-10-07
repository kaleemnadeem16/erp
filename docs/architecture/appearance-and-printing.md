[← Architecture](README.md)

# Appearance and printing

Two independent, similarly-shaped systems: what colours the **app** shows on screen, and what a
**Quick Print** document looks like on paper. Both are plain CSS custom properties a component
reads, both are layered `app default < company default < user choice`, and both are browser-local
today (`localStorage`) because no company-settings/user-preferences endpoint exists yet — the
layering is already shaped for those to slot in later without a screen changing. See
[ui-building-blocks.md](ui-building-blocks.md) for where these components sit among the rest.

Paths are relative to `frontend/`.

## Appearance (light/dark, theme)

**Approved baseline, 2026-10-07:** the owner has locked the current light and refined dark
ERP Blue palettes. Reuse central tokens and adopt the shared standard one screen at a time.
Later palette revisions remain possible through an explicit owner instruction.
See the [baseline rule](erp-blue-design-standard.md#approved-colour-baseline-2026-10-07).

`packages/core-ui/src/lib/appearance.ts` is the registry: a `ColorMode`
(`'light' | 'dark' | 'system'`) and a `ThemeId` (`THEMES` array — ERP Blue, Ocean and Amber today;
ERP Blue retains the stored `slate` ID for compatibility).
The default light/dark surfaces, text, borders and status colours live in
`apps/main/src/palette-erp-blue.css`; accent alternatives and motion live in `themes.css`.
Dark canvas/cards/fields use neutral black and charcoal; the accent is reserved for interactions
and selected/active elements. Shared screen geometry/role rules live in `screen-templates.css`. The registry only names the
choices and their preview swatches. See the [screen standard](erp-blue-design-standard.md).

`resolveAppearance(company, user)` implements the layering; `stores/appearance-store.ts` holds the
`company` (empty until a server endpoint exists) and `user` (persisted to `localStorage` under
`erp.appearance`) layers and exposes `useAppearance()` / `useIsDarkMode()`. `AppearanceProvider`
(`components/theme/appearance-provider.tsx`) applies the resolved appearance to `<html>` — the
`dark` class plus a `data-theme` attribute — in a `useLayoutEffect`, and `apps/main/index.html` has
an inline pre-paint `<script>` that does the same read synchronously before first paint, so there's
no flash of the wrong theme on load.

Every colour a screen uses is a semantic token (`bg-card`, `text-muted-foreground`,
`bg-primary-soft`, …), never a literal Tailwind palette colour — `palette-erp-blue.css` pairs the default light/dark
tokens and `themes.css` overrides the accent family under each theme's selector, so nothing that reads a token needs to
know which mode or theme is active. A hard-coded colour (`text-emerald-600`, `bg-red-100`) is a bug
to fix by mapping it to the nearest `status-*` token, not a one-off to leave alone.

People change this at **Settings → Appearance** (`features/settings/appearance-settings-screen.tsx`,
`/settings/appearance`) — colour mode and theme, each as `OptionCards`
(`components/ui/option-cards.tsx`, a radiogroup of preview cards over native radio inputs) — or from
the sun/moon toggle in the desktop top bar for the common light/dark flip alone.

### Adding a theme

1. Add its `id`/`label`/`description`/`swatch` to `THEMES` in `lib/appearance.ts`.
2. Add its light and dark variable blocks to `themes.css`, following `ocean`/`amber` — only the
   handful of accent-bearing tokens (`--primary`, `--primary-foreground`, `--ring`,
   `--primary-soft`, `--primary-soft-foreground`, `--nav-active`, `--nav-active-foreground`) need
   overriding; everything else inherits the paired base palette. The shared `--ui-*` interactions
   resolve against these values, so focused controls, selected rows and portalled menus follow it.

Nothing else changes — the settings screen, the store and `AppearanceProvider` all read the
registry, not a hard-coded list.

Screen overrides use `ScreenTemplate.variables` or `RecordSheet.variables`. Their design provider
merges nested overrides and forwards them into shared Radix portals; selectors depending on a DOM
ancestor alone cannot theme a body-portalled menu. Default colours now reach all screens through
semantic tokens, while layout adoption remains incremental. The company/user preference layering
is unchanged; this work adds no backend persistence endpoint.

## Quick Print branding

`stores/print-settings-store.ts` holds company name, logo URL, an accent colour, a footer note and
whether signature lines show — persisted to `localStorage` under `erp.print-settings`. This is the
"global handler" for print design: `PrintLayout`, `DetailPrintLayout` and `PrintableTable`
(`components/print/`) — the three components every one of the sixteen Quick Print call sites
renders through — read the store directly. Changing the accent colour or company name in
**Settings → Printing** (`features/settings/printing-settings-screen.tsx`, `/settings/printing`)
reaches every screen's printed output without any of those sixteen screens being touched. A screen
can still pass its own `companyName`/`companyLogoUrl`/`showSignatureLines` to `PrintLayout` for the
rare document that must differ, but none does today.

The accent colour is applied as a CSS custom property (`--print-accent`) on `PrintLayout`'s root
element and read by descendants (`bg-[var(--print-accent)]`, `border-[var(--print-accent)]`,
`text-[var(--print-accent)]`) — the same "one variable, many consumers" shape as the appearance
tokens above, scoped to print instead of screen because printed paper is always white regardless of
the app's own light/dark mode.

`DetailPrintLayout`'s `sections` mirror the screen's own `DetailSection`s (same titles, same
grouping) and lay out two per row by default; a section with one long field (notes, an address)
should set `fullWidth: true` so it isn't squeezed into half a row. `PrintableTable` takes an
optional `footer` (a `<tr>`, rendered in a `<tfoot>`) for a document total — always the server's own
total field, never summed client-side, so it can't silently disagree with what the API considers
correct.

This is still **Quick Print** only — the data a screen already has loaded, rendered client-side.
Backend-generated formal documents (stable PDFs, company letterhead templates, page numbering) are a
separate, not-yet-built track; see the docblock on `components/print/use-quick-print.ts` for the
split and when to reach for which.

## Default purchase-order format (2026-10-06)

PO Quick Print uses **Template 1 — Classic purchase order**, the default A4 template in
`features/purchase-orders/purchase-order-print.tsx`. It follows the owner's two-page reference:
demo/company logo and letterhead at left, PO identity and metadata at right, supplier and delivery
panels, dark item-heading bands, shaded column headings, notes, an emphasised total and signatures.
The reference supersedes the earlier request for an entirely unboxed format. The demo logo is an
inline vector mark used only when no logo is configured; a configured company logo replaces it.
Company name/address/contact/tax identifiers still come from the Company profile.

**Settings → Printing → Purchase order template** saves the selected registry ID in the existing
browser-local print preferences. `PURCHASE_ORDER_PRINT_TEMPLATES` registers the available formats;
`resolvePurchaseOrderPrintTemplate` falls back to Template 1 for unknown IDs. Each versioned
format declares an ID, label, section order and optional section renderers. Per-call overrides
remain available; the rendered document and measurement tree share the template ID for scoped
styles. Adding another format means registering its definition/renderers/styles, without changing
the PO detail screen or its API adapter. Only Template 1 exists today. The Settings preview uses
the actual selected renderer with explicitly labelled demo data. PO signature lines default on,
with their own saved toggle; generic master-data print signature choices remain separate.

`buildPurchaseOrderPrintDocument` adapts the current projected resources to a versioned,
JSON-compatible `PurchaseOrderPrintDocument`. A future authorised backend document/template
projection can feed that boundary without constructing HTML in the screen. This remains browser
Quick Print, not a stable archival PDF or backend template service. Submitted approval print stays
its separate frozen evidence; the live PO never substitutes for it.

**Pagination:** a hidden, off-screen measurement tree outside the hidden print container measures
sections and item rows at the same A4 width as the paper. Rows are allocated to explicit A4 sheets;
continuation sheets repeat a compact company/order header and item column headings. A footer appears
on every sheet; page X of Y uses the calculated sheet count. Notes/total/signatures appear only on
the final sheet, with an additional closing sheet when they do not fit beside the last item rows.
Fonts and configured logo loading trigger measurement again. The table fills spare vertical room
on each item sheet. PO printing requests zero browser page margins; each sheet supplies its own
8mm inset. Disable browser **Headers and footers** if a browser still supplies its own furniture.
A browser's paper-size/scaling settings must preserve A4 for the measured sheet layout.

**Data and authorisation:** existing document-read and commercial-read gates and projected API
omissions still apply; no endpoint, company-data write, approval transition or permission is added.
The template selector changes only browser preferences. Monetary values retain decimal-string
precision, whole-line tax/discount labels and the server total; unsupported aggregate subtotal,
discount and tax rows are omitted. No frontend total arithmetic is introduced. Discount/tax columns
appear when non-zero amounts exist on the printed lines.

Supplier/site address, tax/registration/website, payment terms and lead time appear when available.
Optional line details include expected delivery, supplier item code, package name/code, QA,
stock equivalent and differing priced quantity/unit. PO lines have tax amounts, not a tax-rate or
tax-classification field. Draft item codes may fall back to internal IDs until issue snapshots them.
Supplier details/terms remain current master data, not issue-time snapshots. Missing delivery shows
“Delivery address not specified”; company address is never assumed to be the destination. Contacts,
attention names and legal terms are not invented. Blank signature lines follow the PO toggle.
Internal FX/base amounts, receipt progress/tolerances, exception reasons and arbitrary custom fields
remain outside this supplier-facing default.

Browser checks cover company branding, optional line/supplier details, quantity-only visibility,
Template 1 selection/persistence, signatures and a 55-line order. They verify row retention, footer
clearance, repeated continuation headings and physical PDF sheet counts matching the displayed count.
One-page and continuation-page screenshots are reviewed alongside the generated PDFs.

### Format comparison

Research accessed 2026-10-06: [ERPNext Purchase Order](https://docs.frappe.io/erpnext/purchase-order)
separates supplier address, shipping address, letterhead and agreed terms;
[Odoo's official PO template](https://github.com/odoo/odoo/blob/19.0/addons/purchase/report/purchase_order_templates.xml)
uses an external letterhead, optional destination, order metadata, item/quantity/rate/amount table,
notes and payment terms. We reuse their separation and optionality, rather than duplicating the
company address or mirroring every internal screen card. Odoo's percentage discount and tax
classification differ from our fixed line amounts, so their labels/calculations are not copied.
The reference's internal FX, repeated totals, invented contacts/terms and received-by signatures
were rejected for this supplier-facing default. Backend formal rendering remains the separate
track above, including trustworthy page counts and frozen company letterhead.

## Related

- [UI building blocks](ui-building-blocks.md) — the wider inventory of reusable UI, and the
  screen-presentation switch this sits alongside
- [User-interface delivery](user-interface.md)
