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

`packages/core-ui/src/lib/appearance.ts` is the registry: a `ColorMode`
(`'light' | 'dark' | 'system'`) and a `ThemeId` (`THEMES` array — `slate`/`ocean`/`amber` today).
What a theme actually *looks like* lives in `apps/main/src/themes.css` as CSS variables, not here —
this file only says which ones exist and how they're picked.

`resolveAppearance(company, user)` implements the layering; `stores/appearance-store.ts` holds the
`company` (empty until a server endpoint exists) and `user` (persisted to `localStorage` under
`erp.appearance`) layers and exposes `useAppearance()` / `useIsDarkMode()`. `AppearanceProvider`
(`components/theme/appearance-provider.tsx`) applies the resolved appearance to `<html>` — the
`dark` class plus a `data-theme` attribute — in a `useLayoutEffect`, and `apps/main/index.html` has
an inline pre-paint `<script>` that does the same read synchronously before first paint, so there's
no flash of the wrong theme on load.

Every colour a screen uses is a semantic token (`bg-card`, `text-muted-foreground`,
`bg-primary-soft`, …), never a literal Tailwind palette colour — `themes.css` redefines the same
token set under `.dark` and under each theme's selector, so nothing that reads a token needs to
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
   overriding; everything else inherits the base palette.

Nothing else changes — the settings screen, the store and `AppearanceProvider` all read the
registry, not a hard-coded list.

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

## Related

- [UI building blocks](ui-building-blocks.md) — the wider inventory of reusable UI, and the
  screen-presentation switch this sits alongside
- [User-interface delivery](user-interface.md)
